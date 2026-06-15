<?php

declare(strict_types=1);

namespace MediaPilotAI\CSV;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderRepository;
use MediaPilotAI\Taxonomy\FolderTaxonomy;

/**
 * Exports MediaPilot folder data as downloadable CSV files.
 *
 * Two export types:
 *   1. Folder structure  — one row per folder: id, name, parent_id, color, file_count, user_id
 *   2. File assignments  — one row per assigned file: attachment_id, folder_id, folder_name, file_title, file_url
 *
 * Both methods stream output directly to the browser using PHP's output
 * buffering, so they work even for large datasets without memory issues.
 *
 * Usage:
 *   $exporter = new CsvExporter($folderRepository);
 *   $exporter->streamFolderStructure();   // exits after sending
 *   $exporter->streamFileAssignments();   // exits after sending
 *
 * @package MediaPilotAI\CSV
 * @since   1.0.0
 */
class CsvExporter {

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct(
        private readonly FolderRepository $folderRepository,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Streams a CSV of the full folder structure and exits.
     *
     * Columns: id, name, parent_id, color, file_count, user_id
     *
     * In global mode (all folders), user_id = 0 means shared/unowned.
     * In per-user mode, user_id matches the owning user's WordPress ID.
     */
    public function streamFolderStructure(): void {
        // Get all folders (global = userId 0).
        $tree = $this->folderRepository->getTree(0);
        $flat = $this->flattenTree($tree);

        $this->sendHeaders('mediapilot-folders-' . gmdate('Y-m-d') . '.csv');

        $out = fopen('php://output', 'w'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

        // BOM for Excel UTF-8 compatibility.
        fwrite($out, "\xEF\xBB\xBF"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

        fputcsv($out, ['id', 'name', 'parent_id', 'color', 'file_count', 'user_id']);

        foreach ($flat as $folder) {
            fputcsv($out, [
                $folder['id'],
                $folder['name'],
                $folder['parent'],
                $folder['color'],
                $folder['count'],
                $folder['user_id'],
            ]);
        }

        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        exit;
    }

    /**
     * Streams a CSV of all file-to-folder assignments and exits.
     *
     * Columns: attachment_id, folder_id, folder_name, file_title, file_url
     *
     * Only exports files that are assigned to a folder (folderId > 0).
     * Uncategorized files are intentionally excluded.
     */
    public function streamFileAssignments(): void {
        $tree    = $this->folderRepository->getTree(0);
        $flat    = $this->flattenTree($tree);

        $this->sendHeaders('mediapilot-file-assignments-' . gmdate('Y-m-d') . '.csv');

        $out = fopen('php://output', 'w'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

        // BOM for Excel UTF-8 compatibility.
        fwrite($out, "\xEF\xBB\xBF"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

        fputcsv($out, ['attachment_id', 'folder_id', 'folder_name', 'file_title', 'file_url']);

        foreach ($flat as $folder) {
            $folderId   = (int) $folder['id'];
            $folderName = (string) $folder['name'];

            // get_objects_in_term returns all attachment IDs in this folder.
            $ids = get_objects_in_term($folderId, FolderTaxonomy::TAXONOMY);

            if (is_wp_error($ids) || empty($ids)) {
                continue;
            }

            foreach ($ids as $attachmentId) {
                $post = get_post((int) $attachmentId);

                if (!$post instanceof \WP_Post) {
                    continue;
                }

                fputcsv($out, [
                    $post->ID,
                    $folderId,
                    $folderName,
                    $post->post_title,
                    (string) wp_get_attachment_url($post->ID),
                ]);
            }
        }

        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        exit;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Recursively flattens a nested folder tree into a flat array.
     *
     * @param  array<int, array<string, mixed>> $tree
     * @return array<int, array<string, mixed>>
     */
    private function flattenTree(array $tree): array {
        $flat = [];

        foreach ($tree as $node) {
            $children = $node['children'] ?? [];
            unset($node['children']);
            $flat[] = $node;

            if (!empty($children)) {
                $flat = array_merge($flat, $this->flattenTree($children));
            }
        }

        return $flat;
    }

    /**
     * Sends HTTP headers that trigger a CSV file download.
     *
     * @param  string $filename  Suggested filename for the download.
     */
    private function sendHeaders(string $filename): void {
        // Prevent output buffering from swallowing the stream.
        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}
