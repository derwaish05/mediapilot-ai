<?php

declare(strict_types=1);

namespace MediaPilotAI\Folder;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Taxonomy\FolderTaxonomy;

/**
 * Data access layer for folders (mdpai_folder taxonomy terms).
 *
 * This is the ONLY class that performs database operations for folder data.
 * All taxonomy operations use the standard WordPress taxonomy API.
 * Raw $wpdb usage is reserved for wp_mdpai_user_prefs table lookups only.
 *
 * Transient cache key pattern : mdpai_tree_{userId} | mdpai_tree_global
 * Cache TTL                   : HOUR_IN_SECONDS (3600 s)
 *
 * @package MediaPilotAI\Folder
 * @since   1.0.0
 */
class FolderRepository {

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    private const DEFAULT_COLOR   = '#94a3b8';
    private const META_COLOR      = 'mdpai_folder_color';
    private const META_USER_ID    = 'mdpai_folder_user_id';
    private const TRANSIENT_TTL   = HOUR_IN_SECONDS;

    // -------------------------------------------------------------------------
    // Public API — Tree & Read
    // -------------------------------------------------------------------------

    /**
     * Returns the full folder tree as a nested array for a given user.
     *
     * In global mode (user_id = 0) returns all folders.
     * In per-user mode returns only folders created by that user.
     *
     * Each folder node shape:
     *   [ 'id', 'name', 'slug', 'parent', 'color', 'count', 'children' => [] ]
     *
     * The result is cached in a transient for HOUR_IN_SECONDS.
     *
     * @param  int   $userId  0 = global (all folders).
     * @return array<int, array<string, mixed>>
     */
    public function getTree(int $userId = 0): array {
        $cacheKey = $this->treeTransientKey($userId);
        $cached   = get_transient($cacheKey);

        // Only trust a non-empty cached tree. An empty cached array may be a
        // stale transient set before folders existed (e.g. after plugin activation
        // or a mode switch). We re-query the DB to confirm before returning [].
        if (false !== $cached && is_array($cached) && count($cached) > 0) {
            return $cached;
        }

        $args = [
            'taxonomy'                => FolderTaxonomy::TAXONOMY,
            'hide_empty'              => false,
            'number'                  => 0,
            // Always bypass WordPress's in-request object cache so we get
            // fresh DB rows when the transient has been cleared after a write.
            'cache_results'           => false,
            // Enable meta cache pre-loading: WordPress will run a single
            // batch SELECT on wp_termmeta for all returned term IDs, so the
            // subsequent get_term_meta() calls inside normaliseTerm() hit the
            // in-memory cache instead of firing one query per term.
            'update_term_meta_cache'  => true,
        ];

        // Per-user mode: show the user's own folders, global folders (user_id=0),
        // AND folders that have no mdpai_folder_user_id meta at all (e.g. folders
        // imported or created before the plugin was fully set up).
        if ($userId > 0) {
            $args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                'relation' => 'OR',
                [
                    'key'     => self::META_USER_ID,
                    'value'   => $userId,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ],
                [
                    'key'     => self::META_USER_ID,
                    'value'   => '0',
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ],
                [
                    'key'     => self::META_USER_ID,
                    'compare' => 'NOT EXISTS',
                ],
            ];
        }

        $terms = get_terms($args);

        if (is_wp_error($terms)) {
            return [];
        }

        // Batch-load attachment counts in a single GROUP BY query so that
        // normaliseTerm() never fires one SELECT per folder.
        $termIds          = array_map(static fn(\WP_Term $t): int => (int) $t->term_id, $terms);
        $attachmentCounts = $this->batchCountAttachments($termIds);

        // Normalise to plain arrays.
        $flat = [];
        foreach ($terms as $term) {
            $flat[] = $this->normaliseTerm($term, $attachmentCounts);
        }

        $tree = $this->buildTree($flat, 0);

        // Roll child counts up into each parent so the parent shows the total
        // number of files across itself and all its descendants.
        $this->accumulateChildCounts($tree);

        set_transient($cacheKey, $tree, self::TRANSIENT_TTL);

