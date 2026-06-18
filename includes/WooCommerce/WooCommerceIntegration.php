<?php

declare(strict_types=1);

namespace MediaPilotAI\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderService;

/**
 * WooCommerce Media Folder Integration.
 *
 * Makes the MediaPilot folder sidebar available inside the WordPress media modal
 * whenever it is opened from a WooCommerce product edit screen — e.g. when
 * clicking "Set product image" or "Add product gallery images".
 *
 * What this class does:
 *  1. Confirms the MediaPilot admin JS/CSS bundle is loaded on product edit screens
 *     (MediaLibraryIntegration already covers post.php, but this adds an
 *     explicit check so the dependency is self-contained).
 *  2. Injects `window.MediaPilotConfig` and the four React portal mount-point divs
 *     into the product edit page footer — exactly as MediaLibraryIntegration
 *     does for upload.php.
 *  3. Injects a lightweight bridge script that watches for the WP media modal
 *     opening (via `wp.media` events) and repositions `#mediapilot-sidebar-portal`
 *     inside the modal's `.media-frame-content`, turning it into a flex row
 *     so the folder sidebar sits to the left of the media grid.
 *  4. Forwards `mediapilot:folder-selected` events (dispatched by the React sidebar)
 *     to the active `wp.media` backbone frame so the media grid filters by
 *     the chosen folder without a page reload.
 *
 * Requires WooCommerce to be active; silently bails if it is not.
 *
 * @package MediaPilotAI\WooCommerce
 * @since   1.0.0
 */
