<?php

declare(strict_types=1);

namespace MediaPilotAI\Media;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Taxonomy\FolderTaxonomy;

/**
 * Detects duplicate and visually-similar media files.
 *
 * Two detection modes:
 *
 *   Exact (MD5)
 *     Computes the MD5 hash of each attachment file and groups files that share
 *     the same hash.  Fast and perfectly accurate for identical files.
 *
 *   Perceptual (dHash)
 *     Resizes each image to 9×8, converts to greyscale, then builds a 64-bit
 *     difference hash by comparing adjacent pixel luminance values.  Two images
 *     are considered "visually similar" when their Hamming distance ≤ threshold
 *     (default 10 out of 64 bits).  Requires the GD extension.
 *
 * Scan lifecycle:
 *   1. REST call `POST /files/duplicates/scan` invokes startBackgroundScan().
 *   2. startBackgroundScan() stores a "running" status transient, then
 *      schedules a single WP Cron event (mdpai_run_duplicate_scan).
 *   3. The cron callback runChunk() processes CHUNK_SIZE attachments per run,
 *      updates the progress transient, and re-schedules itself until done.
 *   4. On completion, results are stored in MDPAI_DUPLICATES_RESULTS_KEY.
 *   5. Frontend polls `GET /files/duplicates/status` while scanning.
 *
 * @package MediaPilotAI\Media
 * @since   1.0.0
 */
class DuplicateDetector {

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    public const CRON_HOOK       = 'mdpai_run_duplicate_scan';
    public const STATUS_KEY      = 'mdpai_duplicate_scan_status';
    public const RESULTS_KEY     = 'mdpai_duplicate_results';
    public const PROGRESS_KEY    = 'mdpai_duplicate_scan_progress';

    /** Meta key where each attachment's dHash is stored for fast similar-lookups. */
    public const META_DHASH      = '_mdpai_dhash';

    /** Cron hook fired after upload to compute + store dHash asynchronously. */
    private const CRON_UPLOAD    = 'mdpai_dhash_on_upload';

    /** Number of attachments processed per cron execution. */
    private const CHUNK_SIZE     = 50;

    /** Hamming distance threshold for perceptual similarity (0–64). */
    private const PHASH_THRESHOLD = 10;

    /** Transient TTL: 24 hours. */
    private const TRANSIENT_TTL  = 86400;

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function register(): void {
        add_action( self::CRON_HOOK,    [ $this, 'runChunk' ] );
        add_action( self::CRON_UPLOAD,  [ $this, 'processUploadCron' ] );

        // Compute + index dHash asynchronously on every new upload.
        add_action( 'add_attachment', [ $this, 'onUpload' ] );

        // Inject similar-image count into the attachment JS object (media modal).
        add_filter( 'wp_prepare_attachment_for_js', [ $this, 'addSimilarCountToJs' ], 10, 2 );

        // Append "Find Similar" button to the attachment details panel.
        add_filter( 'attachment_fields_to_edit', [ $this, 'addFindSimilarField' ], 10, 2 );

        // Attach the "Find Similar" JS handler to its enqueued handle. Done on
        // admin_enqueue_scripts (NOT admin_footer): footer-time wp_add_inline_script()
        // is unreliable on sites that flush footer scripts early.
        add_action( 'admin_enqueue_scripts', [ $this, 'printFindSimilarScript' ] );
    }

    // -------------------------------------------------------------------------
    // Public API — Scan control
    // -------------------------------------------------------------------------

