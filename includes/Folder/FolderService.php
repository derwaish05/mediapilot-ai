<?php

declare(strict_types=1);

namespace MediaPilotAI\Folder;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Business logic layer for folder operations.
 *
 * The REST controller and WP-CLI command call this class exclusively.
 * No code outside this class should call FolderRepository directly.
 *
 * Responsibilities:
 *  - Input sanitization and validation.
 *  - Duplicate-name resolution (appends "(2)", "(3)", …).
 *  - Circular-move prevention.
 *  - Firing all do_action / apply_filters hooks.
 *  - Delegating persistence to FolderRepository.
 *
 * @package MediaPilotAI\Folder
 * @since   1.0.0
 */
class FolderService {

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct(
        private readonly FolderRepository $repository
    ) {}

    // -------------------------------------------------------------------------
    // Folder CRUD
    // -------------------------------------------------------------------------

    /**
     * Creates a folder after validating and sanitizing business rules.
     *
     * - Sanitizes name (strip_tags, trim, max 200 chars).
     * - Resolves duplicate names under the same parent for the same user by
     *   appending " (2)", " (3)", etc.
     * - Fires: do_action('mdpai_after_folder_create', $termId, $name, $parentId, $userId)
     *
     * @param  string $name
     * @param  int    $parentId  0 = top-level.
     * @param  int    $userId    0 = global / not user-scoped.
     * @return int    New term ID.
     * @throws \InvalidArgumentException When the sanitized name is empty.
     * @throws \RuntimeException         When the repository insert fails.
     */
    public function createFolder(string $name, int $parentId = 0, int $userId = 0): int {
        $name = $this->sanitizeName($name);

        if ('' === $name) {
            throw new \InvalidArgumentException( __( 'Folder name must not be empty.', 'mediapilot-ai') ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $name = $this->resolveUniqueName($name, $parentId, $userId);

        /**
         * Fires immediately before a new folder is written to the database.
         *
         * Allows plugins to log, audit, or enforce additional validation before
         * the folder record is created.
         *
         * @since 1.0.0
         *
         * @param string $name     Sanitized and de-duplicated folder name.
         * @param int    $parentId Parent term ID (0 = top-level).
         * @param int    $userId   Owner user ID (0 = global).
         */
        do_action('mdpai_before_folder_create', $name, $parentId, $userId);

        $termId = $this->repository->create($name, $parentId, $userId);

        /**
         * Fires after a folder has been successfully created.
         *
         * @since 1.0.0
         *
         * @param int    $termId   New folder term ID.
         * @param string $name     Final (possibly suffixed) folder name.
         * @param int    $parentId Parent term ID (0 = top-level).
         * @param int    $userId   Owner user ID (0 = global).
         */
        do_action('mdpai_after_folder_create', $termId, $name, $parentId, $userId);

        return $termId;
    }

    /**
     * Renames a folder after validation.
     *
     * - Sanitizes name.
     * - Resolves duplicate name conflicts within the same parent and user scope.
     * - Fires: do_action('mdpai_after_folder_rename', $termId, $newName)
     *
     * @param  int    $termId
     * @param  string $newName
     * @return bool
     * @throws \InvalidArgumentException When the sanitized name is empty.
     */
    public function renameFolder(int $termId, string $newName): bool {
        $newName = $this->sanitizeName($newName);

        if ('' === $newName) {
            throw new \InvalidArgumentException( __( 'Folder name must not be empty.', 'mediapilot-ai') ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        // Resolve duplicates relative to the existing parent and owner.
        $folder   = $this->repository->getById($termId);
        $parentId = $folder ? (int) $folder['parent']  : 0;
        $userId   = $folder ? (int) $folder['user_id'] : 0;

        $newName = $this->resolveUniqueName($newName, $parentId, $userId, $termId);

        $success = $this->repository->rename($termId, $newName);

        if ($success) {
            /**
             * Fires after a folder has been successfully renamed.
             *
             * @since 1.0.0
             *
             * @param int    $termId  Folder term ID.
             * @param string $newName Final (possibly suffixed) new name.
             */
            do_action('mdpai_after_folder_rename', $termId, $newName);
        }

        return $success;
    }

    /**
     * Moves a folder to a new parent.
     *
     * - Prevents circular moves: a folder cannot be moved into one of its own
     *   descendants. Use isCircularMove() to detect this condition.
     * - Fires: do_action('mdpai_after_folder_move', $termId, $newParentId)
     *
     * @param  int  $termId
     * @param  int  $newParentId  0 = promote to top-level.
     * @return bool
     * @throws \InvalidArgumentException When the move would create a circular reference.
     */
    public function moveFolder(int $termId, int $newParentId): bool {
        if ($this->isCircularMove($termId, $newParentId)) {
            throw new \InvalidArgumentException( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                sprintf(
                    'MediaPilot: Cannot move folder %d into %d — that would create a circular reference.',
                    $termId,  // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message, not HTML output
                    $newParentId  // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message, not HTML output
                )
            );
        }

        $success = $this->repository->move($termId, $newParentId);

        if ($success) {
            /**
             * Fires after a folder has been successfully moved.
             *
             * @since 1.0.0
             *
             * @param int $termId      Folder term ID.
             * @param int $newParentId New parent term ID (0 = top-level).
             */
            do_action('mdpai_after_folder_move', $termId, $newParentId);
        }

        return $success;
    }

    /**
     * Deletes a folder.
     *
     * - If $recursive = true  : deletes all child folders recursively, moves
     *                           all their files to Uncategorized.
     * - If $recursive = false : child folders are promoted to the deleted
     *                           folder's parent; all files in this folder are
     *                           moved to Uncategorized.
     * - Fires: do_action('mdpai_after_folder_delete', $termId, $recursive)
     *
     * @param  int  $termId
     * @param  bool $recursive
     * @return bool
     */
    public function deleteFolder(int $termId, bool $recursive = false): bool {
        /**
         * Fires immediately before a folder is deleted from the database.
         *
         * @since 1.0.0
         *
         * @param int  $termId    Folder term ID about to be deleted.
         * @param bool $recursive Whether sub-folders will also be deleted.
         */
        do_action('mdpai_before_folder_delete', $termId, $recursive);

        $success = $this->repository->delete($termId, $recursive);

        if ($success) {
            /**
             * Fires after a folder has been successfully deleted.
             *
             * @since 1.0.0
             *
             * @param int  $termId    Deleted folder term ID.
             * @param bool $recursive Whether children were deleted recursively.
             */
            do_action('mdpai_after_folder_delete', $termId, $recursive);
        }

        return $success;
    }

    // -------------------------------------------------------------------------
    // Tree
    // -------------------------------------------------------------------------

    /**
     * Returns the folder tree for use in REST responses and UI rendering.
     *
     * Applies filter: apply_filters('mdpai_folder_tree', $tree, $userId)
     *
     * @param  int   $userId  0 = global tree.
     * @return array<int, array<string, mixed>>
     */
    public function getTree(int $userId = 0): array {
        $tree = $this->repository->getTree($userId);

        /**
         * Filters the folder tree array before it is returned to callers.
         *
         * @since 1.0.0
         *
         * @param array<int, array<string, mixed>> $tree     Nested folder tree.
         * @param int                              $viewerId ID of the user viewing the tree (for permission checks).
         */
        return (array) apply_filters('mdpai_folder_tree', $tree, get_current_user_id());
    }

    // -------------------------------------------------------------------------
    // File Assignment
    // -------------------------------------------------------------------------

    /**
     * Assigns a file to a folder.
     *
     * Pass $termId = 0 to unassign (move to Uncategorized).
     * Fires: do_action('mdpai_after_file_assign', $attachmentId, $termId)
     *
     * @param  int  $attachmentId
     * @param  int  $termId  0 = Uncategorized.
     * @return bool
     */
    public function assignFile(int $attachmentId, int $termId): bool {
        /**
         * Fires immediately before an attachment is assigned to a folder.
         *
         * @since 1.0.0
         *
         * @param int $attachmentId  WordPress attachment post ID.
         * @param int $termId        Target folder term ID (0 = Uncategorized / un-assign).
         */
        do_action('mdpai_before_file_assign', $attachmentId, $termId);

        $success = $this->repository->assignFile($attachmentId, $termId);

        if ($success) {
            /**
             * Fires after an attachment has been assigned to a folder.
             *
             * @since 1.0.0
             *
             * @param int $attachmentId  WordPress attachment post ID.
             * @param int $termId        Target folder term ID (0 = Uncategorized).
             */
            do_action('mdpai_after_file_assign', $attachmentId, $termId);
        }

        return $success;
    }

    // -------------------------------------------------------------------------
    // Color
    // -------------------------------------------------------------------------

    /**
     * Updates a folder's color meta.
     *
     * Validates that $color is a proper 3- or 6-digit hex color (#xxx or #xxxxxx).
     * Returns false without touching the database if the format is invalid.
     *
     * @param  int    $termId
     * @param  string $color  E.g. '#3b82f6' or '#fff'.
     * @return bool   False when color format is invalid.
     */
    public function updateColor(int $termId, string $color): bool {
        if (!$this->isValidHexColor($color)) {
            return false;
        }

        return $this->repository->updateColor($termId, $color);
    }

    // -------------------------------------------------------------------------
    // Circular Move Detection
    // -------------------------------------------------------------------------

    /**
     * Checks whether moving $termId into $targetParentId would create a circular
     * reference (i.e. $targetParentId is a descendant of $termId).
     *
     * Algorithm: walk UP the ancestor chain of $targetParentId.
     * If any ancestor equals $termId the move is circular and must be blocked.
     *
     * @param  int  $termId         Folder being moved.
     * @param  int  $targetParentId Proposed new parent folder.
     * @return bool True = circular (block the move); false = safe.
     */
    public function isCircularMove(int $termId, int $targetParentId): bool {
        // Moving to top-level (0) is always safe.
        if (0 === $targetParentId) {
            return false;
        }

        // A folder cannot be moved into itself.
        if ($termId === $targetParentId) {
            return true;
        }

        // Walk up the ancestor chain of $targetParentId.
        $current = $targetParentId;

        // Guard against pathological data (cycle already in DB) with a depth limit.
        $maxDepth = 1000;
        $depth    = 0;

        while ($current > 0 && $depth < $maxDepth) {
            $folder = $this->repository->getById($current);

            if (null === $folder) {
                break;
            }

            $parentOfCurrent = (int) $folder['parent'];

            if ($parentOfCurrent === $termId) {
                return true;
            }

            $current = $parentOfCurrent;
            ++$depth;
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    /**
     * Sanitizes a folder name: strips HTML tags, trims whitespace, and enforces
     * a maximum length of 200 characters.
     *
     * @param  string $name
     * @return string
     */
    private function sanitizeName(string $name): string {
        $name = wp_strip_all_tags($name);
        $name = trim($name);
        $name = mb_substr($name, 0, 200);

        return $name;
    }

    /**
     * Resolves a folder name to a unique value within the given parent/user
     * scope by appending " (2)", " (3)", etc. when collisions are detected.
     *
     * @param  string   $name          Proposed display name (already sanitized).
     * @param  int      $parentId      Parent folder term ID.
     * @param  int      $userId        Owner user ID.
     * @param  int|null $excludeTermId When renaming, exclude the current term's
     *                                  own name from the collision check.
     * @return string   A unique folder name within the given context.
     */
    private function resolveUniqueName(
        string $name,
        int    $parentId,
        int    $userId,
        ?int   $excludeTermId = null
    ): string {
        $siblings    = $this->getSiblingNames($parentId, $userId, $excludeTermId);
        $candidate   = $name;
        $counter     = 2;

        while (in_array($candidate, $siblings, true)) {
            $candidate = "{$name} ({$counter})";
            ++$counter;
        }

        return $candidate;
    }

    /**
     * Returns the display names of all sibling folders (same parent, same user).
     *
     * An optional $excludeTermId allows the caller to skip the folder being
     * renamed so it does not collide with its own current name.
     *
     * @param  int      $parentId
     * @param  int      $userId
     * @param  int|null $excludeTermId
     * @return string[]
     */
    private function getSiblingNames(int $parentId, int $userId, ?int $excludeTermId): array {
        $children = $this->repository->getChildren($parentId);
        $names    = [];

        foreach ($children as $child) {
            // Skip the folder being renamed from the collision list.
            if (null !== $excludeTermId && (int) $child['id'] === $excludeTermId) {
                continue;
            }

            // In per-user mode restrict comparison to same-owner siblings.
            if ($userId > 0 && (int) $child['user_id'] !== $userId) {
                continue;
            }

            $names[] = $child['name'];
        }

        return $names;
    }

    /**
     * Validates that $color is a 3- or 6-digit CSS hex color string.
     *
     * Valid examples : '#fff', '#3b82f6', '#FFF', '#3B82F6'
     * Invalid         : 'red', '#gg0000', '3b82f6'
     *
     * @param  string $color
     * @return bool
     */
    private function isValidHexColor(string $color): bool {
        return (bool) preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color);
    }
}
