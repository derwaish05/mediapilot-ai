<?php

declare(strict_types=1);

namespace MediaPilotAI\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderRepository;
use MediaPilotAI\Frontend\DocumentLibrary;
use MediaPilotAI\Taxonomy\FolderTaxonomy;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST routes for the Document Library frontend (S41).
 *
 * Namespace: mediapilot/v1
 *
 * Routes:
 *   GET /documents/browse   Returns subfolders + paginated file list for a folder.
 *
 * Used by mediapilot-document-library.js for AJAX subfolder navigation and pagination
 * without a full page reload.
 *
 * @package MediaPilotAI\API
 * @since   1.0.0
 */
class DocumentRestController {

    private const NAMESPACE  = 'mediapilot/v1';
    private const MAX_PP     = 100;
    private const DEFAULT_PP = 20;

    public function __construct(
        private readonly FolderRepository $folderRepository,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function register(): void {
        register_rest_route(
            self::NAMESPACE,
            '/documents/browse',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'browse' ],
                'permission_callback' => [ $this, 'browsePermission' ],
                'args'                => [
                    'folder_id' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ],
                    'root_id' => [
                        'type'              => 'integer',
                        'default'           => 0,
                        'sanitize_callback' => 'absint',
                    ],
                    'page' => [
                        'type'              => 'integer',
                        'default'           => 1,
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                    'per_page' => [
                        'type'              => 'integer',
                        'default'           => self::DEFAULT_PP,
                        'minimum'           => 1,
                        'maximum'           => self::MAX_PP,
                        'sanitize_callback' => 'absint',
                    ],
                    'file_types' => [
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'show_subfolders' => [
                        'type'              => 'boolean',
                        'default'           => true,
                        'sanitize_callback' => 'rest_sanitize_boolean',
                    ],
                ],
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Permissions
    // -------------------------------------------------------------------------

    /**
     * Permission callback for GET /documents/browse.
     *
     * Logged-in users who can manage media may browse any folder. Anonymous
     * visitors may only browse folders inside a root that was explicitly
     * published via the [mdpai_documents] shortcode — this prevents
     * enumeration of the full (potentially private) folder tree.
     */
    public function browsePermission( WP_REST_Request $request ): bool {
        if ( current_user_can( 'upload_files' ) ) {
            return true;
        }

        $folderId = absint( $request->get_param( 'folder_id' ) );
        $rootId   = absint( $request->get_param( 'root_id' ) );

        if ( $folderId <= 0 || $rootId <= 0 ) {
            return false;
        }

        $publicRoots = array_map( 'intval', (array) get_option( DocumentLibrary::PUBLIC_ROOTS_OPTION, [] ) );
        if ( ! in_array( $rootId, $publicRoots, true ) ) {
            return false;
        }

        return $this->isWithinRoot( $folderId, $rootId );
    }

    /**
     * Whether $folderId equals $rootId or is one of its descendants.
     */
    private function isWithinRoot( int $folderId, int $rootId ): bool {
        if ( $folderId === $rootId ) {
            return true;
        }

        $ancestors = get_ancestors( $folderId, FolderTaxonomy::TAXONOMY, 'taxonomy' );

        return in_array( $rootId, array_map( 'intval', $ancestors ), true );
    }

    // -------------------------------------------------------------------------
    // Callback
    // -------------------------------------------------------------------------

    /**
     * GET /documents/browse
     *
     * Returns subfolders and a paginated file list for the requested folder.
     * Also returns the breadcrumb trail from `root_id` to `folder_id`.
     */
    public function browse( WP_REST_Request $request ): WP_REST_Response {
        $folderId       = (int)    $request->get_param( 'folder_id' );
        $rootId         = (int)    $request->get_param( 'root_id' );
        $page           = (int)    $request->get_param( 'page' );
        $perPage        = (int)    $request->get_param( 'per_page' );
        $fileTypesRaw   = (string) $request->get_param( 'file_types' );
        $showSubfolders = (bool)   $request->get_param( 'show_subfolders' );

        // Validate the requested folder exists.
        $folder = $this->folderRepository->getById( $folderId );
        if ( null === $folder ) {
            return new WP_REST_Response(
                [ 'success' => false, 'message' => __( 'Folder not found.', 'mediapilot-ai') ],
                404
            );
        }

        // Build MIME-type filter from comma-separated extensions, e.g. "pdf,doc,zip".
        $mimeFilter = $this->extensionsToMime( $fileTypesRaw );

        // Query files.
        $queryArgs = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $perPage,
            'paged'          => $page,
            'orderby'        => 'post_title',
            'order'          => 'ASC',
            'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                [
                    'taxonomy' => FolderTaxonomy::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => [ $folderId ],
                    'operator' => 'IN',
                ],
            ],
        ];

        if ( ! empty( $mimeFilter ) ) {
            $queryArgs['post_mime_type'] = $mimeFilter;
        }

        $query = new \WP_Query( $queryArgs );

        $files = [];
        foreach ( $query->posts as $post ) {
            if ( ! ( $post instanceof \WP_Post ) ) {
                continue;
            }
            $files[] = $this->formatFile( $post );
        }

        // Subfolders.
        $subfolders = [];
        if ( $showSubfolders ) {
            foreach ( $this->folderRepository->getChildren( $folderId ) as $child ) {
                $subfolders[] = [
                    'id'    => (int)    ( $child['id']    ?? 0 ),
                    'name'  => (string) ( $child['name']  ?? '' ),
                    'count' => (int)    ( $child['count'] ?? 0 ),
                ];
            }
        }

        // Breadcrumb: walk from folderId up to rootId.
        $breadcrumb = $this->buildBreadcrumb( $folderId, $rootId );

        return new WP_REST_Response(
            [
                'success' => true,
                'data'    => [
                    'folder_id'    => $folderId,
                    'subfolders'   => $subfolders,
                    'files'        => $files,
                    'breadcrumb'   => $breadcrumb,
                    'total'        => (int) $query->found_posts,
                    'pages'        => (int) $query->max_num_pages,
                    'current_page' => $page,
                ],
            ],
            200
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Format a single attachment post as the API shape.
     *
     * @param  \WP_Post $post
     * @return array<string, mixed>
     */
    private function formatFile( \WP_Post $post ): array {
        $url      = (string) wp_get_attachment_url( $post->ID );
        $meta     = wp_get_attachment_metadata( $post->ID );
        $fileSize = is_array( $meta ) && isset( $meta['filesize'] )
            ? (int) $meta['filesize']
            : (int) get_post_meta( $post->ID, '_wp_attachment_filesize', true );

        $mime = (string) $post->post_mime_type;
        $ext  = strtolower( pathinfo( get_attached_file( $post->ID ) ?: $post->post_title, PATHINFO_EXTENSION ) );

        return [
            'id'         => $post->ID,
            'name'       => $post->post_title ?: basename( $url ),
            'filename'   => basename( get_attached_file( $post->ID ) ?: $url ),
            'url'        => $url,
            'size'       => $fileSize,
            'size_human' => $fileSize > 0 ? size_format( $fileSize ) : '—',
            'date'       => get_the_date( 'Y-m-d', $post->ID ),
            'mime'       => $mime,
            'ext'        => $ext,
            'category'   => $this->mimeCategory( $mime, $ext ),
        ];
    }

    /**
     * Build the breadcrumb trail from the shortcode root folder to the current folder.
     *
     * Walks up the taxonomy parent chain until $rootId is reached or there is
     * no parent left. The resulting array is ordered root → current.
     *
     * @param  int $folderId  Current folder term ID.
     * @param  int $rootId    Shortcode anchor folder term ID (0 = no limit).
     * @return array<int, array{id:int, name:string}>
     */
    private function buildBreadcrumb( int $folderId, int $rootId ): array {
        $crumbs = [];
        $current = $folderId;

        while ( $current > 0 ) {
            $term = get_term( $current, FolderTaxonomy::TAXONOMY );
            if ( ! ( $term instanceof \WP_Term ) ) {
                break;
            }

            array_unshift( $crumbs, [
                'id'   => (int) $term->term_id,
                'name' => $term->name,
            ] );

            // Stop once we've included the root folder.
            if ( $rootId > 0 && $current === $rootId ) {
                break;
            }

            $current = (int) $term->parent;
        }

        return $crumbs;
    }

    /**
     * Convert a comma-separated list of file extensions to an array of MIME types
     * compatible with `post_mime_type` WP_Query arg.
     *
     * Empty input returns an empty array (no filter).
     *
     * @param  string $raw  e.g. "pdf,doc,zip"
     * @return string[]
     */
    private function extensionsToMime( string $raw ): array {
        if ( '' === trim( $raw ) ) {
            return [];
        }

        $exts = array_map( 'trim', explode( ',', strtolower( $raw ) ) );
        $mimes = [];

        foreach ( $exts as $ext ) {
            $type = $this->extToMime( $ext );
            if ( '' !== $type ) {
                $mimes[] = $type;
            }
        }

        return array_values( array_unique( $mimes ) );
    }

    /**
     * Map a single file extension to its primary MIME type.
     *
     * @param  string $ext
     * @return string  Empty string if unknown.
     */
    private function extToMime( string $ext ): string {
        $map = [
            // Documents
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'odt'  => 'application/vnd.oasis.opendocument.text',
            'rtf'  => 'application/rtf',
            'txt'  => 'text/plain',
            // Spreadsheets
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv'  => 'text/csv',
            'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
            // Presentations
            'ppt'  => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            // Archives
            'zip'  => 'application/zip',
            'rar'  => 'application/x-rar-compressed',
            '7z'   => 'application/x-7z-compressed',
            'tar'  => 'application/x-tar',
            'gz'   => 'application/gzip',
            // Images
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'svg'  => 'image/svg+xml',
            // Audio
            'mp3'  => 'audio/mpeg',
            'wav'  => 'audio/wav',
            'ogg'  => 'audio/ogg',
            // Video
            'mp4'  => 'video/mp4',
            'mov'  => 'video/quicktime',
            'avi'  => 'video/x-msvideo',
            'webm' => 'video/webm',
        ];

        return $map[ $ext ] ?? '';
    }

    /**
     * Return a simple category slug for a MIME type + extension pair.
     *
     * Used as a CSS class hook for the file icon.
     *
     * @param  string $mime
     * @param  string $ext
     * @return string  image|pdf|doc|sheet|ppt|archive|audio|video|text|file
     */
    private function mimeCategory( string $mime, string $ext ): string {
        if ( str_starts_with( $mime, 'image/' ) )                                        return 'image';
        if ( $mime === 'application/pdf' )                                               return 'pdf';
        if ( str_contains( $mime, 'word' ) || str_contains( $mime, 'document' )
            || in_array( $ext, [ 'doc', 'docx', 'odt', 'rtf' ], true ) )                return 'doc';
        if ( str_contains( $mime, 'sheet' ) || str_contains( $mime, 'excel' )
            || in_array( $ext, [ 'xls', 'xlsx', 'csv', 'ods' ], true ) )                return 'sheet';
        if ( str_contains( $mime, 'presentation' ) || str_contains( $mime, 'powerpoint' )
            || in_array( $ext, [ 'ppt', 'pptx' ], true ) )                              return 'ppt';
        if ( str_contains( $mime, 'zip' ) || str_contains( $mime, 'compressed' )
            || str_contains( $mime, 'archive' ) || str_contains( $mime, 'rar' )
            || str_contains( $mime, 'tar' )
            || in_array( $ext, [ 'zip', 'rar', '7z', 'tar', 'gz' ], true ) )           return 'archive';
        if ( str_starts_with( $mime, 'audio/' ) )                                       return 'audio';
        if ( str_starts_with( $mime, 'video/' ) )                                       return 'video';
        if ( str_starts_with( $mime, 'text/' ) )                                        return 'text';
        return 'file';
    }
}