        return $tree;
    }

    /**
     * Returns a single folder by term ID, including mdpai_folder_color and
     * mdpai_folder_user_id meta values.
     *
     * Returns null if the term does not exist or is not an mdpai_folder term.
     *
     * @param  int        $termId
     * @return array<string, mixed>|null
     */
    public function getById(int $termId): ?array {
        $term = get_term($termId, FolderTaxonomy::TAXONOMY);

        if (is_wp_error($term) || null === $term) {
            return null;
        }

        return $this->normaliseTerm($term);
    }

    /**
     * Returns the direct children of a folder.
     *
     * @param  int   $parentId  0 = top-level children.
     * @return array<int, array<string, mixed>>
     */
    public function getChildren(int $parentId): array {
        $terms = get_terms([
            'taxonomy'   => FolderTaxonomy::TAXONOMY,
            'hide_empty' => false,
            'parent'     => $parentId,
            'number'     => 0,
        ]);

        if (is_wp_error($terms)) {
            return [];
        }

        return array_map([$this, 'normaliseTerm'], $terms);
    }

    /**
     * Returns all folders owned by a specific user via mdpai_folder_user_id meta.
     *
     * @param  int   $userId
     * @return array<int, array<string, mixed>>
     */
    public function getByUser(int $userId): array {
        $terms = get_terms([
            'taxonomy'   => FolderTaxonomy::TAXONOMY,
            'hide_empty' => false,
            'number'     => 0,
            'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                [
                    'key'     => self::META_USER_ID,
                    'value'   => $userId,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ],
            ],
        ]);

        if (is_wp_error($terms)) {
            return [];
        }

        return array_map([$this, 'normaliseTerm'], $terms);
    }

    // -------------------------------------------------------------------------
    // Public API — Write
    // -------------------------------------------------------------------------

    /**
     * Creates a new folder term under the given parent for the given user.
     *
     * Stores meta: mdpai_folder_color (default '#94a3b8'), mdpai_folder_user_id.
     * Deletes the folder tree transient after successful creation.
     *
     * @param  string $name
     * @param  int    $parentId  0 = top-level.
     * @param  int    $userId    0 = global (no user ownership).
     * @return int    New term ID.
     * @throws \RuntimeException On wp_insert_term failure.
     */
    public function create(string $name, int $parentId = 0, int $userId = 0): int {
        $args = [
            'taxonomy' => FolderTaxonomy::TAXONOMY,
            'parent'   => $parentId,
        ];

        $result = wp_insert_term($name, FolderTaxonomy::TAXONOMY, $args);

        if (is_wp_error($result)) {
            throw new \RuntimeException( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                sprintf('MediaPilot FolderRepository::create() failed: %s', $result->get_error_message())  // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message, not HTML output
            );
        }

        $termId = (int) $result['term_id'];

        update_term_meta($termId, self::META_COLOR,   self::DEFAULT_COLOR);
        update_term_meta($termId, self::META_USER_ID, $userId);

        $this->deleteTreeTransient($userId);

        return $termId;
    }

    /**
     * Renames a folder term and regenerates its slug.
     *
     * Deletes the folder tree transient after a successful rename.
     *
     * @param  int    $termId
     * @param  string $newName  Sanitized display name.
     * @return bool
     */
    public function rename(int $termId, string $newName): bool {
        // Build a fresh slug from the new name.
        $newSlug = sanitize_title($newName);

        $result = wp_update_term($termId, FolderTaxonomy::TAXONOMY, [
            'name' => $newName,
            'slug' => $newSlug,
        ]);

        if (is_wp_error($result)) {
            return false;
        }

        $userId = (int) get_term_meta($termId, self::META_USER_ID, true);
        $this->deleteTreeTransient($userId);

        return true;
    }

    /**
     * Moves a folder term to a new parent.
     *
     * Deletes the folder tree transient after a successful move.
     *
     * @param  int  $termId
     * @param  int  $newParentId  0 = make top-level.
     * @return bool
     */
    public function move(int $termId, int $newParentId): bool {
        $result = wp_update_term($termId, FolderTaxonomy::TAXONOMY, [
            'parent' => $newParentId,
        ]);

        if (is_wp_error($result)) {
            return false;
        }

        $userId = (int) get_term_meta($termId, self::META_USER_ID, true);
        $this->deleteTreeTransient($userId);

        return true;
    }

    /**
     * Deletes a folder term.
     *
     * Behaviour:
     *   $recursive = false : moves direct child folders to the deleted folder's
     *                        parent; all attachments in this folder are moved to
     *                        Uncategorized (term ID 0).
     *   $recursive = true  : all descendant folders are deleted recursively first;
     *                        all attachments in every deleted folder are moved to
     *                        Uncategorized.
     *
     * Deletes the folder tree transient after completion.
     *
     * @param  int  $termId
     * @param  bool $recursive
     * @return bool
     */
    public function delete(int $termId, bool $recursive = false): bool {
        // Fetch the folder so we know its parent before deleting.
        $folder = $this->getById($termId);

        if (null === $folder) {
            return false;
        }

        $parentId = (int) $folder['parent'];
        $userId   = (int) get_term_meta($termId, self::META_USER_ID, true);

        if ($recursive) {
            // Delete all descendants depth-first.
            $this->deleteDescendants($termId);
        } else {
            // Move direct child folders up to this folder's parent.
            $children = $this->getChildren($termId);
            foreach ($children as $child) {
                wp_update_term((int) $child['id'], FolderTaxonomy::TAXONOMY, [
                    'parent' => $parentId,
                ]);
            }
        }

        // Move all attachments currently in this folder to Uncategorized.
        $this->moveAttachmentsToUncategorized($termId);

        $result = wp_delete_term($termId, FolderTaxonomy::TAXONOMY);

        if (is_wp_error($result) || false === $result) {
            return false;
        }

        $this->deleteTreeTransient($userId);

        return true;
    }

    // -------------------------------------------------------------------------
    // Public API — File Assignment
    // -------------------------------------------------------------------------

    /**
     * Assigns an attachment to a folder, replacing any existing assignment.
     *
     * Pass $termId = 0 to move to Uncategorized (removes all mdpai_folder terms).
     * Deletes the folder tree transient for the owning user after assignment.
     *
     * @param  int  $attachmentId
     * @param  int  $termId  0 = Uncategorized.
     * @return bool
     */
    public function assignFile(int $attachmentId, int $termId): bool {
        if (0 === $termId) {
            // Remove all existing mdpai_folder term assignments.
            $result = wp_set_object_terms($attachmentId, [], FolderTaxonomy::TAXONOMY);
        } else {
            // Replace any existing assignment — a file lives in ONE folder at a time.
            $result = wp_set_object_terms($attachmentId, [$termId], FolderTaxonomy::TAXONOMY);
        }

        if (is_wp_error($result)) {
            return false;
        }

        // Invalidate the tree transient for the folder owner (best-effort).
        $userId = ($termId > 0)
            ? (int) get_term_meta($termId, self::META_USER_ID, true)
            : 0;
        $this->deleteTreeTransient($userId);

        return true;
    }

    /**
     * Returns the folder term ID an attachment is currently assigned to.
     *
     * Returns 0 if the attachment is unassigned (Uncategorized).
     *
     * @param  int $attachmentId
     * @return int
     */
    public function getFileFolder(int $attachmentId): int {
        $terms = wp_get_object_terms($attachmentId, FolderTaxonomy::TAXONOMY, [
            'fields' => 'ids',
            'number' => 1,
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            return 0;
        }

        return (int) $terms[0];
    }

    // -------------------------------------------------------------------------
    // Public API — Counts & Meta
    // -------------------------------------------------------------------------

    /**
     * Returns the count of attachments in a folder.
     *
     * @param  int  $termId
     * @param  bool $includeChildren  When true, counts all nested subfolders too.
     * @return int
     */
    public function getFileCount(int $termId, bool $includeChildren = false): int {
        if (!$includeChildren) {
            $term = get_term($termId, FolderTaxonomy::TAXONOMY);

            if (is_wp_error($term) || null === $term) {
                return 0;
            }

            return (int) $term->count;
        }

        // Build a list of this term + all descendant term IDs.
        $termIds   = $this->collectDescendantIds($termId);
        $termIds[] = $termId;

        $total = 0;
        foreach ($termIds as $id) {
            $term = get_term($id, FolderTaxonomy::TAXONOMY);
            if (!is_wp_error($term) && null !== $term) {
                $total += (int) $term->count;
            }
        }

        return $total;
    }

    /**
     * Updates the mdpai_folder_color meta for a folder.
     *
     * @param  int    $termId
     * @param  string $color  Hex color value (e.g. '#94a3b8').
     * @return bool
     */
    public function updateColor(int $termId, string $color): bool {
        $result = update_term_meta($termId, self::META_COLOR, $color);

        // update_term_meta returns false only on DB error, not on no-change.
        return false !== $result;
    }

    // -------------------------------------------------------------------------
    // Private — Tree Builder
    // -------------------------------------------------------------------------

    /**
     * Recursively converts a flat list of term arrays into a nested tree.
     *
     * @param  array<int, array<string, mixed>> $terms     Flat list of normalised terms.
     * @param  int                              $parentId  Start from this parent level.
     * @return array<int, array<string, mixed>>
     */
    private function buildTree(array $terms, int $parentId = 0): array {
        $branch = [];

        foreach ($terms as $term) {
            if ((int) $term['parent'] === $parentId) {
                $children       = $this->buildTree($terms, (int) $term['id']);
                $term['children'] = $children;
                $branch[]       = $term;
            }
        }

        return $branch;
    }

    // -------------------------------------------------------------------------
    // Private — Helpers
    // -------------------------------------------------------------------------

    /**
     * Normalises a WP_Term object into the standard MediaPilot folder array shape.
     *
     * @param  \WP_Term         $term
     * @param  array<int, int>  $preloadedCounts  Optional map of termId → count,
     *                                             pre-fetched by batchCountAttachments().
     *                                             Falls back to a per-term query when absent.
     * @return array<string, mixed>
     */
    private function normaliseTerm(\WP_Term $term, array $preloadedCounts = []): array {
        $termId = (int) $term->term_id;

        $count = array_key_exists($termId, $preloadedCounts)
            ? $preloadedCounts[$termId]
            : $this->countAttachmentsInFolder($termId);

        return [
            'id'       => $termId,
            'name'     => $term->name,
            'slug'     => $term->slug,
            'parent'   => (int) $term->parent,
            'color'    => (string) (get_term_meta($termId, self::META_COLOR,   true) ?: self::DEFAULT_COLOR),
            'user_id'  => (int)   (get_term_meta($termId, self::META_USER_ID,  true) ?: 0),
            'count'    => $count,
            'children' => [],
        ];
    }

    /**
     * Recursively adds descendant file counts into each parent node's count.
     *
     * After this pass, a folder's `count` equals its own direct files PLUS
     * the direct files of every nested subfolder, so the sidebar shows the
     * true total for the entire subtree.
     *
     * @param  array<int, array<string, mixed>> &$nodes  Tree nodes (modified in place).
     * @return int  Total count for this level (used by the recursive caller).
     */
    private function accumulateChildCounts(array &$nodes): int {
        $levelTotal = 0;

        foreach ($nodes as &$node) {
            if (!empty($node['children'])) {
                $childTotal    = $this->accumulateChildCounts($node['children']);
                $node['count'] += $childTotal;
            }
            $levelTotal += $node['count'];
        }

        return $levelTotal;
    }

    /**
     * Fetches attachment counts for multiple folder terms in a single query.
     *
     * Returns a map of term_id → count. Terms with no attachments are absent
     * from the map (callers should treat missing entries as 0).
     *
     * This eliminates the O(n) per-term queries that would otherwise fire
     * inside normaliseTerm() when building the full folder tree.
     *
     * @param  int[]            $termIds  List of folder term IDs.
     * @return array<int, int>  [ termId => attachmentCount ]
     */
    private function batchCountAttachments(array $termIds): array {
        if (empty($termIds)) {
            return [];
        }

        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($termIds), '%d'));
        $params       = array_merge($termIds, [FolderTaxonomy::TAXONOMY]);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                "SELECT tt.term_id, COUNT(*) AS cnt
                 FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->term_taxonomy} tt
                     ON tr.term_taxonomy_id = tt.term_taxonomy_id
                 INNER JOIN {$wpdb->posts} p
                     ON tr.object_id = p.ID
                 WHERE tt.term_id IN ({$placeholders})
                   AND tt.taxonomy = %s
                   AND p.post_type = 'attachment'
                   AND p.post_status = 'inherit'
                 GROUP BY tt.term_id",
                ...$params
            ) // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );

        $counts = [];
        foreach ((array) $rows as $row) {
            $counts[(int) $row->term_id] = (int) $row->cnt;
        }

        return $counts;
    }

    /**
     * Counts the number of attachment posts directly assigned to a folder term.
     *
     * WordPress's built-in $term->count only counts 'publish' status posts.
     * Attachments use 'inherit' status, so we must query directly.
     *
     * Used as a fallback when pre-loaded counts are unavailable (e.g. getById,
     * getChildren). getTree() uses batchCountAttachments() instead.
     *
     * @param  int $termId  Folder term ID.
     * @return int
     */
    private function countAttachmentsInFolder(int $termId): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT COUNT(*)
                 FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->term_taxonomy} tt
                     ON tr.term_taxonomy_id = tt.term_taxonomy_id
                 INNER JOIN {$wpdb->posts} p
                     ON tr.object_id = p.ID
                 WHERE tt.term_id = %d
                   AND tt.taxonomy = %s
                   AND p.post_type = 'attachment'
                   AND p.post_status = 'inherit'",
                $termId,
                FolderTaxonomy::TAXONOMY
            )
        );
    }

    /**
     * Returns a flat list of all descendant term IDs for a given folder.
     *
     * @param  int   $termId
     * @return int[]
     */
    private function collectDescendantIds(int $termId): array {
        $ids      = [];
        $children = $this->getChildren($termId);

        foreach ($children as $child) {
            $childId = (int) $child['id'];
            $ids[]   = $childId;
            $ids     = array_merge($ids, $this->collectDescendantIds($childId));
        }

        return $ids;
    }

    /**
     * Recursively deletes all descendant folders depth-first, moving their
     * attachments to Uncategorized as each folder is removed.
     *
     * @param  int $termId  Parent folder whose descendants should be deleted.
     */
    private function deleteDescendants(int $termId): void {
        $children = $this->getChildren($termId);

        foreach ($children as $child) {
            $childId = (int) $child['id'];
            $this->deleteDescendants($childId);
            $this->moveAttachmentsToUncategorized($childId);
            wp_delete_term($childId, FolderTaxonomy::TAXONOMY);
        }
    }

    /**
     * Moves all attachments currently in a folder to Uncategorized by removing
     * their mdpai_folder taxonomy assignment.
     *
     * @param  int $termId  Source folder.
     */
    private function moveAttachmentsToUncategorized(int $termId): void {
        // Fetch attachment IDs assigned to this term.
        $attachmentIds = get_objects_in_term($termId, FolderTaxonomy::TAXONOMY);

        if (is_wp_error($attachmentIds) || empty($attachmentIds)) {
            return;
        }

        foreach ($attachmentIds as $attachmentId) {
            wp_set_object_terms((int) $attachmentId, [], FolderTaxonomy::TAXONOMY);
        }
    }

    /**
     * Returns the transient cache key for a user's folder tree.
     *
     * @param  int    $userId  0 = global tree.
     * @return string
     */
    private function treeTransientKey(int $userId): string {
        return 0 === $userId ? 'mdpai_tree_global' : "mdpai_tree_{$userId}";
    }

    /**
     * Deletes the folder tree transient for a user.
     *
     * Always deletes the global transient. When $userId is 0 (a global folder
     * changed), all per-user transients are also purged because per-user trees
     * now include global folders.
     *
     * @param  int $userId  0 = global folder changed; >0 = per-user folder changed.
     */
    private function deleteTreeTransient(int $userId): void {
        global $wpdb;

        if ($userId === 0) {
            // A global folder changed — bust every cached tree so per-user
            // sidebars that include global folders also see the update.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE '_transient_mdpai_tree_%'
                    OR option_name LIKE '_transient_timeout_mdpai_tree_%'"
            );
        } else {
            delete_transient('mdpai_tree_global');
            delete_transient("mdpai_tree_{$userId}");
        }
    }
}
