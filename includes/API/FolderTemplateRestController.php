<?php

declare(strict_types=1);

namespace MediaPilotAI\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderTemplate;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST routes for Folder Templates / Presets (S38).
 *
 * Namespace: mediapilot/v1
 *
 * Routes:
 *   GET    /folder-templates              List all templates (presets + stored).
 *   POST   /folder-templates              Save a new template from a folder ID.
 *   DELETE /folder-templates/{id}         Delete a stored template.
 *   POST   /folder-templates/{id}/apply   Apply template under a target folder.
 *   GET    /folder-templates/{id}/export  Export template as a JSON download.
 *   POST   /folder-templates/import       Import template from JSON body.
 *
 * @package MediaPilotAI\API
 * @since   1.0.0
 */
class FolderTemplateRestController {

    private const NAMESPACE = 'mediapilot/v1';

    public function __construct(
        private readonly FolderTemplate $folderTemplate,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function register(): void {
        // GET /folder-templates
        register_rest_route( self::NAMESPACE, '/folder-templates', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'listTemplates' ],
            'permission_callback' => [ $this, 'permUploadFiles' ],
        ] );

        // POST /folder-templates
        register_rest_route( self::NAMESPACE, '/folder-templates', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'createTemplate' ],
            'permission_callback' => [ $this, 'permManageFolders' ],
            'args'                => [
                'folder_id' => [
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ],
                'name' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'description' => [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
            ],
        ] );

        // DELETE /folder-templates/{id}
        register_rest_route( self::NAMESPACE, '/folder-templates/(?P<id>-?\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'deleteTemplate' ],
            'permission_callback' => [ $this, 'permManageFolders' ],
            'args'                => [
                'id' => [ 'type' => 'integer' ],
            ],
        ] );

        // POST /folder-templates/{id}/apply
        register_rest_route( self::NAMESPACE, '/folder-templates/(?P<id>-?\d+)/apply', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'applyTemplate' ],
            'permission_callback' => [ $this, 'permManageFolders' ],
            'args'                => [
                'id' => [ 'type' => 'integer' ],
                'target_folder_id' => [
                    'type'              => 'integer',
                    'default'           => 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        // GET /folder-templates/{id}/export
        register_rest_route( self::NAMESPACE, '/folder-templates/(?P<id>-?\d+)/export', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'exportTemplate' ],
            'permission_callback' => [ $this, 'permManageFolders' ],
            'args'                => [
                'id' => [ 'type' => 'integer' ],
            ],
        ] );

        // POST /folder-templates/import
        register_rest_route( self::NAMESPACE, '/folder-templates/import', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'importTemplate' ],
            'permission_callback' => [ $this, 'permManageFolders' ],
            'args'                => [
                'json' => [
                    'type'     => 'string',
                    'required' => true,
                ],
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Callbacks
    // -------------------------------------------------------------------------

    /** GET /folder-templates */
    public function listTemplates( WP_REST_Request $request ): WP_REST_Response {
        return $this->success( [ 'templates' => $this->folderTemplate->listAll() ] );
    }

    /** POST /folder-templates */
    public function createTemplate( WP_REST_Request $request ): WP_REST_Response {
        $folderId    = (int) $request->get_param( 'folder_id' );
        $name        = (string) $request->get_param( 'name' );
        $description = (string) $request->get_param( 'description' );

        try {
            $id = $this->folderTemplate->captureFromFolder( $folderId, $name, $description );
        } catch ( \InvalidArgumentException $e ) {
            return $this->error( 'invalid_input', $e->getMessage(), 400 );
        }

        return $this->success( [ 'id' => $id, 'message' => __( 'Template saved.', 'mediapilot-ai') ], 201 );
    }

    /** DELETE /folder-templates/{id} */
    public function deleteTemplate( WP_REST_Request $request ): WP_REST_Response {
        $id = (int) $request->get_param( 'id' );

        if ( $id <= 0 ) {
            return $this->error( 'preset_protected', 'Built-in presets cannot be deleted.', 403 );
        }

        $deleted = $this->folderTemplate->delete( $id );

        if ( ! $deleted ) {
            return $this->error( 'not_found', "Template #{$id} not found.", 404 );
        }

        return $this->success( [ 'deleted' => $id ] );
    }

    /** POST /folder-templates/{id}/apply */
    public function applyTemplate( WP_REST_Request $request ): WP_REST_Response {
        $id             = (int) $request->get_param( 'id' );
        $targetFolderId = (int) $request->get_param( 'target_folder_id' );
        $userId         = get_current_user_id();

        try {
            $result = $this->folderTemplate->applyToFolder( $id, $targetFolderId, $userId );
        } catch ( \InvalidArgumentException $e ) {
            return $this->error( 'not_found', $e->getMessage(), 404 );
        }

        return $this->success( $result );
    }

    /** GET /folder-templates/{id}/export */
    public function exportTemplate( WP_REST_Request $request ): WP_REST_Response {
        $id = (int) $request->get_param( 'id' );

        try {
            $json = $this->folderTemplate->exportJson( $id );
        } catch ( \InvalidArgumentException $e ) {
            return $this->error( 'not_found', $e->getMessage(), 404 );
        }

        // Return raw JSON in the data field so the client can save it.
        return $this->success( [ 'json' => $json ] );
    }

    /** POST /folder-templates/import */
    public function importTemplate( WP_REST_Request $request ): WP_REST_Response {
        $json = (string) $request->get_param( 'json' );

        try {
            $id = $this->folderTemplate->importJson( $json );
        } catch ( \InvalidArgumentException $e ) {
            return $this->error( 'invalid_json', $e->getMessage(), 400 );
        }

        return $this->success( [ 'id' => $id, 'message' => __( 'Template imported.', 'mediapilot-ai') ], 201 );
    }

    // -------------------------------------------------------------------------
    // Permissions
    // -------------------------------------------------------------------------

    public function permUploadFiles(): bool {
        return current_user_can( 'upload_files' );
    }

    public function permManageFolders(): bool {
        return current_user_can( 'manage_mdpai_folders' );
    }

    // -------------------------------------------------------------------------
    // Response helpers
    // -------------------------------------------------------------------------

    private function success( mixed $data, int $status = 200 ): WP_REST_Response {
        return new WP_REST_Response( [ 'success' => true, 'data' => $data ], $status );
    }

    private function error( string $code, string $message, int $status = 400 ): WP_REST_Response {
        return new WP_REST_Response(
            [ 'success' => false, 'code' => $code, 'message' => $message ],
            $status
        );
    }
}
