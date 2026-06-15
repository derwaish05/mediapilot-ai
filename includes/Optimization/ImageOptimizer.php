<?php

declare(strict_types=1);

namespace MediaPilotAI\Optimization;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Taxonomy\FolderTaxonomy;

/**
 * Converts uploaded images to WebP / AVIF and tracks byte savings.
 *
 * On upload a WP Cron event is scheduled so the response is non-blocking.
 * The converted file is stored as a sidecar next to the original (e.g.
 * `photo.jpg` → `photo.webp`) and its path is recorded in attachment meta.
 * The original file is always preserved.
 *
 * Stats recorded per attachment:
 *   _mdpai_webp_path      — absolute path to the WebP sidecar (if created)
 *   _mdpai_avif_path      — absolute path to the AVIF sidecar (if created)
 *   _mdpai_opt_savings    — bytes saved vs. the original (int, can be 0 or negative)
 *   _mdpai_opt_format     — 'webp' | 'avif' | 'skipped' | 'error'
 *
 * Option key: mdpai_optimization_settings
 *
 * @package MediaPilotAI\Optimization
 * @since   1.0.0
 */
class ImageOptimizer {

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    public const OPTION_NAME   = 'mdpai_optimization_settings';
    public const CRON_HOOK     = 'mdpai_optimize_attachment';

    public const META_WEBP     = '_mdpai_webp_path';
    public const META_AVIF     = '_mdpai_avif_path';
    public const META_SAVINGS  = '_mdpai_opt_savings';
    public const META_FORMAT   = '_mdpai_opt_format';

    /** MIME types eligible for conversion. */
    private const CONVERTIBLE = [ 'image/jpeg', 'image/png', 'image/gif' ];

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    public function register(): void {
        add_action( self::CRON_HOOK, [ $this, 'processOptimizeCron' ] );
        add_action( 'add_attachment',  [ $this, 'onUpload' ] );
    }

    // -------------------------------------------------------------------------
    // Upload hook — non-blocking
    // -------------------------------------------------------------------------

    /**
     * Schedule optimisation for a newly uploaded image.
     * Skips non-image MIME types immediately to avoid unnecessary scheduling.
     */
    public function onUpload( int $id ): void {
        $mime = (string) get_post_mime_type( $id );
        if ( ! in_array( $mime, self::CONVERTIBLE, true ) ) {
            return;
        }

        $settings = $this->getSettings();
        if ( ! $settings['auto_webp'] && ! $settings['auto_avif'] ) {
            return;
        }

        wp_schedule_single_event( time(), self::CRON_HOOK, [ $id ] );
        spawn_cron();
    }

    /**
     * WP Cron callback: convert a single attachment to WebP and/or AVIF.
     */
    public function processOptimizeCron( int $id ): void {
        $settings = $this->getSettings();
        $this->optimizeAttachment( $id, $settings );
    }

    // -------------------------------------------------------------------------
    // Core optimisation
    // -------------------------------------------------------------------------

