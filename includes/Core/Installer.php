<?php

declare(strict_types=1);

namespace MediaPilotAI\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Runs on plugin activation.
 * Creates all custom DB tables and sets default options.
 */
final class Installer {

    /**
     * Entry point called by the activation hook in the main plugin file.
     */
    public static function install(): void {
        self::createTables();
        self::setDefaultOptions();
        self::addCapabilities();

        // Store the DB version so Upgrader knows which migrations to run.
        update_option( 'mdpai_db_version', MDPAI_DB_VERSION, true );

        flush_rewrite_rules();
    }

    // -------------------------------------------------------------------------
    // Tables
    // -------------------------------------------------------------------------

    private static function createTables(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        // Load dbDelta utility.
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = self::getSchemaSql( $wpdb->prefix, $charset );

        dbDelta( $sql );
    }

    /**
     * Returns the full CREATE TABLE SQL for all MediaPilot tables.
     * Each statement must end with the charset collate and have two spaces
     * before each column/key — required by dbDelta.
     */
    public static function getSchemaSql( string $prefix, string $charset ): string {
        $tables = [];

        // ------------------------------------------------------------------
        // Per-user UI preferences and active folder memory.
        // ------------------------------------------------------------------
        $tables[] = "CREATE TABLE {$prefix}mdpai_user_prefs (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id bigint(20) UNSIGNED NOT NULL,
  folder_id bigint(20) UNSIGNED DEFAULT NULL,
  sort_files varchar(20) NOT NULL DEFAULT 'date',
  sort_dir varchar(4) NOT NULL DEFAULT 'desc',
  sidebar_w smallint(5) UNSIGNED NOT NULL DEFAULT 220,
  ui_theme varchar(30) NOT NULL DEFAULT 'default',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY user_id (user_id)
) {$charset};";

        // ------------------------------------------------------------------
        // File version history (for version control / rollback).
        // ------------------------------------------------------------------
        $tables[] = "CREATE TABLE {$prefix}mdpai_versions (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  attachment_id bigint(20) UNSIGNED NOT NULL,
  file_path varchar(500) NOT NULL,
  file_hash varchar(64) NOT NULL,
  file_size bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  replaced_by bigint(20) UNSIGNED DEFAULT NULL,
  created_by bigint(20) UNSIGNED NOT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY attachment_id (attachment_id),
  KEY created_at (created_at)
) {$charset};";

        // ------------------------------------------------------------------
        // Tracks where each attachment is used (posts, widgets, ACF, etc.).
        // ------------------------------------------------------------------
        $tables[] = "CREATE TABLE {$prefix}mdpai_usage (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  attachment_id bigint(20) UNSIGNED NOT NULL,
  object_id bigint(20) UNSIGNED NOT NULL,
  object_type varchar(50) NOT NULL,
  context varchar(100) DEFAULT NULL,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY attachment_object_type (attachment_id, object_id, object_type),
  KEY object_id (object_id)
) {$charset};";

        // ------------------------------------------------------------------
        // Folder-level role / user permission overrides.
        // ------------------------------------------------------------------
        $tables[] = "CREATE TABLE {$prefix}mdpai_permissions (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  folder_id bigint(20) UNSIGNED NOT NULL,
  entity varchar(20) NOT NULL COMMENT 'role or user',
  entity_id varchar(100) NOT NULL COMMENT 'role slug or user ID',
  can_read tinyint(1) NOT NULL DEFAULT 1,
  can_write tinyint(1) NOT NULL DEFAULT 0,
  can_delete tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY folder_entity (folder_id, entity, entity_id),
  KEY folder_id (folder_id)
) {$charset};";

        // ------------------------------------------------------------------
        // Analytics events (file views, inserts, downloads).
        // ------------------------------------------------------------------
        $tables[] = "CREATE TABLE {$prefix}mdpai_analytics (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  attachment_id bigint(20) UNSIGNED NOT NULL,
  event_type varchar(30) NOT NULL COMMENT 'view, insert, download',
  user_id bigint(20) UNSIGNED DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY attachment_date (attachment_id, created_at),
  KEY event_type (event_type)
) {$charset};";

        // ------------------------------------------------------------------
        // Smart tags.
        // ------------------------------------------------------------------
        $tables[] = "CREATE TABLE {$prefix}mdpai_tags (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  name varchar(200) NOT NULL,
  slug varchar(200) NOT NULL,
  color varchar(7) NOT NULL DEFAULT '#3b82f6',
  created_by bigint(20) UNSIGNED NOT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) {$charset};";

        // ------------------------------------------------------------------
        // Many-to-many: tags ↔ attachments.
        // ------------------------------------------------------------------
        $tables[] = "CREATE TABLE {$prefix}mdpai_tag_relationships (
  tag_id bigint(20) UNSIGNED NOT NULL,
  attachment_id bigint(20) UNSIGNED NOT NULL,
  PRIMARY KEY  (tag_id, attachment_id),
  KEY attachment_id (attachment_id)
) {$charset};";

        return implode( "\n\n", $tables );
    }

    // -------------------------------------------------------------------------
    // Options
    // -------------------------------------------------------------------------

    private static function setDefaultOptions(): void {
        $defaults = [
            'folder_mode'        => 'global',   // global | per_user
            'default_sort'       => 'date',
            'default_sort_dir'   => 'desc',
            'default_theme'      => 'default',  // default | win11 | dropbox
            'auto_assign_folder' => true,
            'svg_upload'         => true,
            'post_types'         => [ 'attachment' ],
        ];

        // add_option does nothing if the option already exists — safe to call on re-activation.
        add_option( 'mdpai_settings', $defaults, '', false );
    }

    // -------------------------------------------------------------------------
    // Capabilities
    // -------------------------------------------------------------------------

    private static function addCapabilities(): void {
        $admin = get_role( 'administrator' );

        if ( $admin ) {
            $admin->add_cap( 'manage_mdpai_folders' );
            $admin->add_cap( 'manage_mdpai_settings' );
        }

        $editor = get_role( 'editor' );

        if ( $editor ) {
            $editor->add_cap( 'manage_mdpai_folders' );
        }
    }
}
