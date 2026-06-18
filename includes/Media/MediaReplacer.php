<?php

declare(strict_types=1);

namespace MediaPilotAI\Media;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Filesystem\FileMover;
use MediaPilotAI\Media\VersionControl;

/**
 * Media Replacement System (S60).
 *
 * Replaces an existing attachment's physical file while preserving the same
 * attachment ID and — where possible — the same public URL.
 *
 * Behaviour:
 *   - Same filename  → overwrite file in-place; URL unchanged.
 *   - New filename   → write new file, update all URL references in post
 *                      content and postmeta via FileMover::updateAllUrls(),
 *                      delete old sidecar images.
 *   - Always         → regenerate WordPress image sizes via
 *                      wp_generate_attachment_metadata().
 *   - Always         → log the replacement in wp_mdpai_versions.
 *
 * Table managed: wp_mdpai_versions
 *
 * @package MediaPilotAI\Media
 * @since   1.0.0
 */
class MediaReplacer {

    private const META_VERSION = '_mdpai_version_num';

    public function __construct(
        private readonly FileMover      $fileMover,
        private readonly VersionControl $versionControl = new VersionControl(),
    ) {}

    // -------------------------------------------------------------------------
    // Schema
    // -------------------------------------------------------------------------

    /**
     * Create the wp_mdpai_versions table if it does not exist.
     * Safe to call on every boot (dbDelta is idempotent).
     */
    public function createTable(): void {
        global $wpdb;

        $table   = $this->versionsTable();
        $charset = $wpdb->get_charset_collate();

        $sql = "
CREATE TABLE {$table} (
  id             bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  attachment_id  bigint(20) UNSIGNED NOT NULL,
  version_num    int UNSIGNED NOT NULL DEFAULT 1,
  old_file       varchar(500) NOT NULL DEFAULT '',
  new_file       varchar(500) NOT NULL DEFAULT '',
  old_url        varchar(500) NOT NULL DEFAULT '',
  new_url        varchar(500) NOT NULL DEFAULT '',
  replaced_by    bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  replaced_at    datetime NOT NULL,
  notes          text NOT NULL,
  PRIMARY KEY  (id),
  KEY          attachment_id (attachment_id)
) {$charset};
";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    // -------------------------------------------------------------------------
    // Registration (admin UI hooks)
    // -------------------------------------------------------------------------

    public function register(): void {
        add_filter( 'attachment_fields_to_edit',  [ $this, 'addReplaceField' ], 10, 2 );
        add_filter( 'wp_prepare_attachment_for_js', [ $this, 'addReplaceData' ], 10, 2 );
        add_action( 'admin_footer',               [ $this, 'printReplaceScript' ] );
    }

    // -------------------------------------------------------------------------
    // Admin UI hooks
    // -------------------------------------------------------------------------

    /**
     * Inject a "Replace File" row into the attachment edit form
     * (Edit Media screen — not the media modal).
     *
     * @param  array<string, mixed> $fields
     * @param  \WP_Post             $post
     * @return array<string, mixed>
     */
    public function addReplaceField( array $fields, \WP_Post $post ): array {
        if ( $post->post_type !== 'attachment' ) {
            return $fields;
        }

        $nonce      = wp_create_nonce( 'mdpai_replace_' . $post->ID );
        $apiUrl     = esc_attr( rest_url( 'mediapilot/v1/files/' . $post->ID . '/replace' ) );
        $currentFile = esc_html( basename( (string) get_attached_file( $post->ID ) ) );

        $html = '<div class="mediapilot-replace-wrap" data-id="' . esc_attr( (string) $post->ID ) . '" data-api="' . $apiUrl . '" data-nonce="' . esc_attr( $nonce ) . '">';
        $html .= '<p class="description">';
        $html .= sprintf(
            /* translators: %s: current filename */
            esc_html__( 'Current file: %s', 'mediapilot-ai'),
            '<code>' . $currentFile . '</code>'
        );
        $html .= '</p>';
        $html .= '<input type="file" class="mediapilot-replace-input" accept="*/*" style="margin-top:6px">';
        $html .= '<button type="button" class="button mediapilot-replace-btn" style="margin-top:6px;margin-left:4px" disabled>';
        $html .= esc_html__( 'Upload &amp; Replace', 'mediapilot-ai');
        $html .= '</button>';
        $html .= '<span class="mediapilot-replace-status" style="margin-left:8px;font-size:12px;color:#64748b"></span>';
        $html .= '</div>';

        $fields['mdpai_replace'] = [
            'label' => __( 'Replace File', 'mediapilot-ai'),
            'input' => 'html',
            'html'  => $html,
        ];

        // --- Version history panel ---
        $history     = $this->getHistory( $post->ID );
        $versionsUrl = esc_attr( rest_url( 'mediapilot/v1/files/' . $post->ID . '/versions' ) );

        if ( ! empty( $history ) ) {
            $histHtml  = '<table class="widefat striped" style="margin-top:8px;font-size:12px">';
            $histHtml .= '<thead><tr>';
            $histHtml .= '<th>' . esc_html__( 'Ver.', 'mediapilot-ai') . '</th>';
            $histHtml .= '<th>' . esc_html__( 'Replaced', 'mediapilot-ai') . '</th>';
            $histHtml .= '<th>' . esc_html__( 'By', 'mediapilot-ai') . '</th>';
            $histHtml .= '<th>' . esc_html__( 'Notes', 'mediapilot-ai') . '</th>';
            $histHtml .= '<th></th>';
            $histHtml .= '</tr></thead><tbody>';

            foreach ( $history as $row ) {
                $user     = get_user_by( 'id', (int) $row['replaced_by'] );
                $username = $user ? esc_html( $user->display_name ) : '—';
                $restoreUrl = esc_attr( rest_url( 'mediapilot/v1/files/' . $post->ID . '/versions/' . $row['id'] . '/restore' ) );
                $restoreNonce = wp_create_nonce( 'wp_rest' );

                $histHtml .= '<tr>';
                $histHtml .= '<td>#' . (int) $row['version_num'] . '</td>';
                $histHtml .= '<td>' . esc_html( $row['replaced_at'] ) . '</td>';
                $histHtml .= '<td>' . $username . '</td>';
                $histHtml .= '<td>' . esc_html( $row['notes'] ) . '</td>';
                $histHtml .= '<td>';

                // Only show restore if the archived file still exists.
                if ( '' !== $row['old_file'] && file_exists( $row['old_file'] ) ) {
                    $histHtml .= '<button type="button" class="button button-small mediapilot-restore-btn"'
                               . ' data-api="' . $restoreUrl . '"'
                               . ' data-nonce="' . esc_attr( $restoreNonce ) . '">'
                               . esc_html__( 'Restore', 'mediapilot-ai')
                               . '</button>';
                } else {
                    $histHtml .= '<span style="color:#9ca3af">' . esc_html__( 'Archive gone', 'mediapilot-ai') . '</span>';
                }

                $histHtml .= '</td></tr>';
            }

            $histHtml .= '</tbody></table>';

            $fields['mdpai_version_history'] = [
                'label' => __( 'Version History', 'mediapilot-ai'),
                'input' => 'html',
                'html'  => $histHtml,
            ];
        }

        return $fields;
    }

    /**
     * Inject replace endpoint + nonce into the JS attachment data used by the
     * media modal so the modal-based UI can also trigger replacements.
     *
     * @param  array<string, mixed> $response
     * @param  \WP_Post             $attachment
     * @return array<string, mixed>
     */
    public function addReplaceData( array $response, \WP_Post $attachment ): array {
        if ( ! current_user_can( 'upload_files' ) ) {
            return $response;
        }

        $response['mmpReplaceUrl']   = rest_url( 'mediapilot/v1/files/' . $attachment->ID . '/replace' );
        $response['mmpReplaceNonce'] = wp_create_nonce( 'mdpai_replace_' . $attachment->ID );
        $response['mmpVersionNum']   = (int) get_post_meta( $attachment->ID, self::META_VERSION, true ) ?: 1;

        return $response;
    }

    /**
     * Output the inline JS that powers the Replace File UI on both the
     * Edit Media page and the media modal.
     */
    public function printReplaceScript(): void {
        $screen = get_current_screen();

        // Only load on media-related admin screens.
        if ( ! $screen || ! in_array( $screen->id, [ 'upload', 'attachment' ], true ) ) {
            return;
        }

        wp_register_script(
            'mediapilot-replace',
            MDPAI_URL . 'admin/assets/js/mediapilot-replace.js',
            [],
            MDPAI_VERSION,
            true
        );
        wp_localize_script(
            'mediapilot-replace',
            'MediaPilotReplace',
            [
                'i18n' => [
                    'restoreConfirm' => __( 'Restore this version? The current file will be replaced.', 'mediapilot-ai' ),
                    'restoring'      => __( 'Restoring…', 'mediapilot-ai' ),
                    'restored'       => __( 'Restored! Reloading…', 'mediapilot-ai' ),
                    'error'          => __( 'Error', 'mediapilot-ai' ),
                    'restoreFailed'  => __( 'Restore failed.', 'mediapilot-ai' ),
                    'uploading'      => __( 'Uploading…', 'mediapilot-ai' ),
                    'replaced'       => __( 'Replaced! Reloading…', 'mediapilot-ai' ),
                    'errorDot'       => __( 'Error.', 'mediapilot-ai' ),
                ],
            ]
        );
        wp_enqueue_script( 'mediapilot-replace' );
    }

    // -------------------------------------------------------------------------
    // Core replacement logic
    // -------------------------------------------------------------------------

    /**
     * Replace an attachment's file with a new uploaded file.
     *
     * @param  int                  $attachmentId   WP attachment post ID.
     * @param  string               $newFileTmpPath Absolute path to the uploaded temp file.
     * @param  string               $newFilename    Original filename from the upload (used to
     *                                              determine target path and URL changes).
     * @param  string               $notes          Optional notes to store in the version log.
     * @return array{
     *   attachment_id: int,
     *   old_url: string,
     *   new_url: string,
     *   filename_changed: bool,
     *   version_num: int,
     *   thumbnails_regenerated: bool,
     * }
     * @throws \RuntimeException On unrecoverable errors.
     */
    public function replace(
        int    $attachmentId,
        string $newFileTmpPath,
        string $newFilename,
        string $notes = '',
    ): array {
        // Validate attachment.
        $post = get_post( $attachmentId );

        if ( ! $post || $post->post_type !== 'attachment' ) {
            throw new \RuntimeException( "Attachment {$attachmentId} not found." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $oldFilePath = (string) get_attached_file( $attachmentId );

        if ( ! $oldFilePath ) {
            throw new \RuntimeException( "No file path registered for attachment {$attachmentId}." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        if ( ! is_readable( $newFileTmpPath ) ) {
            throw new \RuntimeException( "Uploaded file is not readable: {$newFileTmpPath}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $oldBasename = basename( $oldFilePath );
        $oldDir      = dirname( $oldFilePath );

        // Sanitize the new filename.
        $newFilename = sanitize_file_name( $newFilename );

        // Validate the replacement file's REAL type/extension before writing it
        // anywhere. This prevents an arbitrary or executable file (e.g. a .php
        // payload) from being saved into the uploads directory.
        $checked = wp_check_filetype_and_ext( $newFileTmpPath, $newFilename );
        if ( ! empty( $checked['proper_filename'] ) ) {
            $newFilename = sanitize_file_name( (string) $checked['proper_filename'] );
        }
        if ( empty( $checked['ext'] ) || empty( $checked['type'] )
            || ! in_array( $checked['type'], (array) get_allowed_mime_types(), true )
        ) {
            throw new \RuntimeException( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                esc_html__( 'The replacement file type is not allowed.', 'mediapilot-ai' )
            );
        }

        $newFilePath = $oldDir . '/' . $newFilename;

        $oldUrl = (string) wp_get_attachment_url( $attachmentId );

        $uploadInfo     = wp_get_upload_dir();
        $uploadsBase    = $uploadsBaseUrl = '';
        $uploadsBase    = $uploadInfo['basedir'];
        $uploadsBaseUrl = $uploadInfo['baseurl'];

        // Build old/new URLs.
        $newRelativePath = ltrim( str_replace( $uploadsBase, '', $newFilePath ), '/' );
        $newUrl          = $uploadsBaseUrl . '/' . $newRelativePath;

        $filenameChanged = ( $oldBasename !== $newFilename );

        /**
         * Fires before a media file is replaced.
         *
         * @param int    $attachmentId   Attachment post ID.
         * @param string $oldFilePath    Absolute path to the current file on disk.
         * @param string $newFileTmpPath Absolute path to the uploaded replacement file.
         * @param string $newFilename    Sanitised filename that will be used for the replacement.
         */
        do_action( 'mdpai_before_file_replace', $attachmentId, $oldFilePath, $newFileTmpPath, $newFilename );

        // --- Hash check: reject if new file is identical to the current one ---
        if ( $this->versionControl->isIdenticalToCurrentFile( $attachmentId, $newFileTmpPath ) ) {
            throw new \UnexpectedValueException( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                __( 'The uploaded file is identical to the current version. No replacement was made.', 'mediapilot-ai')  // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message, not HTML output
            );
        }

        // Increment version number early so the archive path uses the right number.
        $versionNum = ( (int) get_post_meta( $attachmentId, self::META_VERSION, true ) ?: 0 ) + 1;

        // --- Archive the current file before overwriting ---
        $archivePath = $this->versionControl->archiveFile( $attachmentId, $versionNum, $oldFilePath );

        // Delete old thumbnail sidecar files before overwriting.
        $this->deleteImageSizes( $attachmentId, $oldDir );

        // Copy the new file into the uploads directory (keep original filename or rename).
        if ( ! @copy( $newFileTmpPath, $newFilePath ) ) {
            throw new \RuntimeException( "Failed to copy uploaded file to {$newFilePath}." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        // Remove old file if the filename changed.
        if ( $filenameChanged && file_exists( $oldFilePath ) ) {
            wp_delete_file( $oldFilePath );
        }

        // Detect MIME type from new file.
        $newMime = mime_content_type( $newFilePath ) ?: (string) $post->post_mime_type;

        // Update MIME type in wp_posts.
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->posts,
            [ 'post_mime_type' => $newMime, 'guid' => $newUrl ],
            [ 'ID'             => $attachmentId ]
        );

        // Update _wp_attached_file.
        update_post_meta( $attachmentId, '_wp_attached_file', $newRelativePath );

        // Regenerate image sizes (clears metadata, sets new sizes).
        $thumbnailsRegenerated = false;

        if ( str_starts_with( $newMime, 'image/' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $metadata = wp_generate_attachment_metadata( $attachmentId, $newFilePath );
            wp_update_attachment_metadata( $attachmentId, $metadata );
            $thumbnailsRegenerated = true;
        } else {
            // Non-image: just clear the old metadata.
            wp_update_attachment_metadata( $attachmentId, [] );
        }

        clean_post_cache( $attachmentId );

        // Update all URL references if the filename changed.
        $urlsUpdated = 0;
        if ( $filenameChanged ) {
            $urlsUpdated = $this->fileMover->updateAllUrls( $oldUrl, $newUrl );
        }

        // Persist the new version number (was already incremented above).
        update_post_meta( $attachmentId, self::META_VERSION, $versionNum );

        // Log the replacement — old_file now holds the archive path so it can be restored.
        $this->logVersion( $attachmentId, $versionNum, $archivePath ?: $oldFilePath, $newFilePath, $oldUrl, $newUrl, $notes );

        // Auto-prune: remove versions beyond the configured limit (default 5).
        $settings  = (array) get_option( 'mdpai_settings', [] );
        $keepLimit = isset( $settings['max_versions'] ) ? max( 1, (int) $settings['max_versions'] ) : VersionControl::DEFAULT_KEEP;
        $this->versionControl->pruneVersions( $attachmentId, $keepLimit );

        /**
         * Fires after a file has been replaced.
         *
         * @param int    $attachmentId  Attachment post ID.
         * @param string $oldUrl        Previous public URL.
         * @param string $newUrl        New public URL.
         * @param bool   $filenameChanged Whether the filename changed.
         */
        do_action( 'mdpai_after_file_replace', $attachmentId, $oldUrl, $newUrl, $filenameChanged );

        return [
            'attachment_id'          => $attachmentId,
            'old_url'                => $oldUrl,
            'new_url'                => $newUrl,
            'filename_changed'       => $filenameChanged,
            'version_num'            => $versionNum,
            'thumbnails_regenerated' => $thumbnailsRegenerated,
        ];
    }

    // -------------------------------------------------------------------------
    // Version history
    // -------------------------------------------------------------------------

    /**
     * Return the version history for an attachment, newest first.
     *
     * @param  int $attachmentId
     * @return array<int, array<string, mixed>>
     */
    public function getHistory( int $attachmentId ): array {
        global $wpdb;

        $table = $this->versionsTable();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM {$table} WHERE attachment_id = %d ORDER BY version_num DESC",  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $attachmentId
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : [];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Delete all registered image-size sidecar files for an attachment.
     *
     * @param  int    $attachmentId
     * @param  string $dir          Directory containing the sidecar files.
     */
    private function deleteImageSizes( int $attachmentId, string $dir ): void {
        $metadata = wp_get_attachment_metadata( $attachmentId );

        if ( ! is_array( $metadata ) || empty( $metadata['sizes'] ) ) {
            return;
        }

        foreach ( $metadata['sizes'] as $sizeData ) {
            $file = $dir . '/' . $sizeData['file'];
            if ( file_exists( $file ) ) {
                wp_delete_file( $file );
            }
        }
    }

    /**
     * Insert a row into wp_mdpai_versions.
     */
    private function logVersion(
        int    $attachmentId,
        int    $versionNum,
        string $oldFile,
        string $newFile,
        string $oldUrl,
        string $newUrl,
        string $notes,
    ): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $this->versionsTable(),
            [
                'attachment_id' => $attachmentId,
                'version_num'   => $versionNum,
                'old_file'      => $oldFile,
                'new_file'      => $newFile,
                'old_url'       => $oldUrl,
                'new_url'       => $newUrl,
                'replaced_by'   => get_current_user_id(),
                'replaced_at'   => current_time( 'mysql' ),
                'notes'         => $notes,
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );
    }

    private function versionsTable(): string {
        global $wpdb;
        return $wpdb->prefix . 'mdpai_versions';
    }
}
