<?php

declare(strict_types=1);

namespace MediaPilotAI\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Media\UsageTracker;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST routes for the Media Usage Tracker (S33).
 *
 * Namespace: mediapilot/v1
 *
 * Routes:
 *   GET  /files/{id}/usage          Returns all usage records for an attachment.
 *   POST /usage/scan                Triggers a full site scan (admin only).
 *
 * @package MediaPilotAI\API
 * @since   1.0.0
 */
class UsageRestController {

    private const NAMESPACE = 'mediapilot/v1';

    public function __construct(
        private readonly UsageTracker $usageTracker,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function register(): void {
        register_rest_route(
            self::NAMESPACE,
            '/files/(?P<id>\d+)/usage',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'getUsage' ],
                'permission_callback' => [ $this, 'permUploadFiles' ],
                'args'                => [
                    'id' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                        'minimum'           => 1,
                    ],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/usage/scan',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'triggerScan' ],
                'permission_callback' => [ $this, 'permManageFolders' ],
            ]
        );

        // POST /usage/scan/advance — chunked rebuild driven from the admin.
        register_rest_route(
            self::NAMESPACE,
            '/usage/scan/advance',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'advanceScan' ],
                'permission_callback' => [ $this, 'permManageFolders' ],
                'args'                => [
                    'offset' => [
                        'type'              => 'integer',
                        'default'           => 0,
                        'minimum'           => 0,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Handlers
    // -------------------------------------------------------------------------

    /**
     * GET /files/{id}/usage
     *
     * Returns all pages/posts/widgets that reference the given attachment.
     *
     * @param  WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function getUsage( WP_REST_Request $request ): WP_REST_Response {
        $attachmentId = (int) $request->get_param( 'id' );

        // Verify the attachment exists.
        if ( 'attachment' !== get_post_type( $attachmentId ) ) {
            return $this->error( 'not_found', __( 'Attachment not found.', 'mediapilot-ai'), 404 );
        }

        $usage = $this->usageTracker->getUsageForAttachment( $attachmentId );

        return $this->success( [
            'attachment_id'      => $attachmentId,
            'usage'              => $usage,
            'total'              => count( $usage ),
            'used_in_published'  => $this->usageTracker->isUsedInPublished( $attachmentId ),
        ] );
    }

    /**
     * POST /usage/scan
     *
     * Triggers a full site-wide usage scan. Runs synchronously (suitable for
     * small sites) and returns a summary. For large sites, use WP-CLI.
     *
     * @param  WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function triggerScan( WP_REST_Request $request ): WP_REST_Response {
        $stats = $this->usageTracker->scanAll();

        return $this->success( [
            'scanned'    => $stats['scanned'],
            'references' => $stats['references'],
            'message'    => sprintf(
                /* translators: 1: number of posts scanned 2: number of references found */
                __( 'Scan complete. %1$d posts scanned, %2$d references indexed.', 'mediapilot-ai'),
                $stats['scanned'],
                $stats['references']
            ),
        ] );
    }

    /**
     * POST /usage/scan/advance
     *
     * Scans one batch of posts (200 per call) and reports progress. The admin
     * UI loops this until `done` is true, rebuilding the usage index without a
     * long synchronous request or WP-Cron.
     *
     * @param  WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function advanceScan( WP_REST_Request $request ): WP_REST_Response {
        $offset = (int) $request->get_param( 'offset' );
        $result = $this->usageTracker->scanRange( $offset, 200 );

        return $this->success( $result );
    }

    // -------------------------------------------------------------------------
    // Permission callbacks
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

    /**
     * @param  array<string, mixed> $data
     * @param  int                  $status
     * @return WP_REST_Response
     */
    private function success( array $data, int $status = 200 ): WP_REST_Response {
        return new WP_REST_Response( [ 'success' => true, 'data' => $data ], $status );
    }

    /**
     * @param  string $code
     * @param  string $message
     * @param  int    $status
     * @return WP_REST_Response
     */
    private function error( string $code, string $message, int $status ): WP_REST_Response {
        return new WP_REST_Response( [ 'success' => false, 'code' => $code, 'message' => $message ], $status );
    }
}
