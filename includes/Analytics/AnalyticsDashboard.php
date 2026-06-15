<?php

declare(strict_types=1);

namespace MediaPilotAI\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Drives the analytics subsystem for MediaPilot AI.
 *
 * Responsibilities:
 *  - Registers the "Media Analytics" admin page under the Media menu.
 *  - Tracks view / insert / download events via an option-based queue that is
 *    flushed into wp_mdpai_analytics in a single INSERT on shutdown (or when the
 *    queue reaches 50 items).
 *  - Provides query methods consumed by AnalyticsRestController to power the
 *    analytics dashboard widgets.
 *  - Injects a lightweight inline tracking script on media/post/page screens.
 *
 * @package MediaPilotAI\Analytics
 * @since   1.0.0
 */
class AnalyticsDashboard {

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    /** @var int Flush the queue to the DB when it reaches this many items. */
    private const QUEUE_FLUSH_THRESHOLD = 50;

    /** @var string Option key used as the in-memory event queue. */
    private const QUEUE_OPTION = 'mdpai_analytics_queue';

    /** @var list<string> Allowed event types. */
    private const VALID_EVENT_TYPES = [ 'insert', 'download' ];

    // -------------------------------------------------------------------------
    // WordPress Hook Registration
    // -------------------------------------------------------------------------

    /**
     * Attaches all WordPress actions required by this class.
     *
     * Call once during plugin bootstrap (e.g. from the service container).
     *
     * @return void
     */
    public function register(): void {
        add_action( 'admin_menu', [ $this, 'registerAdminPage' ] );
        add_action( 'shutdown',   [ $this, 'flushQueue' ] );
        // Attach the tracking snippet to its enqueued handle on
        // admin_enqueue_scripts (NOT admin_footer): a footer-time
        // wp_add_inline_script() is dropped on sites that flush footer scripts
        // early, which left the insert/download tracking script off the page.
        add_action( 'admin_enqueue_scripts', [ $this, 'injectTrackingScript' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueueAssets' ] );
    }

    /**
     * Enqueues Chart.js (bundled locally) on the analytics page only.
     *
     * Hooked to `admin_enqueue_scripts`.
     *
     * @param string $hookSuffix Current admin page hook suffix.
     * @return void
     */
    public function enqueueAssets( string $hookSuffix ): void {
        if ( 'media_page_mediapilot-analytics' !== $hookSuffix ) {
            return;
        }

        wp_enqueue_script(
            'mediapilot-chartjs',
            MDPAI_URL . 'admin/assets/vendor/chart.umd.js',
            [],
            '4.5.0',
            true
        );

        // Analytics page styles, attached to an inline-only style handle so the
        // view does not emit a raw <style> tag.
        wp_register_style( 'mediapilot-analytics', false );
        wp_enqueue_style( 'mediapilot-analytics' );
        wp_add_inline_style(
            'mediapilot-analytics',
            '.mediapilot-analytics-wrap .mediapilot-stat-card:hover { border-color: #2271b1; }'
            . '.mediapilot-analytics-wrap h3 { font-size: 14px; color: #1d2327; }'
            . '.mediapilot-analytics-wrap .mediapilot-thumb { width: 40px; height: 40px; object-fit: cover; border-radius: 3px; background: #f0f0f1; }'
        );
    }

    // -------------------------------------------------------------------------
    // Admin Page
    // -------------------------------------------------------------------------

    /**
     * Registers the "Analytics" sub-page under the built-in Media menu.
     *
     * Hooked to `admin_menu`.
     *
     * @return void
     */
    public function registerAdminPage(): void {
        add_media_page(
            __( 'Media Analytics', 'mediapilot-ai'),
            __( 'Analytics', 'mediapilot-ai'),
            'manage_options',
            'mediapilot-analytics',
            [ $this, 'renderPage' ]
        );
    }

    /**
     * Renders the analytics admin page.
     *
     * Aborts with wp_die() when the current user lacks `manage_options`.
     *
     * @return void
     */
    public function renderPage(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'mediapilot-ai') );
        }

