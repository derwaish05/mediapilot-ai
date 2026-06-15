<?php

declare(strict_types=1);

namespace MediaPilotAI\CSV;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderRepository;

/**
 * Imports MediaPilot folder data from previously exported CSV files.
 *
 * Two import types (matching the two CsvExporter streams):
 *   1. importFolderStructure() — recreates folder tree from a structure CSV.
 *      Builds an old_id → new_id map so child folders resolve to newly created
 *      parents regardless of original term IDs.
 *
 *   2. restoreFileAssignments() — reassigns attachments to folders using a
 *      file-assignments CSV. Matches folders by name lookup (via importedMap
 *      or a live name search) so it works after importFolderStructure().
 *
 * Both methods return an ImportResult value object with success/skipped/error
 * counts and a human-readable messages array.
 *
 * @package MediaPilotAI\CSV
 * @since   1.0.0
 */
class CsvImporter {

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
     * Imports a folder structure CSV (previously exported by CsvExporter).
     *
     * CSV must have header row: id, name, parent_id, color, file_count, user_id
     * Rows with parent_id = 0 are created first; children are resolved via the
     * old_id → new_id map built during the import pass.
     *
     * @param  resource $handle  Opened file/stream handle (CSV).
     * @param  int      $userId  User ID to assign folders to. 0 = global.
     * @return ImportResult
     */
    public function importFolderStructure($handle, int $userId = 0): ImportResult {
        $rows = $this->parseCsv($handle);

        if (empty($rows)) {
            return new ImportResult(0, 0, 1, ['CSV file is empty or missing the required header row.']);
        }

        $required = ['id', 'name', 'parent_id'];
        $missing  = array_diff($required, array_keys($rows[0]));

        if (!empty($missing)) {
            return new ImportResult(0, 0, 1, [
                'CSV is missing required columns: ' . implode(', ', $missing),
            ]);
        }

        // Sort: rows with parent_id = 0 come first, then by original id asc.
        usort($rows, static function (array $a, array $b): int {
            $aParent = (int) $a['parent_id'];
            $bParent = (int) $b['parent_id'];

            if ($aParent === 0 && $bParent !== 0) return -1;
            if ($aParent !== 0 && $bParent === 0) return 1;

            return (int) $a['id'] <=> (int) $b['id'];
        });

        // old term_id → newly created term_id
        $idMap    = [];
        $success  = 0;
        $skipped  = 0;
        $errors   = 0;
        $messages = [];

        foreach ($rows as $row) {
            $oldId    = (int) ($row['id'] ?? 0);
            $name     = trim((string) ($row['name'] ?? ''));
            $parentId = (int) ($row['parent_id'] ?? 0);

            if ('' === $name) {
                $skipped++;
                $messages[] = "Row with id={$oldId} skipped: name is empty.";
                continue;
            }

            // Resolve the new parent ID from the map (0 stays 0 = top-level).
            $newParentId = ($parentId > 0 && isset($idMap[$parentId]))
                ? $idMap[$parentId]
                : 0;

            try {
                $newId         = $this->folderRepository->create($name, $newParentId, $userId);
                $idMap[$oldId] = $newId;
                $success++;

                // Restore color if provided.
                $color = trim((string) ($row['color'] ?? ''));
                if ('' !== $color && preg_match('/^#[0-9a-fA-F]{3,6}$/', $color)) {
                    $this->folderRepository->updateColor($newId, $color);
                }
            } catch (\RuntimeException $e) {
                $errors++;
                $messages[] = "Failed to create folder \"{$name}\": " . $e->getMessage();
            }
        }

        return new ImportResult($success, $skipped, $errors, $messages);
    }

