<?php

declare(strict_types=1);

namespace MediaPilotAI\Media;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Taxonomy\FolderTaxonomy;

/**
 * Data access layer for media/attachment queries.
 *
 * This is the ONLY class that performs database operations for attachment data.
 * No business logic lives here — all decisions belong in MediaService.
 *
 * Folder ID semantics:
 *   $folderId  > 0  → specific folder term
 *   $folderId === 0 → Uncategorized (not in any mdpai_folder term)
 *   $folderId === -1→ All attachments (no folder filter)
 *
 * @package MediaPilotAI\Media
 * @since   1.0.0
 */
class MediaRepository {

    // -------------------------------------------------------------------------
    // Sort map
    // -------------------------------------------------------------------------

    private const SORT_MAP = [
        'name'     => 'post_title',
        'date'     => 'post_date',
        'modified' => 'post_modified',
        'author'   => 'post_author',
        'size'     => 'meta_value_num',
    ];

    // -------------------------------------------------------------------------
    // Public API — Queries
    // -------------------------------------------------------------------------

    /**
     * Returns attachments in a folder as WP_Post objects, with pagination meta.
     *
     * Supported $args keys:
     *   sort      string  One of: name, date, modified, author, size. Default 'date'.
     *   order     string  'asc' or 'desc'. Default 'desc'.
     *   page      int     Page number (1-based). Default 1.
     *   per_page  int     Results per page (1–100). Default 40.
     *   search    string  Title/content search term.
     *   mime_type string  post_mime_type value (e.g. 'image', 'application/pdf').
     *
     * @param  int   $folderId  Folder term ID, 0 = Uncategorized, -1 = all.
     * @param  array<string, mixed> $args  Query modifiers.
     * @return array{ posts: \WP_Post[], total: int, pages: int }
     */
    public function getFilesInFolder(int $folderId, array $args = []): array {
        $sort    = isset($args['sort']) ? (string) $args['sort'] : 'date';
        $order   = strtoupper(isset($args['order']) ? (string) $args['order'] : 'desc');
        $page    = max(1, (int) ($args['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($args['per_page'] ?? 40)));
        $search  = isset($args['search']) ? (string) $args['search'] : '';
        $mime    = isset($args['mime_type']) ? (string) $args['mime_type'] : '';

        $orderby = self::SORT_MAP[$sort] ?? 'post_date';

        $queryArgs = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $perPage,
            'paged'          => $page,
            'orderby'        => $orderby,
            'order'          => in_array($order, ['ASC', 'DESC'], true) ? $order : 'DESC',
        ];

        // Size sort requires meta_key.
        if ('size' === $sort) {
            $queryArgs['meta_key'] = '_wp_attachment_filesize'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        }

        // Search term.
        if ('' !== $search) {
            $queryArgs['s'] = $search;
        }

        // MIME type filter.
        if ('' !== $mime) {
            $queryArgs['post_mime_type'] = $mime;
        }

        // Folder tax_query.
        if ($folderId > 0) {
            // Specific folder — attachments IN this term.
            $queryArgs['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                [
                    'taxonomy' => FolderTaxonomy::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => [$folderId],
                    'operator' => 'IN',
                ],
            ];
        } elseif ($folderId === 0) {
            // Uncategorized — attachments NOT in any mdpai_folder term.
            $queryArgs['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                [
                    'taxonomy' => FolderTaxonomy::TAXONOMY,
                    'field'    => 'term_id',
                    'operator' => 'NOT EXISTS',
                ],
            ];
        }
        // folderId === -1: no tax_query, return all attachments.

        $query = new \WP_Query($queryArgs);

        /** @var \WP_Post[] $posts */
        $posts = array_filter(
            $query->posts,
            static fn($p): bool => $p instanceof \WP_Post
        );

        return [
            'posts' => array_values($posts),
            'total' => (int) $query->found_posts,
            'pages' => (int) $query->max_num_pages,
        ];
    }

    /**
     * Returns the mdpai_folder term ID an attachment currently belongs to.
     * Returns 0 if unassigned (Uncategorized).
     *
     * @param  int $attachmentId  WordPress attachment post ID.
     * @return int  Term ID, or 0.
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

    /**
     * Assigns an attachment to a folder term, replacing any existing assignment.
     *
     * Pass $folderId = 0 to remove from all folders (move to Uncategorized).
     *
     * @param  int $attachmentId
     * @param  int $folderId  0 = Uncategorized; > 0 = specific folder.
     * @return bool  True on success, false on WP_Error.
     */
    public function assignToFolder(int $attachmentId, int $folderId): bool {
        if ($folderId === 0) {
            // Clear all mdpai_folder term assignments.
            $result = wp_set_object_terms($attachmentId, [], FolderTaxonomy::TAXONOMY);
        } else {
            // Set to exactly one folder — replaces any previous assignment.
            $result = wp_set_object_terms($attachmentId, [$folderId], FolderTaxonomy::TAXONOMY);
        }

        return !is_wp_error($result);
    }

    /**
     * Returns a normalized attachment data array suitable for REST/JS consumption.
     *
     * Keys returned:
     *   id, title, url, thumbnail_url, mime_type, file_size, date, alt,
     *   folder_id, width, height, filename
     *
     * @param  \WP_Post $post  Attachment post object.
     * @return array<string, mixed>
     */
    public function formatAttachment(\WP_Post $post): array {
        $id       = (int) $post->ID;
        $filePath = get_attached_file($id);

        // File size — 0 if the file is missing.
        $fileSize = 0;
        if (is_string($filePath) && file_exists($filePath)) {
            $fileSize = (int) filesize($filePath);
        }

        // Thumbnail — fall back to full URL for non-image files.
        $thumbnailUrl = wp_get_attachment_image_url($id, 'thumbnail');
        if (false === $thumbnailUrl || '' === $thumbnailUrl) {
            $thumbnailUrl = (string) wp_get_attachment_url($id);
        }

        // Width + height from attachment metadata.
        $meta   = wp_get_attachment_metadata($id);
        $width  = 0;
        $height = 0;
        if (is_array($meta)) {
            $width  = isset($meta['width'])  ? (int) $meta['width']  : 0;
            $height = isset($meta['height']) ? (int) $meta['height'] : 0;
        }

        return [
            'id'            => $id,
            'title'         => (string) $post->post_title,
            'url'           => (string) wp_get_attachment_url($id),
            'thumbnail_url' => $thumbnailUrl,
            'mime_type'     => (string) $post->post_mime_type,
            'file_size'     => $fileSize,
            'date'          => (string) $post->post_date,
            'alt'           => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
            'caption'       => (string) $post->post_excerpt,
            'description'   => (string) $post->post_content,
            'folder_id'     => $this->getFileFolder($id),
            'width'         => $width,
            'height'        => $height,
            'filename'      => is_string($filePath) ? basename($filePath) : '',
        ];
    }

    /**
     * Returns all attachment IDs currently assigned to a folder.
     *
     * @param  int   $folderId  Folder term ID (must be > 0).
     * @return int[]
     */
    public function getAttachmentIds(int $folderId): array {
        if ($folderId <= 0) {
            return [];
        }

        $ids = get_objects_in_term($folderId, FolderTaxonomy::TAXONOMY);

        if (is_wp_error($ids) || empty($ids)) {
            return [];
        }

        return array_map('intval', (array) $ids);
    }
}
