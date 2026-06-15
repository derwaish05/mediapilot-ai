<?php

declare(strict_types=1);

namespace MediaPilotAI\Media;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Business logic layer for media/file operations.
 *
 * All external callers (REST controller, WP-CLI, admin integrations) should
 * use this service exclusively.  No code outside this class should call
 * MediaRepository directly for write operations.
 *
 * Responsibilities:
 *  - Delegate persistence to MediaRepository.
 *  - Fire all do_action hooks after confirmed success.
 *  - Provide formatted data suitable for REST responses.
 *
 * @package MediaPilotAI\Media
 * @since   1.0.0
 */
class MediaService {

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct(
        private readonly MediaRepository $repository
    ) {}

    // -------------------------------------------------------------------------
    // File Assignment
    // -------------------------------------------------------------------------

    /**
     * Assigns a single attachment to a folder.
     *
     * Pass $folderId = 0 to move the file to Uncategorized.
     *
     * Fires: do_action('mdpai_after_file_assign', $attachmentId, $folderId)
     *
     * @param  int $attachmentId  WordPress attachment post ID.
     * @param  int $folderId      Target folder term ID (0 = Uncategorized).
     * @return bool  True on success.
     */
    public function assignFile(int $attachmentId, int $folderId): bool {
        $success = $this->repository->assignToFolder($attachmentId, $folderId);

        if ($success) {
            /**
             * Fires after an attachment has been successfully assigned to a folder.
             *
             * @since 1.0.0
             *
             * @param int $attachmentId  WordPress attachment post ID.
             * @param int $folderId      Target folder term ID (0 = Uncategorized).
             */
            do_action('mdpai_after_file_assign', $attachmentId, $folderId);
        }

        return $success;
    }

    /**
     * Moves multiple attachments to a folder in a single batch operation.
     *
     * Silently skips any IDs that are not valid positive integers.
     * Returns the count of successfully moved attachments.
     *
     * Fires: do_action('mdpai_after_files_moved', $attachmentIds, $folderId, $movedCount)
     *
     * @param  int[] $attachmentIds  List of WordPress attachment post IDs.
     * @param  int   $folderId       Target folder term ID (0 = Uncategorized).
     * @return int  Count of successfully moved files.
     */
    public function moveFiles(array $attachmentIds, int $folderId): int {
        $moved = 0;

        foreach ($attachmentIds as $rawId) {
            $id = absint($rawId);

            if ($id <= 0) {
                continue;
            }

            if ($this->repository->assignToFolder($id, $folderId)) {
                ++$moved;
            }
        }

        if ($moved > 0) {
            /**
             * Fires after a batch of attachments has been successfully moved.
             *
             * @since 1.0.0
             *
             * @param int[] $attachmentIds  Original list of requested attachment IDs.
             * @param int   $folderId       Target folder term ID.
             * @param int   $movedCount     Count of attachments that were successfully moved.
             */
            do_action('mdpai_after_files_moved', $attachmentIds, $folderId, $moved);
        }

        return $moved;
    }

    // -------------------------------------------------------------------------
    // Data Retrieval
    // -------------------------------------------------------------------------

    /**
     * Returns files in a folder formatted for a REST response.
     *
     * Delegates the query to MediaRepository and formats each post.
     *
     * @param  int   $folderId  Folder term ID, 0 = Uncategorized, -1 = all.
     * @param  array<string, mixed> $args  Query modifiers (see MediaRepository::getFilesInFolder).
     * @return array{ files: array<int, array<string, mixed>>, total: int, pages: int }
     */
    public function getFilesInFolder(int $folderId, array $args = []): array {
        $result = $this->repository->getFilesInFolder($folderId, $args);

        $files = [];
        foreach ($result['posts'] as $post) {
            $files[] = $this->repository->formatAttachment($post);
        }

        return [
            'files' => $files,
            'total' => $result['total'],
            'pages' => $result['pages'],
        ];
    }

    /**
     * Returns a single attachment formatted for REST/JS consumption.
     *
     * @param  \WP_Post $post  Attachment post object.
     * @return array<string, mixed>
     */
    public function formatAttachment(\WP_Post $post): array {
        return $this->repository->formatAttachment($post);
    }
}
