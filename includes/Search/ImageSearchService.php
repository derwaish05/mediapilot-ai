<?php

declare(strict_types=1);

namespace MediaPilotAI\Search;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Image Search Service (S58).
 *
 * Extracts and indexes per-attachment image properties that can be used
 * as search filters but are not stored in standard WordPress meta:
 *
 *   - Dominant colour  → mdpai_dominant_color (6-char hex, e.g. "3498db")
 *   - Orientation      → mdpai_orientation    (landscape | portrait | square)
 *   - ISO              → mdpai_exif_iso       (integer string from image_meta)
 *   - Aperture         → mdpai_exif_aperture  (float string, e.g. "2.8")
 *   - Focal length     → mdpai_exif_focal_len (float string, e.g. "50")
 *
 * These values are stored in post meta on upload (via cron) so that
 * WP_Query meta_query clauses or a post-filter can use them.
 *
 * @package MediaPilotAI\Search
 * @since   1.0.0
 */
class ImageSearchService {

    public const META_COLOR     = 'mdpai_dominant_color';
    public const META_ORIENT    = 'mdpai_orientation';
    public const META_ISO       = 'mdpai_exif_iso';
    public const META_APERTURE  = 'mdpai_exif_aperture';
    public const META_FOCAL_LEN = 'mdpai_exif_focal_len';

    private const CRON_HOOK = 'mdpai_image_index_upload';

    /** Euclidean distance threshold for colour matching (0–441). */
    private const COLOR_DISTANCE_THRESHOLD = 60;

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function register(): void {
        add_action( 'add_attachment',      [ $this, 'onUpload'      ] );
        add_action( self::CRON_HOOK,       [ $this, 'processCron'   ] );
    }

    // -------------------------------------------------------------------------
    // Upload hook
    // -------------------------------------------------------------------------

    public function onUpload( int $id ): void {
        $mime = (string) get_post_mime_type( $id );

        if ( ! str_starts_with( $mime, 'image/' ) ) {
            return;
        }

        wp_schedule_single_event( time(), self::CRON_HOOK, [ $id ] );
        spawn_cron();
    }

    // -------------------------------------------------------------------------
    // Cron handler
    // -------------------------------------------------------------------------

    public function processCron( int $id ): void {
        $this->indexAttachment( $id );
    }

    // -------------------------------------------------------------------------
    // Public indexer — also callable directly for batch / CLI use
    // -------------------------------------------------------------------------

    /**
     * Extract and store all image-search meta for a single attachment.
     *
     * @param  int $id  Attachment post ID.
     * @return bool     True if indexing succeeded (file existed and is readable).
     */
    public function indexAttachment( int $id ): bool {
        $path = (string) get_attached_file( $id );

        if ( ! $path || ! is_readable( $path ) ) {
            return false;
        }

        $mime = (string) get_post_mime_type( $id );

        // --- Dominant colour --------------------------------------------------
        $hex = $this->extractDominantColor( $path, $mime );
        if ( $hex !== null ) {
            update_post_meta( $id, self::META_COLOR, $hex );
        }

        // --- Orientation ------------------------------------------------------
        $meta = wp_get_attachment_metadata( $id );
        if ( is_array( $meta ) && isset( $meta['width'], $meta['height'] ) ) {
            $w = (int) $meta['width'];
            $h = (int) $meta['height'];
            if ( $w > 0 && $h > 0 ) {
                $orientation = match( true ) {
                    $w > $h  => 'landscape',
                    $h > $w  => 'portrait',
                    default  => 'square',
                };
                update_post_meta( $id, self::META_ORIENT, $orientation );
            }
        }

        // --- EXIF (iso / aperture / focal_length) ----------------------------
        if ( is_array( $meta ) && isset( $meta['image_meta'] ) ) {
            $exif = (array) $meta['image_meta'];

            if ( ! empty( $exif['iso'] ) ) {
                update_post_meta( $id, self::META_ISO, (string) $exif['iso'] );
            }

            if ( ! empty( $exif['aperture'] ) ) {
                update_post_meta( $id, self::META_APERTURE, (string) $exif['aperture'] );
            }

            if ( ! empty( $exif['focal_length'] ) ) {
                update_post_meta( $id, self::META_FOCAL_LEN, (string) $exif['focal_length'] );
            }
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Colour search
    // -------------------------------------------------------------------------

    /**
     * Return attachment IDs whose dominant colour is within the Euclidean
     * RGB distance threshold of the supplied hex colour.
     *
     * @param  string $hex  6-char hex string (without #), e.g. "3498db".
     * @return int[]
     */
    public function findByColor( string $hex ): array {
        $hex = ltrim( $hex, '#' );

        if ( strlen( $hex ) !== 6 || ! ctype_xdigit( $hex ) ) {
            return [];
        }

        [ $tr, $tg, $tb ] = $this->hexToRgb( $hex );

        global $wpdb;

        // Fetch all indexed colours from postmeta.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                  WHERE meta_key = %s AND meta_value != ''",
                self::META_COLOR
            ),
            ARRAY_A
        );

        $matching = [];

        foreach ( (array) $rows as $row ) {
            $storedHex = ltrim( (string) $row['meta_value'], '#' );

            if ( strlen( $storedHex ) !== 6 || ! ctype_xdigit( $storedHex ) ) {
                continue;
            }

            [ $r, $g, $b ] = $this->hexToRgb( $storedHex );

            $distance = sqrt(
                ( $r - $tr ) ** 2 +
                ( $g - $tg ) ** 2 +
                ( $b - $tb ) ** 2
            );

            if ( $distance <= self::COLOR_DISTANCE_THRESHOLD ) {
                $matching[] = (int) $row['post_id'];
            }
        }

        return $matching;
    }

