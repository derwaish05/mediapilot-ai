<?php

declare(strict_types=1);

namespace MediaPilotAI\Media;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * File Version Control (S34).
 *
 * Responsibilities:
 *  - Archive the current file to a versioned directory before it is replaced,
 *    so it can be restored later.
 *  - Detect identical files by SHA-256 hash and reject no-op replacements.
 *  - Restore a previous version: copy the archived file back, update all
 *    WordPress attachment metadata and optionally rewrite content URLs.
 *  - Prune old versions: delete archived files and version rows beyond the
 *    configured keep limit.
 *
 * Archive path pattern:
 *   {uploads_base}/mediapilot-versions/{attachment_id}/v{version_num}/{filename}
 *
 * This class is stateless and operates exclusively through method calls from
 * MediaReplacer and ReplaceRestController.
 *
 * @package MediaPilotAI\Media
 * @since   1.0.0
 */
class VersionControl {

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    /** Sub-directory inside {uploads_base} where archives are stored. */
    private const ARCHIVE_DIR = 'mediapilot-versions';

    /** Default number of previous versions to keep per attachment. */
    public const DEFAULT_KEEP = 5;

    // -------------------------------------------------------------------------
    // Public API — Hashing
    // -------------------------------------------------------------------------

    /**
     * Computes the SHA-256 hash of a file.
     *
     * @param  string $filePath  Absolute path to the file.
     * @return string  64-char hex digest, or empty string on failure.
     */
    public function computeHash( string $filePath ): string {
        if ( ! is_readable( $filePath ) ) {
            return '';
        }

        return (string) hash_file( 'sha256', $filePath );
    }

    /**
     * Returns true when the file at $newFilePath is byte-for-byte identical to
     * the attachment's current file, preventing a no-op replacement.
     *
     * @param  int    $attachmentId
     * @param  string $newFilePath  Absolute path to the candidate new file.
     * @return bool
     */
    public function isIdenticalToCurrentFile( int $attachmentId, string $newFilePath ): bool {
        $currentPath = (string) get_attached_file( $attachmentId );

        if ( '' === $currentPath || ! is_readable( $currentPath ) ) {
            return false;
        }

        $currentHash = $this->computeHash( $currentPath );
        $newHash     = $this->computeHash( $newFilePath );

        return '' !== $currentHash && $currentHash === $newHash;
    }

    // -------------------------------------------------------------------------
    // Public API — Archiving
    // -------------------------------------------------------------------------

    /**
     * Copies the attachment's current file into the versioned archive directory
     * before it is overwritten by a replacement.
     *
     * Returns the absolute archive path on success, or an empty string when
     * the source file is missing or the copy fails.
     *
     * @param  int    $attachmentId
     * @param  int    $versionNum    The version number being archived (the OLD version).
     * @param  string $sourcePath   Absolute path of the file to archive.
     * @return string  Absolute archive path, or '' on failure.
     */
    public function archiveFile( int $attachmentId, int $versionNum, string $sourcePath ): string {
        if ( ! is_readable( $sourcePath ) ) {
            return '';
        }

        $archiveDir = $this->archiveDirForVersion( $attachmentId, $versionNum );

        if ( ! wp_mkdir_p( $archiveDir ) ) {
            return '';
        }

        $filename    = basename( $sourcePath );
        $archivePath = $archiveDir . '/' . $filename;

        if ( ! @copy( $sourcePath, $archivePath ) ) {
            return '';
        }

        return $archivePath;
    }

    // -------------------------------------------------------------------------
    // Public API — Restore
    // -------------------------------------------------------------------------

