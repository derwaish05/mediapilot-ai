<?php

declare(strict_types=1);

namespace MediaPilotAI\Media;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Tracks where every attachment is used across the WordPress site.
 *
 * Persistence : `wp_mdpai_usage`  (attachment_id, object_id, object_type, context)
 * Hooks registered:
 *   save_post        → scan post content + meta + featured image
 *   delete_post      → remove all usage rows for that post
 *   updated_option   → re-scan widgets when sidebar settings change
 *
 * Detected contexts:
 *   content          – img/a tags or Gutenberg block attrs in post_content
 *   block            – named Gutenberg block (wp:image, wp:cover, wp:gallery, …)
 *   featured_image   – post thumbnail
 *   post_meta        – numeric attachment ID found in custom field value
 *   acf              – ACF image/gallery field
 *   gallery_shortcode– [gallery ids="…"] shortcode
 *   widget           – sidebar widget setting
 *   elementor        – Elementor widget / section setting (_elementor_data)
 *   beaver_builder   – Beaver Builder module setting (_fl_builder_data)
 *   bricks           – Bricks Builder element setting (_bricks_page_content_2)
 *   wpbakery         – WPBakery shortcode in post_content
 *   divi             – Divi Builder shortcode in post_content
 *
 * @package MediaPilotAI\Media
 * @since   1.0.0
 */
class UsageTracker {

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    private const TABLE = 'mdpai_usage';

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public function register(): void {
        // Post save — primary trigger for content scanning.
        add_action( 'save_post', [ $this, 'onSavePost' ], 20 );

        // Post delete — clean up stale usage records.
        add_action( 'delete_post', [ $this, 'onDeletePost' ], 10 );

        // Widget update — re-scan all widget settings.
        add_action( 'updated_option', [ $this, 'onWidgetOptionUpdate' ], 10, 3 );

        // Featured image set via REST API (Gutenberg) — _thumbnail_id is written
        // AFTER save_post fires, so we need a separate hook to catch it.
        add_action( 'updated_post_meta', [ $this, 'onThumbnailMetaChanged' ], 20, 4 );
        add_action( 'added_post_meta',   [ $this, 'onThumbnailMetaChanged' ], 20, 4 );

        // Featured image removed via REST API — _thumbnail_id is deleted
        // AFTER save_post fires, so we need a separate hook here too.
        add_action( 'deleted_post_meta', [ $this, 'onThumbnailMetaDeleted' ], 20, 4 );
    }

    // -------------------------------------------------------------------------
    // Hook Handlers
    // -------------------------------------------------------------------------

    /**
     * Re-scan a post whenever it is saved.
     *
     * Skips attachments and auto-drafts to avoid false positives.
     */
    public function onSavePost( int $postId ): void {
        // Avoid recursion or scanning revisions/auto-saves.
        if ( wp_is_post_revision( $postId ) || wp_is_post_autosave( $postId ) ) {
            return;
        }

        $post = get_post( $postId );

        if ( ! $post || in_array( $post->post_type, [ 'attachment', 'auto-draft' ], true ) ) {
            return;
        }

        $this->scanPost( $postId );
    }

    /**
     * Remove all usage rows associated with a deleted post.
     */
    public function onDeletePost( int $postId ): void {
        $this->clearPostUsage( $postId );
    }

    /**
     * Re-scan all widget settings whenever `sidebars_widgets` or a widget
     * instance option changes.
     *
     * @param  string $option
     * @param  mixed  $oldValue
     * @param  mixed  $newValue
     */
    public function onWidgetOptionUpdate( string $option, mixed $oldValue, mixed $newValue ): void {
        if ( 'sidebars_widgets' !== $option && strpos( $option, 'widget_' ) !== 0 ) {
            return;
        }

        $this->scanWidgets();
    }

    /**
     * Re-scans a post when its featured image meta is set or updated.
     *
     * Fires on `updated_post_meta` and `added_post_meta`. Gutenberg's REST API
     * writes `_thumbnail_id` after `save_post`, so this hook catches that
     * second write and ensures the usage index reflects the current state.
     *
     * @param int    $metaId    ID of the wp_postmeta row (unused).
     * @param int    $postId    Post ID.
     * @param string $metaKey   Meta key being updated.
     * @param mixed  $metaValue New meta value (unused — we re-scan the full post).
     */
    public function onThumbnailMetaChanged( int $metaId, int $postId, string $metaKey, mixed $metaValue ): void {
        if ( '_thumbnail_id' !== $metaKey ) {
            return;
        }

        $post = get_post( $postId );
        if ( ! $post || in_array( $post->post_type, [ 'attachment', 'auto-draft' ], true ) ) {
            return;
        }

        $this->scanPost( $postId );
    }

