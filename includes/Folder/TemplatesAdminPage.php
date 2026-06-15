<?php

declare(strict_types=1);

namespace MediaPilotAI\Folder;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Registers the "Media › Folder Templates" admin page (S38).
 *
 * Outputs <div id="mediapilot-templates-root"> and the MediaPilotConfig global so the
 * shared mediapilot-admin.js bundle can mount the FolderTemplatesPage React app.
 *
 * @package MediaPilotAI\Folder
 * @since   1.0.0
 */
class TemplatesAdminPage {

    private const PAGE_SLUG  = 'mediapilot-folder-templates';
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
            __( 'Folder Templates', 'mediapilot-ai'),
            __( 'Folder Templates', 'mediapilot-ai'),
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
            <div id="mediapilot-templates-root"></div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Assets
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
            'page'        => 'folder-templates',
        ];
    }
}
