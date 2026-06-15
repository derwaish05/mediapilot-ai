<?php

declare(strict_types=1);

namespace MediaPilotAI\Filesystem;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderRepository;
use MediaPilotAI\Taxonomy\FolderTaxonomy;

/**
 * Orchestrates Real Filesystem Mode by hooking into the folder/file lifecycle
 * and mirroring virtual folder changes to physical directories under
 * {uploads}/mediapilot-ai/{folder-slug}/.
 *
 * Enable via Media > Filesystem settings page or the mdpai_filesystem_settings
 * WordPress option.
 *
 * @package MediaPilotAI\Filesystem
 * @since   1.0.0
 */
class RealFolderSync {

    public const OPTION_NAME       = 'mdpai_filesystem_settings';
    public const UNCATEGORIZED_SLUG = 'uncategorized';

    public function __construct(
        private readonly FolderRepository $folderRepo,
        private readonly FileMover $fileMover
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Register all WordPress action hooks for folder and file lifecycle events.
     */
    public function register(): void {
        add_action( 'mdpai_after_folder_create', [ $this, 'onFolderCreate' ], 10, 4 );
        add_action( 'mdpai_after_folder_rename', [ $this, 'onFolderRename' ], 10, 2 );
        add_action( 'mdpai_after_folder_delete', [ $this, 'onFolderDelete' ], 10, 2 );
        add_action( 'mdpai_after_file_assign',   [ $this, 'onFileAssign'   ], 10, 2 );
        add_action( 'admin_notices',           [ $this, 'adminNotice'    ] );

        // Capture the term slug before deletion so onFolderDelete can use it.
        add_action( 'pre_delete_term', [ $this, 'captureSlugBeforeDelete' ], 10, 2 );
    }

    // -------------------------------------------------------------------------
    // Hook handlers
    // -------------------------------------------------------------------------

    /**
     * Create a physical directory for a newly created folder.
     *
     * @param int    $termId   Term ID of the new folder.
     * @param string $name     Folder display name.
     * @param int    $parentId Parent folder term ID (0 = root).
     * @param int    $userId   ID of the user who created the folder.
     */
    public function onFolderCreate( int $termId, string $name, int $parentId, int $userId ): void {
        if ( ! $this->isEnabled() ) {
            return;
        }

        $term = get_term( $termId );

        if ( null === $term || is_wp_error( $term ) ) {
            return;
        }

        $this->ensureRoot();

        $dir = $this->folderDir( (string) $term->slug );
        wp_mkdir_p( $dir );
    }

    /**
     * Ensure the new-slug directory exists after a folder rename.
     *
     * Directory renaming on folder rename requires a full sync.
     * Use `wp mediapilot filesystem sync` to reconcile old directories.
     *
     * @param int    $termId  Term ID of the renamed folder.
     * @param string $newName New display name.
     */
    public function onFolderRename( int $termId, string $newName ): void {
        if ( ! $this->isEnabled() ) {
            return;
        }

        $term = get_term( $termId );

        if ( null === $term || is_wp_error( $term ) ) {
            return;
        }

        // Directory renaming on folder rename requires a full sync.
        // Use `wp mediapilot filesystem sync` to reconcile.
        $newDir = $this->folderDir( (string) $term->slug );
        wp_mkdir_p( $newDir );
    }

    /**
     * Store the term slug in a short-lived transient before the term is
     * deleted so that onFolderDelete can locate the physical directory.
     *
     * @param int    $termId   Term ID about to be deleted.
     * @param string $taxonomy Taxonomy name.
     */
    public function captureSlugBeforeDelete( int $termId, string $taxonomy ): void {
        if ( $taxonomy !== FolderTaxonomy::TAXONOMY ) {
            return;
        }

        $term = get_term( $termId );

        if ( null === $term || is_wp_error( $term ) ) {
            return;
        }

        set_transient( "mdpai_deleting_folder_slug_{$termId}", $term->slug, 30 );
    }

    /**
     * After a folder is deleted, move all its physical files to the
     * uncategorized directory.
     *
     * @param int  $termId    Term ID of the deleted folder.
     * @param bool $recursive Whether sub-folders were deleted recursively.
     */
    public function onFolderDelete( int $termId, bool $recursive ): void {
        if ( ! $this->isEnabled() ) {
            return;
        }

        $slug = get_transient( "mdpai_deleting_folder_slug_{$termId}" );
        delete_transient( "mdpai_deleting_folder_slug_{$termId}" );

        if ( false === $slug || '' === $slug ) {
            return;
        }

        $dir           = $this->folderDir( (string) $slug );
        $uncategorized = $this->uncategorizedDir();

        wp_mkdir_p( $uncategorized );

        $files = glob( $dir . '/*' );

        if ( ! is_array( $files ) ) {
            return;
        }

        foreach ( $files as $file ) {
            if ( ! is_file( $file ) ) {
                continue;
            }

            $dest = $uncategorized . '/' . basename( $file );
            rename( $file, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
        }

        // Leave the empty directory in place for safety.
    }

    /**
     * Move an attachment's physical file to the directory that corresponds to
     * its assigned folder when a file-to-folder assignment occurs.
     *
     * @param int $attachmentId Attachment post ID.
     * @param int $termId       Folder term ID (0 = Uncategorized).
     */
    public function onFileAssign( int $attachmentId, int $termId ): void {
        if ( ! $this->isEnabled() ) {
            return;
        }

        if ( $termId === 0 ) {
            $destDir = $this->uncategorizedDir();
        } else {
            $term = get_term( $termId );

            if ( null === $term || is_wp_error( $term ) ) {
                $destDir = $this->uncategorizedDir();
            } else {
                $destDir = $this->folderDir( (string) $term->slug );
            }
        }

        wp_mkdir_p( $destDir );

        $this->fileMover->moveFile( $attachmentId, $destDir );
    }

    // -------------------------------------------------------------------------
    // Sync
    // -------------------------------------------------------------------------

    /**
     * Reconcile the virtual folder tree with the physical filesystem.
     *
     * Ensures every virtual folder has a corresponding directory under
     * {uploads}/mediapilot-ai/ and moves any misplaced files into the correct directory.
     *
     * @return array{scanned: int, moved: int, dirs_created: int, errors: int}
     */
    public function syncAll(): array {
        $this->ensureRoot();

        $stats = [
            'scanned'      => 0,
            'moved'        => 0,
            'dirs_created' => 0,
            'errors'       => 0,
        ];

        $tree    = $this->folderRepo->getTree( 0 );
        $folders = $this->flattenTree( $tree );

        foreach ( $folders as $termId => $slug ) {
            $destDir = $this->folderDir( $slug );

            if ( ! is_dir( $destDir ) ) {
                wp_mkdir_p( $destDir );
                $stats['dirs_created']++;
            }

            // Query attachment IDs in this folder.
            $query = new \WP_Query( [
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                    [
                        'taxonomy' => FolderTaxonomy::TAXONOMY,
                        'field'    => 'term_id',
                        'terms'    => $termId,
                    ],
                ],
            ] );

            /** @var int[] $ids */
            $ids = $query->posts;

            foreach ( $ids as $id ) {
                $stats['scanned']++;

                $currentPath = (string) get_attached_file( $id );

                if ( ! file_exists( $currentPath ) ) {
                    continue;
                }

                $currentDir = dirname( $currentPath );

                if ( rtrim( $currentDir, '/' ) === rtrim( $destDir, '/' ) ) {
                    // Already in the correct directory.
                    continue;
                }

                $moved = $this->fileMover->moveFile( $id, $destDir );

                if ( $moved ) {
                    $stats['moved']++;
                } else {
                    $stats['errors']++;
                }
            }
        }

        return $stats;
    }

    // -------------------------------------------------------------------------
    // Settings helpers
    // -------------------------------------------------------------------------

    /**
     * Returns true when Real Filesystem Mode is enabled in settings.
     */
    public function isEnabled(): bool {
        $settings = (array) get_option( self::OPTION_NAME, [] );
        return ! empty( $settings['enabled'] );
    }

    /**
     * Returns the current settings merged with defaults.
     *
     * @return array{enabled: bool}
     */
    public function getSettings(): array {
        return wp_parse_args(
            (array) get_option( self::OPTION_NAME, [] ),
            [ 'enabled' => false ]
        );
    }

    // -------------------------------------------------------------------------
    // Directory helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the absolute path to the MediaPilot root directory inside uploads.
     */
    public function getRoot(): string {
        $uploads = wp_get_upload_dir();
        return rtrim( $uploads['basedir'], '/' ) . '/mediapilot-ai';
    }

    /**
     * Returns the absolute path to the directory for a given folder slug.
     *
     * @param string $slug  Folder term slug.
     */
    public function folderDir( string $slug ): string {
        return $this->getRoot() . '/' . sanitize_file_name( $slug );
    }

    /**
     * Returns the absolute path to the uncategorized directory.
     */
    public function uncategorizedDir(): string {
        return $this->folderDir( self::UNCATEGORIZED_SLUG );
    }

    /**
     * Creates the MediaPilot root and uncategorized directories if they do not exist.
     */
    public function ensureRoot(): bool {
        wp_mkdir_p( $this->getRoot() );
        wp_mkdir_p( $this->uncategorizedDir() );
        return true;
    }

    // -------------------------------------------------------------------------
    // Admin notice
    // -------------------------------------------------------------------------

    /**
     * Show a warning notice on the Media Library screen when Real Filesystem
     * Mode is active.
     */
    public function adminNotice(): void {
        if ( ! $this->isEnabled() ) {
            return;
        }

        $screen = get_current_screen();

        if ( ! $screen || $screen->id !== 'upload' ) {
            return;
        }

        $settingsUrl = admin_url( 'upload.php?page=mediapilot-filesystem' );

        printf(
            '<div class="notice notice-warning is-dismissible"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
            esc_html__( 'MediaPilot AI: Real Filesystem Mode is active.', 'mediapilot-ai' ),
            esc_html__( 'Files are physically moved when assigned to folders — test on staging before using in production.', 'mediapilot-ai' ),
            esc_url( $settingsUrl ),
            esc_html__( 'Disable', 'mediapilot-ai' )
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Recursively flatten a nested folder tree into a flat [ termId => slug ] map.
     *
     * @param  array<int, array<string, mixed>> $tree  Nested tree from FolderRepository::getTree().
     * @return array<int, string>                      Flat map of term ID to slug.
     */
    private function flattenTree( array $tree ): array {
        $flat = [];

        foreach ( $tree as $node ) {
            $flat[ (int) $node['id'] ] = (string) $node['slug'];

            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                $flat += $this->flattenTree( $node['children'] );
            }
        }

        return $flat;
    }
}