    /**
     * Restores an attachment to a previously archived version.
     *
     * Steps:
     *  1. Look up the version row in wp_mdpai_versions.
     *  2. Verify the archived file exists at old_file.
     *  3. Copy the archived file back to the attachment's current file path.
     *  4. Update WordPress attachment metadata (_wp_attached_file, MIME, sizes).
     *  5. Log a new version row for the restore operation.
     *
     * @param  int $versionId  Primary key in wp_mdpai_versions.
     * @return array{
     *   attachment_id: int,
     *   restored_from_version: int,
     *   new_version_num: int,
     *   old_url: string,
     *   new_url: string,
     * }
     * @throws \InvalidArgumentException When version row or archive file is not found.
     * @throws \RuntimeException         On file-system errors.
     */
    public function restoreVersion( int $versionId ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'mdpai_versions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $versionId ),  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );

        if ( ! $row ) {
            throw new \InvalidArgumentException( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                /* translators: %d: version record ID */
                sprintf( __( 'Version record #%d not found.', 'mediapilot-ai'), $versionId )  // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message, not HTML output
            );
        }

        $attachmentId = (int) $row['attachment_id'];
        $archivePath  = (string) $row['old_file'];

        if ( '' === $archivePath || ! is_readable( $archivePath ) ) {
            throw new \InvalidArgumentException( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                /* translators: %s: archive file path */
                sprintf( __( 'Archived file not found: %s', 'mediapilot-ai'), $archivePath )  // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message, not HTML output
            );
        }

        // Determine the current attachment file path (target for restore).
        $currentPath = (string) get_attached_file( $attachmentId );

        if ( '' === $currentPath ) {
            throw new \RuntimeException( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                /* translators: %d: attachment ID */
                sprintf( __( 'No file path registered for attachment %d.', 'mediapilot-ai'), $attachmentId )  // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message, not HTML output
            );
        }

        $oldUrl   = (string) wp_get_attachment_url( $attachmentId );
        $filename = basename( $archivePath );

        // Ensure the target directory exists.
        $targetDir = dirname( $currentPath );
        if ( ! wp_mkdir_p( $targetDir ) ) {
            throw new \RuntimeException( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                /* translators: %s: directory path */
                sprintf( __( 'Cannot create directory: %s', 'mediapilot-ai'), $targetDir )  // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message, not HTML output
            );
        }

        $targetPath = $targetDir . '/' . $filename;

        // Copy archived file to the uploads directory.
        if ( ! @copy( $archivePath, $targetPath ) ) {
            throw new \RuntimeException( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                /* translators: %s: target file path */
                sprintf( __( 'Failed to restore file to: %s', 'mediapilot-ai'), $targetPath )  // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message, not HTML output
            );
        }

        // Compute new URL.
        $uploadInfo   = wp_get_upload_dir();
        $uploadsBase  = $uploadInfo['basedir'];
        $uploadsUrl   = $uploadInfo['baseurl'];
        $relativePath = ltrim( str_replace( $uploadsBase, '', $targetPath ), '/' );
        $newUrl       = $uploadsUrl . '/' . $relativePath;

        // Update WordPress attachment meta.
        update_post_meta( $attachmentId, '_wp_attached_file', $relativePath );

        $newMime = mime_content_type( $targetPath ) ?: get_post_mime_type( $attachmentId );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->posts,
            [ 'post_mime_type' => $newMime, 'guid' => $newUrl ],
            [ 'ID'             => $attachmentId ]
        );

