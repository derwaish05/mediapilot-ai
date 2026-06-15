<?php
/**
 * Plugin Name:       MediaPilot AI
 * Plugin URI:        https://github.com/derwaish05/mediapilot-ai
 * Description:       AI-powered media management, organization, optimization, and digital asset management for WordPress.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            BrainStudioz
 * Author URI:        https://brainstudioz.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mediapilot-ai
 * Domain Path:       /languages
 */

declare(strict_types=1);

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants.
define( 'MDPAI_VERSION',    '1.0.0' );
define( 'MDPAI_DB_VERSION', '1.1.0' );
define( 'MDPAI_PATH',       plugin_dir_path( __FILE__ ) );
define( 'MDPAI_URL',        plugin_dir_url( __FILE__ ) );
define( 'MDPAI_BASENAME',   plugin_basename( __FILE__ ) );
define( 'MDPAI_FILE',       __FILE__ );

// Autoloader.
if ( file_exists( MDPAI_PATH . 'vendor/autoload.php' ) ) {
    require_once MDPAI_PATH . 'vendor/autoload.php';
} else {
    // Fallback manual autoloader (used before `composer install`).
    spl_autoload_register( function ( string $class ): void {
        $prefix = 'MediaPilotAI\\';
        $base   = MDPAI_PATH . 'includes/';

        if ( ! str_starts_with( $class, $prefix ) ) {
            return;
        }

        $relative = substr( $class, strlen( $prefix ) );
        $file     = $base . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';

        if ( file_exists( $file ) ) {
            require_once $file;
        }
    } );
}

// Activation hook.
register_activation_hook( MDPAI_FILE, function (): void {
    MediaPilotAI\Core\Installer::install();
} );

// Deactivation hook.
register_deactivation_hook( MDPAI_FILE, function (): void {
    flush_rewrite_rules();
} );

// Uninstall is handled via uninstall.php (WP calls it directly).

// Boot the plugin after all plugins are loaded.
add_action( 'plugins_loaded', function (): void {
    MediaPilotAI\Core\Plugin::getInstance()->boot();
} );