    /**
     * Trigger a background duplicate scan via WP Cron.
     *
     * If a scan is already running this is a no-op; call clearScan() first
     * to force a rescan.
     *
     * @return bool  True if the scan was scheduled; false if already running.
     */
    public function startBackgroundScan(): bool {
        $status = get_transient( self::STATUS_KEY );

        if ( 'running' === $status ) {
            return false;
        }

        // Reset state.
        delete_transient( self::STATUS_KEY );
        delete_transient( self::RESULTS_KEY );
        delete_transient( self::PROGRESS_KEY );

        set_transient( self::STATUS_KEY, 'running', self::TRANSIENT_TTL );
        set_transient( self::PROGRESS_KEY, [ 'processed' => 0, 'total' => 0 ], self::TRANSIENT_TTL );

        // Schedule the first chunk.
        wp_schedule_single_event( time(), self::CRON_HOOK, [ 0 ] );
        spawn_cron();

        return true;
    }

    /**
     * Run one scan chunk.  Called by WP Cron; re-schedules itself if not done.
     *
     * @param int $offset  Attachment query offset for this chunk.
     */
    public function runChunk( int $offset = 0 ): void {
        // Guard against stale cron events after a manual clear.
        if ( 'running' !== get_transient( self::STATUS_KEY ) ) {
            return;
        }

        // Count total attachments (cached per chunk run).
        $total = (int) wp_count_posts( 'attachment' )->inherit;

        // Fetch chunk of attachment posts.
        $posts = get_posts( [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => self::CHUNK_SIZE,
            'offset'         => $offset,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );

        // Load or initialise accumulator from a working transient.
        $accumulator = get_transient( 'mdpai_dup_accumulator' );
        if ( ! is_array( $accumulator ) ) {
            $accumulator = [
                'md5'    => [], // [ hash => [ id, ... ] ]
                'dhash'  => [], // [ id => hashString ]
            ];
        }

        foreach ( $posts as $id ) {
            $id       = (int) $id;
            $filePath = get_attached_file( $id );

            if ( ! is_string( $filePath ) || ! file_exists( $filePath ) ) {
                continue;
            }

            // --- Exact hash (MD5) ---
            $md5 = md5_file( $filePath );
            if ( false !== $md5 ) {
                $accumulator['md5'][ $md5 ][] = $id;
            }

            // --- Perceptual hash (dHash) — images only ---
            $mime = (string) get_post_mime_type( $id );
            if ( str_starts_with( $mime, 'image/' ) && extension_loaded( 'gd' ) ) {
                $dh = $this->computeDHash( $filePath, $mime );
                if ( null !== $dh ) {
                    $accumulator['dhash'][ $id ] = $dh;
                    // Index in post meta so findSimilarToId() can use it without a full rescan.
                    update_post_meta( $id, self::META_DHASH, $dh );
                }
            }
        }

        // Persist accumulator between chunks.
        set_transient( 'mdpai_dup_accumulator', $accumulator, self::TRANSIENT_TTL );

        $processed = $offset + count( $posts );

        set_transient( self::PROGRESS_KEY, [
            'processed' => $processed,
            'total'     => $total,
        ], self::TRANSIENT_TTL );

        // More chunks remaining?
        if ( count( $posts ) === self::CHUNK_SIZE ) {
            wp_schedule_single_event( time() + 1, self::CRON_HOOK, [ $offset + self::CHUNK_SIZE ] );
            spawn_cron();
            return;
        }

        // --- All chunks done — build final results ---
        $results = $this->buildResults( $accumulator );

        set_transient( self::RESULTS_KEY, $results, self::TRANSIENT_TTL );
        set_transient( self::STATUS_KEY, 'done', self::TRANSIENT_TTL );
        delete_transient( 'mdpai_dup_accumulator' );
    }

    /**
     * Returns the current scan status and results.
     *
     * @return array{
     *   status: string,
     *   progress: array{processed: int, total: int},
     *   groups: array<int, array{type: string, files: list<array<string,mixed>>}>
     * }
     */
    public function getStatus(): array {
        $status   = get_transient( self::STATUS_KEY ) ?: 'idle';
        $progress = get_transient( self::PROGRESS_KEY ) ?: [ 'processed' => 0, 'total' => 0 ];
        $results  = get_transient( self::RESULTS_KEY ) ?: [];

        return [
            'status'   => (string) $status,
            'progress' => $progress,
            'groups'   => $results,
        ];
    }

    /**
     * Clears all scan state (results + status + progress transients).
     */
    public function clearScan(): void {
        delete_transient( self::STATUS_KEY );
        delete_transient( self::RESULTS_KEY );
        delete_transient( self::PROGRESS_KEY );
        delete_transient( 'mdpai_dup_accumulator' );
    }

    /**
     * Cancel an in-progress scan.
     *
     * Unschedules every pending scan cron event (regardless of its offset arg)
     * and clears all scan state. Any chunk that is already mid-flight will see
     * the missing "running" status on its next guard check and stop without
     * re-scheduling. Safe to call when no scan is running.
     *
     * @return bool True if a scan was running and has been cancelled; false if
     *              there was nothing to cancel.
     */
    public function cancelScan(): bool {
        $wasRunning = ( 'running' === get_transient( self::STATUS_KEY ) );

        // Remove all queued chunks for this hook, regardless of their offset arg.
        wp_unschedule_hook( self::CRON_HOOK );

        $this->clearScan();

        return $wasRunning;
    }

    // -------------------------------------------------------------------------
    // Resolve — keep primary, delete others, merge folder assignments
    // -------------------------------------------------------------------------

    /**
     * Resolve a duplicate group: keep $primaryId, delete the rest, move any
     * folder assignments from the deleted files to the primary.
     *
     * @param  int   $primaryId   The attachment ID to keep.
     * @param  int[] $deleteIds   Attachment IDs to permanently delete.
     * @return array{ kept: int, deleted: int[], errors: array<int, string> }
     */
    public function resolveGroup( int $primaryId, array $deleteIds ): array {
        $deleted = [];
        $errors  = [];

        foreach ( $deleteIds as $id ) {
            $id = absint( $id );
            if ( $id === $primaryId || $id <= 0 ) {
                continue;
            }

            // Merge folder assignment: if the duplicate is in a folder but the
            // primary isn't, assign the primary to that folder.
            $dupFolderTerms = wp_get_object_terms( $id, FolderTaxonomy::TAXONOMY, [ 'fields' => 'ids' ] );
            $primaryTerms   = wp_get_object_terms( $primaryId, FolderTaxonomy::TAXONOMY, [ 'fields' => 'ids' ] );

            if ( ! is_wp_error( $dupFolderTerms ) && ! is_wp_error( $primaryTerms ) ) {
                $missingTerms = array_diff( (array) $dupFolderTerms, (array) $primaryTerms );
                if ( ! empty( $missingTerms ) ) {
                    wp_set_object_terms( $primaryId, array_merge( (array) $primaryTerms, $missingTerms ), FolderTaxonomy::TAXONOMY );
                }
            }

            // Permanently delete the duplicate attachment.
            $post = get_post( $id );
            if ( ! $post ) {
                $errors[$id] = "Post {$id} not found.";
                continue;
            }
            if ( 'attachment' !== $post->post_type ) {
                $errors[$id] = "Post {$id} is not an attachment (type: {$post->post_type}).";
                continue;
            }

            $result = wp_delete_attachment( $id, true );

            if ( false === $result || null === $result ) {
                $errors[$id] = "wp_delete_attachment({$id}) returned " . ( null === $result ? 'null' : 'false' ) . " — likely blocked by a pre_delete_attachment filter.";
            } else {
                $deleted[] = $id;
            }
        }

        return [
            'kept'    => $primaryId,
            'deleted' => $deleted,
            'errors'  => $errors,
        ];
    }

    /**
     * Resolve every stored duplicate group at once. Within each group the first
     * file (files[0]) is kept as primary and the remainder are deleted.
     *
     * @return array{
     *     groups_resolved: int,
     *     deleted: int,
     *     errors: array<int, string>
     * }
     */
    public function resolveAllGroups(): array {
        $groups = get_transient( self::RESULTS_KEY ) ?: [];

        $groupsResolved = 0;
        $totalDeleted   = 0;
        $allErrors      = [];
        $remaining      = [];

        foreach ( (array) $groups as $group ) {
            $files = isset( $group['files'] ) && is_array( $group['files'] ) ? $group['files'] : [];
            if ( count( $files ) < 2 ) {
                continue;
            }

            $primaryId = isset( $files[0]['id'] ) ? (int) $files[0]['id'] : 0;
            $deleteIds = [];
            foreach ( array_slice( $files, 1 ) as $f ) {
                if ( isset( $f['id'] ) ) {
                    $deleteIds[] = (int) $f['id'];
                }
            }

            if ( $primaryId <= 0 || empty( $deleteIds ) ) {
                continue;
            }

            $result = $this->resolveGroup( $primaryId, $deleteIds );
            $groupsResolved++;
            $totalDeleted += count( $result['deleted'] );

            foreach ( $result['errors'] as $id => $msg ) {
                $allErrors[ (int) $id ] = (string) $msg;
            }

            // If any files in this group could not be deleted, keep a slimmed-down
            // group entry in the stored results so the UI can retry just those.
            if ( ! empty( $result['errors'] ) ) {
                $keptFiles = [];
                foreach ( $files as $f ) {
                    $fid = isset( $f['id'] ) ? (int) $f['id'] : 0;
                    if ( $fid === $primaryId || isset( $result['errors'][ $fid ] ) ) {
                        $keptFiles[] = $f;
                    }
                }
                if ( count( $keptFiles ) > 1 ) {
                    $group['files'] = $keptFiles;
                    $remaining[]    = $group;
                }
            }
        }

        // Replace stored results with any unresolved groups (empty if all succeeded).
        set_transient( self::RESULTS_KEY, $remaining, self::TRANSIENT_TTL );

        return [
            'groups_resolved' => $groupsResolved,
            'deleted'         => $totalDeleted,
            'errors'          => $allErrors,
        ];
    }

    // -------------------------------------------------------------------------
    // Synchronous scan (for CLI / direct call)
    // -------------------------------------------------------------------------

    /**
     * Run a full synchronous scan and return results immediately.
     * Use this from WP-CLI where cron is not needed.
     *
     * @return array  Same structure as getStatus()['groups'].
     */
    public function runFullScanSync(): array {
        $accumulator = [ 'md5' => [], 'dhash' => [] ];
        $offset      = 0;

        do {
            $posts = get_posts( [
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => self::CHUNK_SIZE,
                'offset'         => $offset,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ] );

            foreach ( $posts as $id ) {
                $id       = (int) $id;
                $filePath = get_attached_file( $id );

                if ( ! is_string( $filePath ) || ! file_exists( $filePath ) ) {
                    continue;
                }

                $md5 = md5_file( $filePath );
                if ( false !== $md5 ) {
                    $accumulator['md5'][ $md5 ][] = $id;
                }

                $mime = (string) get_post_mime_type( $id );
                if ( str_starts_with( $mime, 'image/' ) && extension_loaded( 'gd' ) ) {
                    $dh = $this->computeDHash( $filePath, $mime );
                    if ( null !== $dh ) {
                        $accumulator['dhash'][ $id ] = $dh;
                        update_post_meta( $id, self::META_DHASH, $dh );
                    }
                }
            }

            $offset += count( $posts );
        } while ( count( $posts ) === self::CHUNK_SIZE );

        $results = $this->buildResults( $accumulator );

        set_transient( self::RESULTS_KEY, $results, self::TRANSIENT_TTL );
        set_transient( self::STATUS_KEY, 'done', self::TRANSIENT_TTL );

        return $results;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Turn the raw accumulator into decorated duplicate groups.
     *
     * @param  array{md5: array<string, int[]>, dhash: array<int, string>} $accumulator
     * @return list<array{type: string, hash: string, files: list<array<string,mixed>>}>
     */
    private function buildResults( array $accumulator ): array {
        $groups = [];

        // --- Exact duplicates ---
        foreach ( $accumulator['md5'] as $hash => $ids ) {
            if ( count( $ids ) < 2 ) {
                continue;
            }

            $groups[] = [
                'type'  => 'exact',
                'hash'  => $hash,
                'files' => $this->decorateIds( $ids ),
            ];
        }

        // --- Perceptual (visually similar) duplicates ---
        $dhashes   = $accumulator['dhash'];
        $ids       = array_keys( $dhashes );
        $processed = [];

        /**
         * Filters the Hamming-distance threshold for perceptual duplicate detection.
         *
         * Lower values = stricter matching (fewer false positives).
         * Valid range: 0 (identical) – 64 (always match). Default: 10.
         *
         * @param int $threshold Hamming distance threshold.
         */
        $pHashThreshold = max( 0, (int) apply_filters( 'mdpai_duplicate_threshold', self::PHASH_THRESHOLD ) );

        foreach ( $ids as $i => $idA ) {
            if ( isset( $processed[$idA] ) ) {
                continue;
            }

            $group = [ $idA ];

            for ( $j = $i + 1; $j < count( $ids ); $j++ ) {
                $idB = $ids[$j];
                if ( isset( $processed[$idB] ) ) {
                    continue;
                }

                $distance = $this->hammingDistance( $dhashes[$idA], $dhashes[$idB] );

                if ( $distance <= $pHashThreshold ) {
                    $group[]          = $idB;
                    $processed[$idB]  = true;
                }
            }

            if ( count( $group ) >= 2 ) {
                $processed[$idA] = true;

                $groups[] = [
                    'type'  => 'similar',
                    'hash'  => $dhashes[$idA],
                    'files' => $this->decorateIds( $group ),
                ];
            }
        }

        return $groups;
    }

    /**
     * Decorate an array of attachment IDs with metadata for the frontend.
     *
     * @param  int[] $ids
     * @return list<array<string, mixed>>
     */
    private function decorateIds( array $ids ): array {
        $files = [];

        foreach ( $ids as $id ) {
            $post = get_post( $id );
            if ( ! $post || 'attachment' !== $post->post_type ) {
                continue;
            }

            $filePath     = get_attached_file( $id );
            $thumbnailUrl = wp_get_attachment_image_url( $id, 'thumbnail' );
            if ( false === $thumbnailUrl || '' === $thumbnailUrl ) {
                $thumbnailUrl = (string) wp_get_attachment_url( $id );
            }

            $fileSize = 0;
            if ( is_string( $filePath ) && file_exists( $filePath ) ) {
                $fileSize = (int) filesize( $filePath );
            }

            $files[] = [
                'id'            => $id,
                'filename'      => is_string( $filePath ) ? basename( $filePath ) : '',
                'title'         => (string) $post->post_title,
                'date'          => (string) $post->post_date,
                'file_size'     => $fileSize,
                'mime_type'     => (string) $post->post_mime_type,
                'thumbnail_url' => $thumbnailUrl,
                'url'           => (string) wp_get_attachment_url( $id ),
            ];
        }

        return $files;
    }

    /**
     * Compute a 64-bit difference hash (dHash) for an image file.
     *
     * Resize to 9×8 → greyscale → compare adjacent pixels per row →
     * produce 64 bits encoded as a 16-character hex string.
     *
     * Returns null on failure (missing GD, unsupported format, corrupt file).
     *
     * @param  string $filePath  Absolute path to the image file.
     * @param  string $mime      MIME type (e.g. 'image/jpeg').
     * @return string|null  16-character hex hash, or null on failure.
     */
    private function computeDHash( string $filePath, string $mime ): ?string {
        if ( ! extension_loaded( 'gd' ) ) {
            return null;
        }

        $img = match ( $mime ) {
            'image/jpeg' => @imagecreatefromjpeg( $filePath ),
            'image/png'  => @imagecreatefrompng( $filePath ),
            'image/gif'  => @imagecreatefromgif( $filePath ),
            'image/webp' => function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $filePath ) : false,
            default      => false,
        };

        if ( false === $img || null === $img ) {
            return null;
        }

        // Resize to 9×8.
        $small = imagecreatetruecolor( 9, 8 );
        if ( false === $small ) {
            imagedestroy( $img );
            return null;
        }

        imagecopyresampled( $small, $img, 0, 0, 0, 0, 9, 8, imagesx( $img ), imagesy( $img ) );
        imagedestroy( $img );

        // Build 64-bit hash (8 rows × 8 bit-comparisons).
        $hash = 0;
        $bit  = 0;

        for ( $y = 0; $y < 8; $y++ ) {
            for ( $x = 0; $x < 8; $x++ ) {
                $colorA = imagecolorat( $small, $x, $y );
                $colorB = imagecolorat( $small, $x + 1, $y );

                // Greyscale luminance via BT.601 coefficients.
                $lumA = 0.299 * ( ( $colorA >> 16 ) & 0xFF )
                      + 0.587 * ( ( $colorA >> 8 )  & 0xFF )
                      + 0.114 * (   $colorA          & 0xFF );
                $lumB = 0.299 * ( ( $colorB >> 16 ) & 0xFF )
                      + 0.587 * ( ( $colorB >> 8 )  & 0xFF )
                      + 0.114 * (   $colorB          & 0xFF );

                if ( $lumA > $lumB ) {
                    $hash |= ( 1 << $bit );
                }

                $bit++;
            }
        }

        imagedestroy( $small );

        return str_pad( dechex( $hash ), 16, '0', STR_PAD_LEFT );
    }

    // -------------------------------------------------------------------------
    // Per-upload dHash indexing (S50)
    // -------------------------------------------------------------------------

    /**
     * Scheduled via add_attachment — defers dHash computation off the upload
     * request so the response is not blocked.
     */
    public function onUpload( int $id ): void {
        $mime = (string) get_post_mime_type( $id );
        if ( ! str_starts_with( $mime, 'image/' ) ) {
            return;
        }

        wp_schedule_single_event( time(), self::CRON_UPLOAD, [ $id ] );
        spawn_cron();
    }

    /**
     * WP Cron callback: compute dHash for one attachment, store it in meta,
     * then find and cache any visually-similar matches.
     */
    public function processUploadCron( int $id ): void {
        $filePath = get_attached_file( $id );
        if ( ! is_string( $filePath ) || ! file_exists( $filePath ) ) {
            return;
        }

        $mime = (string) get_post_mime_type( $id );
        if ( ! str_starts_with( $mime, 'image/' ) || ! extension_loaded( 'gd' ) ) {
            return;
        }

        $dHash = $this->computeDHash( $filePath, $mime );
        if ( null === $dHash ) {
            return;
        }

        update_post_meta( $id, self::META_DHASH, $dHash );

        // Cache similar IDs in meta so the UI can show a count immediately.
        $similar = $this->findSimilarToId( $id );
        if ( ! empty( $similar ) ) {
            update_post_meta( $id, '_mdpai_similar_ids', $similar );
        } else {
            delete_post_meta( $id, '_mdpai_similar_ids' );
        }
    }

    /**
     * Return attachment IDs that are visually similar to $id.
     *
     * Uses the `_mdpai_dhash` meta index built by processUploadCron() and
     * runChunk()/runFullScanSync() for O(n) comparison without a full rescan.
     *
     * @param  int   $id  The attachment to compare against.
     * @return int[]      IDs of similar attachments (empty on failure / no GD).
     */
    public function findSimilarToId( int $id ): array {
        // Ensure we have a hash for this attachment.
        $myHash = (string) get_post_meta( $id, self::META_DHASH, true );

        if ( '' === $myHash ) {
            // Fall back to on-the-fly computation if not yet indexed.
            $filePath = get_attached_file( $id );
            $mime     = (string) get_post_mime_type( $id );

            if (
                is_string( $filePath )
                && file_exists( $filePath )
                && str_starts_with( $mime, 'image/' )
                && extension_loaded( 'gd' )
            ) {
                $computed = $this->computeDHash( $filePath, $mime );
                if ( null === $computed ) {
                    return [];
                }
                $myHash = $computed;
                update_post_meta( $id, self::META_DHASH, $myHash );
            } else {
                return [];
            }
        }

        // Query all indexed dHashes except this attachment.
        global $wpdb;
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT post_id, meta_value
                   FROM {$wpdb->postmeta}
                  WHERE meta_key = %s
                    AND post_id  != %d",
                self::META_DHASH,
                $id
            ),
            ARRAY_A
        );

