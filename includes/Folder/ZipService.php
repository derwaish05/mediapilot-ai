<?php

declare(strict_types=1);

namespace MediaPilotAI\Folder;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Media\MediaRepository;

/**
 * Builds and streams a ZIP archive of all files in a folder (recursively).
 *
 * Usage:
 *   $zipService->streamFolderZip($folderId);   // streams & exits
 *
 * Requirements:
 *   - PHP ZipArchive extension (bundled with PHP on all major hosts).
 *   - The caller must verify permissions before invoking streamFolderZip().
 *
 * Algorithm:
 *   1. Validate the folder exists.
 *   2. Recursively walk descendant folders to collect all attachment IDs.
 *   3. Create a temp ZIP file using ZipArchive.
 *   4. Add each physical file, de-duplicating filenames with a counter suffix.
 *   5. Stream via headers + readfile(), then clean up the temp file.
 *
 * The temp file is always deleted — even if streaming fails — via a
 * register_shutdown_function() guard registered before streaming starts.
 *
 * @package MediaPilotAI\Folder
 * @since   1.0.0
 */
class ZipService {

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct(
        private readonly FolderRepository $folderRepository,
        private readonly MediaRepository  $mediaRepository,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Builds a ZIP of all files in $folderId (including subfolders) and streams
     * it to the browser, then terminates script execution.
     *
     * Call this only after permission checks have passed.
     *
     * @param  int $folderId  Must be > 0.
     * @return never           Always exits (streams file or dies with error).
     *
     * @throws \RuntimeException If ZipArchive is not available or temp file
     *                           cannot be created. The REST callback catches this
     *                           and returns a 500 error response instead.
     */
    public function streamFolderZip(int $folderId): never {
        $folder = $this->folderRepository->getById($folderId);

        if (null === $folder) {
            // Caller should have validated, but guard anyway.
            status_header(404);
            die(wp_json_encode([
                'success' => false,
                'code'    => 'mdpai_folder_not_found',
                'message' => __('Folder not found.', 'mediapilot-ai'),
            ]));
        }

        if (!class_exists('ZipArchive')) {
            status_header(500);
            die(wp_json_encode([
                'success' => false,
                'code'    => 'mdpai_zip_unavailable',
                'message' => __('ZIP extension is not available on this server.', 'mediapilot-ai'),
            ]));
        }

        // Collect all attachment IDs (this folder + all descendants).
        $attachmentIds = $this->collectAttachmentIds($folderId);

        // Build the ZIP to a temp file.
        $tmpFile = $this->buildZip($attachmentIds, (string) $folder['name']);

        // Register a shutdown guard so the temp file is always removed.
        register_shutdown_function(static function () use ($tmpFile): void {
            if (file_exists($tmpFile)) {
                wp_delete_file($tmpFile);
            }
        });

        // Stream the ZIP to the browser.
        $this->stream($tmpFile, (string) $folder['name']);
    }

    /**
     * Builds a ZIP of an arbitrary list of attachment IDs and streams it to the
     * browser, then terminates script execution.
     *
     * Used by the `POST /files/zip` bulk-download REST endpoint. The caller is
     * responsible for permission checks before calling this method.
     *
     * @param  int[]  $attachmentIds  List of WP attachment post IDs. Empty list is allowed — produces an empty ZIP.
     * @param  string $zipName        Basename for the downloaded file (no extension).
     * @return never
     *
     * @throws \RuntimeException If ZipArchive is unavailable or temp file cannot be created.
     */
    public function streamAttachmentsZip(array $attachmentIds, string $zipName = 'media-files'): never {
        if (!class_exists('ZipArchive')) {
            status_header(500);
            die(wp_json_encode([
                'success' => false,
                'code'    => 'mdpai_zip_unavailable',
                'message' => __('ZIP extension is not available on this server.', 'mediapilot-ai'),
            ]));
        }

        $ids     = array_values(array_unique(array_map('intval', $attachmentIds)));
        $tmpFile = $this->buildZip($ids, $zipName);

        register_shutdown_function(static function () use ($tmpFile): void {
            if (file_exists($tmpFile)) {
                wp_delete_file($tmpFile);
            }
        });

        $this->stream($tmpFile, $zipName);
    }

    // -------------------------------------------------------------------------
    // Private — Collection
    // -------------------------------------------------------------------------

    /**
     * Recursively collects all attachment IDs in $folderId and its descendants.
     *
     * @param  int   $folderId
     * @return int[]  Unique, unsorted attachment IDs.
     */
    private function collectAttachmentIds(int $folderId): array {
        // Direct attachments in this folder.
        $ids = $this->mediaRepository->getAttachmentIds($folderId);

        // Recurse into child folders.
        $children = $this->folderRepository->getChildren($folderId);

        foreach ($children as $child) {
            $childIds = $this->collectAttachmentIds((int) $child['id']);
            $ids      = array_merge($ids, $childIds);
        }

        // Remove duplicates (shouldn't happen, but be safe).
        return array_values(array_unique($ids));
    }

    // -------------------------------------------------------------------------
    // Private — ZIP Builder
    // -------------------------------------------------------------------------

    /**
     * Creates a ZIP file in the system temp directory containing the physical
     * files for every supplied attachment ID.
     *
     * Files whose paths cannot be resolved or do not exist on disk are skipped
     * silently (the attachment record may have been orphaned).
     *
     * Filename collisions inside the ZIP are resolved by appending ` (n)` before
     * the extension: `photo.jpg` → `photo (2).jpg` → `photo (3).jpg`.
     *
     * @param  int[]  $attachmentIds
     * @param  string $folderName    Used in the ZIP comment only.
     * @return string  Absolute path to the created temp file.
     *
     * @throws \RuntimeException If the temp file or ZipArchive cannot be opened.
     */
    private function buildZip(array $attachmentIds, string $folderName): string {
        $tmpFile = tempnam(sys_get_temp_dir(), 'mdpai_zip_');

        if (false === $tmpFile) {
            throw new \RuntimeException('MediaPilot ZipService: could not create temp file.');
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($tmpFile, \ZipArchive::OVERWRITE);

        if (true !== $opened) {
            throw new \RuntimeException( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                sprintf('MediaPilot ZipService: ZipArchive::open() failed with code %d.', $opened)  // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message, not HTML output
            );
        }

        $zip->setArchiveComment(
            sprintf(
                'Media folder: %s — exported by MediaPilot AI',
                $folderName
            )
        );

        /** @var array<string, int> $usedNames  basename → next-counter */
        $usedNames = [];

        foreach ($attachmentIds as $attachmentId) {
            $filePath = get_attached_file($attachmentId);

            if (!is_string($filePath) || !file_exists($filePath)) {
                // Attachment record exists but physical file is gone — skip.
                continue;
            }

            $entryName = $this->uniqueEntryName($filePath, $usedNames);
            $zip->addFile($filePath, $entryName);
        }

        $zip->close();

        return $tmpFile;
    }

    /**
     * Returns a filename string that is unique within the current ZIP archive,
     * tracking used names in the $usedNames reference map.
     *
     * Example: two files both named `photo.jpg` → `photo.jpg` and `photo (2).jpg`.
     *
     * @param  string             $filePath   Absolute path to the source file.
     * @param  array<string, int> $usedNames  Pass-by-reference collision tracker.
     * @return string
     */
    private function uniqueEntryName(string $filePath, array &$usedNames): string {
        $basename  = basename($filePath);
        $extension = pathinfo($basename, PATHINFO_EXTENSION);
        $stem      = $extension !== ''
            ? substr($basename, 0, -(strlen($extension) + 1))
            : $basename;

        if (!isset($usedNames[$basename])) {
            $usedNames[$basename] = 1;
            return $basename;
        }

        // Collision — increment the counter until we find a free slot.
        $usedNames[$basename]++;
        $counter   = $usedNames[$basename];
        $candidate = $extension !== ''
            ? "{$stem} ({$counter}).{$extension}"
            : "{$stem} ({$counter})";

        // Recurse if the candidate itself is already taken (rare but possible).
        return $this->uniqueEntryName(
            str_replace(basename($filePath), $candidate, $filePath),
            $usedNames
        );
    }

    // -------------------------------------------------------------------------
    // Private — Streaming
    // -------------------------------------------------------------------------

    /**
     * Outputs the ZIP file to the browser and terminates PHP execution.
     *
     * Any previously buffered output is discarded to prevent header corruption.
     *
     * @param  string $tmpFile     Absolute path to the temp ZIP.
     * @param  string $folderName  Used as the download filename base.
     * @return never
     */
    private function stream(string $tmpFile, string $folderName): never {
        // Discard any buffered output that could corrupt the binary stream.
        if (ob_get_level()) {
            ob_end_clean();
        }

        $downloadName = sanitize_file_name($folderName) . '.zip';
        $fileSize     = (int) filesize($tmpFile);

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Ensure the script does not time out during readfile for large archives.
        set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged

        readfile($tmpFile); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_readfile, WordPress.WP.AlternativeFunctions.file_system_operations_readfile

        exit;
    }
}