    // -------------------------------------------------------------------------
    // Dominant colour extraction
    // -------------------------------------------------------------------------

    /**
     * Extract the dominant colour from an image file.
     *
     * Tries Imagick first (fastest), falls back to GD.
     * Returns a 6-char lowercase hex string or null on failure.
     *
     * @param  string $path  Absolute filesystem path.
     * @param  string $mime  MIME type (e.g. "image/jpeg").
     * @return string|null
     */
    public function extractDominantColor( string $path, string $mime ): ?string {
        // Imagick path — resize to 1×1 for instant dominant colour.
        if ( extension_loaded( 'imagick' ) ) {
            try {
                $img = new \Imagick( $path );
                $img->resizeImage( 1, 1, \Imagick::FILTER_LANCZOS, 1 );
                $pixel = $img->getImagePixelColor( 0, 0 );
                $c     = $pixel->getColor();
                $img->clear();
                $img->destroy();

                return sprintf( '%02x%02x%02x', $c['r'], $c['g'], $c['b'] );
            } catch ( \Exception $e ) {
                // Fall through to GD.
            }
        }

        // GD path.
        if ( extension_loaded( 'gd' ) ) {
            $gdImg = $this->gdCreateFromPath( $path, $mime );

            if ( $gdImg === null ) {
                return null;
            }

            $thumb = imagecreatetruecolor( 1, 1 );

            if ( $thumb === false ) {
                imagedestroy( $gdImg );
                return null;
            }

            imagecopyresampled( $thumb, $gdImg, 0, 0, 0, 0, 1, 1, (int) imagesx( $gdImg ), (int) imagesy( $gdImg ) );
            imagedestroy( $gdImg );

            $colorIndex = imagecolorat( $thumb, 0, 0 );
            imagedestroy( $thumb );

            if ( $colorIndex === false ) {
                return null;
            }

            $r = ( $colorIndex >> 16 ) & 0xFF;
            $g = ( $colorIndex >>  8 ) & 0xFF;
            $b =   $colorIndex         & 0xFF;

            return sprintf( '%02x%02x%02x', $r, $g, $b );
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Convert a 6-char hex string to [r, g, b] integers.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function hexToRgb( string $hex ): array {
        return [
            hexdec( substr( $hex, 0, 2 ) ),
            hexdec( substr( $hex, 2, 2 ) ),
            hexdec( substr( $hex, 4, 2 ) ),
        ];
    }

    /**
     * Create a GD image resource from a file path, honouring MIME type.
     *
     * @param  string $path
     * @param  string $mime
     * @return \GdImage|null
     */
    private function gdCreateFromPath( string $path, string $mime ): ?\GdImage {
        $gdImg = match( $mime ) {
            'image/jpeg' => @imagecreatefromjpeg( $path ),
            'image/png'  => @imagecreatefrompng( $path ),
            'image/gif'  => @imagecreatefromgif( $path ),
            'image/webp' => function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $path ) : false,
            'image/avif' => function_exists( 'imagecreatefromavif' ) ? @imagecreatefromavif( $path ) : false,
            default      => false,
        };

        return $gdImg instanceof \GdImage ? $gdImg : null;
    }
}
