<?php

declare(strict_types=1);

namespace MediaPilotAI\Search;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderRepository;
use MediaPilotAI\Taxonomy\FolderTaxonomy;
use MediaPilotAI\Search\ImageSearchService;

/**
 * Advanced Search Service (S44).
 *
 * Translates a set of filter parameters into a WP_Query, post-filters for
 * EXIF criteria (which cannot be queried via WP_Query), and enriches every
 * result with the folder it belongs to.
 *
 * Also manages per-user saved search filters stored in user meta.
 *
 * Supported parameters (all optional):
 *   q             string   Title / filename text search.
 *   folder        int      Folder term ID (-1 = all, 0 = uncategorised).
 *   type          string   Comma-separated MIME groups: image, pdf, video, audio, document, archive.
 *   date_from     string   Y-m-d  — only files uploaded on or after this date.
 *   date_to       string   Y-m-d  — only files uploaded on or before this date.
 *   size_min      int      Minimum file size in bytes.
 *   size_max      int      Maximum file size in bytes.
 *   missing_alt   bool     true = only image files with an empty ALT text.
 *   used          string   "true" = has post_parent > 0 | "false" = post_parent = 0.
 *   camera        string   EXIF camera model (substring match, post-filter).
 *   date_taken_from string Y-m-d EXIF date taken range start (post-filter).
 *   date_taken_to   string Y-m-d EXIF date taken range end (post-filter).
 *   color         string   Dominant colour hex (post-filter via ImageSearchService).
 *   orientation   string   landscape | portrait | square (meta_query).
 *   iso           string   EXIF ISO value (post-filter substring match).
 *   aperture      string   EXIF aperture (post-filter substring match).
 *   focal_length  string   EXIF focal length (post-filter substring match).
 *   page          int      Pagination page (default 1).
 *   per_page      int      Results per page, 1–100 (default 40).
 *
 * @package MediaPilotAI\Search
 * @since   1.0.0
 */
class AdvancedSearchService {

    private const USER_META_KEY = 'mdpai_saved_searches';
    private const MAX_PER_PAGE  = 100;

    // MIME type groups for the `type` parameter.
    private const MIME_MAP = [
        'image'    => 'image',
        'video'    => 'video',
        'audio'    => 'audio',
        'pdf'      => 'application/pdf',
        'document' => [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.oasis.opendocument.text',
            'application/rtf',
            'text/plain',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.oasis.opendocument.spreadsheet',
            'text/csv',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ],
        'archive'  => [
            'application/zip',
            'application/x-rar-compressed',
            'application/x-7z-compressed',
            'application/x-tar',
            'application/gzip',
        ],
    ];

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct(
        private readonly FolderRepository    $folderRepository,
        private readonly ?ImageSearchService $imageSearchService = null,
    ) {}

    // -------------------------------------------------------------------------
    // Search
    // -------------------------------------------------------------------------

    /**
     * Run the advanced search and return a paginated result set.
     *
     * @param  array<string, mixed> $params  Filter parameters (see class docblock).
     * @return array{
     *   files: array<int, array<string, mixed>>,
     *   total: int,
     *   pages: int,
     *   current_page: int,
     * }
     */
    public function search( array $params ): array {
        $page    = max( 1, (int) ( $params['page']     ?? 1 ) );
        $perPage = min( self::MAX_PER_PAGE, max( 1, (int) ( $params['per_page'] ?? 40 ) ) );

        $queryArgs = $this->buildQueryArgs( $params, $page, $perPage );
        $query     = new \WP_Query( $queryArgs );

        $files = [];
        foreach ( $query->posts as $post ) {
            if ( ! ( $post instanceof \WP_Post ) ) {
                continue;
            }
            $files[] = $this->formatFile( $post );
        }

        // Post-filter: EXIF camera / date_taken (cannot be queried via WP_Query).
        $camera          = trim( (string) ( $params['camera']          ?? '' ) );
        $dateTakenFrom   = trim( (string) ( $params['date_taken_from'] ?? '' ) );
        $dateTakenTo     = trim( (string) ( $params['date_taken_to']   ?? '' ) );
        $iso             = trim( (string) ( $params['iso']             ?? '' ) );
        $aperture        = trim( (string) ( $params['aperture']        ?? '' ) );
        $focalLength     = trim( (string) ( $params['focal_length']    ?? '' ) );

        if ( '' !== $camera || '' !== $dateTakenFrom || '' !== $dateTakenTo
            || '' !== $iso || '' !== $aperture || '' !== $focalLength
        ) {
            $files = $this->filterByExif( $files, $camera, $dateTakenFrom, $dateTakenTo, $iso, $aperture, $focalLength );
        }

        // Post-filter: dominant colour (Euclidean RGB distance).
        $color = trim( (string) ( $params['color'] ?? '' ) );
        if ( '' !== $color && $this->imageSearchService !== null ) {
            $matchingIds = $this->imageSearchService->findByColor( $color );
            if ( ! empty( $matchingIds ) ) {
                $files = array_values( array_filter( $files, static fn( $f ) => in_array( $f['id'], $matchingIds, true ) ) );
            } else {
                $files = [];
            }
        }

        return [
            'files'        => $files,
            'total'        => (int) $query->found_posts,
            'pages'        => (int) $query->max_num_pages,
            'current_page' => $page,
        ];
    }

