<?php

declare(strict_types=1);

namespace MediaPilotAI\Media;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderRepository;

/**
 * Registers the "Media › Duplicates" admin page (S37).
 *
 * Outputs a single <div id="mediapilot-duplicates-root"> and the MediaPilotConfig global
 * so the shared mediapilot-admin.js bundle can mount the DuplicatesPage React app
 * on this page.
 *
 * @package MediaPilotAI\Media
 * @since   1.0.0
 */
class DuplicatesAdminPage {

    private const PAGE_SLUG  = 'mediapilot-duplicates';
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
            __( 'Duplicate Files', 'mediapilot-ai'),
            __( 'Duplicates', 'mediapilot-ai'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [ $this, 'renderPage' ]
        );

        // Enqueue assets only on this page.
        add_action( 'admin_enqueue_scripts', function ( string $hookSuffix ) use ( $hook ): void {
            if ( $hookSuffix !== $hook ) {
                return;
            }
            $this->enqueueAssets();
        } );
    }

    // -------------------------------------------------------------------------
    // Page render
    // -------------------------------------------------------------------------

    public function renderPage(): void {
        ?>
        <div class="wrap">
            <div id="mediapilot-duplicates-root"></div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Asset enqueueing
    // -------------------------------------------------------------------------

    private function enqueueAssets(): void {
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

        // Inject the React bootstrap config inline (no raw <script> tag).
        wp_add_inline_script(
            'mediapilot-admin',
            'window.MediaPilotConfig = ' . wp_json_encode( $this->buildConfig() ) . ';',
            'before'
        );
    }

    // -------------------------------------------------------------------------
    // Config builder
    // -------------------------------------------------------------------------

    /**
     * Build the MediaPilotConfig object that the React bundle expects.
     *
     * @return array<string, mixed>
     */
    private function buildConfig(): array {
        $userId   = get_current_user_id();
        $settings = (array) get_option( 'mdpai_settings', [] );

        $folderMode = isset( $settings['folder_mode'] ) ? (string) $settings['folder_mode'] : 'global';

        return [
            'restUrl'     => rest_url( 'mediapilot/v1/' ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'userId'      => $userId,
            'isAdmin'     => current_user_can( 'manage_options' ),
            'folderMode'  => $folderMode,
            'initialTree' => $this->folderRepository->getTree( $folderMode === 'global' ? 0 : $userId ),
            'userPrefs'   => [
                'folder_id'  => null,
                'sort_files' => 'date',
                'sort_dir'   => 'desc',
                'sidebar_w'  => 240,
                'ui_theme'   => 'default',
            ],
            'licenceTier' => 'free',
            'page'        => 'duplicates',
        ];
    }
}