    /**
     * Re-scans a post when its featured image meta is deleted (image removed).
     *
     * Fires on `deleted_post_meta`. At this point `_thumbnail_id` is already
     * gone, so `scanPost()` will find no featured image and the usage row
     * will be removed.
     *
     * @param int[]  $metaIds   IDs of the deleted wp_postmeta rows (unused).
     * @param int    $postId    Post ID.
     * @param string $metaKey   Meta key that was deleted.
     * @param mixed  $metaValue Value that was deleted (unused).
     */
    public function onThumbnailMetaDeleted( array $metaIds, int $postId, string $metaKey, mixed $metaValue ): void {
        if ( '_thumbnail_id' !== $metaKey ) {
            return;
        }

        $post = get_post( $postId );
        if ( ! $post || in_array( $post->post_type, [ 'attachment', 'auto-draft' ], true ) ) {
            return;
        }

        $this->scanPost( $postId );
    }

    // -------------------------------------------------------------------------
    // Public API — Scanning
    // -------------------------------------------------------------------------

    /**
     * Scans a single post and records all attachment references.
     *
     * Clears the post's existing usage rows first, then re-inserts fresh ones.
     *
     * @param  int    $postId
     * @return int    Number of distinct attachment IDs found.
     */
    public function scanPost( int $postId ): int {
        $post = get_post( $postId );

        if ( ! $post ) {
            return 0;
        }

        // Clear stale rows before re-inserting.
        $this->clearPostUsage( $postId );

        $objectType = $post->post_type;
        $found      = 0;

        // --- Featured image ---
        $thumbId = (int) get_post_thumbnail_id( $postId );
        if ( $thumbId > 0 ) {
            $this->upsertUsage( $thumbId, $postId, $objectType, 'featured_image' );
            ++$found;
        }

        // --- Post content ---
        $content = (string) $post->post_content;
        if ( '' !== $content ) {
            foreach ( $this->extractFromContent( $content ) as $attachmentId => $context ) {
                $this->upsertUsage( $attachmentId, $postId, $objectType, $context );
                ++$found;
            }
        }

        // --- Post meta (custom fields) ---
        foreach ( $this->extractFromMeta( $postId ) as $attachmentId ) {
            $this->upsertUsage( $attachmentId, $postId, $objectType, 'post_meta' );
            ++$found;
        }

        // --- Page builders (Elementor, Beaver Builder, Bricks, WPBakery, Divi) ---
        foreach ( $this->extractFromPageBuilders( $postId, $content ) as $attachmentId => $context ) {
            $this->upsertUsage( $attachmentId, $postId, $objectType, $context );
            ++$found;
        }

        return $found;
    }

    /**
     * Scans all widget option values for attachment IDs and stores them in
     * the usage table under object_type = 'widget'.
     *
     * @return int  Number of distinct attachment references found.
     */
    public function scanWidgets(): int {
        global $wpdb;

        // Remove all existing widget usage rows before a full re-scan.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete( $wpdb->prefix . self::TABLE, [ 'object_type' => 'widget' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        $found = 0;

        // Collect all registered widget instances (stored as 'widget_{slug}' options).
        $widgetOptions = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'widget_%'"
        );

        foreach ( (array) $widgetOptions as $optionName ) {
            $instances = get_option( $optionName );

            if ( ! is_array( $instances ) ) {
                continue;
            }

            foreach ( $instances as $instanceId => $instance ) {
                if ( ! is_array( $instance ) ) {
                    continue;
                }

                $objectId = (int) $instanceId;
                $json     = (string) wp_json_encode( $instance );

                foreach ( $this->extractIdsFromText( $json ) as $attachmentId ) {
                    $this->upsertUsage( $attachmentId, $objectId, 'widget', (string) $optionName );
                    ++$found;
                }
            }
        }