    // -------------------------------------------------------------------------
    // Saved filters
    // -------------------------------------------------------------------------

    /**
     * Return all saved search filters for the current user.
     *
     * @param  int $userId
     * @return array<int, array<string, mixed>>
     */
    public function listFilters( int $userId ): array {
        $raw = get_user_meta( $userId, self::USER_META_KEY, true );
        return is_array( $raw ) ? $raw : [];
    }

    /**
     * Save a new named search filter.
     *
     * @param  int                  $userId
     * @param  string               $name    Display name for the saved filter.
     * @param  array<string, mixed> $params  Search parameters to save.
     * @return int  New filter ID.
     */
    public function saveFilter( int $userId, string $name, array $params ): int {
        $filters = $this->listFilters( $userId );

        // Auto-incrementing integer ID based on the max existing.
        $maxId = 0;
        foreach ( $filters as $f ) {
            $maxId = max( $maxId, (int) ( $f['id'] ?? 0 ) );
        }
        $newId = $maxId + 1;

        $filters[] = [
            'id'     => $newId,
            'name'   => sanitize_text_field( $name ),
            'params' => $params,
        ];

        update_user_meta( $userId, self::USER_META_KEY, $filters );

        return $newId;
    }

    /**
     * Delete a saved filter by ID.
     *
     * @param  int $userId
     * @param  int $filterId
     * @return bool  True if filter was found and deleted.
     */
    public function deleteFilter( int $userId, int $filterId ): bool {
        $filters = $this->listFilters( $userId );
        $before  = count( $filters );

        $filters = array_values(
            array_filter( $filters, static fn( $f ) => (int) ( $f['id'] ?? 0 ) !== $filterId )
        );

        if ( count( $filters ) === $before ) {
            return false;
        }

        update_user_meta( $userId, self::USER_META_KEY, $filters );
        return true;
    }

    // -------------------------------------------------------------------------
    // WP_Query builder
    // -------------------------------------------------------------------------

    /**
     * Translate filter params into WP_Query args.
     *
     * @param  array<string, mixed> $params
     * @param  int                  $page
     * @param  int                  $perPage
     * @return array<string, mixed>
     */
    private function buildQueryArgs( array $params, int $page, int $perPage ): array {
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $perPage,
            'paged'          => $page,
            'orderby'        => 'post_date',
            'order'          => 'DESC',
        ];

        // ── Post ID restriction (used by SmartSearchService for AI results) ──
        $postIn = isset( $params['post__in'] ) && is_array( $params['post__in'] )
            ? array_map( 'intval', $params['post__in'] )
            : [];

        if ( ! empty( $postIn ) ) {
            $args['post__in'] = $postIn;
        }

        // ── Text search ──────────────────────────────────────────────────────
        $q = trim( (string) ( $params['q'] ?? '' ) );
        if ( '' !== $q ) {
            $args['s'] = $q;
        }

