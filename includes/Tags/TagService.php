<?php

declare(strict_types=1);

namespace MediaPilotAI\Tags;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Business-logic layer for the Smart Tags system.
 *
 * Responsibilities:
 *  - Hook into AJAX and WP_Query-based media library requests to filter
 *    attachments by one or more tag IDs.
 *  - Evaluate Smart Folder rules stored as term-meta (`mdpai_smart_rules`)
 *    on folder taxonomy terms.
 */
class TagService {

    public function __construct(
        private readonly TagRepository $tagRepo,
    ) {}

    /**
     * Register all WordPress hooks. Called once from Plugin::registerServices().
     */
    public function register(): void {
        // AJAX media-library grid (Backbone/wp.media)
        add_filter( 'ajax_query_attachments_args', [ $this, 'filterByTagsAjax' ] );

        // WP_Query on the list-table view
        add_action( 'pre_get_posts', [ $this, 'filterByTagsQuery' ] );
    }

    // -------------------------------------------------------------------------
    // Tag-filter hooks
    // -------------------------------------------------------------------------

    /**
     * Narrow down the AJAX backbone query when `mdpai_tags[]` is in the request.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function filterByTagsAjax( array $query ): array {
        $tagIds = $this->getTagIdsFromRequest();

        if ( empty( $tagIds ) ) {
            return $query;
        }

        $mode = $this->getModeFromRequest();
        $ids  = $this->tagRepo->getAttachmentIdsByTags( $tagIds, $mode );

        if ( empty( $ids ) ) {
            $query['post__in'] = [ 0 ]; // force empty result
        } elseif ( ! empty( $query['post__in'] ) ) {
            $ids               = array_values( array_intersect( $query['post__in'], $ids ) );
            $query['post__in'] = empty( $ids ) ? [ 0 ] : $ids;
        } else {
            $query['post__in'] = $ids;
        }

        return $query;
    }

    /**
     * Narrow down the main WP_Query on media-library list screens.
     */
    public function filterByTagsQuery( \WP_Query $query ): void {
        if ( ! $query->is_main_query() || ! is_admin() ) {
            return;
        }

        $tagIds = $this->getTagIdsFromRequest( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( empty( $tagIds ) ) {
            return;
        }

        $mode = $this->getModeFromRequest( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $ids  = $this->tagRepo->getAttachmentIdsByTags( $tagIds, $mode );

        if ( empty( $ids ) ) {
            $query->set( 'post__in', [ 0 ] );
        } else {
            $existing = (array) $query->get( 'post__in' );

            if ( ! empty( $existing ) ) {
                $ids = array_values( array_intersect( $existing, $ids ) );
                $query->set( 'post__in', empty( $ids ) ? [ 0 ] : $ids );
            } else {
                $query->set( 'post__in', $ids );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Smart Folder evaluation
    // -------------------------------------------------------------------------

    /**
     * Return attachment IDs that match the smart-folder rules stored on `$folderId`.
     * Returns [] if no rules are set.
     *
     * @return int[]
     */
    public function evaluateSmartFolder( int $folderId ): array {
        $rulesJson = get_term_meta( $folderId, 'mdpai_smart_rules', true );

        if ( empty( $rulesJson ) ) {
            return [];
        }

        $rules = json_decode( (string) $rulesJson, true );

        if ( ! is_array( $rules ) || empty( $rules['conditions'] ) ) {
            return [];
        }

        $mode   = ( $rules['mode'] ?? '' ) === 'OR' ? 'OR' : 'AND';
        $tagIds = [];

        foreach ( $rules['conditions'] as $cond ) {
            if ( isset( $cond['type'], $cond['tag_id'] ) && $cond['type'] === 'tag' ) {
                $tagIds[] = (int) $cond['tag_id'];
            }
        }

        // Base set from tag conditions
        $attachmentIds = ! empty( $tagIds )
            ? $this->tagRepo->getAttachmentIdsByTags( $tagIds, $mode )
            : null;

        // Refine by non-tag conditions (mime, date range)
        return $this->applyNonTagConditions( $rules['conditions'], $attachmentIds );
    }

    /**
     * Whether a folder term has smart rules stored.
     */
    public function isSmart( int $folderId ): bool {
        $raw = get_term_meta( $folderId, 'mdpai_smart_rules', true );

        if ( empty( $raw ) ) {
            return false;
        }

        $rules = json_decode( (string) $raw, true );

        return is_array( $rules ) && ! empty( $rules['conditions'] );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Run a WP_Query to filter by mime-type and/or date conditions.
     *
     * @param array<int, array<string, mixed>> $conditions
     * @param int[]|null                       $ids  Pre-filtered set; null = no restriction.
     * @return int[]
     */
    private function applyNonTagConditions( array $conditions, ?array $ids ): array {
        $hasMime = false;
        $hasDate = false;
        $args    = [
            'post_type'      => 'attachment',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ];

        if ( $ids !== null ) {
            $args['post__in'] = empty( $ids ) ? [ 0 ] : $ids;
        }

        foreach ( $conditions as $cond ) {
            $type = $cond['type'] ?? '';

            switch ( $type ) {
                case 'mime':
                    if ( ! empty( $cond['mime'] ) ) {
                        $args['post_mime_type'] = sanitize_text_field( $cond['mime'] );
                        $hasMime               = true;
                    }
                    break;

                case 'date_after':
                    if ( ! empty( $cond['date'] ) ) {
                        $args['date_query'][] = [ 'after' => $cond['date'], 'inclusive' => true ];
                        $hasDate              = true;
                    }
                    break;

                case 'date_before':
                    if ( ! empty( $cond['date'] ) ) {
                        $args['date_query'][] = [ 'before' => $cond['date'], 'inclusive' => true ];
                        $hasDate              = true;
                    }
                    break;
            }
        }

        if ( ! $hasMime && ! $hasDate ) {
            return $ids ?? [];
        }

        $posts = get_posts( $args );

        return array_map( 'intval', $posts );
    }

    /**
     * Extract sanitised tag ID array from a superglobal array.
     *
     * @param array<string, mixed> $source  Defaults to $_REQUEST.
     * @return int[]
     */
    private function getTagIdsFromRequest( ?array $source = null ): array {
        $source = $source ?? $_REQUEST; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $raw    = $source['mdpai_tags'] ?? [];

        if ( ! is_array( $raw ) ) {
            return [];
        }

        return array_values( array_filter( array_map( 'intval', $raw ) ) );
    }

    /**
     * @param array<string, mixed>|null $source
     */
    private function getModeFromRequest( ?array $source = null ): string {
        $source = $source ?? $_REQUEST; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        return isset( $source['mdpai_tag_mode'] ) && $source['mdpai_tag_mode'] === 'AND' ? 'AND' : 'OR';
    }
}
