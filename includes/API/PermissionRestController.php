<?php

declare(strict_types=1);

namespace MediaPilotAI\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderPermission;
use MediaPilotAI\Folder\FolderRepository;
use MediaPilotAI\Folder\PermissionRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST routes for per-folder permission management.
 *
 * All routes require the `manage_mdpai_folders` capability.
 *
 * Routes:
 *   GET    /mediapilot/v1/folders/{id}/permissions          — list all rules
 *   PUT    /mediapilot/v1/folders/{id}/permissions          — upsert a rule
 *   DELETE /mediapilot/v1/folders/{id}/permissions          — delete a rule
 *   POST   /mediapilot/v1/folders/{id}/permissions/preset   — apply agency preset
 *   GET    /mediapilot/v1/permissions/presets               — list available presets
 *   GET    /mediapilot/v1/permissions/check                 — check caller's access
 *
 * @package MediaPilotAI\API
 * @since   1.0.0
 */
class PermissionRestController {

    private const NS = 'mediapilot/v1';

    public function __construct(
        private readonly FolderPermission    $folderPermission,
        private readonly PermissionRepository $permRepo,
        private readonly FolderRepository    $folderRepo,
    ) {}

    public function register(): void {
        // List rules for a folder.
        register_rest_route( self::NS, '/folders/(?P<id>\d+)/permissions', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'listPermissions' ],
            'permission_callback' => [ $this, 'permManage' ],
            'args'                => [ 'id' => [ 'type' => 'integer', 'sanitize_callback' => 'absint' ] ],
        ] );

        // Upsert a rule.
        register_rest_route( self::NS, '/folders/(?P<id>\d+)/permissions', [
            'methods'             => 'PUT',
            'callback'            => [ $this, 'upsertPermission' ],
            'permission_callback' => [ $this, 'permManage' ],
            'args'                => $this->upsertArgs(),
        ] );

        // Delete a rule.
        register_rest_route( self::NS, '/folders/(?P<id>\d+)/permissions', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'deletePermission' ],
            'permission_callback' => [ $this, 'permManage' ],
            'args'                => [
                'id'        => [ 'type' => 'integer', 'sanitize_callback' => 'absint' ],
                'entity'    => [ 'type' => 'string',  'required' => true, 'enum' => [ 'role', 'user' ] ],
                'entity_id' => [ 'type' => 'string',  'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        // Apply a named agency preset to a role on a folder.
        register_rest_route( self::NS, '/folders/(?P<id>\d+)/permissions/preset', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'applyPreset' ],
            'permission_callback' => [ $this, 'permManage' ],
            'args'                => [
                'id'        => [ 'type' => 'integer', 'sanitize_callback' => 'absint' ],
                'role'      => [ 'type' => 'string',  'required' => true, 'sanitize_callback' => 'sanitize_key' ],
                'preset'    => [
                    'type'     => 'string',
                    'required' => true,
                    'enum'     => [ 'designer', 'editor', 'publisher', 'viewer', 'none' ],
                ],
            ],
        ] );

        // List available preset names and their bit definitions.
        register_rest_route( self::NS, '/permissions/presets', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'listPresets' ],
            'permission_callback' => [ $this, 'permManage' ],
        ] );

        // Check the current user's access for a specific folder + operation.
        register_rest_route( self::NS, '/permissions/check', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'checkAccess' ],
            'permission_callback' => static fn() => is_user_logged_in(),
            'args'                => [
                'folder_id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
                'action'    => [
                    'type'    => 'string',
                    'default' => 'read',
                    'enum'    => [ 'read', 'write', 'delete' ],
                ],
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Handlers
    // -------------------------------------------------------------------------

    public function listPermissions( WP_REST_Request $request ): WP_REST_Response {
        $folderId = (int) $request->get_param( 'id' );

        if ( null === $this->folderRepo->getById( $folderId ) ) {
            return $this->error( 'folder_not_found', 'Folder not found.', 404 );
        }

        $rows = $this->permRepo->getForFolder( $folderId );

        // Decorate user rows with display names.
        $rows = array_map( function ( array $row ) {
            if ( 'user' === $row['entity'] ) {
                $user = get_userdata( (int) $row['entity_id'] );
                $row['display_name'] = $user ? $user->display_name : $row['entity_id'];
            } else {
                $row['display_name'] = ucfirst( $row['entity_id'] );
            }
            return $row;
        }, $rows );

        return $this->success( $rows );
    }

    public function upsertPermission( WP_REST_Request $request ): WP_REST_Response {
        $folderId  = (int)    $request->get_param( 'id' );
        $entity    = (string) $request->get_param( 'entity' );
        $entityId  = (string) $request->get_param( 'entity_id' );
        $canRead   = (bool)   $request->get_param( 'can_read' );
        $canWrite  = (bool)   $request->get_param( 'can_write' );
        $canDelete = (bool)   $request->get_param( 'can_delete' );

        if ( null === $this->folderRepo->getById( $folderId ) ) {
            return $this->error( 'folder_not_found', 'Folder not found.', 404 );
        }

        // Validate entity_id: if entity='user', must be a valid user ID.
        if ( 'user' === $entity ) {
            $userId = (int) $entityId;
            if ( $userId <= 0 || ! get_userdata( $userId ) ) {
                return $this->error( 'invalid_user', 'User not found.', 400 );
            }
        }

        // Validate entity_id: if entity='role', must be a registered role.
        if ( 'role' === $entity && ! get_role( $entityId ) ) {
            return $this->error( 'invalid_role', "Role \"{$entityId}\" not found.", 400 );
        }

        $ok = $this->permRepo->upsert( $folderId, $entity, $entityId, $canRead, $canWrite, $canDelete );

        if ( ! $ok ) {
            return $this->error( 'db_error', 'Failed to save permission.', 500 );
        }

        $saved = $this->permRepo->get( $folderId, $entity, $entityId );

        return $this->success( $saved, 200 );
    }

    public function deletePermission( WP_REST_Request $request ): WP_REST_Response {
        $folderId = (int)    $request->get_param( 'id' );
        $entity   = (string) $request->get_param( 'entity' );
        $entityId = (string) $request->get_param( 'entity_id' );

        $ok = $this->permRepo->delete( $folderId, $entity, $entityId );

        return $ok
            ? $this->success( null )
            : $this->error( 'not_found', 'Permission rule not found.', 404 );
    }

    public function applyPreset( WP_REST_Request $request ): WP_REST_Response {
        $folderId = (int)    $request->get_param( 'id' );
        $roleName = (string) $request->get_param( 'role' );
        $preset   = (string) $request->get_param( 'preset' );

        if ( null === $this->folderRepo->getById( $folderId ) ) {
            return $this->error( 'folder_not_found', 'Folder not found.', 404 );
        }

        if ( ! get_role( $roleName ) ) {
            return $this->error( 'invalid_role', "Role \"{$roleName}\" not found.", 400 );
        }

        try {
            $this->folderPermission->applyPreset( $folderId, $roleName, $preset );
        } catch ( \InvalidArgumentException $e ) {
            return $this->error( 'invalid_preset', $e->getMessage(), 400 );
        }

        return $this->success( $this->permRepo->get( $folderId, 'role', $roleName ) );
    }

    public function listPresets(): WP_REST_Response {
        $presets = [];
        foreach ( $this->folderPermission->getPresetNames() as $name ) {
            $presets[ $name ] = $this->folderPermission->getPreset( $name );
        }

        return $this->success( $presets );
    }

    public function checkAccess( WP_REST_Request $request ): WP_REST_Response {
        $folderId = (int)    $request->get_param( 'folder_id' );
        $action   = (string) $request->get_param( 'action' );
        $userId   = get_current_user_id();

        $allowed = match ( $action ) {
            'read'   => $this->folderPermission->canRead( $folderId, $userId ),
            'write'  => $this->folderPermission->canWrite( $folderId, $userId ),
            'delete' => $this->folderPermission->canDelete( $folderId, $userId ),
            default  => false,
        };

        return $this->success( [ 'allowed' => $allowed, 'folder_id' => $folderId, 'action' => $action ] );
    }

    // -------------------------------------------------------------------------
    // Permission callback
    // -------------------------------------------------------------------------

    public function permManage(): bool {
        return current_user_can( 'manage_mdpai_folders' );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function upsertArgs(): array {
        return [
            'id'         => [ 'type' => 'integer', 'sanitize_callback' => 'absint' ],
            'entity'     => [ 'type' => 'string',  'required' => true, 'enum' => [ 'role', 'user' ] ],
            'entity_id'  => [ 'type' => 'string',  'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
            'can_read'   => [ 'type' => 'boolean', 'default'  => true,  'sanitize_callback' => 'rest_sanitize_boolean' ],
            'can_write'  => [ 'type' => 'boolean', 'default'  => false, 'sanitize_callback' => 'rest_sanitize_boolean' ],
            'can_delete' => [ 'type' => 'boolean', 'default'  => false, 'sanitize_callback' => 'rest_sanitize_boolean' ],
        ];
    }

    private function success( mixed $data, int $status = 200 ): WP_REST_Response {
        return new WP_REST_Response( [ 'success' => true, 'data' => $data ], $status );
    }

    private function error( string $code, string $message, int $status ): WP_REST_Response {
        return new WP_REST_Response( [ 'success' => false, 'code' => $code, 'message' => $message ], $status );
    }
}
