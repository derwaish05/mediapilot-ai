<?php

declare(strict_types=1);

namespace MediaPilotAI\Folder;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Data-access layer for the wp_mdpai_permissions table.
 *
 * Table shape:
 *   folder_id  — mdpai_folder term ID
 *   entity     — 'role' | 'user'
 *   entity_id  — WP role slug (e.g. 'editor') or user ID as string
 *   can_read   — 0|1
 *   can_write  — 0|1
 *   can_delete — 0|1
 *
 * @package MediaPilotAI\Folder
 * @since   1.0.0
 */
class PermissionRepository {

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Returns all permission rows for a folder, ordered: user rules first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getForFolder( int $folderId ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'mdpai_permissions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                "SELECT id, folder_id, entity, entity_id, can_read, can_write, can_delete
                 FROM `{$table}`
                 WHERE folder_id = %d
                 ORDER BY entity DESC",  // 'user' > 'role' alphabetically
                $folderId
            ), // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );

        return is_array( $rows ) ? array_map( [ $this, 'cast' ], $rows ) : [];
    }

    /**
     * Returns a single permission row, or null if none exists.
     *
     * @return array<string, mixed>|null
     */
    public function get( int $folderId, string $entity, string $entityId ): ?array {
        global $wpdb;

        $table = $wpdb->prefix . 'mdpai_permissions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                "SELECT id, folder_id, entity, entity_id, can_read, can_write, can_delete
                 FROM `{$table}`
                 WHERE folder_id = %d AND entity = %s AND entity_id = %s
                 LIMIT 1",
                $folderId,
                $entity,
                $entityId
            ), // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );

        return is_array( $row ) ? $this->cast( $row ) : null;
    }

    /**
     * Inserts or updates a permission row (upsert via ON DUPLICATE KEY UPDATE).
     */
    public function upsert(
        int    $folderId,
        string $entity,
        string $entityId,
        bool   $canRead,
        bool   $canWrite,
        bool   $canDelete
    ): bool {
        global $wpdb;

        $table = $wpdb->prefix . 'mdpai_permissions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                "INSERT INTO `{$table}` (folder_id, entity, entity_id, can_read, can_write, can_delete)
                 VALUES (%d, %s, %s, %d, %d, %d)
                 ON DUPLICATE KEY UPDATE
                   can_read   = VALUES(can_read),
                   can_write  = VALUES(can_write),
                   can_delete = VALUES(can_delete)",
                $folderId,
                $entity,
                $entityId,
                (int) $canRead,
                (int) $canWrite,
                (int) $canDelete
            ) // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );

        return false !== $result;
    }

    /**
     * Deletes a single permission row.
     */
    public function delete( int $folderId, string $entity, string $entityId ): bool {
        global $wpdb;

        $table = $wpdb->prefix . 'mdpai_permissions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $table,
            [
                'folder_id' => $folderId,
                'entity'    => $entity,
                'entity_id' => $entityId,
            ],
            [ '%d', '%s', '%s' ]
        );

        return false !== $result;
    }

    /**
     * Deletes all permission rows for a folder (called when a folder is deleted).
     */
    public function deleteAllForFolder( int $folderId ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'mdpai_permissions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->delete( $table, [ 'folder_id' => $folderId ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Returns true if the folder has ANY explicit permission rows.
     * Used to decide whether client-isolation applies.
     */
    public function folderHasRules( int $folderId ): bool {
        global $wpdb;

        $table = $wpdb->prefix . 'mdpai_permissions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT COUNT(*) FROM `{$table}` WHERE folder_id = %d LIMIT 1",  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $folderId
            )
        );

        return $count > 0;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Casts raw DB row strings to typed values.
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function cast( array $row ): array {
        return [
            'id'         => (int)    $row['id'],
            'folder_id'  => (int)    $row['folder_id'],
            'entity'     => (string) $row['entity'],
            'entity_id'  => (string) $row['entity_id'],
            'can_read'   => (bool)   $row['can_read'],
            'can_write'  => (bool)   $row['can_write'],
            'can_delete' => (bool)   $row['can_delete'],
        ];
    }
}