        // ── Folder filter ────────────────────────────────────────────────────
        $folderId = isset( $params['folder'] ) ? (int) $params['folder'] : -1;
        if ( $folderId > 0 ) {
            $args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                [
                    'taxonomy' => FolderTaxonomy::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => [ $folderId ],
                    'operator' => 'IN',
                ],
            ];
        } elseif ( $folderId === 0 ) {
            $args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                [
                    'taxonomy' => FolderTaxonomy::TAXONOMY,
                    'operator' => 'NOT EXISTS',
                ],
            ];
        }
        // -1 = no folder filter

        // ── File type ────────────────────────────────────────────────────────
        $typeRaw = trim( (string) ( $params['type'] ?? '' ) );
        if ( '' !== $typeRaw ) {
            $args['post_mime_type'] = $this->resolveTypes( $typeRaw );
        }

        // ── Date range ───────────────────────────────────────────────────────
        $dateFrom = trim( (string) ( $params['date_from'] ?? '' ) );
        $dateTo   = trim( (string) ( $params['date_to']   ?? '' ) );

        if ( '' !== $dateFrom || '' !== $dateTo ) {
            $dateQuery = [ 'inclusive' => true ];
            if ( '' !== $dateFrom ) {
                $dateQuery['after'] = $dateFrom;
            }
            if ( '' !== $dateTo ) {
                $dateQuery['before'] = $dateTo;
            }
            $args['date_query'] = [ $dateQuery ];
        }

        // ── Size range ───────────────────────────────────────────────────────
        $sizeMin = isset( $params['size_min'] ) ? (int) $params['size_min'] : 0;
        $sizeMax = isset( $params['size_max'] ) ? (int) $params['size_max'] : 0;

        $metaQuery = [];

        if ( $sizeMin > 0 ) {
            $metaQuery[] = [
                'key'     => '_wp_attachment_filesize',
                'value'   => $sizeMin,
                'compare' => '>=',
                'type'    => 'NUMERIC',
            ];
        }

        if ( $sizeMax > 0 ) {
            $metaQuery[] = [
                'key'     => '_wp_attachment_filesize',
                'value'   => $sizeMax,
                'compare' => '<=',
                'type'    => 'NUMERIC',
            ];
        }

        // ── Missing ALT ──────────────────────────────────────────────────────
        $missingAlt = filter_var( $params['missing_alt'] ?? false, FILTER_VALIDATE_BOOLEAN );
        if ( $missingAlt ) {
            $metaQuery[] = [
                'relation' => 'OR',
                [
                    'key'     => '_wp_attachment_image_alt',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => '_wp_attachment_image_alt',
                    'value'   => '',
                    'compare' => '=',
                ],
            ];
        }

        // ── Orientation ──────────────────────────────────────────────────────
        $orientation = trim( (string) ( $params['orientation'] ?? '' ) );
        if ( in_array( $orientation, [ 'landscape', 'portrait', 'square' ], true ) ) {
            $metaQuery[] = [
                'key'     => ImageSearchService::META_ORIENT,
                'value'   => $orientation,
                'compare' => '=',
            ];
        }

        if ( ! empty( $metaQuery ) ) {
            $metaQuery['relation'] = 'AND';
            $args['meta_query']    = $metaQuery; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        }

        // ── Used / Unused ────────────────────────────────────────────────────
        $used = trim( (string) ( $params['used'] ?? '' ) );
        if ( 'true' === $used ) {
            $args['post_parent__not_in'] = [ 0 ];
        } elseif ( 'false' === $used ) {
            $args['post_parent'] = 0;
        }

        return $args;
    }

    // -------------------------------------------------------------------------
    // EXIF post-filter
    // -------------------------------------------------------------------------

    /**
     * Filter a file result set by EXIF camera, date-taken, ISO, aperture, and focal length.
     *
     * Operates on the in-memory result set from WP_Query. Stored-meta values
     * (mdpai_exif_iso etc.) are checked first; if absent, falls back to
     * `_wp_attachment_metadata['image_meta']`.
     *
     * @param  array<int, array<string, mixed>> $files
     * @param  string                           $camera
     * @param  string                           $dateTakenFrom  Y-m-d
     * @param  string                           $dateTakenTo    Y-m-d
     * @param  string                           $iso
     * @param  string                           $aperture
     * @param  string                           $focalLength
     * @return array<int, array<string, mixed>>
     */
    private function filterByExif(
        array  $files,
        string $camera,
        string $dateTakenFrom,
        string $dateTakenTo,
        string $iso         = '',
        string $aperture    = '',
        string $focalLength = '',
    ): array {
        $fromTs = '' !== $dateTakenFrom ? strtotime( $dateTakenFrom . ' 00:00:00' ) : 0;
        $toTs   = '' !== $dateTakenTo   ? strtotime( $dateTakenTo   . ' 23:59:59' ) : PHP_INT_MAX;

        return array_values( array_filter( $files, function ( array $file ) use ( $camera, $fromTs, $toTs, $iso, $aperture, $focalLength ): bool {
            $meta     = wp_get_attachment_metadata( $file['id'] );
            $exif     = is_array( $meta ) && isset( $meta['image_meta'] ) ? (array) $meta['image_meta'] : [];
            $camValue = strtolower( (string) ( $exif['camera'] ?? '' ) );
            $takenTs  = isset( $exif['created_timestamp'] ) ? (int) $exif['created_timestamp'] : 0;

            // Camera substring match.
            if ( '' !== $camera && ! str_contains( $camValue, strtolower( $camera ) ) ) {
                return false;
            }

            // Date-taken range.
            if ( $takenTs > 0 && ( $takenTs < $fromTs || $takenTs > $toTs ) ) {
                return false;
            }

            // ISO — prefer stored meta, fall back to image_meta.
            if ( '' !== $iso ) {
                $storedIso = (string) get_post_meta( $file['id'], ImageSearchService::META_ISO, true );
                $isoValue  = $storedIso !== '' ? $storedIso : (string) ( $exif['iso'] ?? '' );
                if ( ! str_contains( $isoValue, $iso ) ) {
                    return false;
                }
            }

            // Aperture.
            if ( '' !== $aperture ) {
                $storedAp  = (string) get_post_meta( $file['id'], ImageSearchService::META_APERTURE, true );
                $apValue   = $storedAp !== '' ? $storedAp : (string) ( $exif['aperture'] ?? '' );
                if ( ! str_contains( $apValue, $aperture ) ) {
                    return false;
                }
            }

            // Focal length.
            if ( '' !== $focalLength ) {
                $storedFl  = (string) get_post_meta( $file['id'], ImageSearchService::META_FOCAL_LEN, true );
                $flValue   = $storedFl !== '' ? $storedFl : (string) ( $exif['focal_length'] ?? '' );
                if ( ! str_contains( $flValue, $focalLength ) ) {
                    return false;
                }
            }

            return true;
        } ) );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve comma-separated type group names to an array of MIME types
     * suitable for the `post_mime_type` WP_Query arg.
     *
     * @param  string $raw  e.g. "image,pdf,document"
     * @return string[]
     */
    private function resolveTypes( string $raw ): array {
        $mimes = [];
        foreach ( array_map( 'trim', explode( ',', strtolower( $raw ) ) ) as $type ) {
            $mapped = self::MIME_MAP[ $type ] ?? null;
            if ( null === $mapped ) {
                continue;
            }
            if ( is_array( $mapped ) ) {
                array_push( $mimes, ...$mapped );
            } else {
                $mimes[] = $mapped;
            }
        }
        return array_values( array_unique( $mimes ) );
    }

    /**
     * Format a WP_Post attachment for the API response, including folder info.
     *
     * @param  \WP_Post $post
     * @return array<string, mixed>
     */
    private function formatFile( \WP_Post $post ): array {
        $url      = (string) wp_get_attachment_url( $post->ID );
        $filePath = (string) ( get_attached_file( $post->ID ) ?: '' );
        $meta     = wp_get_attachment_metadata( $post->ID );
        $fileSize = is_array( $meta ) && isset( $meta['filesize'] )
            ? (int) $meta['filesize']
            : (int) get_post_meta( $post->ID, '_wp_attachment_filesize', true );

        // Resolve folder.
        $terms    = wp_get_object_terms( $post->ID, FolderTaxonomy::TAXONOMY, [ 'fields' => 'all', 'number' => 1 ] );
        $folderId   = 0;
        $folderName = '';
        if ( ! is_wp_error( $terms ) && ! empty( $terms ) && $terms[0] instanceof \WP_Term ) {
            $folderId   = (int) $terms[0]->term_id;
            $folderName = $terms[0]->name;
        }

        $mime = (string) $post->post_mime_type;
        $alt  = (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true );

        // EXIF camera (for display in results).
        $exifCamera = '';
        if ( str_starts_with( $mime, 'image/' ) && is_array( $meta ) && isset( $meta['image_meta']['camera'] ) ) {
            $exifCamera = (string) $meta['image_meta']['camera'];
        }

        // Dominant colour and orientation (S58 indexed meta).
        $dominantColor = (string) get_post_meta( $post->ID, ImageSearchService::META_COLOR, true );
        $orientation   = (string) get_post_meta( $post->ID, ImageSearchService::META_ORIENT, true );

        return [
            'id'             => $post->ID,
            'title'          => $post->post_title,
            'filename'       => basename( $filePath ?: $url ),
            'url'            => $url,
            'mime_type'      => $mime,
            'size'           => $fileSize,
            'size_human'     => $fileSize > 0 ? size_format( $fileSize ) : '—',
            'date'           => get_the_date( 'Y-m-d', $post->ID ),
            'alt_text'       => $alt,
            'used'           => (int) $post->post_parent > 0,
            'folder_id'      => $folderId,
            'folder_name'    => $folderName,
            'camera'         => $exifCamera,
            'dominant_color' => $dominantColor,
            'orientation'    => $orientation,
        ];
    }
}
