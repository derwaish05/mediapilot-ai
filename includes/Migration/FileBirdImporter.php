<?php

declare(strict_types=1);

namespace MediaPilotAI\Migration;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderRepository;
use MediaPilotAI\Taxonomy\FolderTaxonomy;

/**
 * Imports folders from the FileBird plugin.
 *
 * FileBird stores its data in two custom DB tables (NOT taxonomy terms):
 *   wp_fbv                    — folders: id, name, parent, type (0=folder,1=collection)
 *   wp_fbv_attachment_folder  — assignments: folder_id, attachment_id
 *
 * @package MediaPilotAI\Migration
 * @since   1.0.0
 */
class FileBirdImporter implements PluginImporterInterface {

    public function __construct(
        private readonly FolderRepository $folderRepository,
    ) {}

    public function getLabel(): string {
        return 'FileBird';
    }

    public function getSourceTaxonomy(): string {
        return 'wp_fbv (custom table)';
    }

    /**
     * Available when the wp_fbv table exists (FileBird is/was active).
     */
    public function isAvailable(): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'fbv';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Two-phase batch import.
     *
     * Phase A (offset=0, nothing created yet): import folder rows from wp_fbv.
     * Phase B (offset>0 or after Phase A): import attachment assignments in pages.
     */
    public function runBatch( ImportProgress $progress, int $batchSize = 50 ): bool {
        if ( 0 === $progress->offset && 0 === $progress->created + $progress->skipped ) {
            $this->importFolders( $progress );
            $progress->save( 'filebird' );
        }

        return $this->importAssignmentsBatch( $progress, $batchSize );
    }

    // -------------------------------------------------------------------------
    // Private — Phase A: import folder hierarchy
    // -------------------------------------------------------------------------

    private function importFolders( ImportProgress $progress ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'fbv';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            "SELECT id, name, parent FROM `{$table}` WHERE type = 0 ORDER BY parent ASC, id ASC",  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            $progress->total = 0;
            return;
        }

        $idMap = []; // fbv id → mdpai_folder term_id

        foreach ( $rows as $row ) {
            $oldId    = (int) $row['id'];
            $parentId = (int) $row['parent'];
            $newParent = $idMap[ $parentId ] ?? 0;

            try {
                $newId           = $this->folderRepository->create( (string) $row['name'], $newParent, 0 );
                $idMap[ $oldId ] = $newId;
                $progress->created++;
            } catch ( \RuntimeException ) {
                $existing = get_term_by( 'name', $row['name'], FolderTaxonomy::TAXONOMY );
                if ( $existing instanceof \WP_Term ) {
                    $idMap[ $oldId ] = (int) $existing->term_id;
                    $progress->skipped++;
                } else {
                    $progress->errors++;
                    $progress->messages[] = "Could not create folder \"{$row['name']}\"";
                }
            }

            $progress->processed++;
        }

        $this->saveIdMap( $idMap );

        // Count total assignments so progress bar is meaningful.
        $assignTable = $wpdb->prefix . 'fbv_attachment_folder';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $assignCount = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$assignTable}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $progress->total = $progress->processed + $assignCount;
    }

    // -------------------------------------------------------------------------
    // Private — Phase B: import file assignments
    // -------------------------------------------------------------------------

    private function importAssignmentsBatch( ImportProgress $progress, int $batchSize ): bool {
        global $wpdb;

        $idMap       = $this->loadIdMap();
        $assignTable = $wpdb->prefix . 'fbv_attachment_folder';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT folder_id, attachment_id FROM `{$assignTable}` LIMIT %d OFFSET %d",  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $batchSize,
                $progress->offset
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return true; // done
        }

        foreach ( $rows as $row ) {
            $sourceFolderId = (int) $row['folder_id'];
            $attachmentId   = (int) $row['attachment_id'];
            $targetTermId   = $idMap[ $sourceFolderId ] ?? 0;

            if ( $targetTermId > 0 ) {
                $this->folderRepository->assignFile( $attachmentId, $targetTermId );
                $progress->created++;
            } else {
                $progress->skipped++;
            }

            $progress->processed++;
        }

        $progress->offset += $batchSize;

        // Check if more rows remain.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $remaining = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT COUNT(*) FROM `{$assignTable}` LIMIT 1 OFFSET %d",  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $progress->offset
            )
        );

        return $remaining === 0;
    }

    // -------------------------------------------------------------------------
    // Private — id-map persistence
    // -------------------------------------------------------------------------

    /** @param array<int,int> $map */
    private function saveIdMap( array $map ): void {
        update_option( 'mdpai_migration_idmap_filebird', $map, false );
    }

    /** @return array<int,int> */
    private function loadIdMap(): array {
        return (array) get_option( 'mdpai_migration_idmap_filebird', [] );
    }
}