    /**
     * Restores file-to-folder assignments from a file-assignments CSV.
     *
     * CSV must have header row: attachment_id, folder_id, folder_name, ...
     * Additional columns (file_title, file_url) are ignored.
     *
     * Lookup strategy:
     *   1. Try to match folder_id directly (term must still exist with that ID).
     *   2. Fall back to folder_name search if the folder_id no longer exists
     *      (useful after importFolderStructure() which assigns new IDs).
     *
     * @param  resource $handle  Opened file/stream handle (CSV).
     * @return ImportResult
     */
    public function restoreFileAssignments($handle): ImportResult {
        $rows = $this->parseCsv($handle);

        if (empty($rows)) {
            return new ImportResult(0, 0, 1, ['CSV file is empty or missing the required header row.']);
        }

        $required = ['attachment_id', 'folder_id', 'folder_name'];
        $missing  = array_diff($required, array_keys($rows[0]));

        if (!empty($missing)) {
            return new ImportResult(0, 0, 1, [
                'CSV is missing required columns: ' . implode(', ', $missing),
            ]);
        }

        $success  = 0;
        $skipped  = 0;
        $errors   = 0;
        $messages = [];

        // Name → term_id cache to avoid repeated DB lookups.
        $nameCache = [];

        foreach ($rows as $row) {
            $attachmentId = (int) ($row['attachment_id'] ?? 0);
            $folderId     = (int) ($row['folder_id'] ?? 0);
            $folderName   = trim((string) ($row['folder_name'] ?? ''));

            if ($attachmentId <= 0) {
                $skipped++;
                continue;
            }

            // Verify the attachment exists.
            if (!get_post($attachmentId) instanceof \WP_Post) {
                $skipped++;
                $messages[] = "Attachment ID {$attachmentId} does not exist — skipped.";
                continue;
            }

            // Resolve target folder ID.
            $resolvedFolderId = $this->resolveFolderId($folderId, $folderName, $nameCache);

            if (null === $resolvedFolderId) {
                $errors++;
                $messages[] = "Could not resolve folder for attachment {$attachmentId} "
                    . "(folder_id={$folderId}, folder_name=\"{$folderName}\") — skipped.";
                continue;
            }

            $ok = $this->folderRepository->assignFile($attachmentId, $resolvedFolderId);

            if ($ok) {
                $success++;
            } else {
                $errors++;
                $messages[] = "Failed to assign attachment {$attachmentId} to folder {$resolvedFolderId}.";
            }
        }

        return new ImportResult($success, $skipped, $errors, $messages);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Parses a CSV file handle into an array of associative rows.
     *
     * Strips the UTF-8 BOM from the first header cell if present.
     *
     * @param  resource $handle
     * @return array<int, array<string, string>>
     */
    private function parseCsv($handle): array {
        $rows   = [];
        $header = null;

        while (($line = fgetcsv($handle, 4096, ',')) !== false) {
            if (null === $header) {
                // Strip BOM from first cell.
                $line[0] = ltrim((string) ($line[0] ?? ''), "\xEF\xBB\xBF");
                $header  = array_map('trim', $line);
                continue;
            }

            if (count($line) !== count($header)) {
                // Malformed row — skip silently.
                continue;
            }

            $rows[] = array_combine($header, $line);
        }

        return $rows;
    }

    /**
     * Resolves a folder ID from the CSV, falling back to name-based lookup.
     *
     * @param  int    $folderId    Original folder_id column value.
     * @param  string $folderName  folder_name column value (fallback).
     * @param  array<string, int> &$nameCache  Memoization cache by folder name.
     * @return int|null  Resolved term ID, or null if not found.
     */
    private function resolveFolderId(int $folderId, string $folderName, array &$nameCache): ?int {
        // Try direct folder ID first — verify the term still exists.
        if ($folderId > 0 && null !== $this->folderRepository->getById($folderId)) {
            return $folderId;
        }

        // Fall back to name-based lookup.
        if ('' === $folderName) {
            return null;
        }

        if (isset($nameCache[$folderName])) {
            return $nameCache[$folderName];
        }

        $term = get_term_by('name', $folderName, \MediaPilotAI\Taxonomy\FolderTaxonomy::TAXONOMY);

        if (!$term instanceof \WP_Term) {
            $nameCache[$folderName] = 0;
            return null;
        }

        $nameCache[$folderName] = (int) $term->term_id;

        return (int) $term->term_id;
    }
}
