<?php

declare(strict_types=1);

namespace MediaPilotAI\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderRepository;
use MediaPilotAI\Taxonomy\FolderTaxonomy;

/**
 * Document Library shortcode (S41).
 *
 * Registers [mdpai_documents] and renders a browsable, paginated file list for
 * a given MediaPilot folder with support for nested subfolder navigation (AJAX, no
 * page reload) and configurable file-type filtering.
 *
 * Shortcode syntax:
 *   [mdpai_documents
 *       folder="42"
 *       show_subfolders="true"
 *       pagination="true"
 *       per_page="20"
 *       file_types="pdf,doc,zip"
 *   ]
 *
 * Parameters:
 *   folder          (int)    Root folder term ID — required; 0 renders nothing.
 *   show_subfolders (bool)   Show subfolder cards above the file list. Default true.
 *   pagination      (bool)   Show pagination controls. Default true.
 *   per_page        (int)    Files per page, 1–100. Default 20.
 *   file_types      (string) Comma-separated extensions to include, e.g. "pdf,doc,zip".
 *                            Empty = all types. Default empty.
 *
 * Frontend assets (CSS + JS) are enqueued lazily — only when the shortcode
 * is actually present on the rendered page.
 *
 * @package MediaPilotAI\Frontend
 * @since   1.0.0
 */
class DocumentLibrary {

    /**
     * Option holding the folder term IDs explicitly published as public
     * document-library roots via the [mdpai_documents] shortcode. The REST
     * browse endpoint only serves anonymous requests for folders inside
     * one of these roots.
     */
    public const PUBLIC_ROOTS_OPTION = 'mdpai_doclib_public_roots';

    private const SHORTCODE   = 'mdpai_documents';
    private const STYLE_HANDLE = 'mediapilot-document-library';
    private const SCRIPT_HANDLE = 'mediapilot-document-library';
    private const MAX_PER_PAGE  = 100;

    /** Incremented per shortcode instance to give each library a unique DOM id. */
    private int $instanceCount = 0;

