<?php

declare(strict_types=1);

namespace MediaPilotAI\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Handles database migrations between plugin versions.
 *
 * Each migration is a private static method named `migrate_X_Y_Z()`
 * where X.Y.Z is the version that introduces the change.
 * Migrations run in ascending version order and are never repeated.
 */
final class Upgrader {

    /**
     * Called on every page load (via Plugin::boot).
     * Compares the stored DB version against MDPAI_DB_VERSION and runs
     * any pending migrations.
     */
    public static function maybeUpgrade(): void {
        $stored = get_option( 'mdpai_db_version', '0.0.0' );

        if ( version_compare( $stored, MDPAI_DB_VERSION, '>=' ) ) {
            return;
        }

        self::runMigrations( $stored );

        update_option( 'mdpai_db_version', MDPAI_DB_VERSION, true );
    }

    // -------------------------------------------------------------------------
    // Migration runner
    // -------------------------------------------------------------------------

    private static function runMigrations( string $fromVersion ): void {
        $migrations = self::getMigrations();

        foreach ( $migrations as $version => $method ) {
            if ( version_compare( $fromVersion, $version, '<' ) ) {
                self::$method();
            }
        }
    }

    /**
     * Returns ordered map of version → migration method name.
     * Add new entries here for each future release.
     *
     * @return array<string, string>
     */
    private static function getMigrations(): array {
        return [
            '1.0.0' => 'migrate_1_0_0',
            '1.1.0' => 'migrate_1_1_0',
        ];
    }

    // -------------------------------------------------------------------------
    // Individual migrations
    // -------------------------------------------------------------------------

    /**
     * v1.0.0 — initial schema (tables created by Installer on first activation).
     * For users upgrading from a pre-release version, re-run dbDelta to ensure
     * all tables exist with the latest schema.
     */
    private static function migrate_1_0_0(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $sql     = Installer::getSchemaSql( $wpdb->prefix, $charset );

        dbDelta( $sql );
    }

    /**
     * v1.1.0 — ensure mdpai_tags + mdpai_tag_relationships tables exist for sites
     * that installed the plugin before these tables were added to the schema.
     * dbDelta is idempotent and skips tables/columns that already exist.
     */
    private static function migrate_1_1_0(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $sql     = Installer::getSchemaSql( $wpdb->prefix, $charset );

        dbDelta( $sql );
    }
}
