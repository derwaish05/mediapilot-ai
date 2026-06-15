<?php

declare(strict_types=1);

namespace MediaPilotAI\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Cleans up all plugin data when the user deletes the plugin
 * via the WordPress admin Plugins screen.
 *
 * Called from uninstall.php (WordPress invokes that file directly,
 * bypassing the normal plugin boot sequence).
 */
final class Uninstaller {

    public static function uninstall(): void {
        // Guard: only run when WP triggers uninstall.
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            return;
        }

        self::dropTables();
        self::deleteOptions();
        self::deletePostMeta();
        self::deleteUserMeta();
        self::deleteTermMeta();
        self::removeTaxonomy();
        self::removeCapabilities();
        self::clearScheduledEvents();
    }

    // -------------------------------------------------------------------------

    private static function dropTables(): void {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'mdpai_tag_relationships',
            $wpdb->prefix . 'mdpai_tags',
            $wpdb->prefix . 'mdpai_analytics',
            $wpdb->prefix . 'mdpai_permissions',
            $wpdb->prefix . 'mdpai_usage',
            $wpdb->prefix . 'mdpai_versions',
            $wpdb->prefix . 'mdpai_user_prefs',
            $wpdb->prefix . 'mdpai_share_links',
            $wpdb->prefix . 'mdpai_share_downloads',
        ];

        foreach ( $tables as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
        }
    }

    private static function deleteOptions(): void {
        $options = [
            'mdpai_settings',
            'mdpai_db_version',
            'mdpai_ai_settings',          // Contains Google Vision / AWS API credentials.
            'mdpai_optimization_settings',
            'mdpai_filesystem_settings',
            'mdpai_folder_templates',
            'mdpai_analytics_queue',
            'mdpai_portal_rules_flushed',
            'mdpai_doclib_public_roots',
        ];

        foreach ( $options as $option ) {
            delete_option( $option );
        }

        // Remove dynamically named migration ID maps and all MediaPilot AI transients.
        global $wpdb;
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE 'mdpai\_migration\_%'
                OR option_name LIKE '_transient_mdpai_%'
                OR option_name LIKE '_transient_timeout_mdpai_%'"
        );
    }

    private static function deletePostMeta(): void {
        global $wpdb;

        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "DELETE FROM {$wpdb->postmeta}
             WHERE meta_key LIKE 'mdpai_%'"
        );
    }

    private static function deleteUserMeta(): void {
        global $wpdb;

        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "DELETE FROM {$wpdb->usermeta}
             WHERE meta_key LIKE 'mdpai\_%'"
        );
    }

    private static function deleteTermMeta(): void {
        global $wpdb;

        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "DELETE FROM {$wpdb->termmeta}
             WHERE meta_key LIKE 'mdpai_%'"
        );
    }

    /**
     * Remove all terms and the taxonomy itself.
     * We delete terms assigned to the mdpai_folder taxonomy so no orphan rows
     * remain in wp_terms / wp_term_taxonomy / wp_term_relationships.
     */
    private static function removeTaxonomy(): void {
        $terms = get_terms( [
            'taxonomy'   => 'mdpai_folder',
            'hide_empty' => false,
            'fields'     => 'ids',
        ] );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return;
        }

        foreach ( $terms as $termId ) {
            wp_delete_term( (int) $termId, 'mdpai_folder' );
        }
    }

    private static function removeCapabilities(): void {
        $roles = [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ];
        $caps  = [ 'manage_mdpai_folders', 'manage_mdpai_settings' ];

        foreach ( $roles as $roleName ) {
            $role = get_role( $roleName );

            if ( ! $role ) {
                continue;
            }

            foreach ( $caps as $cap ) {
                $role->remove_cap( $cap );
            }
        }
    }

    private static function clearScheduledEvents(): void {
        $hooks = [
            'mdpai_import_batch',
            'mdpai_duplicate_scan',
            'mdpai_cloud_sync',
            'mdpai_backup_run',
            'mdpai_usage_scan',
        ];

        foreach ( $hooks as $hook ) {
            $timestamp = wp_next_scheduled( $hook );

            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, $hook );
            }
        }
    }
}