    /**
     * Convert one attachment to WebP / AVIF based on current settings.
     *
     * @param  int        $id       Attachment post ID.
     * @param  array|null $settings Pass-in to avoid re-fetching in batch loops.
     * @return array{format: string, original_size: int, new_size: int, savings: int}
     */
    public function optimizeAttachment( int $id, ?array $settings = null ): array {
        $settings ??= $this->getSettings();

        $filePath = get_attached_file( $id );
        if ( ! is_string( $filePath ) || ! file_exists( $filePath ) ) {
            update_post_meta( $id, self::META_FORMAT, 'skipped' );
            return [ 'format' => 'skipped', 'original_size' => 0, 'new_size' => 0, 'savings' => 0 ];
        }

        $mime = (string) get_post_mime_type( $id );
        if ( ! in_array( $mime, self::CONVERTIBLE, true ) ) {
            update_post_meta( $id, self::META_FORMAT, 'skipped' );
            return [ 'format' => 'skipped', 'original_size' => 0, 'new_size' => 0, 'savings' => 0 ];
        }

        $originalSize = (int) filesize( $filePath );
        $quality      = (int) $settings['quality'];
        $result       = [ 'format' => 'skipped', 'original_size' => $originalSize, 'new_size' => $originalSize, 'savings' => 0 ];

        // Try AVIF first (better compression) if enabled.
        if ( $settings['auto_avif'] ) {
            $avifPath = $this->convertToAvif( $filePath, $mime, $quality );
            if ( null !== $avifPath && file_exists( $avifPath ) ) {
                $avifSize = (int) filesize( $avifPath );
                $savings  = $originalSize - $avifSize;

                update_post_meta( $id, self::META_AVIF,    $avifPath );
                update_post_meta( $id, self::META_SAVINGS, max( 0, $savings ) );
                update_post_meta( $id, self::META_FORMAT,  'avif' );

                $result = [ 'format' => 'avif', 'original_size' => $originalSize, 'new_size' => $avifSize, 'savings' => $savings ];
            }
        }

        // WebP fallback (or primary if AVIF is disabled).
        if ( $settings['auto_webp'] && $result['format'] === 'skipped' ) {
            $webpPath = $this->convertToWebP( $filePath, $mime, $quality );
            if ( null !== $webpPath && file_exists( $webpPath ) ) {
                $webpSize = (int) filesize( $webpPath );
                $savings  = $originalSize - $webpSize;

                update_post_meta( $id, self::META_WEBP,    $webpPath );
                update_post_meta( $id, self::META_SAVINGS, max( 0, $savings ) );
                update_post_meta( $id, self::META_FORMAT,  'webp' );

                $result = [ 'format' => 'webp', 'original_size' => $originalSize, 'new_size' => $webpSize, 'savings' => $savings ];
            }
        }

        if ( $result['format'] === 'skipped' ) {
            update_post_meta( $id, self::META_FORMAT, 'error' );
            $result['format'] = 'error';
        }

        return $result;
    }

    /**
     * Batch-optimise all images (or a folder subset) via CLI / REST.
     *
     * @param  int    $folderId  0 = all folders.
     * @param  string $format    'webp' | 'avif' | 'auto'
     * @param  int    $limit     0 = no limit.
     * @return array{processed: int, converted: int, saved_bytes: int, errors: int}
     */
    public function optimizeAll( int $folderId = 0, string $format = 'auto', int $limit = 0 ): array {
        $settings = $this->getSettings();

        // Override format from CLI / REST request.
        if ( $format === 'webp' ) {
            $settings['auto_webp'] = true;
            $settings['auto_avif'] = false;
        } elseif ( $format === 'avif' ) {
            $settings['auto_webp'] = false;
            $settings['auto_avif'] = true;
        }

        $queryArgs = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => self::CONVERTIBLE,
            'posts_per_page' => $limit > 0 ? $limit : -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ];

