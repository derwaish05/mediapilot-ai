<?php
/**
 * Fired when the plugin is uninstalled via the WP admin Plugins screen.
 * WordPress calls this file directly — no plugin code is loaded.
 */

declare(strict_types=1);

// WP guard: only run when called by WordPress uninstall process.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Bootstrap just enough to use the Uninstaller class.
require_once __DIR__ . '/includes/Core/Uninstaller.php';

MediaPilotAI\Core\Uninstaller::uninstall();
