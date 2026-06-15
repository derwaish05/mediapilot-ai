<?php

declare(strict_types=1);

namespace MediaPilotAI\Tags;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Data-access layer for smart tags and tag-to-attachment relationships.
 *
 * Tables used:
 *   wp_mdpai_tags              — tag definitions (id, name, slug, color)
 *   wp_mdpai_tag_relationships — many-to-many pivot (tag_id, attachment_id)
 */
class TagRepository {

    private \wpdb  $db;
    private string $tagsTable;
    private string $relTable;

    public function __construct() {
        global $wpdb;
        $this->db        = $wpdb;
        $this->tagsTable = $wpdb->prefix . 'mdpai_tags';
        $this->relTable  = $wpdb->prefix . 'mdpai_tag_relationships';
    }

    // -------------------------------------------------------------------------
    // Tag CRUD
    // -------------------------------------------------------------------------

    /**
     * Return all tags.
     *
     * @param bool $withCount Include `usage_count` (number of attached files).
     * @return array<int, array<string, mixed>>
     */
    public function getAll( bool $withCount = false ): array {
        if ( $withCount ) {
            $sql = "SELECT t.*, COUNT(r.attachment_id) AS usage_count
                    FROM {$this->tagsTable} t
                    LEFT JOIN {$this->relTable} r ON r.tag_id = t.id
                    GROUP BY t.id
                    ORDER BY t.name ASC";
        } else {
            $sql = "SELECT * FROM {$this->tagsTable} ORDER BY name ASC";
        }

        return $this->db->get_results( $sql, ARRAY_A ) ?: [];
    }

    /** @return array<string, mixed>|null */
    public function findById( int $id ): ?array {
        return $this->db->get_row(
            $this->db->prepare( "SELECT * FROM {$this->tagsTable} WHERE id = %d", $id ),
            ARRAY_A
        ) ?: null;
    }

    /** @return array<string, mixed>|null */
    public function findBySlug( string $slug ): ?array {
        return $this->db->get_row(
            $this->db->prepare( "SELECT * FROM {$this->tagsTable} WHERE slug = %s", $slug ),
            ARRAY_A
        ) ?: null;
    }

    public function create( string $name, string $slug, string $color ): int {
        /**
         * Fires before a new MediaPilot tag is created.
         *
         * @param string $name  Tag name.
         * @param string $slug  Tag slug.
         * @param string $color Tag hex colour.
         */
        do_action( 'mdpai_before_tag_create', $name, $slug, $color );

        $this->db->insert(
            $this->tagsTable,
            [
                'name'       => $name,
                'slug'       => $slug,
                'color'      => $color,
                'created_by' => get_current_user_id(),
            ],
            [ '%s', '%s', '%s', '%d' ]
        );

        $newId = (int) $this->db->insert_id;

        /**
         * Fires after a new MediaPilot tag has been created.
         *
         * @param int    $tagId Tag ID of the newly created tag.
         * @param string $name  Tag name.
         * @param string $slug  Tag slug.
         * @param string $color Tag hex colour.
         */
        do_action( 'mdpai_after_tag_create', $newId, $name, $slug, $color );

        return $newId;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update( int $id, array $data ): bool {
        $formats = array_map(
            static fn( string $k ) => match ( $k ) {
                'created_by' => '%d',
                default      => '%s',
            },
            array_keys( $data )
        );

        return (bool) $this->db->update(
            $this->tagsTable,
            $data,
            [ 'id' => $id ],
            $formats,
            [ '%d' ]
        );
    }

    /** Delete tag + all its relationships. */
    public function delete( int $id ): bool {
        $this->db->delete( $this->relTable, [ 'tag_id' => $id ], [ '%d' ] );

        return (bool) $this->db->delete( $this->tagsTable, [ 'id' => $id ], [ '%d' ] );
    }

    // -------------------------------------------------------------------------
    // Tag ↔ Attachment relationships
    // -------------------------------------------------------------------------

    /**
     * Get all tags assigned to a given attachment.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getForAttachment( int $attachmentId ): array {
        return $this->db->get_results(
            $this->db->prepare(
                "SELECT t.*
                 FROM {$this->tagsTable} t
                 INNER JOIN {$this->relTable} r ON r.tag_id = t.id
                 WHERE r.attachment_id = %d
                 ORDER BY t.name ASC",
                $attachmentId
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Return attachment IDs that have at least one (OR) or all (AND) of the
     * given tag IDs.
     *
     * @param int[]  $tagIds
     * @param string $mode   'OR' | 'AND'
     * @return int[]
     */
    public function getAttachmentIdsByTags( array $tagIds, string $mode = 'OR' ): array {
        if ( empty( $tagIds ) ) {
            return [];
        }

        $placeholders = implode( ',', array_fill( 0, count( $tagIds ), '%d' ) );

        if ( $mode === 'AND' ) {
            $sql = $this->db->prepare(
                "SELECT attachment_id
                 FROM {$this->relTable}
                 WHERE tag_id IN ({$placeholders})
                 GROUP BY attachment_id
                 HAVING COUNT(DISTINCT tag_id) = %d",
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                ...[ ...$tagIds, count( $tagIds ) ]
            );
        } else {
            $sql = $this->db->prepare(
                "SELECT DISTINCT attachment_id
                 FROM {$this->relTable}
                 WHERE tag_id IN ({$placeholders})",
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                ...$tagIds
            );
        }

        return array_map( 'intval', $this->db->get_col( $sql ) );
    }

    /**
     * Replace all tags on an attachment with the given set.
     *
     * @param int[] $tagIds
     */
    public function setTagsForAttachment( int $attachmentId, array $tagIds ): void {
        $this->db->delete( $this->relTable, [ 'attachment_id' => $attachmentId ], [ '%d' ] );

        foreach ( $tagIds as $tagId ) {
            $this->db->replace(
                $this->relTable,
                [ 'tag_id' => (int) $tagId, 'attachment_id' => $attachmentId ],
                [ '%d', '%d' ]
            );
        }
    }

    /**
     * Add tags to an attachment without removing existing ones.
     *
     * @param int[] $tagIds
     */
    public function addTagsForAttachment( int $attachmentId, array $tagIds ): void {
        foreach ( $tagIds as $tagId ) {
            $this->db->replace(
                $this->relTable,
                [ 'tag_id' => (int) $tagId, 'attachment_id' => $attachmentId ],
                [ '%d', '%d' ]
            );
        }
    }

    /** Remove a single tag from an attachment. */
    public function removeTagFromAttachment( int $attachmentId, int $tagId ): bool {
        return (bool) $this->db->delete(
            $this->relTable,
            [ 'tag_id' => $tagId, 'attachment_id' => $attachmentId ],
            [ '%d', '%d' ]
        );
    }

    // -------------------------------------------------------------------------
    // Slug helper
    // -------------------------------------------------------------------------

    /** Generate a slug that does not collide with existing tags. */
    public function generateUniqueSlug( string $name ): string {
        $base = sanitize_title( $name );
        $slug = $base;
        $i    = 1;

        while ( $this->findBySlug( $slug ) !== null ) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
