<?php

declare(strict_types=1);

namespace MediaPilotAI\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\AI\AiTaggingService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST routes for AI Auto-Tagging (S47).
 *
 * Namespace: mediapilot/v1
 *
 * Routes:
 *   GET  /files/{id}/ai-tags   Fetch stored AI tags + folder suggestion for a file.
 *   POST /files/{id}/ai-retag  Trigger (or re-trigger) AI analysis for a file.
 *
 * @package MediaPilotAI\API
 * @since   1.0.0
 */
class AiTaggingRestController {

    private const NAMESPACE = 'mediapilot/v1';

    public function __construct(
        private readonly AiTaggingService $aiService,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function register(): void {
        // GET /files/{id}/ai-tags
        register_rest_route( self::NAMESPACE, '/files/(?P<id>\d+)/ai-tags', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'getTags' ],
            'permission_callback' => [ $this, 'permUploadFiles' ],
            'args'                => $this->idArg(),
        ] );

        // POST /files/{id}/ai-retag
        register_rest_route( self::NAMESPACE, '/files/(?P<id>\d+)/ai-retag', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'retag' ],
            'permission_callback' => [ $this, 'permUploadFiles' ],
            'args'                => $this->idArg(),
        ] );
    }

    // -------------------------------------------------------------------------
    // Callbacks
    // -------------------------------------------------------------------------

    public function getTags( WP_REST_Request $request ): WP_REST_Response {
        $id = (int) $request->get_param( 'id' );

        if ( get_post_type( $id ) !== 'attachment' ) {
            return new WP_REST_Response(
                [ 'success' => false, 'message' => __( 'Attachment not found.', 'mediapilot-ai') ],
                404
            );
        }

        return new WP_REST_Response(
            [ 'success' => true, 'data' => $this->aiService->getAiTagsForApi( $id ) ],
            200
        );
    }

    public function retag( WP_REST_Request $request ): WP_REST_Response {
        $id = (int) $request->get_param( 'id' );

        if ( get_post_type( $id ) !== 'attachment' ) {
            return new WP_REST_Response(
                [ 'success' => false, 'message' => __( 'Attachment not found.', 'mediapilot-ai') ],
                404
            );
        }

        try {
            $result = $this->aiService->analyzeAttachment( $id );

            return new WP_REST_Response( [ 'success' => true, 'data' => $result ], 200 );
        } catch ( \RuntimeException $e ) {
            return new WP_REST_Response(
                [ 'success' => false, 'message' => $e->getMessage() ],
                500
            );
        }
    }

    public function permUploadFiles(): bool {
        return current_user_can( 'upload_files' );
    }

    // -------------------------------------------------------------------------
    // Arg definitions
    // -------------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    private function idArg(): array {
        return [
            'id' => [
                'type'              => 'integer',
                'required'          => true,
                'minimum'           => 1,
                'sanitize_callback' => 'absint',
            ],
        ];
    }
}
