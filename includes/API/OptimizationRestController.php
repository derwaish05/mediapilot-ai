<?php

declare(strict_types=1);

namespace MediaPilotAI\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Optimization\ImageOptimizer;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST routes for the CDN + Image Optimization feature (S56).
 *
 * Namespace: mediapilot/v1
 *
 * Routes:
 *   GET  /optimization/stats            Speed dashboard aggregate data.
 *   POST /optimization/process/{id}     Optimise a single attachment.
 *   POST /optimization/batch            Optimise all images (or folder subset).
 *
 * @package MediaPilotAI\API
 * @since   1.0.0
 */
class OptimizationRestController {

    private const NAMESPACE = 'mediapilot/v1';

    public function __construct(
        private readonly ImageOptimizer $optimizer,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function register(): void {
        // GET /optimization/stats
        register_rest_route(
            self::NAMESPACE,
            '/optimization/stats',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'getStats' ],
                'permission_callback' => [ $this, 'permManageSettings' ],
            ]
        );

        // POST /optimization/process/{id}
        register_rest_route(
            self::NAMESPACE,
            '/optimization/process/(?P<id>\d+)',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'processOne' ],
                'permission_callback' => [ $this, 'permManageSettings' ],
                'args'                => [
                    'id'     => [
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ],
                    'format' => [
                        'type'    => 'string',
                        'default' => 'auto',
                        'enum'    => [ 'auto', 'webp', 'avif' ],
                    ],
                ],
            ]
        );

        // POST /optimization/batch
        register_rest_route(
            self::NAMESPACE,
            '/optimization/batch',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'processBatch' ],
                'permission_callback' => [ $this, 'permManageSettings' ],
                'args'                => [
                    'folder_id' => [
                        'type'    => 'integer',
                        'default' => 0,
                    ],
                    'format'    => [
                        'type'    => 'string',
                        'default' => 'auto',
                        'enum'    => [ 'auto', 'webp', 'avif' ],
                    ],
                    'limit'     => [
                        'type'    => 'integer',
                        'default' => 0,
                    ],
                ],
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Callbacks
    // -------------------------------------------------------------------------

    /**
     * GET /optimization/stats
     *
     * Returns aggregate optimisation stats for the speed dashboard.
     */
    public function getStats( WP_REST_Request $request ): WP_REST_Response {
        return $this->success( $this->optimizer->getStats() );
    }

    /**
     * POST /optimization/process/{id}
     *
     * Synchronously optimise a single attachment.
     */
    public function processOne( WP_REST_Request $request ): WP_REST_Response {
        $id = (int) $request->get_param( 'id' );

        if ( 'attachment' !== get_post_type( $id ) ) {
            return $this->error( 'not_found', 'Attachment not found.', 404 );
        }

        $format   = (string) $request->get_param( 'format' );
        $settings = $this->optimizer->getSettings();

        if ( $format === 'webp' ) {
            $settings['auto_webp'] = true;
            $settings['auto_avif'] = false;
        } elseif ( $format === 'avif' ) {
            $settings['auto_webp'] = false;
            $settings['auto_avif'] = true;
        }

        $result = $this->optimizer->optimizeAttachment( $id, $settings );

        return $this->success( array_merge( [ 'attachment_id' => $id ], $result ) );
    }

    /**
     * POST /optimization/batch
     *
     * Synchronously optimise a batch of images.
     * For large libraries, call from WP-CLI instead.
     */
    public function processBatch( WP_REST_Request $request ): WP_REST_Response {
        $folderId = (int) $request->get_param( 'folder_id' );
        $format   = (string) $request->get_param( 'format' );
        $limit    = (int) $request->get_param( 'limit' );

        $result = $this->optimizer->optimizeAll( $folderId, $format, $limit );

        return $this->success( $result );
    }

    // -------------------------------------------------------------------------
    // Permission callbacks
    // -------------------------------------------------------------------------

    public function permManageSettings(): bool {
        return current_user_can( 'manage_mdpai_settings' );
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
