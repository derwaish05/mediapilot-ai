<?php

declare(strict_types=1);

namespace MediaPilotAI\PostType;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderRepository;
use MediaPilotAI\Folder\FolderService;
use MediaPilotAI\Taxonomy\FolderTaxonomy;

/**
 * Integrates the MediaPilot folder system with custom post type list screens.
 *
 * For each CPT enabled on the Settings page this class:
 *  1. Extends the `mdpai_folder` taxonomy to cover that post type.
 *  2. Adds a "Folder" column to the WP list table.
 *  3. Filters the list query when ?mdpai_folder_id is present in the URL.
 *  4. Enqueues the compiled React bundle on the list screen.
 *  5. Injects `window.MediaPilotConfig` + React portal mount points into the footer.
 *  6. Injects bridge JS that repositions the sidebar portal next to the table.
 *
 * Developer extension point — add/remove post types programmatically:
 *
 *   add_filter( 'mdpai_post_type_folders', fn( $types ) => [ ...$types, 'portfolio' ] );
 *
 * @package MediaPilotAI\PostType
 * @since   1.0.0
 */
class PostTypeIntegration {

    /** @var string[] Final list of CPT slugs that receive folder support. */
    private array $enabledTypes;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct(
        private readonly FolderRepository $folderRepository,
        private readonly FolderService    $folderService,
    ) {
        $settings = (array) get_option( 'mdpai_settings', [] );
        $saved    = is_array( $settings['enabled_post_types'] ?? null )
            ? array_filter( $settings['enabled_post_types'], 'is_string' )
            : [];

        /**
         * Filters the post types that receive MediaPilot folder support.
         *
         * Seeded from the settings page checkboxes. Developers can append or
         * remove types programmatically without touching the database.
         *
         * @since 1.0.0
         *
         * @param string[] $post_types Post type slugs.
         */
        $this->enabledTypes = array_values(
            (array) apply_filters( 'mdpai_post_type_folders', array_values( $saved ) )
        );
    }

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function register(): void {
        if ( empty( $this->enabledTypes ) ) {
            return;
        }

        // Extend `mdpai_folder` taxonomy to cover enabled post types.
        add_filter( 'mdpai_supported_post_types', [ $this, 'addEnabledTypes' ] );

        // List table: column header + cell renderer per post type.
        foreach ( $this->enabledTypes as $postType ) {
            add_filter( "manage_{$postType}_posts_columns",       [ $this, 'addFolderColumn' ] );
            add_action( "manage_{$postType}_posts_custom_column", [ $this, 'renderFolderColumn' ], 10, 2 );
        }

        // Filter CPT list query when a folder is selected.
        add_action( 'pre_get_posts', [ $this, 'filterByFolder' ] );

        // Assets + the MediaPilotConfig global + the sidebar-positioning bridge
        // are attached to the bundle at enqueue time in enqueueAssets(). Only the
        // hidden mount-point divs are emitted in the footer. Footer-time
        // wp_add_inline_script() is unreliable when other plugins flush footer
        // scripts early, so config/bridge must NOT be attached from the footer.
        add_action( 'admin_enqueue_scripts',  [ $this, 'enqueueAssets' ] );
        add_action( 'admin_footer-edit.php',  [ $this, 'injectSidebarMount' ] );
    }

    // -------------------------------------------------------------------------
    // Taxonomy extension
    // -------------------------------------------------------------------------

    /**
     * @param  string[] $types
     * @return string[]
     */
    public function addEnabledTypes( array $types ): array {
        return array_unique( array_merge( $types, $this->enabledTypes ) );
    }

    // -------------------------------------------------------------------------
    // List table column
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, string> $columns
     * @return array<string, string>
     */
    public function addFolderColumn( array $columns ): array {
        $columns['mdpai_folder'] = __( 'Folder', 'mediapilot-ai');
        return $columns;
    }