        if ( $folderId > 0 ) {
            $queryArgs['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                [
                    'taxonomy' => FolderTaxonomy::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => $folderId,
                ],
            ];
        }

        $ids = ( new \WP_Query( $queryArgs ) )->posts;

        $processed  = 0;
        $converted  = 0;
        $savedBytes = 0;
        $errors     = 0;

        foreach ( $ids as $id ) {
            $id = (int) $id;
            $processed++;

            $result = $this->optimizeAttachment( $id, $settings );

            if ( in_array( $result['format'], [ 'webp', 'avif' ], true ) ) {
                $converted++;
                $savedBytes += max( 0, $result['savings'] );
            } elseif ( $result['format'] === 'error' ) {
                $errors++;
            }
        }

        return [
            'processed'   => $processed,
            'converted'   => $converted,
            'saved_bytes' => $savedBytes,
            'errors'      => $errors,
        ];
    }

    /**
     * Aggregate optimisation statistics across the entire media library.
     *
     * @return array{
     *   total: int,
     *   webp_count: int,
     *   avif_count: int,
     *   total_savings_bytes: int,
     *   conversion_rate: float
     * }
     */
    public function getStats(): array {
        global $wpdb;

        $total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_mdpai_opt_format'"
        );

        $webpCount = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = 'webp'",
                self::META_FORMAT
            )
        );

        $avifCount = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = 'avif'",
                self::META_FORMAT
            )
        );

        $totalSavings = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT SUM(CAST(meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} WHERE meta_key = %s",
                self::META_SAVINGS
            )
        );

        // Total images in the library (for rate calculation).
        $libraryTotal = (int) wp_count_posts( 'attachment' )->inherit;

        $converted = $webpCount + $avifCount;
        $rate      = $libraryTotal > 0 ? round( ( $converted / $libraryTotal ) * 100, 1 ) : 0.0;

        return [
            'total'               => $total,
            'webp_count'          => $webpCount,
            'avif_count'          => $avifCount,
            'total_savings_bytes' => $totalSavings,
            'conversion_rate'     => $rate,
        ];
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    public function getSettings(): array {
        return wp_parse_args(
            (array) get_option( self::OPTION_NAME, [] ),
            self::defaults()
        );
    }

    public static function defaults(): array {
        return [
            'auto_webp'    => true,
            'auto_avif'    => false,
            'quality'      => 82,
            'cdn_provider' => 'none',
            'cdn_base_url' => '',
            'lazy_load'    => true,
        ];
    }

    // -------------------------------------------------------------------------
    // Conversion helpers
    // -------------------------------------------------------------------------

    /**
     * Convert an image to WebP using Imagick (preferred) or GD.
     *
     * Returns the absolute path of the new file, or null on failure.
     */
    public function convertToWebP( string $sourcePath, string $mime, int $quality ): ?string {
        $destPath = $this->deriveDestPath( $sourcePath, 'webp' );

        // Imagick path.
        if ( extension_loaded( 'imagick' ) ) {
            try {
                $imagick = new \Imagick( $sourcePath );
                $imagick->setImageFormat( 'webp' );
                $imagick->setImageCompressionQuality( $quality );
                $imagick->stripImage();
                $imagick->writeImage( $destPath );
                $imagick->clear();
                return $destPath;
            } catch ( \Exception $e ) {
                // fall through to GD
            }
        }

        // GD path.
        if ( extension_loaded( 'gd' ) && function_exists( 'imagewebp' ) ) {
            $img = $this->gdCreateFromMime( $sourcePath, $mime );
            if ( null === $img ) {
                return null;
            }
            $ok = imagewebp( $img, $destPath, $quality );
            imagedestroy( $img );
            return $ok ? $destPath : null;
        }

        return null;
    }

    /**
     * Convert an image to AVIF using Imagick.
     * Returns null if Imagick is unavailable or does not support AVIF.
     */
    public function convertToAvif( string $sourcePath, string $mime, int $quality ): ?string {
        if ( ! extension_loaded( 'imagick' ) ) {
            return null;
        }

        $formats = \Imagick::queryFormats( 'AVIF' );
        if ( empty( $formats ) ) {
            return null;
        }

        $destPath = $this->deriveDestPath( $sourcePath, 'avif' );

        try {
            $imagick = new \Imagick( $sourcePath );
            $imagick->setImageFormat( 'avif' );
            $imagick->setImageCompressionQuality( $quality );
            $imagick->stripImage();
            $imagick->writeImage( $destPath );
            $imagick->clear();
            return $destPath;
        } catch ( \Exception $e ) {
            return null;
        }
    }

    /**
     * Derive the destination file path for a converted image.
     * e.g. /uploads/2024/01/photo.jpg → /uploads/2024/01/photo.webp
     */
    private function deriveDestPath( string $sourcePath, string $ext ): string {
        $info = pathinfo( $sourcePath );
        return $info['dirname'] . DIRECTORY_SEPARATOR . $info['filename'] . '.' . $ext;
    }

    /**
     * Create a GD image resource from a MIME type.
     *
     * @return \GdImage|null
     */
    private function gdCreateFromMime( string $path, string $mime ): ?\GdImage {
        $img = match ( $mime ) {
            'image/jpeg' => @imagecreatefromjpeg( $path ),
            'image/png'  => @imagecreatefrompng( $path ),
            'image/gif'  => @imagecreatefromgif( $path ),
            default      => false,
        };

        return ( false === $img || null === $img ) ? null : $img;
    }
}