        return $found;
    }

    /**
     * Scans all published posts on the site and rebuilds the usage table.
     *
     * Intended for the WP-CLI `wp mediapilot usage scan` command and the admin
     * "Rebuild Usage Index" button.
     *
     * @param  callable|null $progress  Optional callback(int $done, int $total) for progress reporting.
     * @return array{ scanned: int, references: int }
     */
    public function scanAll( ?callable $progress = null ): array {
        global $wpdb;

        // Truncate the table for a clean rebuild.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}" . self::TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

        $postTypes = get_post_types( [ 'public' => true ] );
        unset( $postTypes['attachment'] );

        $ids = get_posts( [
            'post_type'      => array_values( $postTypes ),
            'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );

        $total      = count( $ids );
        $done       = 0;
        $references = 0;

        foreach ( $ids as $postId ) {
            $references += $this->scanPost( (int) $postId );
            ++$done;

            if ( null !== $progress ) {
                ($progress)( $done, $total );
            }
        }

        // Also scan widgets.
        $references += $this->scanWidgets();

        return [ 'scanned' => $done, 'references' => $references ];
    }

    /**
     * Scans one batch of posts as part of a chunked, client-driven rebuild.
     *
     * On the first batch ($offset === 0) the usage table is truncated for a
     * clean rebuild; widgets are scanned on the final batch. This lets the
     * admin rebuild the index without a long synchronous request or WP-Cron.
     *
     * Post order is stable (by ID) so paging by offset is safe between calls.
     *
     * @param int $offset Zero-based post offset for this batch.
     * @param int $limit  Maximum posts to scan this batch.
     * @return array{processed:int,total:int,references:int,done:bool}
     */
    public function scanRange( int $offset, int $limit ): array {
        global $wpdb;

        if ( $offset <= 0 ) {
            // Clean rebuild on the first batch.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}" . self::TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
            $offset = 0;
        }

        $postTypes = get_post_types( [ 'public' => true ] );
        unset( $postTypes['attachment'] );

        $query = new \WP_Query( [
            'post_type'      => array_values( $postTypes ),
            'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ] );

        $ids        = $query->posts;
        $total      = (int) $query->found_posts;
        $references = 0;

        foreach ( $ids as $postId ) {
            $references += $this->scanPost( (int) $postId );
        }

        $processed = $offset + count( $ids );
        $done      = ( count( $ids ) < $limit );

        // Scan widgets once, on the final batch.
        if ( $done ) {
            $references += $this->scanWidgets();
        }

        return [
            'processed'  => $processed,
            'total'      => $total,
            'references' => $references,
            'done'       => $done,
        ];
    }

    // -------------------------------------------------------------------------
    // Public API — Queries
    // -------------------------------------------------------------------------

    /**
     * Returns all usage records for an attachment.
     *
     * Each record is enriched with the post title and permalink so the UI
     * can render a clickable list.
     *
     * @param  int $attachmentId
     * @return list<array{
     *   object_id:    int,
     *   object_type:  string,
     *   context:      string,
     *   title:        string,
     *   permalink:    string,
     *   post_status:  string,
     * }>
     */
    public function getUsageForAttachment( int $attachmentId ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT object_id, object_type, context
                 FROM {$wpdb->prefix}mdpai_usage
                 WHERE attachment_id = %d
                 ORDER BY object_type, object_id",
                $attachmentId
            ),
            ARRAY_A
        );

        $result = [];

        foreach ( (array) $rows as $row ) {
            $objectId = (int) $row['object_id'];
            $post     = get_post( $objectId );

            if ( 'widget' === $row['object_type'] ) {
                $result[] = [
                    'object_id'   => $objectId,
                    'object_type' => 'widget',
                    'context'     => (string) $row['context'],
                    /* translators: %s: widget context/name */
                    'title'       => sprintf( __( 'Widget: %s', 'mediapilot-ai'), (string) $row['context'] ),
                    'permalink'   => admin_url( 'widgets.php' ),
                    'post_status' => 'active',
                ];
                continue;
            }

            if ( ! $post ) {
                continue;
            }

            $result[] = [
                'object_id'   => $objectId,
                'object_type' => (string) $row['object_type'],
                'context'     => (string) $row['context'],
                'title'       => get_the_title( $post ),
                'permalink'   => (string) get_permalink( $post ),
                'post_status' => $post->post_status,
            ];
        }

        return $result;
    }

    /**
     * Returns the total usage count for an attachment.
     *
     * @param  int $attachmentId
     * @return int
     */
    public function countUsage( int $attachmentId ): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT COUNT(*) FROM {$wpdb->prefix}mdpai_usage WHERE attachment_id = %d",
                $attachmentId
            )
        );
    }

    /**
     * Returns true when the attachment is referenced by at least one published post.
     *
     * @param  int $attachmentId
     * @return bool
     */
    public function isUsedInPublished( int $attachmentId ): bool {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT COUNT(u.id)
                 FROM {$wpdb->prefix}mdpai_usage u
                 INNER JOIN {$wpdb->posts} p ON u.object_id = p.ID
                 WHERE u.attachment_id = %d
                   AND p.post_status = 'publish'",
                $attachmentId
            )
        );

        return $count > 0;
    }

    /**
     * Returns the IDs of all attachments that have zero usage entries.
     *
     * @param  int $limit
     * @param  int $offset
     * @return int[]
     */
    public function getUnusedAttachmentIds( int $limit = 100, int $offset = 0 ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT p.ID
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->prefix}mdpai_usage u ON u.attachment_id = p.ID
                 WHERE p.post_type   = 'attachment'
                   AND p.post_status = 'inherit'
                   AND u.id IS NULL
                 ORDER BY p.ID
                 LIMIT %d OFFSET %d",
                $limit,
                $offset
            )
        );

        return array_map( 'intval', (array) $rows );
    }

    // -------------------------------------------------------------------------
    // Private — Extraction Helpers
    // -------------------------------------------------------------------------

    /**
     * Extracts attachment IDs from post content (classic editor + Gutenberg).
     *
     * Returns [ attachmentId => context ] where context is one of:
     *   'content', 'block', 'gallery_shortcode'
     *
     * @param  string             $content
     * @return array<int, string>
     */
    private function extractFromContent( string $content ): array {
        $found = [];

        // 1. Gutenberg blocks — parse_blocks() is authoritative for block content.
        //    Handles wp:image, wp:gallery, wp:cover, wp:media-text, wp:video, etc.
        if ( function_exists( 'parse_blocks' ) ) {
            $blocks = parse_blocks( $content );
            $this->extractFromBlocks( $blocks, $found );
        }

        // 2. Classic-editor fallback: Standard WP image class: class="wp-image-123"
        if ( preg_match_all( '/class=["\'][^"\']*wp-image-(\d+)/', $content, $m ) ) {
            foreach ( $m[1] as $id ) {
                $id = (int) $id;
                if ( $id > 0 ) {
                    $found[ $id ] = $found[ $id ] ?? 'content';
                }
            }
        }

        // 3. data-id attribute (WP gallery items, Jetpack, etc.)
        if ( preg_match_all( '/data-id=["\'](\d+)["\']/', $content, $m ) ) {
            foreach ( $m[1] as $id ) {
                $id = (int) $id;
                if ( $id > 0 ) {
                    $found[ $id ] = $found[ $id ] ?? 'content';
                }
            }
        }

        // 4. [gallery ids="1,2,3"] shortcode
        if ( preg_match_all( '/\[gallery[^\]]*\bids=["\']([0-9,\s]+)["\']/', $content, $m ) ) {
            foreach ( $m[1] as $list ) {
                foreach ( explode( ',', $list ) as $id ) {
                    $id = (int) trim( $id );
                    if ( $id > 0 ) {
                        $found[ $id ] = 'gallery_shortcode';
                    }
                }
            }
        }

        // 5. wp-content/uploads src URLs — resolve to attachment ID via DB
        if ( preg_match_all( '/src=["\']([^"\']+\/wp-content\/uploads\/[^"\']+)["\']/', $content, $m ) ) {
            foreach ( $m[1] as $url ) {
                $attachmentId = $this->attachmentIdFromUrl( $url );
                if ( $attachmentId > 0 ) {
                    $found[ $attachmentId ] = $found[ $attachmentId ] ?? 'content';
                }
            }
        }

        // Remove any IDs that don't belong to an actual attachment post.
        return array_filter(
            $found,
            static fn( int $id ) => 'attachment' === get_post_type( $id ),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Recursively walks a parsed block tree and extracts attachment IDs from
     * block attributes.
     *
     * Handles: id (int), ids (int[]), mediaId (int), featuredImageId (int).
     *
     * @param  array<mixed>  $blocks
     * @param  array<int,string> &$found  Accumulator: [ attachmentId => context ]
     */
    private function extractFromBlocks( array $blocks, array &$found ): void {
        foreach ( $blocks as $block ) {
            if ( ! is_array( $block ) ) {
                continue;
            }

            $attrs     = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
            $blockName = (string) ( $block['blockName'] ?? '' );

            // Single ID attributes: id, mediaId, featuredImageId
            foreach ( [ 'id', 'mediaId', 'featuredImageId' ] as $key ) {
                if ( isset( $attrs[ $key ] ) && is_numeric( $attrs[ $key ] ) ) {
                    $id = (int) $attrs[ $key ];
                    if ( $id > 0 ) {
                        $found[ $id ] = 'block';
                    }
                }
            }

            // Array ID attribute: ids (wp:gallery)
            if ( isset( $attrs['ids'] ) && is_array( $attrs['ids'] ) ) {
                foreach ( $attrs['ids'] as $id ) {
                    $id = (int) $id;
                    if ( $id > 0 ) {
                        $found[ $id ] = 'block';
                    }
                }
            }

            // Recurse into inner blocks.
            if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
                $this->extractFromBlocks( $block['innerBlocks'], $found );
            }
        }
    }

    /**
     * Scans all custom-field (post meta) values for numeric attachment IDs.
     *
     * Only checks non-hidden meta keys. Skips serialized values to avoid
     * false positives from non-ID integers.
     *
     * @param  int    $postId
     * @return int[]
     */
    private function extractFromMeta( int $postId ): array {
        $found = [];
        $meta  = get_post_meta( $postId );

        foreach ( (array) $meta as $key => $values ) {
            // Skip internal WP/MediaPilot meta keys.
            if ( strpos( $key, '_' ) === 0 ) {
                continue;
            }

            foreach ( (array) $values as $raw ) {
                if ( is_string( $raw ) && is_numeric( $raw ) ) {
                    $id = (int) $raw;
                    if ( $id > 0 && 'attachment' === get_post_type( $id ) ) {
                        $found[] = $id;
                    }
                }
            }
        }

        return array_unique( $found );
    }

    // -------------------------------------------------------------------------
    // Private — Page Builder Extractors
    // -------------------------------------------------------------------------

    /**
     * Aggregates attachment IDs from all supported page builders for one post.
     *
     * Dispatches to per-builder helpers and merges the results into a single
     * [ attachmentId => context ] map.  Each context string identifies the
     * builder (e.g. 'elementor', 'beaver_builder', 'bricks', 'wpbakery', 'divi').
     *
     * @param  int    $postId   Post ID being scanned.
     * @param  string $content  Post content (already loaded by the caller).
     * @return array<int, string>
     */
    private function extractFromPageBuilders( int $postId, string $content ): array {
        $found = [];

        $this->extractFromElementor( $postId, $found );
        $this->extractFromBeaverBuilder( $postId, $found );
        $this->extractFromBricks( $postId, $found );
        $this->extractFromWpBakery( $content, $found );
        $this->extractFromDivi( $content, $found );

        // Validate: keep only real attachment IDs.
        return array_filter(
            $found,
            static fn( int $id ) => 'attachment' === get_post_type( $id ),
            ARRAY_FILTER_USE_KEY
        );
    }

    // ---- Elementor ----------------------------------------------------------

    /**
     * Walks the Elementor `_elementor_data` JSON tree and collects every
     * attachment ID referenced in widget / section settings.
     *
     * Handles: Image, Gallery, Carousel, Cover, Section backgrounds, Video
     * poster, Logo, Before/After, and any custom widget that stores an image
     * object with an `id` key.
     *
     * @param  int                 $postId
     * @param  array<int, string> &$found
     */
    private function extractFromElementor( int $postId, array &$found ): void {
        $raw = get_post_meta( $postId, '_elementor_data', true );

        if ( empty( $raw ) || ! is_string( $raw ) ) {
            return;
        }

        $data = json_decode( $raw, true );

        if ( ! is_array( $data ) ) {
            return;
        }

        $this->walkElementorElements( $data, $found );
    }

    /**
     * Recursively traverses an Elementor elements tree.
     *
     * @param  array<mixed>      $elements
     * @param  array<int,string> &$found
     */
    private function walkElementorElements( array $elements, array &$found ): void {
        foreach ( $elements as $element ) {
            if ( ! is_array( $element ) ) {
                continue;
            }

            $settings = isset( $element['settings'] ) && is_array( $element['settings'] )
                ? $element['settings']
                : [];

            // --- Scalar image-object fields: { id: N, url: "..." } ---
            foreach ( [
                'image',                       // Image widget
                'background_image',            // Section / column background
                'video_image',                 // Video poster
                'background_video_fallback',   // Video section fallback
                'logo',                        // Logo widget
                'photo',                       // Some theme widgets
                'image_1',                     // Before/After left
                'image_2',                     // Before/After right
                'hover_image',                 // Hover effect
            ] as $key ) {
                if ( isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ) {
                    $id = (int) ( $settings[ $key ]['id'] ?? 0 );
                    if ( $id > 0 ) {
                        $found[ $id ] = 'elementor';
                    }
                }
            }

            // --- Array image collections ---

            // gallery / wp_gallery: [ { id: N, url: "..." }, ... ]
            foreach ( [ 'gallery', 'wp_gallery' ] as $key ) {
                if ( isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ) {
                    foreach ( $settings[ $key ] as $item ) {
                        if ( is_array( $item ) ) {
                            $id = (int) ( $item['id'] ?? 0 );
                            if ( $id > 0 ) {
                                $found[ $id ] = 'elementor';
                            }
                        }
                    }
                }
            }

            // slides: each slide may carry its own image object
            if ( isset( $settings['slides'] ) && is_array( $settings['slides'] ) ) {
                foreach ( $settings['slides'] as $slide ) {
                    if ( ! is_array( $slide ) ) {
                        continue;
                    }
                    // Slide background image
                    if ( isset( $slide['image'] ) && is_array( $slide['image'] ) ) {
                        $id = (int) ( $slide['image']['id'] ?? 0 );
                        if ( $id > 0 ) {
                            $found[ $id ] = 'elementor';
                        }
                    }
                    // Some sliders nest directly: { id: N }
                    $id = (int) ( $slide['id'] ?? 0 );
                    if ( $id > 0 ) {
                        $found[ $id ] = 'elementor';
                    }
                }
            }

            // image_ids: array of raw IDs (custom widgets / Elementor Pro)
            if ( isset( $settings['image_ids'] ) && is_array( $settings['image_ids'] ) ) {
                foreach ( $settings['image_ids'] as $id ) {
                    $id = (int) $id;
                    if ( $id > 0 ) {
                        $found[ $id ] = 'elementor';
                    }
                }
            }

            // Recurse into child elements.
            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $this->walkElementorElements( $element['elements'], $found );
            }
        }
    }

    // ---- Beaver Builder -----------------------------------------------------

    /**
     * Walks Beaver Builder's serialized node data stored in `_fl_builder_data`
     * (published) and `_fl_builder_draft` (draft/preview).
     *
     * @param  int                 $postId
     * @param  array<int, string> &$found
     */
    private function extractFromBeaverBuilder( int $postId, array &$found ): void {
        foreach ( [ '_fl_builder_data', '_fl_builder_draft' ] as $metaKey ) {
            $raw = get_post_meta( $postId, $metaKey, true );

            if ( empty( $raw ) ) {
                continue;
            }

            // BB stores PHP-serialized stdClass objects.
            $data = is_string( $raw ) ? @unserialize( $raw ) : $raw; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize

            if ( ! is_array( $data ) && ! is_object( $data ) ) {
                continue;
            }

            foreach ( (array) $data as $node ) {
                $settings = (array) ( is_object( $node ) ? ( $node->settings ?? [] ) : ( $node['settings'] ?? [] ) );

                // photo_id — Photo module
                if ( ! empty( $settings['photo_id'] ) ) {
                    $id = (int) $settings['photo_id'];
                    if ( $id > 0 ) {
                        $found[ $id ] = 'beaver_builder';
                    }
                }

                // image_ids — Gallery module (comma-separated or array)
                if ( ! empty( $settings['image_ids'] ) ) {
                    $raw_ids = is_array( $settings['image_ids'] )
                        ? $settings['image_ids']
                        : explode( ',', (string) $settings['image_ids'] );

                    foreach ( $raw_ids as $id ) {
                        $id = (int) trim( (string) $id );
                        if ( $id > 0 ) {
                            $found[ $id ] = 'beaver_builder';
                        }
                    }
                }

                // logo_image — Themer Header module
                if ( ! empty( $settings['logo_image_src'] ) ) {
                    $id = $this->attachmentIdFromUrl( (string) $settings['logo_image_src'] );
                    if ( $id > 0 ) {
                        $found[ $id ] = 'beaver_builder';
                    }
                }

                // bg_image_src / bg_photo — row/column background
                foreach ( [ 'bg_image_src', 'bg_photo' ] as $key ) {
                    if ( ! empty( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
                        $id = $this->attachmentIdFromUrl( $settings[ $key ] );
                        if ( $id > 0 ) {
                            $found[ $id ] = 'beaver_builder';
                        }
                    }
                }
            }
        }
    }

    // ---- Bricks -------------------------------------------------------------

    /**
     * Parses Bricks Builder's JSON page data stored in `_bricks_page_content_2`.
     *
     * Bricks uses a flat element array (not nested); each element has a `name`
     * (e.g. "image", "gallery") and a `settings` map.
     *
     * @param  int                 $postId
     * @param  array<int, string> &$found
     */
    private function extractFromBricks( int $postId, array &$found ): void {
        // Bricks stores content under different keys across versions.
        foreach ( [ '_bricks_page_content_2', '_bricks_page_content' ] as $metaKey ) {
            $raw = get_post_meta( $postId, $metaKey, true );

            if ( empty( $raw ) || ! is_string( $raw ) ) {
                continue;
            }

            $elements = json_decode( $raw, true );

            if ( ! is_array( $elements ) ) {
                continue;
            }

            foreach ( $elements as $element ) {
                if ( ! is_array( $element ) ) {
                    continue;
                }

                $settings = isset( $element['settings'] ) && is_array( $element['settings'] )
                    ? $element['settings']
                    : [];

                // Single image object fields: { id: N, url: "..." }
                foreach ( [ 'image', 'logo', 'bgImage', 'background' ] as $key ) {
                    if ( isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ) {
                        $id = (int) ( $settings[ $key ]['id'] ?? 0 );
                        if ( $id > 0 ) {
                            $found[ $id ] = 'bricks';
                        }
                    }
                }

                // Gallery / images: [ { id: N, url: "..." }, ... ]
                foreach ( [ 'images', 'gallery' ] as $key ) {
                    if ( isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ) {
                        foreach ( $settings[ $key ] as $img ) {
                            if ( is_array( $img ) ) {
                                $id = (int) ( $img['id'] ?? 0 );
                                if ( $id > 0 ) {
                                    $found[ $id ] = 'bricks';
                                }
                            }
                        }
                    }
                }

                // Slides that carry an image object
                if ( isset( $settings['items'] ) && is_array( $settings['items'] ) ) {
                    foreach ( $settings['items'] as $item ) {
                        if ( is_array( $item ) && isset( $item['image'] ) && is_array( $item['image'] ) ) {
                            $id = (int) ( $item['image']['id'] ?? 0 );
                            if ( $id > 0 ) {
                                $found[ $id ] = 'bricks';
                            }
                        }
                    }
                }
            }
        }
    }

    // ---- WPBakery (Visual Composer) -----------------------------------------

    /**
     * Extracts attachment IDs from WPBakery shortcodes in post content.
     *
     * Covers: vc_single_image, vc_gallery, vc_media_grid, vc_basic_grid,
     * vc_masonry_grid, vc_masonry_media_grid, vc_carousel_slide.
     *
     * @param  string             $content  Post content.
     * @param  array<int, string> &$found
     */
    private function extractFromWpBakery( string $content, array &$found ): void {
        if ( '' === $content ) {
            return;
        }

        // [vc_single_image image="123"] and [vc_carousel_slide image="123"]
        if ( preg_match_all(
            '/\[vc_(?:single_image|carousel_slide)[^\]]*\bimage=["\'](\d+)["\']/',
            $content,
            $m
        ) ) {
            foreach ( $m[1] as $id ) {
                $id = (int) $id;
                if ( $id > 0 ) {
                    $found[ $id ] = 'wpbakery';
                }
            }
        }

        // [vc_gallery images="1,2,3"] and grid variants
        if ( preg_match_all(
            '/\[vc_(?:gallery|media_grid|basic_grid|masonry_grid|masonry_media_grid)[^\]]*\b(?:images|mediafiles)=["\']([0-9,\s]+)["\']/',
            $content,
            $m
        ) ) {
            foreach ( $m[1] as $list ) {
                foreach ( explode( ',', $list ) as $id ) {
                    $id = (int) trim( $id );
                    if ( $id > 0 ) {
                        $found[ $id ] = 'wpbakery';
                    }
                }
            }
        }
    }

    // ---- Divi ---------------------------------------------------------------

    /**
     * Extracts attachment references from Divi Builder shortcodes.
     *
     * Covers: et_pb_image, et_pb_fullwidth_image, et_pb_fullwidth_header,
     * et_pb_gallery (gallery_ids), et_pb_slide (image URL).
     *
     * Divi stores images as URLs in most cases, so attachment_url_to_postid()
     * is used to resolve them. Gallery IDs are stored as integers.
     *
     * @param  string             $content  Post content.
     * @param  array<int, string> &$found
     */
    private function extractFromDivi( string $content, array &$found ): void {
        if ( '' === $content || strpos( $content, 'et_pb_' ) === false ) {
            return;
        }

        // Image shortcodes with src="URL"
        if ( preg_match_all(
            '/\[et_pb_(?:image|fullwidth_image|fullwidth_header|section|row)[^\]]*\bsrc=["\']([^"\']+)["\']/',
            $content,
            $m
        ) ) {
            foreach ( $m[1] as $url ) {
                $id = $this->attachmentIdFromUrl( $url );
                if ( $id > 0 ) {
                    $found[ $id ] = 'divi';
                }
            }
        }

        // et_pb_gallery gallery_ids="1,2,3"
        if ( preg_match_all(
            '/\[et_pb_gallery[^\]]*\bgallery_ids=["\']([0-9,\s]+)["\']/',
            $content,
            $m
        ) ) {
            foreach ( $m[1] as $list ) {
                foreach ( explode( ',', $list ) as $id ) {
                    $id = (int) trim( $id );
                    if ( $id > 0 ) {
                        $found[ $id ] = 'divi';
                    }
                }
            }
        }

        // et_pb_slide image="URL" (slider module)
        if ( preg_match_all(
            '/\[et_pb_slide[^\]]*\bimage=["\']([^"\']+)["\']/',
            $content,
            $m
        ) ) {
            foreach ( $m[1] as $url ) {
                $id = $this->attachmentIdFromUrl( $url );
                if ( $id > 0 ) {
                    $found[ $id ] = 'divi';
                }
            }
        }
    }

    /**
     * Extracts any integers that look like attachment IDs from arbitrary text.
     *
     * Used for widget scanning where the content is JSON-encoded.
     *
     * @param  string $text
     * @return int[]
     */
    private function extractIdsFromText( string $text ): array {
        $found = [];

        // Match standalone integers ≥ 1 in the text.
        if ( ! preg_match_all( '/\b(\d+)\b/', $text, $m ) ) {
            return [];
        }

        foreach ( $m[1] as $raw ) {
            $id = (int) $raw;
            if ( $id > 0 && 'attachment' === get_post_type( $id ) ) {
                $found[] = $id;
            }
        }

        return array_unique( $found );
    }

    /**
     * Resolves a full URL (possibly a resized thumbnail URL) to an attachment
     * post ID via `attachment_url_to_postid()`.
     *
     * Falls back to stripping size suffix (e.g. "-300x200") and retrying.
     *
     * @param  string $url
     * @return int  0 when not found.
     */
    private function attachmentIdFromUrl( string $url ): int {
        $id = (int) attachment_url_to_postid( $url );

        if ( $id > 0 ) {
            return $id;
        }

        // Strip thumbnail size suffix and retry.
        $stripped = preg_replace( '/-\d+x\d+(\.[a-z]{2,4})$/i', '$1', $url );

        if ( $stripped && $stripped !== $url ) {
            return (int) attachment_url_to_postid( $stripped );
        }

        return 0;
    }

    // -------------------------------------------------------------------------
    // Private — DB Helpers
    // -------------------------------------------------------------------------

    /**
     * Insert or update a usage row.
     *
     * The UNIQUE KEY on (attachment_id, object_id, object_type) ensures that
     * duplicate inserts just refresh `updated_at`.
     *
     * @param  int    $attachmentId
     * @param  int    $objectId
     * @param  string $objectType
     * @param  string $context
     */
    private function upsertUsage( int $attachmentId, int $objectId, string $objectType, string $context ): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "INSERT INTO {$wpdb->prefix}mdpai_usage
                    (attachment_id, object_id, object_type, context, updated_at)
                 VALUES (%d, %d, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    context    = VALUES(context),
                    updated_at = VALUES(updated_at)",
                $attachmentId,
                $objectId,
                $objectType,
                $context,
                current_time( 'mysql' )
            )
        );
    }

    /**
     * Removes all usage rows associated with a post (called on delete or before rescan).
     *
     * @param  int $postId
     */
    private function clearPostUsage( int $postId ): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prefix . self::TABLE,
            [ 'object_id' => $postId ],
            [ '%d' ]
        );
    }
}