        require_once MDPAI_PATH . 'admin/views/analytics-page.php';
    }

    // -------------------------------------------------------------------------
    // Event Tracking
    // -------------------------------------------------------------------------

    /**
     * Queues a single analytics event for the given attachment.
     *
     * The event is appended to the option-based queue.  When the queue length
     * reaches {@see QUEUE_FLUSH_THRESHOLD} the queue is flushed to the DB
     * immediately; otherwise it is flushed on `shutdown`.
     *
     * @param int    $attachmentId WordPress attachment post ID (must be > 0).
     * @param string $eventType    One of 'insert', 'download'.
     * @return void
     */
    public function track( int $attachmentId, string $eventType ): void {
        if ( $attachmentId <= 0 ) {
            return;
        }

        if ( ! in_array( $eventType, self::VALID_EVENT_TYPES, true ) ) {
            return;
        }

        /**
         * Fires when an analytics event is about to be queued.
         *
         * Return false from any hooked callback to suppress the event entirely.
         *
         * @param int    $attachmentId The attachment being tracked.
         * @param string $eventType    One of 'insert', 'download'.
         */
        $allow = apply_filters( 'mdpai_analytics_event', true, $attachmentId, $eventType );

        if ( ! $allow ) {
            return;
        }

        /** @var list<array{int,string,int,string}> $queue */
        $queue   = (array) get_option( self::QUEUE_OPTION, [] );
        $queue[] = [
            $attachmentId,
            $eventType,
            get_current_user_id(),
            current_time( 'mysql' ),
        ];

        update_option( self::QUEUE_OPTION, $queue, false );

        if ( count( $queue ) >= self::QUEUE_FLUSH_THRESHOLD ) {
            $this->flushQueue();
        }
    }

    /**
     * Flushes all queued events to the database in a single multi-row INSERT.
     *
     * Safe to call multiple times; a second call within the same request is a
     * no-op because the option is deleted after the first successful flush.
     *
     * Hooked to `shutdown`; also called inline from {@see track()} when the
     * queue reaches the threshold.
     *
     * @return void
     */
    public function flushQueue(): void {
        global $wpdb;

        /** @var list<array{int,string,int,string}> $queue */
        $queue = (array) get_option( self::QUEUE_OPTION, [] );

        if ( empty( $queue ) ) {
            return;
        }

        // Delete the option immediately so a concurrent request does not
        // double-insert the same rows.
        delete_option( self::QUEUE_OPTION );

        // Build the VALUES placeholders and flatten the values array.
        $placeholders = [];
        $values       = [];

        foreach ( $queue as $row ) {
            [ $attachmentId, $eventType, $userId, $createdAt ] = $row;
            $placeholders[] = '(%d,%s,%d,%s)';
            $values[]       = (int) $attachmentId;
            $values[]       = (string) $eventType;
            $values[]       = (int) $userId;
            $values[]       = (string) $createdAt;
        }

        $sql = 'INSERT INTO ' . $wpdb->prefix . 'mdpai_analytics'
             . ' (attachment_id, event_type, user_id, created_at) VALUES '
             . implode( ', ', $placeholders );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare( $sql, ...$values ) // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared
        );
    }

    // -------------------------------------------------------------------------
    // Dashboard Query Methods
    // -------------------------------------------------------------------------

    /**
     * Returns storage consumption grouped by MediaPilot folder (taxonomy term).
     *
     * @param int $limit Maximum rows to return (default 15).
     * @return list<array{folder_id:int,folder_name:string,bytes:int,file_count:int}>
     */
    public function getStorageByFolder( int $limit = 15 ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT
                    tt.term_taxonomy_id AS folder_id,
                    t.name              AS folder_name,
                    SUM( CAST( pm.meta_value AS UNSIGNED ) ) AS bytes,
                    COUNT( p.ID )       AS file_count
                 FROM {$wpdb->posts} AS p
                 INNER JOIN {$wpdb->term_relationships} AS tr
                     ON tr.object_id = p.ID
                 INNER JOIN {$wpdb->term_taxonomy}  AS tt
                     ON tt.term_taxonomy_id = tr.term_taxonomy_id
                     AND tt.taxonomy = 'mdpai_folder'
                 INNER JOIN {$wpdb->terms} AS t
                     ON t.term_id = tt.term_id
                 INNER JOIN {$wpdb->postmeta} AS pm
                     ON pm.post_id = p.ID
                     AND pm.meta_key = '_mdpai_filesize'
                 WHERE p.post_type   = 'attachment'
                   AND p.post_status = 'inherit'
                 GROUP BY tt.term_taxonomy_id, t.name
                 ORDER BY bytes DESC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [];
        }

        return array_map(
            static fn( array $row ): array => [
                'folder_id'   => (int) $row['folder_id'],
                'folder_name' => (string) $row['folder_name'],
                'bytes'       => (int) $row['bytes'],
                'file_count'  => (int) $row['file_count'],
            ],
            $rows
        );
    }

    /**
     * Returns storage consumption grouped by top-level MIME type
     * (e.g. "image", "video", "application").
     *
     * @return list<array{type:string,bytes:int,file_count:int}>
     */
    public function getStorageByType(): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT
                SUBSTRING_INDEX( p.post_mime_type, '/', 1 ) AS type,
                SUM( CAST( pm.meta_value AS UNSIGNED ) )    AS bytes,
                COUNT( p.ID )                               AS file_count
             FROM {$wpdb->posts} AS p
             INNER JOIN {$wpdb->postmeta} AS pm
                 ON pm.post_id = p.ID
                 AND pm.meta_key = '_mdpai_filesize'
             WHERE p.post_type   = 'attachment'
               AND p.post_status = 'inherit'
             GROUP BY type
             ORDER BY bytes DESC",
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [];
        }

        return array_map(
            static fn( array $row ): array => [
                'type'       => (string) $row['type'],
                'bytes'      => (int) $row['bytes'],
                'file_count' => (int) $row['file_count'],
            ],
            $rows
        );
    }

    /**
     * Returns daily upload counts for the given date range.
     *
     * @param string $since ISO-8601 / MySQL datetime lower-bound (inclusive).
     * @param string $until ISO-8601 / MySQL datetime upper-bound (inclusive, optional).
     * @return list<array{date:string,count:int}>
     */
    public function getUploadActivity( string $since, string $until = '' ): array {
        global $wpdb;

        $whereExtra = '';
        $queryArgs  = [ $since ];

        if ( '' !== $until ) {
            $whereExtra  = 'AND post_date <= %s';
            $queryArgs[] = $until;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                "SELECT
                    DATE( post_date ) AS date,
                    COUNT(*) AS count
                 FROM {$wpdb->posts}
                 WHERE post_type   = 'attachment'
                   AND post_status = 'inherit'
                   AND post_date  >= %s
                   {$whereExtra}
                 GROUP BY DATE( post_date )
                 ORDER BY date ASC",
                ...$queryArgs
            ), // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [];
        }

        return array_map(
            static fn( array $row ): array => [
                'date'  => (string) $row['date'],
                'count' => (int) $row['count'],
            ],
            $rows
        );
    }

    /**
     * Backfills the `_mdpai_filesize` post meta for existing attachments that do
     * not yet have it.
     *
     * The meta is normally written on upload, so media added before the plugin
     * was active has no size recorded — which leaves the storage stats empty.
     * This processes a bounded batch per call (so it can be looped from the
     * admin without timing out) and returns how many attachments still need it.
     *
     * Attachments whose file is missing on disk are recorded as 0 bytes so they
     * are not re-queried forever.
     *
     * @param int $limit Maximum attachments to process this call.
     * @return array{processed:int,remaining:int}
     */
    public function backfillFilesizes( int $limit = 100 ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT p.ID
                 FROM {$wpdb->posts} AS p
                 LEFT JOIN {$wpdb->postmeta} AS pm
                     ON pm.post_id = p.ID
                     AND pm.meta_key = '_mdpai_filesize'
                 WHERE p.post_type   = 'attachment'
                   AND p.post_status = 'inherit'
                   AND pm.post_id IS NULL
                 LIMIT %d",
                $limit
            )
        );

        foreach ( $ids as $id ) {
            $id       = (int) $id;
            $filePath = get_attached_file( $id );
            $bytes    = ( is_string( $filePath ) && file_exists( $filePath ) )
                ? (int) filesize( $filePath )
                : 0;

            update_post_meta( $id, '_mdpai_filesize', $bytes );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $remaining = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT COUNT( p.ID )
             FROM {$wpdb->posts} AS p
             LEFT JOIN {$wpdb->postmeta} AS pm
                 ON pm.post_id = p.ID
                 AND pm.meta_key = '_mdpai_filesize'
             WHERE p.post_type   = 'attachment'
               AND p.post_status = 'inherit'
               AND pm.post_id IS NULL"
        );

        return [
            'processed' => count( $ids ),
            'remaining' => $remaining,
        ];
    }

    /**
     * Returns aggregate summary statistics for the media library.
     *
     * @param string $since Optional ISO-8601 / MySQL datetime lower-bound for
     *                      event counts. Empty string means all time.
     * @return array{total_files:int,total_bytes:int,events:array{insert:int,download:int}}
     */
    public function getSummaryStats( string $since = '' ): array {
        global $wpdb;

        // --- Total files and bytes -----------------------------------------
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $totals = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT
                COUNT( p.ID )                              AS total_files,
                SUM( CAST( pm.meta_value AS UNSIGNED ) )  AS total_bytes
             FROM {$wpdb->posts} AS p
             LEFT JOIN {$wpdb->postmeta} AS pm
                 ON pm.post_id = p.ID
                 AND pm.meta_key = '_mdpai_filesize'
             WHERE p.post_type   = 'attachment'
               AND p.post_status = 'inherit'",
            ARRAY_A
        );

        // --- Event counts (optionally filtered by $since) -------------------
        $eventWhereClause = '';
        $eventArgs        = [];

        if ( '' !== $since ) {
            $eventWhereClause = 'WHERE created_at >= %s';
            $eventArgs[]      = $since;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $eventRows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            '' !== $since  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                ? $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    "SELECT event_type, COUNT(*) AS cnt
                     FROM {$wpdb->prefix}mdpai_analytics
                     {$eventWhereClause}
                     GROUP BY event_type",  // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                    ...$eventArgs
                ) // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                : "SELECT event_type, COUNT(*) AS cnt
                   FROM {$wpdb->prefix}mdpai_analytics
                   GROUP BY event_type",
            ARRAY_A
        );

        $events = [ 'insert' => 0, 'download' => 0 ];

        if ( ! empty( $eventRows ) ) {
            foreach ( $eventRows as $eventRow ) {
                $type = (string) $eventRow['event_type'];
                if ( array_key_exists( $type, $events ) ) {
                    $events[ $type ] = (int) $eventRow['cnt'];
                }
            }
        }

        return [
            'total_files'  => isset( $totals['total_files'] ) ? (int) $totals['total_files'] : 0,
            'total_bytes'  => isset( $totals['total_bytes'] ) ? (int) $totals['total_bytes'] : 0,
            'events'       => $events,
        ];
    }

    /**
     * Exports analytics events as a UTF-8 CSV string.
     *
     * Columns: id, attachment_id, filename, event_type, user_id, created_at.
     * Up to 10,000 rows are included; apply $since to narrow the window.
     *
     * @param string $since Optional ISO-8601 / MySQL datetime lower-bound.
     * @return string CSV-formatted string (UTF-8, with BOM stripped).
     */
    public function exportCsv( string $since = '' ): string {
        global $wpdb;

        $whereClause = '';
        $queryArgs   = [];

        if ( '' !== $since ) {
            $whereClause = 'WHERE created_at >= %s';
            $queryArgs[] = $since;
        }

        $queryArgs[] = 10000;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            '' !== $since  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                ? $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    "SELECT id, attachment_id, event_type, user_id, created_at
                     FROM {$wpdb->prefix}mdpai_analytics
                     {$whereClause}
                     ORDER BY id ASC
                     LIMIT %d",
                    ...$queryArgs
                ) // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                : $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    "SELECT id, attachment_id, event_type, user_id, created_at
                     FROM {$wpdb->prefix}mdpai_analytics
                     ORDER BY id ASC
                     LIMIT %d",
                    10000
                ),
            ARRAY_A
        );

        $handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

        // Write CSV header row.
        fputcsv( $handle, [ 'id', 'attachment_id', 'filename', 'event_type', 'user_id', 'created_at' ] );

        if ( ! empty( $rows ) ) {
            foreach ( $rows as $row ) {
                $attachmentId = (int) $row['attachment_id'];
                $filePath     = get_attached_file( $attachmentId );
                $filename     = is_string( $filePath ) ? basename( $filePath ) : '';

                fputcsv( $handle, [
                    (int) $row['id'],
                    $attachmentId,
                    $filename,
                    (string) $row['event_type'],
                    (int) $row['user_id'],
                    (string) $row['created_at'],
                ] );
            }
        }

        rewind( $handle );
        $csv = stream_get_contents( $handle );
        fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        return is_string( $csv ) ? $csv : '';
    }

    // -------------------------------------------------------------------------
    // Tracking Script
    // -------------------------------------------------------------------------

    /**
     * Injects an inline JavaScript tracking snippet on media / post / page
     * admin screens.
     *
     * The script patches:
     *  - `wp.media.editor.insert` to fire an 'insert' event for each selected
     *    attachment at insert time.
     *
     * Both patches call `trackEvent()`, which sends a fire-and-forget fetch
     * POST to the `mediapilot/v1/analytics/track` REST endpoint with keepalive:true.
     *
     * Hooked to `admin_footer`.
     *
     * @return void
     */
    public function injectTrackingScript(): void {
        $screen = get_current_screen();

        if ( null === $screen ) {
            return;
        }

        $allowedBases = [ 'upload', 'post', 'page' ];

        if ( ! in_array( $screen->base, $allowedBases, true ) ) {
            return;
        }

        $nonce   = wp_json_encode( wp_create_nonce( 'wp_rest' ) );
        $restUrl = wp_json_encode( rest_url( 'mediapilot/v1/analytics/track' ) );

        // Inline-only registered handle so the snippet is enqueued, not a raw
        // <script> tag. The script self-guards for wp.media availability.
        wp_register_script( 'mediapilot-analytics-track', false, [], MDPAI_VERSION, true );
        wp_enqueue_script( 'mediapilot-analytics-track' );
        ob_start();
        ?>
        ( function() {
            'use strict';

            var nonce   = <?php echo $nonce; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
            var restUrl = <?php echo $restUrl; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;

            /**
             * Sends an analytics event to the MediaPilot REST endpoint.
             *
             * @param {number} attachmentId
             * @param {string} eventType  'insert' | 'download'
             */
            function trackEvent( attachmentId, eventType ) {
                if ( ! attachmentId ) { return; }
                fetch( restUrl, {
                    method:    'POST',
                    keepalive: true,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce':   nonce
                    },
                    body: JSON.stringify( {
                        attachment_id: attachmentId,
                        event_type:    eventType
                    } )
                } ).catch( function() {} );
            }

            /**
             * Resolve the attachment id currently shown in the media modal.
             * Works for the grid "edit attachment" view (frame.model) and falls
             * back to the nearest [data-id] element in the DOM.
             *
             * @param {Element} el  The clicked element.
             * @return {number}
             */
            function resolveModalAttachmentId( el ) {
                var id = 0;
                try {
                    if ( window.wp && wp.media && wp.media.frame && wp.media.frame.model && wp.media.frame.model.get ) {
                        id = parseInt( wp.media.frame.model.get( 'id' ), 10 ) || 0;
                    }
                } catch ( e ) {}
                if ( ! id && el && el.closest ) {
                    var holder = el.closest( '[data-id]' );
                    if ( holder ) { id = parseInt( holder.getAttribute( 'data-id' ), 10 ) || 0; }
                }
                return id;
            }

            // Track downloads: the attachment details modal renders a
            // "Download file" link carrying a `download` attribute. Delegated so
            // it works no matter when the modal is opened. Registered immediately
            // (no wp.media dependency for the listener itself).
            document.addEventListener( 'click', function( e ) {
                if ( ! e.target || ! e.target.closest ) { return; }
                var link = e.target.closest( 'a[download]' );
                if ( ! link ) { return; }
                if ( ! link.closest( '.media-modal, .attachment-details, .attachment-info, .media-frame' ) ) { return; }
                var id = resolveModalAttachmentId( link );
                if ( id ) { trackEvent( id, 'download' ); }
            }, true );

            document.addEventListener( 'DOMContentLoaded', function() {
                // Wait for wp.media to be available before patching inserts.
                if ( typeof wp === 'undefined' || ! wp.media ) { return; }

                // Patch: media editor insert -> 'insert' event per attachment
                // (classic editor "Add Media" -> Insert into post/page flow).
                if ( wp.media.editor && typeof wp.media.editor.insert === 'function' ) {
                    var _insert = wp.media.editor.insert;
                    wp.media.editor.insert = function( html ) {
                        try {
                            var state      = wp.media.frame && wp.media.frame.state && wp.media.frame.state();
                            var selection  = state && state.get( 'selection' );
                            if ( selection ) {
                                selection.each( function( model ) {
                                    var id = model && model.get( 'id' );
                                    if ( id ) { trackEvent( id, 'insert' ); }
                                } );
                            }
                        } catch ( e ) {}
                        return _insert.apply( this, arguments );
                    };
                }
            } );
        } )();
        <?php
        wp_add_inline_script( 'mediapilot-analytics-track', (string) ob_get_clean() );
    }
}