class WooCommerceIntegration {

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct(
        private readonly FolderService $folderService,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Register all hooks. Silently bails when WooCommerce is not active.
     */
    public function register(): void {
        if ( ! $this->wooActive() ) {
            return;
        }

        add_action( 'admin_enqueue_scripts', [ $this, 'enqueueAssets' ] );
        add_action( 'admin_footer',          [ $this, 'injectSidebarMount' ] );
        add_action( 'admin_footer',          [ $this, 'injectBridge' ] );
    }

    // -------------------------------------------------------------------------
    // Asset enqueueing
    // -------------------------------------------------------------------------

    /**
     * Ensures the compiled MediaPilot bundle is loaded on WooCommerce product edit
     * screens. MediaLibraryIntegration covers post.php globally, but we
     * register the scripts here so this class is self-contained and the
     * correct dependency is explicit.
     *
     * @param string $hook  Current admin page hook suffix.
     */
    public function enqueueAssets( string $hook ): void {
        if ( ! $this->isProductEditScreen( $hook ) ) {
            return;
        }

        if ( ! wp_script_is( 'mediapilot-admin', 'enqueued' ) ) {
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
        }

        // Layout styles for the media-frame sidebar portal (no raw <style> tag).
        wp_add_inline_style(
            'mediapilot-admin',
            '#mediapilot-sidebar-portal { height:100%; overflow:hidden; flex-shrink:0; }'
            . '#mediapilot-media-content-inner { flex:1; min-width:0; overflow:hidden; display:flex; flex-direction:column; }'
            . '#mediapilot-media-content-inner .wp-filter { flex-shrink:0; }'
            . '#mediapilot-media-content-inner .attachments-browser { flex:1; min-height:0; overflow-y:auto; }'
        );
    }

    // -------------------------------------------------------------------------
    // React mount points + MediaPilotConfig
    // -------------------------------------------------------------------------

    /**
     * Outputs the four React portal mount-point divs and `window.MediaPilotConfig`
     * into the footer of WooCommerce product edit/new screens.
     *
     * Mirrors MediaLibraryIntegration::injectSidebarMount() exactly so the
     * same React application boots here as on upload.php.
     */
    public function injectSidebarMount(): void {
        $screen = get_current_screen();

        if ( ! $screen ) {
            return;
        }

        if ( ! in_array( $screen->base, [ 'post', 'post-new' ], true )
            || 'product' !== $screen->post_type
        ) {
            return;
        }

        global $wpdb;

        $userId     = get_current_user_id();
        $settings   = (array) get_option( 'mdpai_settings', [] );
        $folderMode = isset( $settings['folder_mode'] ) ? (string) $settings['folder_mode'] : 'global';
        $treeUserId = ( 'per_user' === $folderMode ) ? $userId : 0;

        // Always load a fresh tree (flush stale transients first).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_mdpai_tree_%'
                OR option_name LIKE '_transient_timeout_mdpai_tree_%'"
        );

        $initialTree = $this->folderService->getTree( $treeUserId );
        $userPrefs   = $this->getUserPrefs( $userId );

        $config = [
            'restUrl'     => rest_url( 'mediapilot/v1' ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'userId'      => $userId,
            'isAdmin'     => current_user_can( 'manage_options' ),
            'folderMode'  => $folderMode,
            'initialTree' => $initialTree,
            'userPrefs'   => $userPrefs,
            'licenceTier' => 'pro',
        ];

        echo '<div id="mediapilot-root" style="display:none;"></div>' . "\n";
        echo '<div id="mediapilot-sidebar-portal" style="display:none;"></div>' . "\n";
        echo '<div id="mediapilot-breadcrumb-root" style="display:none;"></div>' . "\n";
        echo '<div id="mediapilot-toolbar-root" style="display:none;"></div>' . "\n";

        // Bootstrap config via the enqueued bundle handle (no raw <script> tag).
        wp_add_inline_script(
            'mediapilot-admin',
            'window.MediaPilotConfig = ' . wp_json_encode( $config ) . ';',
            'before'
        );
    }

    // -------------------------------------------------------------------------
    // Bridge JS
    // -------------------------------------------------------------------------

    /**
     * Injects a JavaScript bridge that:
     *
     *  1. Hooks into the `wp.media` backbone events so it knows when a media
     *     modal frame is opened from the product edit screen.
     *  2. Repositions `#mediapilot-sidebar-portal` inside the modal's
     *     `.media-frame-content`, wrapping the existing WP children in a
     *     `#mediapilot-media-content-inner` flex column — the same layout used on
     *     upload.php.
     *  3. Resets the sidebar placement every time the modal is closed so it
     *     re-attaches cleanly the next time the modal opens.
     *  4. Listens for `mediapilot:folder-selected` custom events (dispatched by the
     *     React FolderSidebar) and forwards them to the active `wp.media`
     *     frame's attachment collection — identical to the upload.php bridge.
     */
    public function injectBridge(): void {
        $screen = get_current_screen();

        if ( ! $screen ) {
            return;
        }

        if ( ! in_array( $screen->base, [ 'post', 'post-new' ], true )
            || 'product' !== $screen->post_type
        ) {
            return;
        }
        // Behavior script delivered as a real enqueued file (no inline buffer).
        wp_register_script(
            'mediapilot-woo-media-bridge',
            MDPAI_URL . 'admin/assets/js/mediapilot-woo-media-bridge.js',
            [ 'mediapilot-admin' ],
            MDPAI_VERSION,
            true
        );
        wp_enqueue_script( 'mediapilot-woo-media-bridge' );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @param string $hook  Admin page hook suffix from admin_enqueue_scripts.
     */
    private function isProductEditScreen( string $hook ): bool {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return false;
        }
        $screen = get_current_screen();
        return $screen && 'product' === $screen->post_type;
    }

    /**
     * Retrieves saved user preferences, falling back to defaults.
     *
     * @param  int $userId
     * @return array<string, mixed>
     */
    private function getUserPrefs( int $userId ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT folder_id, sort_files, sort_dir, sidebar_w
                 FROM {$wpdb->prefix}mdpai_user_prefs
                 WHERE user_id = %d
                 LIMIT 1",
                $userId
            ),
            ARRAY_A
        );

        if ( null === $row ) {
            return [ 'folder_id' => null, 'sort_files' => 'date', 'sort_dir' => 'desc', 'sidebar_w' => 300 ];
        }

        return [
            'folder_id'  => isset( $row['folder_id'] ) ? (int) $row['folder_id'] : null,
            'sort_files' => (string) ( $row['sort_files'] ?? 'date' ),
            'sort_dir'   => (string) ( $row['sort_dir']   ?? 'desc' ),
            'sidebar_w'  => (int)    ( $row['sidebar_w']  ?? 300 ),
        ];
    }

    /**
     * Returns true when WooCommerce is active.
     */
    private function wooActive(): bool {
        return function_exists( 'WC' ) || class_exists( 'WooCommerce' );
    }
}
