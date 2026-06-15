<?php

declare(strict_types=1);

namespace MediaPilotAI\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Media\DuplicateDetector;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST routes for the Duplicate File Detection feature (S37).
 *
 * Namespace: mediapilot/v1
 *
 * Routes:
 *   GET  /files/duplicates          Returns stored duplicate groups (or empty).
 *   POST /files/duplicates/scan     Triggers a background scan via WP Cron.
 *   GET  /files/duplicates/status   Returns current scan status + progress.
 *   POST /files/duplicates/resolve  Keep primary, delete others, merge folders.
 *
 * @package MediaPilotAI\API
 * @since   1.0.0
 */
class DuplicateRestController {

    private const NAMESPACE = 'mediapilot/v1';

    public function __construct(
        private readonly DuplicateDetector $detector,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function register(): void {
        // GET /files/duplicates
        register_rest_route(
            self::NAMESPACE,
            '/files/duplicates',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'getDuplicates' ],
                'permission_callback' => [ $this, 'permUploadFiles' ],
            ]
        );

        // POST /files/duplicates/scan
        register_rest_route(
            self::NAMESPACE,
            '/files/duplicates/scan',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'startScan' ],
                'permission_callback' => [ $this, 'permManageFolders' ],
            ]
        );

        // GET /files/duplicates/status
        register_rest_route(
            self::NAMESPACE,
            '/files/duplicates/status',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'getScanStatus' ],
                'permission_callback' => [ $this, 'permUploadFiles' ],
            ]
        );

        // POST /files/duplicates/cancel
        register_rest_route(
            self::NAMESPACE,
            '/files/duplicates/cancel',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'cancelScan' ],
                'permission_callback' => [ $this, 'permManageFolders' ],
            ]
        );

        // POST /files/duplicates/resolve
        register_rest_route(
            self::NAMESPACE,
            '/files/duplicates/resolve',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'resolveGroup' ],
                'permission_callback' => [ $this, 'permDeleteAttachments' ],
                'args'                => [
                    'primary_id' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ],
                    'delete_ids' => [
                        'type'     => 'array',
                        'required' => true,
                        'items'    => [ 'type' => 'integer' ],
                    ],
                ],
            ]
        );

        // POST /files/duplicates/resolve-all
        register_rest_route(
            self::NAMESPACE,
            '/files/duplicates/resolve-all',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'resolveAll' ],
                'permission_callback' => [ $this, 'permDeleteAttachments' ],
            ]
        );

        // GET /files/similar/{id}  — find visually similar images (S50)
        register_rest_route(
            self::NAMESPACE,
            '/files/similar/(?P<id>\d+)',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'getSimilar' ],
                'permission_callback' => [ $this, 'permUploadFiles' ],
                'args'                => [
                    'id' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Callbacks
    // -------------------------------------------------------------------------

    /**
     * GET /files/duplicates
     *
     * Returns the stored duplicate groups from the last completed scan.
     * Returns an empty array if no scan has been run yet.
     */
    public function getDuplicates( WP_REST_Request $request ): WP_REST_Response {
        $status = $this->detector->getStatus();

        return $this->success( [
            'status' => $status['status'],
            'groups' => $status['groups'],
        ] );
    }

    /**
     * POST /files/duplicates/scan
     *
     * Triggers a background duplicate scan.
     * Returns 409 Conflict if a scan is already in progress.
     */
    public function startScan( WP_REST_Request $request ): WP_REST_Response {
        $scheduled = $this->detector->startBackgroundScan();

        if ( ! $scheduled ) {
            return $this->error( 'scan_running', 'A scan is already in progress.', 409 );
        }

        return $this->success( [ 'message' => 'Scan started.' ] );
    }

    /**
     * GET /files/duplicates/status
     *
     * Returns scan status, progress counters, and any available results.
     */
    public function getScanStatus( WP_REST_Request $request ): WP_REST_Response {
        return $this->success( $this->detector->getStatus() );
    }

    /**
     * POST /files/duplicates/cancel
     *
     * Cancels an in-progress scan: unschedules pending cron chunks and clears
     * scan state. Idempotent — returns success even if nothing was running.
     */
    public function cancelScan( WP_REST_Request $request ): WP_REST_Response {
        $wasRunning = $this->detector->cancelScan();

        return $this->success( [
            'cancelled' => $wasRunning,
            'message'   => $wasRunning ? 'Scan cancelled.' : 'No scan was running.',
        ] );
    }

    /**
     * POST /files/duplicates/resolve
     *
     * Keep the primary attachment, delete the rest, and merge folder
     * assignments from deleted files to the primary.
     */
    public function resolveGroup( WP_REST_Request $request ): WP_REST_Response {
        $primaryId = (int) $request->get_param( 'primary_id' );
        $deleteIds = array_map( 'absint', (array) $request->get_param( 'delete_ids' ) );

        // Defence-in-depth: only delete attachments this user may delete.
        $deleteIds = array_values( array_filter( $deleteIds, static function ( int $id ): bool {
            return $id > 0 && current_user_can( 'delete_post', $id );
        } ) );

        if ( $primaryId <= 0 ) {
            return $this->error( 'invalid_primary', 'Invalid primary_id.', 400 );
        }

        $result = $this->detector->resolveGroup( $primaryId, $deleteIds );

        return $this->success( $result );
    }

    /**
     * POST /files/duplicates/resolve-all
     *
     * Resolve every stored duplicate group at once. The first file in each
     * group is kept as primary; the rest are permanently deleted.
     */
    public function resolveAll( WP_REST_Request $request ): WP_REST_Response {
        return $this->success( $this->detector->resolveAllGroups() );
    }

    /**
     * GET /files/similar/{id}
     *
     * Returns attachments visually similar to the requested image using the
     * pre-computed pHash (dHash) index.  Falls back to on-the-fly computation
     * when the attachment has not yet been indexed.
     */
    public function getSimilar( WP_REST_Request $request ): WP_REST_Response {
        $id = (int) $request->get_param( 'id' );

        if ( 'attachment' !== get_post_type( $id ) ) {
            return $this->error( 'not_found', 'Attachment not found.', 404 );
        }

        $similarIds = $this->detector->findSimilarToId( $id );

        $files = [];
        foreach ( $similarIds as $simId ) {
            $post = get_post( $simId );
            if ( ! $post || 'attachment' !== $post->post_type ) {
                continue;
            }

            $filePath     = get_attached_file( $simId );
            $thumbnailUrl = wp_get_attachment_image_url( $simId, 'thumbnail' );
            if ( false === $thumbnailUrl || '' === $thumbnailUrl ) {
                $thumbnailUrl = (string) wp_get_attachment_url( $simId );
            }

            $files[] = [
                'id'            => $simId,
                'filename'      => is_string( $filePath ) ? basename( $filePath ) : '',
                'title'         => (string) $post->post_title,
                'mime_type'     => (string) $post->post_mime_type,
                'thumbnail_url' => $thumbnailUrl,
                'url'           => (string) wp_get_attachment_url( $simId ),
            ];
        }

        return $this->success( [
            'attachment_id' => $id,
            'similar'       => $files,
        ] );
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

    /**
     * Resolving duplicates permanently deletes attachments, so the caller must
     * hold a delete capability — not merely upload_files.
     */
    public function permDeleteAttachments(): bool {
        return current_user_can( 'upload_files' ) && current_user_can( 'delete_posts' );
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
