<?php

declare(strict_types=1);

namespace MediaPilotAI\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Data-access layer for client share links and download-log entries.
 *
 * Tables managed:
 *   wp_mdpai_share_links      — one row per share link
 *   wp_mdpai_share_downloads  — one row per file download event
 *
 * @package MediaPilotAI\Frontend
 * @since   1.0.0
 */
class ShareLinkRepository {

    // -------------------------------------------------------------------------
    // Table names (resolved at runtime)
    // -------------------------------------------------------------------------

    public function linksTable(): string {
        global $wpdb;
        return $wpdb->prefix . 'mdpai_share_links';
    }

    public function downloadsTable(): string {
        global $wpdb;
        return $wpdb->prefix . 'mdpai_share_downloads';
    }

    // -------------------------------------------------------------------------
    // Schema
    // -------------------------------------------------------------------------

    /**
     * Create or upgrade the share-link tables.
     * Safe to call repeatedly — uses dbDelta().
     */
    public function createTables(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        $linksTable     = $this->linksTable();
        $downloadsTable = $this->downloadsTable();

        $sql = "
CREATE TABLE {$linksTable} (
  id            bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  token         varchar(64)  NOT NULL,
  folder_id     bigint(20) UNSIGNED NOT NULL,
  password_hash varchar(255) NOT NULL DEFAULT '',
  expires_at    datetime     DEFAULT NULL,
  logo_url      varchar(500) NOT NULL DEFAULT '',
  header_color  varchar(7)   NOT NULL DEFAULT '#2563eb',
  created_by    bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  created_at    datetime     NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY   token (token),
  KEY          folder_id (folder_id)
) {$charset};

CREATE TABLE {$downloadsTable} (
  id             bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  share_id       bigint(20) UNSIGNED NOT NULL,
  attachment_id  bigint(20) UNSIGNED NOT NULL,
  ip             varchar(45)  NOT NULL DEFAULT '',
  email          varchar(255) NOT NULL DEFAULT '',
  downloaded_at  datetime     NOT NULL,
  PRIMARY KEY  (id),
  KEY          share_id (share_id),
  KEY          attachment_id (attachment_id)
) {$charset};
";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    // -------------------------------------------------------------------------
    // Share link CRUD
    // -------------------------------------------------------------------------

    /**
     * Create a new share link and return it.
     *
     * @param  int                  $folderId
     * @param  array<string, mixed> $options   password, expires_at (Y-m-d H:i:s|null),
     *                                         logo_url, header_color.
     * @param  int                  $userId    WP user who created the link.
     * @return array<string, mixed>|null
     */
    public function create( int $folderId, array $options, int $userId ): ?array {
        global $wpdb;

        $token        = $this->generateToken();
        $passwordHash = ! empty( $options['password'] )
            ? wp_hash_password( (string) $options['password'] )
            : '';

        $expiresAt   = ! empty( $options['expires_at'] ) ? (string) $options['expires_at'] : null;
        $logoUrl     = sanitize_url( (string) ( $options['logo_url']     ?? '' ) );
        $headerColor = sanitize_hex_color( (string) ( $options['header_color'] ?? '#2563eb' ) ) ?: '#2563eb';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $this->linksTable(),
            [
                'token'         => $token,
                'folder_id'     => $folderId,
                'password_hash' => $passwordHash,
                'expires_at'    => $expiresAt,
                'logo_url'      => $logoUrl,
                'header_color'  => $headerColor,
                'created_by'    => $userId,
                'created_at'    => current_time( 'mysql' ),
            ],
            [ '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s' ]
        );

        if ( ! $inserted ) {
            return null;
        }

        return $this->getByToken( $token );
    }

    /**
     * Fetch a share link row by token.
     *
     * @return array<string, mixed>|null
     */
    public function getByToken( string $token ): ?array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM {$this->linksTable()} WHERE token = %s",  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $token
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Fetch a share link row by its primary key ID.
     *
     * @return array<string, mixed>|null
     */
    public function getById( int $id ): ?array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM {$this->linksTable()} WHERE id = %d",  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Return all share links, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAll(): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            "SELECT * FROM {$this->linksTable()} ORDER BY created_at DESC",  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Return all share links for a given folder.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getByFolder( int $folderId ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM {$this->linksTable()} WHERE folder_id = %d ORDER BY created_at DESC",  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $folderId
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Delete a share link (revoke it). Returns true if a row was deleted.
     */
    public function delete( int $id ): bool {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $this->linksTable(),
            [ 'id' => $id ],
            [ '%d' ]
        );

        return (bool) $deleted;
    }

    // -------------------------------------------------------------------------
    // Download log
    // -------------------------------------------------------------------------

    /**
     * Record a file-download event.
     *
     * @param  int    $shareId       Share-link row ID.
     * @param  int    $attachmentId  WP attachment ID.
     * @param  string $ip            Client IP address.
     * @param  string $email         Client email (from gate prompt, may be empty).
     */
    public function logDownload( int $shareId, int $attachmentId, string $ip, string $email = '' ): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $this->downloadsTable(),
            [
                'share_id'      => $shareId,
                'attachment_id' => $attachmentId,
                'ip'            => $ip,
                'email'         => sanitize_email( $email ),
                'downloaded_at' => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%s', '%s', '%s' ]
        );
    }

    /**
     * Return download log rows for a share link, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDownloads( int $shareId ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM {$this->downloadsTable()} WHERE share_id = %d ORDER BY downloaded_at DESC",  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $shareId
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Total download count for a share link.
     */
    public function downloadCount( int $shareId ): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT COUNT(*) FROM {$this->downloadsTable()} WHERE share_id = %d",  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $shareId
            )
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Check whether a share link is still valid (not expired).
     *
     * @param array<string, mixed> $link  Row from getByToken().
     */
    public function isValid( array $link ): bool {
        if ( empty( $link['expires_at'] ) ) {
            return true;
        }

        return strtotime( (string) $link['expires_at'] ) > time();
    }

    private function generateToken(): string {
        return bin2hex( random_bytes( 32 ) );
    }
}
