<?php

declare(strict_types=1);

namespace MediaPilotAI\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Frontend\ShareLinkRepository;
use MediaPilotAI\Frontend\ClientPortal;
use MediaPilotAI\Folder\FolderRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST endpoints for Client Sharing Portal (S59).
 *
 * Namespace: mediapilot/v1
 *
 * Routes:
 *   GET    /folders/{id}/shares          List all share links for a folder.
 *   POST   /folders/{id}/share           Create a new share link.
 *   DELETE /shares/{id}                  Revoke (delete) a share link by ID.
 *   GET    /shares/{id}/downloads        Download log for a share link.
 *   GET    /shares                       List all share links (admin).
 *
 * @package MediaPilotAI\API
 * @since   1.0.0
 */
class ShareRestController {

    private const NAMESPACE = 'mediapilot/v1';
    private const CAP       = 'manage_mdpai_folders';

    public function __construct(
        private readonly ShareLinkRepository $linkRepo,
        private readonly FolderRepository    $folderRepo,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function register(): void {
        // List shares for a folder.
        register_rest_route( self::NAMESPACE, '/folders/(?P<folder_id>\d+)/shares', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'listForFolder' ],
            'permission_callback' => [ $this, 'permCap' ],
            'args'                => [
                'folder_id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        // Create a share link for a folder.
        register_rest_route( self::NAMESPACE, '/folders/(?P<folder_id>\d+)/share', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'create' ],
            'permission_callback' => [ $this, 'permCap' ],
            'args'                => [
                'folder_id'    => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
                'password'     => [ 'type' => 'string',  'default'  => '', 'sanitize_callback' => 'sanitize_text_field' ],
                'expires_at'   => [ 'type' => 'string',  'default'  => '', 'sanitize_callback' => 'sanitize_text_field' ],
                'logo_url'     => [ 'type' => 'string',  'default'  => '', 'sanitize_callback' => 'sanitize_url' ],
                'header_color' => [ 'type' => 'string',  'default'  => '#2563eb', 'sanitize_callback' => 'sanitize_hex_color' ],
            ],
        ] );

        // Revoke a share link.
        register_rest_route( self::NAMESPACE, '/shares/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'revoke' ],
            'permission_callback' => [ $this, 'permCap' ],
            'args'                => [
                'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        // Download log for a share link.
        register_rest_route( self::NAMESPACE, '/shares/(?P<id>\d+)/downloads', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'downloads' ],
            'permission_callback' => [ $this, 'permCap' ],
            'args'                => [
                'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        // All shares (admin overview).
        register_rest_route( self::NAMESPACE, '/shares', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'listAll' ],
            'permission_callback' => [ $this, 'permManageSettings' ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Callbacks
    // -------------------------------------------------------------------------

    public function listForFolder( WP_REST_Request $request ): WP_REST_Response {
        $folderId = (int) $request->get_param( 'folder_id' );
        $links    = $this->linkRepo->getByFolder( $folderId );

        return new WP_REST_Response( [
            'success' => true,
            'data'    => array_map( [ $this, 'formatLink' ], $links ),
        ], 200 );
    }

    public function create( WP_REST_Request $request ): WP_REST_Response {
        $folderId = (int) $request->get_param( 'folder_id' );

        // Ensure folder exists.
        if ( null === $this->folderRepo->getById( $folderId ) ) {
            return new WP_REST_Response(
                [ 'success' => false, 'message' => __( 'Folder not found.', 'mediapilot-ai') ],
                404
            );
        }

        $expiresRaw = trim( (string) $request->get_param( 'expires_at' ) );
        $expiresAt  = '' !== $expiresRaw ? gmdate( 'Y-m-d H:i:s', (int) strtotime( $expiresRaw ) ) : null;

        $link = $this->linkRepo->create(
            $folderId,
            [
                'password'     => (string) $request->get_param( 'password' ),
                'expires_at'   => $expiresAt,
                'logo_url'     => (string) $request->get_param( 'logo_url' ),
                'header_color' => (string) ( $request->get_param( 'header_color' ) ?: '#2563eb' ),
            ],
            get_current_user_id()
        );

        if ( null === $link ) {
            return new WP_REST_Response(
                [ 'success' => false, 'message' => __( 'Failed to create share link.', 'mediapilot-ai') ],
                500
            );
        }

        return new WP_REST_Response( [
            'success' => true,
            'data'    => $this->formatLink( $link ),
        ], 201 );
    }

    public function revoke( WP_REST_Request $request ): WP_REST_Response {
        $id      = (int) $request->get_param( 'id' );
        $deleted = $this->linkRepo->delete( $id );

        if ( ! $deleted ) {
            return new WP_REST_Response(
                [ 'success' => false, 'message' => __( 'Share link not found.', 'mediapilot-ai') ],
                404
            );
        }

        return new WP_REST_Response( [ 'success' => true, 'data' => [ 'deleted_id' => $id ] ], 200 );
    }

    public function downloads( WP_REST_Request $request ): WP_REST_Response {
        $id   = (int) $request->get_param( 'id' );
        $rows = $this->linkRepo->getDownloads( $id );

        return new WP_REST_Response( [ 'success' => true, 'data' => $rows ], 200 );
    }

    public function listAll( WP_REST_Request $request ): WP_REST_Response {
        $links = $this->linkRepo->getAll();

        return new WP_REST_Response( [
            'success' => true,
            'data'    => array_map( [ $this, 'formatLink' ], $links ),
        ], 200 );
    }

    // -------------------------------------------------------------------------
    // Permissions
    // -------------------------------------------------------------------------

    public function permCap(): bool {
        return current_user_can( self::CAP );
    }

    public function permManageSettings(): bool {
        return current_user_can( 'manage_mdpai_settings' );
    }

    // -------------------------------------------------------------------------
    // Formatting
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed> $link  Raw DB row.
     * @return array<string, mixed>
     */
    private function formatLink( array $link ): array {
        $token      = (string) $link['token'];
        $isExpired  = ! $this->linkRepo->isValid( $link );
        $dlCount    = $this->linkRepo->downloadCount( (int) $link['id'] );

        return [
            'id'             => (int)    $link['id'],
            'token'          => $token,
            'folder_id'      => (int)    $link['folder_id'],
            'has_password'   => ! empty( $link['password_hash'] ),
            'expires_at'     => $link['expires_at'] ?? null,
            'is_expired'     => $isExpired,
            'logo_url'       => (string) $link['logo_url'],
            'header_color'   => (string) $link['header_color'],
            'created_by'     => (int)    $link['created_by'],
            'created_at'     => (string) $link['created_at'],
            'portal_url'     => ClientPortal::portalUrl( $token ),
            'download_count' => $dlCount,
        ];
    }
}
