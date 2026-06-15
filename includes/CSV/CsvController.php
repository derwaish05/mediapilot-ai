<?php

declare(strict_types=1);

namespace MediaPilotAI\CSV;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderRepository;

/**
 * Handles admin-post.php actions for CSV export and import.
 *
 * Actions registered:
 *   mdpai_export_folders         — streams folder structure CSV (GET link).
 *   mdpai_export_assignments     — streams file-assignments CSV (GET link).
 *   mdpai_import_folders         — processes uploaded folder-structure CSV (POST form).
 *   mdpai_import_assignments     — processes uploaded file-assignments CSV (POST form).
 *
 * All actions require the `manage_mdpai_settings` capability and a valid nonce.
 *
 * @package MediaPilotAI\CSV
 * @since   1.0.0
 */
class CsvController {

    private const CAPABILITY    = 'manage_mdpai_settings';
    private const NONCE_EXPORT  = 'mdpai_csv_export';
    private const NONCE_IMPORT  = 'mdpai_csv_import';
    private const REDIRECT_PAGE = 'upload.php?page=mediapilot-settings&tab=csv';

    private CsvExporter $exporter;
    private CsvImporter $importer;

    public function __construct( FolderRepository $folderRepository ) {
        $this->exporter = new CsvExporter( $folderRepository );
        $this->importer = new CsvImporter( $folderRepository );
    }

    /**
     * Register admin-post.php action hooks. Call on admin_init or plugins_loaded.
     */
    public function register(): void {
        add_action( 'admin_post_mdpai_export_folders',     [ $this, 'handleExportFolders' ] );
        add_action( 'admin_post_mdpai_export_assignments', [ $this, 'handleExportAssignments' ] );
        add_action( 'admin_post_mdpai_import_folders',     [ $this, 'handleImportFolders' ] );
        add_action( 'admin_post_mdpai_import_assignments', [ $this, 'handleImportAssignments' ] );
    }

    // -------------------------------------------------------------------------
    // Export handlers
    // -------------------------------------------------------------------------

    public function handleExportFolders(): void {
        $this->checkPermission();
        $this->verifyNonce( 'mdpai_export_folders', self::NONCE_EXPORT );
        $this->exporter->streamFolderStructure(); // exits
    }

    public function handleExportAssignments(): void {
        $this->checkPermission();
        $this->verifyNonce( 'mdpai_export_assignments', self::NONCE_EXPORT );
        $this->exporter->streamFileAssignments(); // exits
    }

    // -------------------------------------------------------------------------
    // Import handlers
    // -------------------------------------------------------------------------

    public function handleImportFolders(): void {
        $this->checkPermission();
        $this->verifyNonce( 'mdpai_import_folders', self::NONCE_IMPORT );

        $handle = $this->openUploadedFile( 'mdpai_csv_file' );
        if ( null === $handle ) {
            $this->redirectWithNotice( 'error', __( 'No valid CSV file was uploaded.', 'mediapilot-ai') );
            return;
        }

        $userId = get_current_user_id();
        $result = $this->importer->importFolderStructure( $handle, $userId );
        fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        $this->redirectWithResult( $result, 'folders' );
    }

    public function handleImportAssignments(): void {
        $this->checkPermission();
        $this->verifyNonce( 'mdpai_import_assignments', self::NONCE_IMPORT );

        $handle = $this->openUploadedFile( 'mdpai_csv_file' );
        if ( null === $handle ) {
            $this->redirectWithNotice( 'error', __( 'No valid CSV file was uploaded.', 'mediapilot-ai') );
            return;
        }

        $result = $this->importer->restoreFileAssignments( $handle );
        fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        $this->redirectWithResult( $result, 'assignments' );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function checkPermission(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'mediapilot-ai') );
        }
    }

    private function verifyNonce( string $action, string $nonceName ): void {
        $nonce = isset( $_REQUEST[ $nonceName ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $nonceName ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- this IS the nonce check
        if ( ! wp_verify_nonce( $nonce, $action ) ) {
            wp_die( esc_html__( 'Security check failed. Please try again.', 'mediapilot-ai') );
        }
    }

    /**
     * Opens an uploaded CSV file and returns a file resource handle, or null on failure.
     *
     * @param  string   $fieldName  $_FILES key.
     * @return resource|null
     */
    private function openUploadedFile( string $fieldName ) {
        if ( // phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller verifies nonce via verifyNonce()
            ! isset( $_FILES[ $fieldName ] ) || // phpcs:ignore WordPress.Security.NonceVerification.Missing
            UPLOAD_ERR_OK !== (int) $_FILES[ $fieldName ]['error'] || // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            ! is_uploaded_file( (string) $_FILES[ $fieldName ]['tmp_name'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        ) {
            return null;
        }

        // Validate MIME type — must be text/csv or text/plain.
        $tmpPath  = (string) $_FILES[ $fieldName ]['tmp_name']; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $mimeType = mime_content_type( $tmpPath );

        if ( ! in_array( $mimeType, [ 'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel' ], true ) ) {
            return null;
        }

        $handle = fopen( $tmpPath, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

        return $handle ?: null;
    }

    /**
     * Redirects back to the settings CSV tab with a query-string notice.
     */
    private function redirectWithResult( ImportResult $result, string $type ): void {
        $status  = $result->isOk() ? 'success' : 'error';
        $message = sprintf(
            /* translators: 1: success count, 2: skipped count, 3: error count */
            __( 'Import complete — %1$d created, %2$d skipped, %3$d errors.', 'mediapilot-ai'),
            $result->success,
            $result->skipped,
            $result->errors,
        );

        $this->redirectWithNotice( $status, $message );
    }

    private function redirectWithNotice( string $status, string $message ): never {
        $url = add_query_arg(
            [
                'mdpai_notice'  => rawurlencode( $message ),
                'mdpai_status'  => $status,
            ],
            admin_url( self::REDIRECT_PAGE )
        );

        wp_safe_redirect( $url );
        exit;
    }
}
