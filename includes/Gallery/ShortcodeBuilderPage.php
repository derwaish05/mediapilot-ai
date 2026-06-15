<?php

declare(strict_types=1);

namespace MediaPilotAI\Gallery;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderRepository;

/**
 * Registers the "Media › Shortcode Builder" admin page (S39).
 *
 * Outputs <div id="mediapilot-builder-root"> and injects MediaPilotConfig so the shared
 * mediapilot-admin.js bundle can mount the ShortcodeBuilderPage React wizard.
 *
 * Also enqueues the gallery CSS on this page so the live preview renders
 * correctly inside the React component.
 *
 * @package MediaPilotAI\Gallery
 * @since   1.0.0
 */
class ShortcodeBuilderPage {

    private const PAGE_SLUG  = 'mediapilot-shortcode-builder';
    private const CAPABILITY = 'upload_files';

    public function __construct(
        private readonly FolderRepository $folderRepository,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'addMenuPage' ] );
    }

    // -------------------------------------------------------------------------
    // Menu
    // -------------------------------------------------------------------------

    public function addMenuPage(): void {
        $hook = add_submenu_page(
            'upload.php',
            __( 'Shortcode Builder', 'mediapilot-ai'),
            __( 'Shortcode Builder', 'mediapilot-ai'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [ $this, 'renderPage' ]
        );

        add_action( 'admin_enqueue_scripts', function ( string $hookSuffix ) use ( $hook ): void {
            if ( $hookSuffix !== $hook ) {
                return;
            }
            $this->enqueueAssets();
        } );
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function renderPage(): void {
        ?>
        <div class="wrap">
            <div id="mediapilot-builder-root"></div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Assets
    // -------------------------------------------------------------------------

    private function enqueueAssets(): void {
        // Main React bundle.
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

        // Inject the React bootstrap config inline, attached to the bundle
        // handle so it runs before mediapilot-admin.js (no raw <script> tag).
        wp_add_inline_script(
            'mediapilot-admin',
            'window.MediaPilotConfig = ' . wp_json_encode( $this->buildConfig() ) . ';',
            'before'
        );

        // Gallery CSS — needed so the live preview renders correctly.
        wp_enqueue_style(
            'mediapilot-gallery-block',
            MDPAI_URL . 'admin/assets/css/block-mediapilot-gallery.css',
            [],
            MDPAI_VERSION
        );

        // Carousel JS — needed so the live preview works for carousel layout.
        wp_enqueue_script(
            'mediapilot-gallery-carousel',
            MDPAI_URL . 'admin/assets/js/mediapilot-gallery-carousel.js',
            [],
            MDPAI_VERSION,
            true
        );
    }

    // -------------------------------------------------------------------------
    // Config
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function buildConfig(): array {
        $userId   = get_current_user_id();
        $settings = (array) get_option( 'mdpai_settings', [] );
        $mode     = isset( $settings['folder_mode'] ) ? (string) $settings['folder_mode'] : 'global';

        return [
            'restUrl'     => rest_url( 'mediapilot/v1/' ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'userId'      => $userId,
            'isAdmin'     => current_user_can( 'manage_options' ),
            'folderMode'  => $mode,
            'initialTree' => $this->folderRepository->getTree( $mode === 'global' ? 0 : $userId ),
            'userPrefs'   => [
                'folder_id'  => null,
                'sort_files' => 'date',
                'sort_dir'   => 'desc',
                'sidebar_w'  => 240,
                'ui_theme'   => 'default',
            ],
            'licenceTier' => 'free',
            'page'        => 'shortcode-builder',
        ];
    }
}
