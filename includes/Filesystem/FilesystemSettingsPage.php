<?php

declare(strict_types=1);

namespace MediaPilotAI\Filesystem;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Admin settings page for Real Filesystem Mode (S57).
 *
 * Registered under Media › Filesystem.
 * Option key: mdpai_filesystem_settings
 *
 * Settings:
 *   enabled  bool   Whether Real Filesystem Mode is active.
 *
 * @package MediaPilotAI\Filesystem
 * @since   1.0.0
 */
class FilesystemSettingsPage {

    private const PAGE_SLUG  = 'mediapilot-filesystem';
    private const SECTION_ID = 'mdpai_fs_section';
    private const CAPABILITY = 'manage_mdpai_settings';

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'addMenuPage' ] );
        add_action( 'admin_init', [ $this, 'registerSettings' ] );
    }

    // -------------------------------------------------------------------------
    // Menu
    // -------------------------------------------------------------------------

    public function addMenuPage(): void {
        add_submenu_page(
            'upload.php',
            __( 'MediaPilot Filesystem', 'mediapilot-ai'),
            __( 'Filesystem', 'mediapilot-ai'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [ $this, 'renderPage' ]
        );
    }

    // -------------------------------------------------------------------------
    // Settings API
    // -------------------------------------------------------------------------

    public function registerSettings(): void {
        register_setting(
            self::PAGE_SLUG,
            RealFolderSync::OPTION_NAME,
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize' ],
                'default'           => [ 'enabled' => false ],
            ]
        );

        add_settings_section( self::SECTION_ID, '', '__return_false', self::PAGE_SLUG );

        add_settings_field(
            'mdpai_fs_enabled',
            __( 'Real Filesystem Mode', 'mediapilot-ai'),
            [ $this, 'renderEnabled' ],
            self::PAGE_SLUG,
            self::SECTION_ID
        );
    }

    public function sanitize( mixed $raw ): array {
        $raw = is_array( $raw ) ? $raw : [];
        return [ 'enabled' => ! empty( $raw['enabled'] ) ];
    }

    // -------------------------------------------------------------------------
    // Field renderers
    // -------------------------------------------------------------------------

    public function renderEnabled(): void {
        $settings = (array) get_option( RealFolderSync::OPTION_NAME, [] );
        $checked  = checked( ! empty( $settings['enabled'] ), true, false );

        $uploads  = wp_get_upload_dir();
        $root     = rtrim( $uploads['basedir'], '/' ) . '/mediapilot-ai/';

        echo "<label><input type='checkbox' name='" . esc_attr( RealFolderSync::OPTION_NAME ) . "[enabled]' value='1' " . esc_attr( $checked ) . '> '
            . esc_html__( 'Enable Real Filesystem Mode', 'mediapilot-ai')
            . '</label>';
        echo "<p class='description'>"
           . sprintf(
               /* translators: %s: upload directory path */
               esc_html__( 'Files will be physically moved to %s{folder-slug}/ when assigned to a folder.', 'mediapilot-ai'),
               '<code>' . esc_html( $root ) . '</code>'
           )
           . '</p>';
    }

    // -------------------------------------------------------------------------
    // Page render
    // -------------------------------------------------------------------------

    public function renderPage(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'mediapilot-ai') );
        }

        $uploads = wp_get_upload_dir();
        $root    = rtrim( $uploads['basedir'], '/' ) . '/mediapilot-ai/';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'MediaPilot Filesystem Settings', 'mediapilot-ai'); ?></h1>

            <!-- Danger warning -->
            <div style="background:#fff3cd;border:1px solid #ffc107;border-left:4px solid #e65c00;border-radius:4px;padding:16px 20px;margin:16px 0">
                <p style="margin:0 0 8px;font-weight:600;font-size:14px;color:#7a3e00">
                    ⚠ <?php esc_html_e( 'Warning: Real Filesystem Mode physically moves files on disk.', 'mediapilot-ai'); ?>
                </p>
                <ul style="margin:0 0 0 20px;color:#5a3000;font-size:13px">
                    <li><?php esc_html_e( 'Always test on a staging site before enabling on production.', 'mediapilot-ai'); ?></li>
                    <li><?php esc_html_e( 'Take a full database and filesystem backup before enabling.', 'mediapilot-ai'); ?></li>
                    <li><?php esc_html_e( 'Moving files updates all known URL references, but hard-coded URLs in theme templates are not touched.', 'mediapilot-ai'); ?></li>
                    <li><?php esc_html_e( 'This feature cannot be auto-reversed; disable it and run a manual restore if needed.', 'mediapilot-ai'); ?></li>
                </ul>
            </div>

            <?php settings_errors(); ?>

            <!-- Settings form -->
            <form method="post" action="options.php">
                <?php settings_fields( self::PAGE_SLUG ); ?>
                <?php do_settings_sections( self::PAGE_SLUG ); ?>
                <?php submit_button(); ?>
            </form>

            <!-- Directory structure info -->
            <hr style="margin:24px 0">
            <h2 style="font-size:14px"><?php esc_html_e( 'Directory Structure', 'mediapilot-ai'); ?></h2>
            <p><?php esc_html_e( 'When enabled, files are organised as follows:', 'mediapilot-ai'); ?></p>
            <pre style="background:#f1f5f9;padding:12px 16px;border-radius:4px;font-size:12px;overflow-x:auto"><?php
                echo esc_html( $root ) . "\n";
                echo "├── uncategorized/   (files not assigned to any folder)\n";
                echo "├── holiday-photos/  (slug of a folder named \"Holiday Photos\")\n";
                echo "└── product-shots/\n";
            ?></pre>

            <!-- CLI sync info -->
            <h2 style="font-size:14px"><?php esc_html_e( 'Manual Reconciliation', 'mediapilot-ai'); ?></h2>
            <p><?php esc_html_e( 'If files become out of sync (e.g. after a bulk import), run the CLI sync tool:', 'mediapilot-ai'); ?></p>
            <pre style="background:#f1f5f9;padding:12px 16px;border-radius:4px;font-size:12px">wp mediapilot filesystem sync
wp mediapilot filesystem sync --dry-run   # preview only</pre>
        </div>
        <?php
    }
}