        /** @see mdpai_duplicate_threshold */
        $pHashThreshold = max( 0, (int) apply_filters( 'mdpai_duplicate_threshold', self::PHASH_THRESHOLD ) );

        $similar = [];
        foreach ( $rows as $row ) {
            $distance = $this->hammingDistance( $myHash, (string) $row['meta_value'] );
            if ( $distance <= $pHashThreshold ) {
                $similar[] = (int) $row['post_id'];
            }
        }

        return $similar;
    }

    // -------------------------------------------------------------------------
    // Attachment panel hooks (S50)
    // -------------------------------------------------------------------------

    /**
     * Inject the similar-image count into the attachment JS object so the
     * media modal can display it without an extra REST call.
     *
     * @param  array    $data  Existing JS data for the attachment.
     * @param  \WP_Post $post  The attachment post.
     * @return array
     */
    public function addSimilarCountToJs( array $data, \WP_Post $post ): array {
        if ( ! str_starts_with( (string) $post->post_mime_type, 'image/' ) ) {
            return $data;
        }

        $similar = get_post_meta( $post->ID, '_mdpai_similar_ids', true );
        $data['mdpai_similar_count'] = is_array( $similar ) ? count( $similar ) : 0;

        return $data;
    }

    /**
     * Append a "Find Similar" button to the attachment edit fields panel.
     *
     * @param  array    $fields  Existing form fields.
     * @param  \WP_Post $post    The attachment post.
     * @return array
     */
    public function addFindSimilarField( array $fields, \WP_Post $post ): array {
        if ( ! str_starts_with( (string) $post->post_mime_type, 'image/' ) ) {
            return $fields;
        }

        $id      = (int) $post->ID;
        $similar = get_post_meta( $id, '_mdpai_similar_ids', true );
        $count   = is_array( $similar ) ? count( $similar ) : 0;

        $btnLabel = $count > 0
            ? sprintf( /* translators: %d: number of similar images found */ _n( 'Find Similar (%d found)', 'Find Similar (%d found)', $count, 'mediapilot-ai'), $count )
            : __( 'Find Similar Images', 'mediapilot-ai');

        $html = sprintf(
            '<div class="mediapilot-find-similar-wrap">
                <button type="button"
                        class="button mediapilot-find-similar-btn"
                        data-id="%d"
                        style="margin-bottom:6px">%s</button>
                <div class="mediapilot-similar-results" data-id="%d"></div>
            </div>',
            $id,
            esc_html( $btnLabel ),
            $id
        );

        $fields['mdpai_find_similar'] = [
            'label'         => __( 'Visual Duplicates', 'mediapilot-ai'),
            'input'         => 'html',
            'html'          => $html,
            'show_in_edit'  => true,
            'show_in_modal' => true,
        ];

        return $fields;
    }

    /**
     * Output the JS handler for the "Find Similar" button once per admin page.
     */
    public function printFindSimilarScript(): void {
        // Only print on screens that have the attachment panel.
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if (
            $screen
            && ! in_array( $screen->id, [ 'upload', 'attachment' ], true )
            && ! str_contains( (string) $screen->id, 'mediapilot-ai')
        ) {
            return;
        }
        wp_register_script( 'mediapilot-find-similar', false, [], MDPAI_VERSION, true );
        wp_enqueue_script( 'mediapilot-find-similar' );

        // Buffer is PURE JS (no <script> wrapper): wp_add_inline_script() adds
        // its own wrapper, so an inline <script>/</script> here would break it.
        ob_start();
        ?>
(function () {
    var REST_BASE = window.MediaPilotConfig && window.MediaPilotConfig.restBase
        ? window.MediaPilotConfig.restBase
        : '<?php echo esc_js( rest_url( 'mediapilot/v1/' ) ); ?>';
    var NONCE = window.MediaPilotConfig && window.MediaPilotConfig.nonce
        ? window.MediaPilotConfig.nonce
        : '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.mediapilot-find-similar-btn');
        if (!btn) return;

        var id        = btn.dataset.id;
        var container = document.querySelector('.mediapilot-similar-results[data-id="' + id + '"]');
        if (!container) return;

        btn.disabled    = true;
        btn.textContent = '<?php echo esc_js( __( 'Searching…', 'mediapilot-ai') ); ?>';

        fetch(REST_BASE + 'files/similar/' + id, {
            headers: { 'X-WP-Nonce': NONCE }
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success || !res.data || !res.data.similar.length) {
                container.innerHTML = '<p style="color:#666;font-size:12px;margin:4px 0"><?php echo esc_js( __( 'No visually similar images found.', 'mediapilot-ai') ); ?></p>';
                btn.textContent = '<?php echo esc_js( __( 'Find Similar Images', 'mediapilot-ai') ); ?>';
                btn.disabled    = false;
                return;
            }
            var files = res.data.similar;
            var html  = '<p style="font-size:11px;color:#555;margin:4px 0 6px">' + files.length + ' <?php echo esc_js( __( 'similar image(s):', 'mediapilot-ai') ); ?></p>';
            html += files.map(function (f) {
                return '<a href="' + f.url + '" target="_blank" title="' + f.filename + '" style="display:inline-block;margin:2px">'
                     + '<img src="' + f.thumbnail_url + '" width="60" height="60"'
                     + ' style="object-fit:cover;border-radius:3px;border:1px solid #ddd;vertical-align:top">'
                     + '</a>';
            }).join('');
            container.innerHTML = html;
            btn.textContent = '<?php echo esc_js( __( 'Refresh', 'mediapilot-ai') ); ?>';
            btn.disabled    = false;
        })
        .catch(function () {
            container.innerHTML = '<p style="color:#c00;font-size:12px;margin:4px 0"><?php echo esc_js( __( 'Error loading results.', 'mediapilot-ai') ); ?></p>';
            btn.textContent = '<?php echo esc_js( __( 'Find Similar Images', 'mediapilot-ai') ); ?>';
            btn.disabled    = false;
        });
    });
}());
        <?php
        wp_add_inline_script( 'mediapilot-find-similar', (string) ob_get_clean() );
    }

    /**
     * Count differing bits between two equal-length hex strings.
     *
     * @param  string $a  16-char hex string.
     * @param  string $b  16-char hex string.
     * @return int  Number of bits that differ (0 = identical, 64 = opposite).
     */
    private function hammingDistance( string $a, string $b ): int {
        $xorHex = str_pad( dechex( hexdec( $a ) ^ hexdec( $b ) ), 16, '0', STR_PAD_LEFT );
        $bits   = 0;

        for ( $i = 0; $i < strlen( $xorHex ); $i++ ) {
            $nibble = hexdec( $xorHex[$i] );
            while ( $nibble ) {
                $bits   += $nibble & 1;
                $nibble >>= 1;
            }
        }

        return $bits;
    }
}