    /** Whether assets have already been enqueued this request. */
    private bool $assetsEnqueued = false;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct(
        private readonly FolderRepository $folderRepository,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function register(): void {
        add_shortcode( self::SHORTCODE, [ $this, 'renderShortcode' ] );
    }

    // -------------------------------------------------------------------------
    // Shortcode callback
    // -------------------------------------------------------------------------

    /**
     * Process [mdpai_documents] and return the initial HTML for the library.
     *
     * @param  array<string, mixed>|string $attrs
     * @return string  Safe HTML.
     */
    public function renderShortcode( array|string $attrs ): string {
        $attrs = shortcode_atts(
            [
                'folder'          => 0,
                'show_subfolders' => 'true',
                'pagination'      => 'true',
                'per_page'        => 20,
                'file_types'      => '',
            ],
            is_array( $attrs ) ? $attrs : [],
            self::SHORTCODE
        );

        $folderId       = absint( $attrs['folder'] );
        $showSubfolders = filter_var( $attrs['show_subfolders'], FILTER_VALIDATE_BOOLEAN );
        $pagination     = filter_var( $attrs['pagination'],      FILTER_VALIDATE_BOOLEAN );
        $perPage        = max( 1, min( self::MAX_PER_PAGE, (int) $attrs['per_page'] ) );
        $fileTypes      = sanitize_text_field( (string) $attrs['file_types'] );

        if ( $folderId <= 0 ) {
            return '<p class="mediapilot-doclib-notice">'
                . esc_html__( 'Document library: no folder specified.', 'mediapilot-ai')
                . '</p>';
        }

        $folder = $this->folderRepository->getById( $folderId );
        if ( null === $folder ) {
            return '<p class="mediapilot-doclib-notice">'
                . esc_html__( 'Document library: folder not found.', 'mediapilot-ai')
                . '</p>';
        }

        // Record this folder as a publicly browsable root so the REST
        // endpoint can serve anonymous navigation within it.
        $this->publishRoot( $folderId );

        $this->enqueueAssets();

        $this->instanceCount++;
        $uid = 'mediapilot-doclib-' . $this->instanceCount;

        // Initial data — render the folder directly on first load.
        $subfolders = $showSubfolders ? $this->folderRepository->getChildren( $folderId ) : [];
        $fileData   = $this->queryFiles( $folderId, $fileTypes, $perPage, 1 );
        $breadcrumb = [ [ 'id' => $folderId, 'name' => (string) ( $folder['name'] ?? '' ) ] ];

        $restUrl = rest_url( 'mediapilot/v1/documents/browse' );
        $nonce   = wp_create_nonce( 'wp_rest' );

        ob_start();
        ?>
        <div id="<?php echo esc_attr( $uid ); ?>"
             class="mediapilot-doc-library"
             data-root-id="<?php echo esc_attr( (string) $folderId ); ?>"
             data-current-folder="<?php echo esc_attr( (string) $folderId ); ?>"
             data-per-page="<?php echo esc_attr( (string) $perPage ); ?>"
             data-file-types="<?php echo esc_attr( $fileTypes ); ?>"
             data-show-subfolders="<?php echo esc_attr( $showSubfolders ? '1' : '0' ); ?>"
             data-pagination="<?php echo esc_attr( $pagination ? '1' : '0' ); ?>"
             data-rest-url="<?php echo esc_url( $restUrl ); ?>"
             data-nonce="<?php echo esc_attr( $nonce ); ?>">

            <?php /* ── Breadcrumb ── */ ?>
            <nav class="mediapilot-doclib__breadcrumb" aria-label="<?php esc_attr_e( 'Folder navigation', 'mediapilot-ai'); ?>">
                <?php echo $this->renderBreadcrumb( $breadcrumb ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderBreadcrumb() returns internally built HTML with all values escaped ?>
            </nav>

            <?php /* ── Body (subfolders + file table) — swapped by JS on navigation ── */ ?>
            <div class="mediapilot-doclib__body">
                <?php
                echo $this->renderSubfolders( $subfolders ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderSubfolders() returns internally built HTML with all values escaped
                echo $this->renderFileTable( $fileData['files'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderFileTable() returns internally built HTML with all values escaped
                if ( $pagination ) {
                    echo $this->renderPagination( 1, $fileData['pages'], $fileData['total'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderPagination() returns internally built HTML with all values escaped
                }
                ?>
            </div>

        </div>
        <?php
        return (string) ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Render partials
    // -------------------------------------------------------------------------

    /**
     * @param  array<int, array{id:int, name:string}> $crumbs
     * @return string
     */
    public function renderBreadcrumb( array $crumbs ): string {
        if ( empty( $crumbs ) ) {
            return '';
        }

        $html = '<ol class="mediapilot-doclib__crumb-list">';
        $last = count( $crumbs ) - 1;

        foreach ( $crumbs as $i => $crumb ) {
            if ( $i === $last ) {
                $html .= sprintf(
                    '<li class="mediapilot-doclib__crumb mediapilot-doclib__crumb--current" aria-current="page">%s</li>',
                    esc_html( $crumb['name'] )
                );
            } else {
                $html .= sprintf(
                    '<li class="mediapilot-doclib__crumb"><button type="button" class="mediapilot-doclib__crumb-btn" data-folder-id="%d">%s</button></li>',
                    (int) $crumb['id'],
                    esc_html( $crumb['name'] )
                );
            }
        }

        $html .= '</ol>';
        return $html;
    }

    /**
     * @param  array<int, array<string, mixed>> $subfolders
     * @return string
     */
    public function renderSubfolders( array $subfolders ): string {
        if ( empty( $subfolders ) ) {
            return '';
        }

        $html = '<div class="mediapilot-doclib__subfolders">';
        foreach ( $subfolders as $sf ) {
            $count = (int) ( $sf['count'] ?? 0 );
            $html .= sprintf(
                '<button type="button" class="mediapilot-doclib__subfolder" data-folder-id="%d">'
                . '<span class="mediapilot-doclib__subfolder-icon" aria-hidden="true">📁</span>'
                . '<span class="mediapilot-doclib__subfolder-name">%s</span>'
                . '<span class="mediapilot-doclib__subfolder-count">%s</span>'
                . '</button>',
                (int) ( $sf['id'] ?? 0 ),
                esc_html( (string) ( $sf['name'] ?? '' ) ),
                esc_html(
                    sprintf(
                        /* translators: %d: number of files */
                        _n( '%d file', '%d files', $count, 'mediapilot-ai'),
                        $count
                    )
                )
            );
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * @param  array<int, array<string, mixed>> $files
     * @return string
     */
    public function renderFileTable( array $files ): string {
        if ( empty( $files ) ) {
            return '<p class="mediapilot-doclib__empty">'
                . esc_html__( 'No files in this folder.', 'mediapilot-ai')
                . '</p>';
        }

        $html  = '<div class="mediapilot-doclib__table-wrap">';
        $html .= '<table class="mediapilot-doclib__table">';
        $html .= '<thead><tr>'
            . '<th class="mediapilot-doclib__col-icon" aria-hidden="true"></th>'
            . '<th class="mediapilot-doclib__col-name">'  . esc_html__( 'File', 'mediapilot-ai')     . '</th>'
            . '<th class="mediapilot-doclib__col-size">'  . esc_html__( 'Size', 'mediapilot-ai')     . '</th>'
            . '<th class="mediapilot-doclib__col-date">'  . esc_html__( 'Date', 'mediapilot-ai')     . '</th>'
            . '<th class="mediapilot-doclib__col-dl"></th>'
            . '</tr></thead>';
        $html .= '<tbody>';

        foreach ( $files as $file ) {
            $category = esc_attr( (string) ( $file['category'] ?? 'file' ) );
            $name     = esc_html( (string) ( $file['name']     ?? '' ) );
            $filename = esc_html( (string) ( $file['filename'] ?? '' ) );
            $size     = esc_html( (string) ( $file['size_human'] ?? '—' ) );
            $date     = esc_html( (string) ( $file['date']     ?? '' ) );
            $url      = esc_url(  (string) ( $file['url']      ?? '' ) );

            $html .= sprintf(
                '<tr class="mediapilot-doclib__row">'
                . '<td class="mediapilot-doclib__col-icon"><span class="mediapilot-doclib__icon mediapilot-doclib__icon--%s" aria-hidden="true">%s</span></td>'
                . '<td class="mediapilot-doclib__col-name" data-label="%s"><span class="mediapilot-doclib__filename" title="%s">%s</span></td>'
                . '<td class="mediapilot-doclib__col-size" data-label="%s">%s</td>'
                . '<td class="mediapilot-doclib__col-date" data-label="%s">%s</td>'
                . '<td class="mediapilot-doclib__col-dl"><a href="%s" class="mediapilot-doclib__download" download aria-label="%s">%s</a></td>'
                . '</tr>',
                $category,
                $this->fileIcon( $category ),
                esc_attr__( 'File', 'mediapilot-ai'),
                $filename,
                $name,
                esc_attr__( 'Size', 'mediapilot-ai'),
                $size,
                esc_attr__( 'Date', 'mediapilot-ai'),
                $date,
                $url,
                /* translators: %s: file name */
                esc_attr( sprintf( __( 'Download %s', 'mediapilot-ai'), $name ) ),
                esc_html__( 'Download', 'mediapilot-ai')
            );
        }

        $html .= '</tbody></table></div>';
        return $html;
    }

    /**
     * @param  int $current  Current page (1-based).
     * @param  int $total    Total number of pages.
     * @param  int $found    Total number of files.
     * @return string
     */
    public function renderPagination( int $current, int $total, int $found ): string {
        if ( $total <= 1 ) {
            return '';
        }

        $html  = '<nav class="mediapilot-doclib__pagination" aria-label="' . esc_attr__( 'Page navigation', 'mediapilot-ai') . '">';
        $html .= '<ul class="mediapilot-doclib__pages">';

        if ( $current > 1 ) {
            $html .= sprintf(
                '<li><button type="button" class="mediapilot-doclib__page-btn" data-page="%d" aria-label="%s">&#8592;</button></li>',
                $current - 1,
                esc_attr__( 'Previous page', 'mediapilot-ai')
            );
        }

        for ( $p = 1; $p <= $total; $p++ ) {
            if ( $p === $current ) {
                $html .= sprintf(
                    '<li><span class="mediapilot-doclib__page-btn mediapilot-doclib__page-btn--current" aria-current="page">%d</span></li>',
                    $p
                );
            } elseif ( $p === 1 || $p === $total || abs( $p - $current ) <= 2 ) {
                $html .= sprintf(
                    '<li><button type="button" class="mediapilot-doclib__page-btn" data-page="%d">%d</button></li>',
                    $p,
                    $p
                );
            } elseif ( abs( $p - $current ) === 3 ) {
                $html .= '<li><span class="mediapilot-doclib__page-ellipsis">&hellip;</span></li>';
            }
        }

        if ( $current < $total ) {
            $html .= sprintf(
                '<li><button type="button" class="mediapilot-doclib__page-btn" data-page="%d" aria-label="%s">&#8594;</button></li>',
                $current + 1,
                esc_attr__( 'Next page', 'mediapilot-ai')
            );
        }

        $html .= '</ul>';
        $html .= sprintf(
            '<p class="mediapilot-doclib__total">%s</p>',
            esc_html(
                sprintf(
                    /* translators: %d: total file count */
                    _n( '%d file total', '%d files total', $found, 'mediapilot-ai'),
                    $found
                )
            )
        );
        $html .= '</nav>';
        return $html;
    }

    // -------------------------------------------------------------------------
    // Public-root registry
    // -------------------------------------------------------------------------

    /**
     * Add a folder term ID to the public-roots option (write-once per ID).
     *
     * @param  int $folderId
     * @return void
     */
    private function publishRoot( int $folderId ): void {
        $roots = array_map( 'intval', (array) get_option( self::PUBLIC_ROOTS_OPTION, [] ) );

        if ( in_array( $folderId, $roots, true ) ) {
            return;
        }

        $roots[] = $folderId;
        update_option( self::PUBLIC_ROOTS_OPTION, array_values( array_unique( $roots ) ), false );
    }

    // -------------------------------------------------------------------------
    // Asset enqueueing
    // -------------------------------------------------------------------------

    private function enqueueAssets(): void {
        if ( $this->assetsEnqueued ) {
            return;
        }
        $this->assetsEnqueued = true;

        wp_enqueue_style(
            self::STYLE_HANDLE,
            MDPAI_URL . 'admin/assets/css/mediapilot-document-library.css',
            [],
            MDPAI_VERSION
        );

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            MDPAI_URL . 'admin/assets/js/mediapilot-document-library.js',
            [],
            MDPAI_VERSION,
            true
        );
    }

    // -------------------------------------------------------------------------
    // File queries
    // -------------------------------------------------------------------------

    /**
     * Query files in a folder, applying optional MIME-type filtering.
     *
     * @param  int    $folderId
     * @param  string $fileTypes  Comma-separated extensions, e.g. "pdf,doc".
     * @param  int    $perPage
     * @param  int    $page
     * @return array{ files: array<int, array<string, mixed>>, total: int, pages: int }
     */
    private function queryFiles( int $folderId, string $fileTypes, int $perPage, int $page ): array {
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

        $mimes = $this->extensionsToMime( $fileTypes );
        if ( ! empty( $mimes ) ) {
            $queryArgs['post_mime_type'] = $mimes;
        }

        $query = new \WP_Query( $queryArgs );

        $files = [];
        foreach ( $query->posts as $post ) {
            if ( ! ( $post instanceof \WP_Post ) ) {
                continue;
            }
            $files[] = $this->formatFile( $post );
        }

        return [
            'files' => $files,
            'total' => (int) $query->found_posts,
            'pages' => (int) $query->max_num_pages,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Return an SVG icon markup string for a file category.
     *
     * @param  string $category  image|pdf|doc|sheet|ppt|archive|audio|video|text|file
     * @return string  Inline SVG.
     */
    private function fileIcon( string $category ): string {
        $icons = [
            'image'   => '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M2 3a1 1 0 011-1h14a1 1 0 011 1v14a1 1 0 01-1 1H3a1 1 0 01-1-1V3zm2 1v8.586l3-3 3 3 2-2 3 3V4H4zm0 10.414V17h12v-1.586l-3-3-2 2-3-3-4 4z"/></svg>',
            'pdf'     => '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M4 2a2 2 0 00-2 2v12a2 2 0 002 2h12a2 2 0 002-2V7.414A2 2 0 0017.414 6L13 1.586A2 2 0 0011.586 1H4zm0 2h7v4a1 1 0 001 1h4v9H4V4zm7-1.586L14.586 6H11V2.414zM6 11a1 1 0 000 2h8a1 1 0 000-2H6zm0 3a1 1 0 000 2h5a1 1 0 000-2H6z"/></svg>',
            'doc'     => '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M4 2a2 2 0 00-2 2v12a2 2 0 002 2h12a2 2 0 002-2V7.414A2 2 0 0017.414 6L13 1.586A2 2 0 0011.586 1H4zm0 2h7v4a1 1 0 001 1h4v9H4V4zm2 7a1 1 0 000 2h8a1 1 0 000-2H6zm0 3a1 1 0 000 2h5a1 1 0 000-2H6z"/></svg>',
            'sheet'   => '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M4 2a2 2 0 00-2 2v12a2 2 0 002 2h12a2 2 0 002-2V4a2 2 0 00-2-2H4zm1 3h10v1H5V5zm0 3h4v1H5V8zm6 0h4v1h-4V8zm-6 3h4v1H5v-1zm6 0h4v1h-4v-1zm-6 3h4v1H5v-1zm6 0h4v1h-4v-1z"/></svg>',
            'ppt'     => '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M4 2a2 2 0 00-2 2v12a2 2 0 002 2h12a2 2 0 002-2V4a2 2 0 00-2-2H4zm2 4h8a1 1 0 010 2H6a1 1 0 010-2zm0 4h5a1 1 0 010 2H6a1 1 0 010-2z"/></svg>',
            'archive' => '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5 3a1 1 0 000 2c.55 0 1 .45 1 1v1H6a1 1 0 000 2h1v1c0 .55-.45 1-1 1a1 1 0 000 2h8a1 1 0 000-2c-.55 0-1-.45-1-1v-1h1a1 1 0 000-2h-1V6c0-.55.45-1 1-1a1 1 0 000-2H5zm4 9a1 1 0 110-2 1 1 0 010 2z"/></svg>',
            'audio'   => '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M18 3a1 1 0 00-1.196-.98l-10 2A1 1 0 006 5v9.114A4.369 4.369 0 005 14c-1.657 0-3 .895-3 2s1.343 2 3 2 3-.895 3-2V7.82l8-1.6v5.894A4.37 4.37 0 0015 12c-1.657 0-3 .895-3 2s1.343 2 3 2 3-.895 3-2V3z"/></svg>',
            'video'   => '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M2 6a2 2 0 012-2h6a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V6zm12.553 1.106A1 1 0 0014 8v4a1 1 0 00.553.894l2 1A1 1 0 0018 13V7a1 1 0 00-1.447-.894l-2 1z"/></svg>',
            'text'    => '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm6 0v4h4l-4-4zM6 9a1 1 0 000 2h8a1 1 0 000-2H6zm0 3a1 1 0 000 2h5a1 1 0 000-2H6z"/></svg>',
            'file'    => '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm6 0v4h4l-4-4z"/></svg>',
        ];

        return $icons[ $category ] ?? $icons['file'];
    }

    /**
     * Format a WP_Post attachment into the shape used by both the initial
     * render and the REST controller.
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

        $mime = (string) $post->post_mime_type;
        $ext  = strtolower( pathinfo( $filePath ?: $post->post_title, PATHINFO_EXTENSION ) );

        return [
            'id'         => $post->ID,
            'name'       => $post->post_title ?: basename( $url ),
            'filename'   => basename( $filePath ?: $url ),
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
     * Convert comma-separated extensions to an array of MIME types for
     * `post_mime_type` WP_Query arg.
     *
     * @param  string $raw  e.g. "pdf,doc,zip"
     * @return string[]
     */
    private function extensionsToMime( string $raw ): array {
        if ( '' === trim( $raw ) ) {
            return [];
        }

        $mimes = [];
        foreach ( array_map( 'trim', explode( ',', strtolower( $raw ) ) ) as $ext ) {
            $type = wp_get_mime_types()[ "*.$ext" ] ?? '';
            if ( '' !== $type ) {
                $mimes[] = $type;
            }
        }

        return array_values( array_unique( $mimes ) );
    }

    /**
     * @param  string $mime
     * @param  string $ext
     * @return string  image|pdf|doc|sheet|ppt|archive|audio|video|text|file
     */
    private function mimeCategory( string $mime, string $ext ): string {
        if ( str_starts_with( $mime, 'image/' ) )                                          return 'image';
        if ( $mime === 'application/pdf' )                                                 return 'pdf';
        if ( str_contains( $mime, 'word' ) || str_contains( $mime, 'document' )
            || in_array( $ext, [ 'doc', 'docx', 'odt', 'rtf' ], true ) )                  return 'doc';
        if ( str_contains( $mime, 'sheet' ) || str_contains( $mime, 'excel' )
            || in_array( $ext, [ 'xls', 'xlsx', 'csv', 'ods' ], true ) )                  return 'sheet';
        if ( str_contains( $mime, 'presentation' ) || str_contains( $mime, 'powerpoint' )
            || in_array( $ext, [ 'ppt', 'pptx' ], true ) )                                return 'ppt';
        if ( str_contains( $mime, 'zip' ) || str_contains( $mime, 'compressed' )
            || in_array( $ext, [ 'zip', 'rar', '7z', 'tar', 'gz' ], true ) )              return 'archive';
        if ( str_starts_with( $mime, 'audio/' ) )                                         return 'audio';
        if ( str_starts_with( $mime, 'video/' ) )                                         return 'video';
        if ( str_starts_with( $mime, 'text/' ) )                                          return 'text';
        return 'file';
    }
}