        // Regenerate image sizes.
        if ( str_starts_with( (string) $newMime, 'image/' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $metadata = wp_generate_attachment_metadata( $attachmentId, $targetPath );
            wp_update_attachment_metadata( $attachmentId, $metadata );
        }

        clean_post_cache( $attachmentId );

        // Increment version number and log the restore event.
        $newVersionNum = ( (int) get_post_meta( $attachmentId, '_mdpai_version_num', true ) ?: 0 ) + 1;
        update_post_meta( $attachmentId, '_mdpai_version_num', $newVersionNum );

        // Archive the file that was just replaced (so it can be restored too).
        $archivedPath = $this->archiveFile( $attachmentId, $newVersionNum, $currentPath );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $table,
            [
                'attachment_id' => $attachmentId,
                'version_num'   => $newVersionNum,
                'old_file'      => $archivedPath ?: $currentPath,
                'new_file'      => $targetPath,
                'old_url'       => $oldUrl,
                'new_url'       => $newUrl,
                'replaced_by'   => get_current_user_id(),
                'replaced_at'   => current_time( 'mysql' ),
                'notes'         => sprintf(
                    /* translators: %d: version record ID being restored */
                    __( 'Restored from version #%d', 'mediapilot-ai'),
                    $versionId
                ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        return [
            'attachment_id'         => $attachmentId,
            'restored_from_version' => (int) $row['version_num'],
            'new_version_num'       => $newVersionNum,
            'old_url'               => $oldUrl,
            'new_url'               => $newUrl,
        ];
    }

    // -------------------------------------------------------------------------
    // Public API — Pruning
    // -------------------------------------------------------------------------

    /**
     * Deletes old archived files and version rows for one attachment, keeping
     * only the $keep most recent versions.
     *
     * @param  int $attachmentId
     * @param  int $keep  Number of versions to retain (≥ 1).
     * @return int  Number of version records deleted.
     */
    public function pruneVersions( int $attachmentId, int $keep = self::DEFAULT_KEEP ): int {
        global $wpdb;

        /**
         * Filters the maximum number of versions to retain per attachment.
         *
         * @param int $keep         Number of versions to keep. Default: VersionControl::DEFAULT_KEEP (5).
         * @param int $attachmentId Attachment post ID being pruned.
         */
        $keep = (int) apply_filters( 'mdpai_version_limit', $keep, $attachmentId );
        $keep  = max( 1, $keep );
        $table = $wpdb->prefix . 'mdpai_versions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                "SELECT id, old_file
                 FROM {$table}
                 WHERE attachment_id = %d
                 ORDER BY version_num DESC",
                $attachmentId
            ), // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );

        if ( ! $rows || count( $rows ) <= $keep ) {
            return 0;
        }

        $toDelete = array_slice( (array) $rows, $keep );
        $deleted  = 0;

        foreach ( $toDelete as $row ) {
            $this->deleteArchiveFile( (string) $row['old_file'] );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete( $table, [ 'id' => (int) $row['id'] ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            ++$deleted;
        }

        return $deleted;
    }

    /**
     * Prunes versions for every attachment that has version history, keeping
     * the $keep most recent per attachment.
     *
     * @param  int $keep  Number of versions to retain per attachment.
     * @return array{ attachments: int, records_deleted: int }
     */
    public function pruneAll( int $keep = self::DEFAULT_KEEP ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'mdpai_versions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ids = $wpdb->get_col( "SELECT DISTINCT attachment_id FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $attachments    = 0;
        $recordsDeleted = 0;

        foreach ( (array) $ids as $attachmentId ) {
            $deleted = $this->pruneVersions( (int) $attachmentId, $keep );
            if ( $deleted > 0 ) {
                ++$attachments;
                $recordsDeleted += $deleted;
            }
        }

        return [ 'attachments' => $attachments, 'records_deleted' => $recordsDeleted ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the archive directory for a specific attachment version.
     *
     * @param  int $attachmentId
     * @param  int $versionNum
     * @return string  Absolute directory path.
     */
    private function archiveDirForVersion( int $attachmentId, int $versionNum ): string {
        $uploadInfo = wp_get_upload_dir();
        $base       = (string) $uploadInfo['basedir'];

        return $base . '/' . self::ARCHIVE_DIR . '/' . $attachmentId . '/v' . $versionNum;
    }

    /**
     * Deletes a single archived file. Silently ignores missing files.
     *
     * Also removes the parent version directory when it becomes empty.
     *
     * @param  string $archivePath
     */
    private function deleteArchiveFile( string $archivePath ): void {
        if ( '' === $archivePath || ! file_exists( $archivePath ) ) {
            return;
        }

        wp_delete_file( $archivePath );

        // Clean up empty parent directory.
        $dir = dirname( $archivePath );
        if ( is_dir( $dir ) && count( (array) scandir( $dir ) ) <= 2 ) {
            @rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        }
    }
}
