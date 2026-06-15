<?php

declare(strict_types=1);

namespace MediaPilotAI\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Analytics\AnalyticsDashboard;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Registers and handles REST API routes for the MediaPilot analytics subsystem.
 *
 * Routes registered under namespace `mediapilot/v1`:
 *
 *  POST /analytics/track   — Record a view / insert / download event.
 *  GET  /analytics/stats   — Retrieve aggregated analytics data for the
 *                            dashboard (requires manage_options).
 *  GET  /analytics/export  — Stream a CSV export of raw events
 *                            (requires manage_options).
 *
 * Authentication: WP REST API nonce (X-WP-Nonce header).
 *
 * @package MediaPilotAI\API
 * @since   1.0.0
 */
class AnalyticsRestController {

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    /** @var string REST namespace shared by all MediaPilot routes. */
    private const NAMESPACE = 'mediapilot/v1';

    /** @var list<string> Allowed range shorthand values. */
    private const VALID_RANGES = [ '7d', '30d', '90d', 'all' ];

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param AnalyticsDashboard $analytics The analytics service instance.
     */
    public function __construct(
        private readonly AnalyticsDashboard $analytics,
    ) {}

    // -------------------------------------------------------------------------
    // Route Registration
    // -------------------------------------------------------------------------

    /**
     * Registers all analytics REST routes.
     *
     * Called on the `rest_api_init` action from the plugin bootstrap.
     *
     * @return void
     */
    public function register(): void {
        // POST /analytics/track
        register_rest_route(
            self::NAMESPACE,
            '/analytics/track',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'track' ],
                'permission_callback' => [ $this, 'permUploadFiles' ],
                'args'                => [
                    'attachment_id' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                    'event_type'    => [
                        'type'              => 'string',
                        'required'          => true,
                        'enum'              => [ 'insert', 'download' ],
                        'sanitize_callback' => 'sanitize_key',
                    ],
                ],
            ]
        );

        // GET /analytics/stats
        register_rest_route(
            self::NAMESPACE,
            '/analytics/stats',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'stats' ],
                'permission_callback' => [ $this, 'permManageOptions' ],
                'args'                => [
                    'range' => [
                        'type'              => 'string',
                        'default'           => '30d',
                        'enum'              => self::VALID_RANGES,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'from'  => [
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'to'    => [
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        // POST /analytics/backfill-sizes
        register_rest_route(
            self::NAMESPACE,
            '/analytics/backfill-sizes',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'backfillSizes' ],
                'permission_callback' => [ $this, 'permManageOptions' ],
            ]
        );

        // GET /analytics/export
        register_rest_route(
            self::NAMESPACE,
            '/analytics/export',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'export' ],
                'permission_callback' => [ $this, 'permManageOptions' ],
                'args'                => [
                    'range' => [
                        'type'              => 'string',
                        'default'           => '30d',
                        'enum'              => self::VALID_RANGES,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Route Callbacks
    // -------------------------------------------------------------------------

    /**
     * POST /analytics/track
     *
     * Queues a single analytics event.
     *
     * @param WP_REST_Request $request Validated REST request.
     * @return WP_REST_Response
     */
    public function track( WP_REST_Request $request ): WP_REST_Response {
        $attachmentId = (int) $request->get_param( 'attachment_id' );
        $eventType    = (string) $request->get_param( 'event_type' );

        $this->analytics->track( $attachmentId, $eventType );

        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    /**
     * GET /analytics/stats
     *
     * Returns aggregated analytics data used by the dashboard.
     *
     * Response shape:
     * ```json
     * {
     *   "success": true,
     *   "data": {
     *     "summary":         { ... },
     *     "storage_folder":  [ ... ],
     *     "storage_type":    [ ... ],
     *     "upload_activity": [ ... ]
     *   }
     * }
     * ```
     *
     * @param WP_REST_Request $request Validated REST request.
     * @return WP_REST_Response
     */
    public function stats( WP_REST_Request $request ): WP_REST_Response {
        $range = (string) $request->get_param( 'range' );
        $from  = (string) $request->get_param( 'from' );
        $to    = (string) $request->get_param( 'to' );

        $since = $this->resolveSince( $range, $from );
        $until = ( '' !== $to ) ? $to : '';

        $data = [
            'summary'         => $this->analytics->getSummaryStats( $since ),
            'storage_folder'  => $this->analytics->getStorageByFolder( 15 ),
            'storage_type'    => $this->analytics->getStorageByType(),
            'upload_activity' => $this->analytics->getUploadActivity( $since, $until ),
        ];

        return new WP_REST_Response(
            [
                'success' => true,
                'data'    => $data,
            ],
            200
        );
    }

    /**
     * POST /analytics/backfill-sizes
     *
     * Populates the `_mdpai_filesize` meta for a batch of existing attachments
     * that lack it. The dashboard calls this in a loop (until remaining = 0) so
     * storage stats become accurate for media uploaded before the plugin.
     *
     * @param WP_REST_Request $request Validated REST request.
     * @return WP_REST_Response
     */
    public function backfillSizes( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response(
            [
                'success' => true,
                'data'    => $this->analytics->backfillFilesizes( 100 ),
            ],
            200
        );
    }

    /**
     * GET /analytics/export
     *
     * Streams a CSV file of raw analytics events to the browser.
     *
     * This method echoes output directly and calls exit; it never returns a
     * WP_REST_Response under normal circumstances.  The return type hint is
     * declared void because the WP REST dispatch mechanism accepts void
     * callbacks when the response is streamed manually.
     *
     * @param WP_REST_Request $request Validated REST request.
     * @return void
     */
    public function export( WP_REST_Request $request ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 403 );
        }

        $range = (string) $request->get_param( 'range' );
        $since = $this->resolveSince( $range, '' );
        $csv   = $this->analytics->exportCsv( $since );

        $filename = 'mediapilot-analytics-' . gmdate( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    // -------------------------------------------------------------------------
    // Permission Callbacks
    // -------------------------------------------------------------------------

    /**
     * Allows any user with the `upload_files` capability.
     *
     * Used for the track endpoint (any media uploader can fire events).
     *
     * @return bool
     */
    public function permUploadFiles(): bool {
        return current_user_can( 'upload_files' );
    }

    /**
     * Restricts access to administrators (`manage_options` capability).
     *
     * Used for stats and export endpoints.
     *
     * @return bool
     */
    public function permManageOptions(): bool {
        return current_user_can( 'manage_options' );
    }

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    /**
     * Resolves a `$since` datetime string from either a raw `$from` value or a
     * named `$range` shorthand.
     *
     * Resolution order:
     *  1. If `$from` is not empty it is returned as-is (caller-supplied date).
     *  2. If `$range` maps to a non-zero number of days, a UTC datetime
     *     relative to now is returned.
     *  3. 'all' (or any unrecognised value) returns '' meaning no lower bound.
     *
     * @param string $range One of '7d', '30d', '90d', 'all'.
     * @param string $from  Optional explicit lower-bound datetime string.
     * @return string MySQL-formatted datetime string or '' for all time.
     */
    private function resolveSince( string $range, string $from ): string {
        if ( '' !== $from ) {
            return $from;
        }

        $days = match ( $range ) {
            '7d'  => 7,
            '30d' => 30,
            '90d' => 90,
            default => 0,  // 'all' and any unknown value
        };

        if ( $days > 0 ) {
            return gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
        }

        return '';
    }
}