    /**
     * @param string $column  Column machine name.
     * @param int    $postId  Current post ID.
     */
    public function renderFolderColumn( string $column, int $postId ): void {
        if ( 'mdpai_folder' !== $column ) {
            return;
        }

        $terms = get_the_terms( $postId, FolderTaxonomy::TAXONOMY );

        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            echo '<span class="mediapilot-uncategorized">&mdash;</span>';
            return;
        }

        $names = array_map( static fn( \WP_Term $t ) => esc_html( $t->name ), $terms );
        echo '<span class="mediapilot-folder-label">' . implode( ', ', $names ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $names are esc_html() escaped via array_map above
    }

    // -------------------------------------------------------------------------
    // Query filter
    // -------------------------------------------------------------------------

    /**
     * Applies the folder filter to the CPT list query when ?mdpai_folder_id is set.
     *
     *   mdpai_folder_id absent / -1 → no filter
     *   mdpai_folder_id = 0         → Uncategorized (NOT EXISTS)
     *   mdpai_folder_id > 0         → specific folder
     */
    public function filterByFolder( \WP_Query $query ): void {
        if ( ! $query->is_main_query() || ! is_admin() ) {
            return;
        }

        $postType = (string) $query->get( 'post_type' );

        if ( ! in_array( $postType, $this->enabledTypes, true ) ) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $folderId = isset( $_GET['mdpai_folder_id'] ) ? (int) $_GET['mdpai_folder_id'] : -1;
        // phpcs:enable

        if ( -1 === $folderId ) {
            return;
        }

        if ( 0 === $folderId ) {
            $query->set( 'tax_query', [
                [
                    'taxonomy' => FolderTaxonomy::TAXONOMY,
                    'field'    => 'term_id',
                    'operator' => 'NOT EXISTS',
                ],
            ] );
            return;
        }

        $query->set( 'tax_query', [
            [
                'taxonomy' => FolderTaxonomy::TAXONOMY,
                'field'    => 'term_id',
                'terms'    => [ $folderId ],
                'operator' => 'IN',
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Asset enqueueing
    // -------------------------------------------------------------------------

    public function enqueueAssets( string $hook ): void {
        if ( 'edit.php' !== $hook ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $postType = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : 'post';

        if ( ! in_array( $postType, $this->enabledTypes, true ) ) {
            return;
        }

        wp_enqueue_style(
            'mediapilot-admin',
            MDPAI_URL . 'admin/assets/dist/mediapilot-admin.css',
            [],
            MDPAI_VERSION
        );

        wp_enqueue_script(
            'mediapilot-admin',
            MDPAI_URL . 'admin/assets/dist/mediapilot-admin.js',
            [],
            MDPAI_VERSION,
            true
        );

        // Layout styles for the CPT-list sidebar (no raw <style> tag).
        wp_add_inline_style(
            'mediapilot-admin',
            '#mediapilot-sidebar-portal { height: 100%; overflow: hidden; flex-shrink: 0; }'
            . '#mediapilot-cpt-layout { display: flex; flex-direction: row; align-items: flex-start; }'
            . '#mediapilot-cpt-content { flex: 1; min-width: 0; overflow-x: auto; }'
        );

        // Attach the bootstrap config + positioning bridge to the bundle here
        // (enqueue time), NOT from the admin_footer-edit.php hook: a footer-time
        // wp_add_inline_script() is silently dropped on sites that flush footer
        // scripts early, which left the CPT sidebar unconfigured/unpositioned.
        wp_add_inline_script(
            'mediapilot-admin',
            'window.MediaPilotConfig = ' . wp_json_encode( $this->buildConfig( $postType ) ) . ';',
            'before'
        );
        $this->injectBridge();
    }

    /**
     * Builds the bootstrap configuration object for a CPT list screen.
     *
     * @param  string $postType
     * @return array<string, mixed>
     */
    private function buildConfig( string $postType ): array {
        $userId      = get_current_user_id();
        $settings    = (array) get_option( 'mdpai_settings', [] );
        $folderMode  = isset( $settings['folder_mode'] ) ? (string) $settings['folder_mode'] : 'global';
        $treeUserId  = ( 'per_user' === $folderMode ) ? $userId : 0;

        return [
            'restUrl'     => rest_url( 'mediapilot/v1' ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'userId'      => $userId,
            'isAdmin'     => current_user_can( 'manage_options' ),
            'folderMode'  => $folderMode,
            'postType'    => $postType,
            'initialTree' => $this->folderService->getTree( $treeUserId ),
            'userPrefs'   => $this->getUserPrefs( $userId ),
            'licenceTier' => 'pro',
        ];
    }

    // -------------------------------------------------------------------------
    // React mount points + MediaPilotConfig
    // -------------------------------------------------------------------------

    /**
     * Outputs hidden portal divs and `window.MediaPilotConfig` into the edit.php footer.
     * Mirrors MediaLibraryIntegration::injectSidebarMount().
     */
    public function injectSidebarMount(): void {
        $screen = get_current_screen();

        if ( ! $screen || 'edit' !== $screen->base ) {
            return;
        }

        $postType = $screen->post_type;

        if ( ! in_array( $postType, $this->enabledTypes, true ) ) {
            return;
        }

        // Mount points only. The MediaPilotConfig global and the positioning
        // bridge are attached to the bundle at enqueue time in enqueueAssets().
        echo '<div id="mediapilot-root" style="display:none;"></div>' . "\n";
        echo '<div id="mediapilot-sidebar-portal" style="display:none;"></div>' . "\n";
        echo '<div id="mediapilot-breadcrumb-root" style="display:none;"></div>' . "\n";
        echo '<div id="mediapilot-toolbar-root" style="display:none;"></div>' . "\n";
    }

    // -------------------------------------------------------------------------
    // Bridge JS
    // -------------------------------------------------------------------------

    /**
     * Injects the JavaScript that repositions #mediapilot-sidebar-portal next to the
     * CPT list table, turning the .wrap into a flex-row layout.
     *
     * The CPT list screen DOM at rest:
     *   .wrap
     *     h1
     *     <search / bulk forms>
     *     form#posts-filter
     *       .tablenav.top
     *       table.wp-list-table
     *       .tablenav.bottom
     *
     * After bridge runs:
     *   .wrap
     *     h1
     *     #mediapilot-cpt-layout  (flex-row)
     *       #mediapilot-sidebar-portal  (React FolderSidebar)
     *       #mediapilot-cpt-content     (flex-1)
     *         form#posts-filter
     */
    public function injectBridge(): void {
        // CPT-list sidebar positioning bridge, delivered as a real enqueued file
        // (no inline buffer). Called from enqueueAssets() where the screen and
        // post type have already been validated and the bundle is enqueued.
        wp_register_script(
            'mediapilot-cpt-bridge',
            MDPAI_URL . 'admin/assets/js/mediapilot-cpt-bridge.js',
            [ 'mediapilot-admin' ],
            MDPAI_VERSION,
            true
        );
        wp_enqueue_script( 'mediapilot-cpt-bridge' );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Retrieves user preferences from wp_mdpai_user_prefs.
     *
     * @param  int $userId
     * @return array<string, mixed>
     */
    private function getUserPrefs( int $userId ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT folder_id, sort_files, sort_dir, sidebar_w, ui_theme
                 FROM {$wpdb->prefix}mdpai_user_prefs
                 WHERE user_id = %d
                 LIMIT 1",
                $userId
            ),
            ARRAY_A
        );

        if ( null === $row ) {
            return $this->defaultPrefs();
        }

        return [
            'folder_id'  => isset( $row['folder_id'] ) ? (int) $row['folder_id'] : null,
            'sort_files' => (string) ( $row['sort_files'] ?? 'date' ),
            'sort_dir'   => (string) ( $row['sort_dir']   ?? 'desc' ),
            'sidebar_w'  => (int)    ( $row['sidebar_w']  ?? 220 ),
            'ui_theme'   => (string) ( $row['ui_theme']   ?? 'default' ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultPrefs(): array {
        return [
            'folder_id'  => null,
            'sort_files' => 'date',
            'sort_dir'   => 'desc',
            'sidebar_w'  => 220,
            'ui_theme'   => 'default',
        ];
    }
}
