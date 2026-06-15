<?php

declare(strict_types=1);

namespace MediaPilotAI\Migration;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderRepository;
use MediaPilotAI\Taxonomy\FolderTaxonomy;

/**
 * Imports folders from the Wicked Folders plugin.
 *
 * Wicked Folders stores media folders in the `wf_media_folder` taxonomy.
 * Terms have a standard WP parent relationship; file assignments use
 * wp_term_relationships.
 *
 * @package MediaPilotAI\Migration
 * @since   1.0.0
 */
class WickedFoldersImporter implements PluginImporterInterface {

    private const SOURCE_TAXONOMY = 'wf_media_folder';
    private const SLUG            = 'wicked';

    public function __construct(
        private readonly FolderRepository $folderRepository,
    ) {}

    public function getLabel(): string {
        return 'Wicked Folders';
    }

    public function getSourceTaxonomy(): string {
        return self::SOURCE_TAXONOMY;
    }

    public function isAvailable(): bool {
        return taxonomy_exists( self::SOURCE_TAXONOMY );
    }

    public function runBatch( ImportProgress $progress, int $batchSize = 50 ): bool {
        if ( 0 === $progress->offset && 0 === $progress->created + $progress->skipped ) {
            $this->importTerms( $progress );
        }

        return $this->importAssignmentsBatch( $progress, $batchSize );
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    private function importTerms( ImportProgress $progress ): void {
        $terms = get_terms( [
            'taxonomy'   => self::SOURCE_TAXONOMY,
            'hide_empty' => false,
            'number'     => 0,
            'orderby'    => 'parent',
            'order'      => 'ASC',
        ] );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            $progress->total = 0;
            return;
        }

        usort( $terms, static fn( \WP_Term $a, \WP_Term $b ) => $a->parent <=> $b->parent );

        $idMap = [];

        foreach ( $terms as $term ) {
            $oldId     = (int) $term->term_id;
            $parentId  = (int) $term->parent;
            $newParent = $idMap[ $parentId ] ?? 0;

            try {
                $newId           = $this->folderRepository->create( $term->name, $newParent, 0 );
                $idMap[ $oldId ] = $newId;
                $progress->created++;
            } catch ( \RuntimeException ) {
                $existing = get_term_by( 'name', $term->name, FolderTaxonomy::TAXONOMY );
                if ( $existing instanceof \WP_Term ) {
                    $idMap[ $oldId ] = (int) $existing->term_id;
                    $progress->skipped++;
                } else {
                    $progress->errors++;
                    $progress->messages[] = "Could not create folder \"{$term->name}\".";
                }
            }

            $progress->processed++;
        }

        $this->saveIdMap( $idMap );

        $progress->total = $progress->processed + $this->countAssignedAttachments();
    }

    private function importAssignmentsBatch( ImportProgress $progress, int $batchSize ): bool {
        $idMap = $this->loadIdMap();

        $attachments = get_posts( [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $batchSize,
            'offset'         => $progress->offset,
            'fields'         => 'ids',
            'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                [
                    'taxonomy' => self::SOURCE_TAXONOMY,
                    'operator' => 'EXISTS',
                ],
            ],
        ] );

        if ( empty( $attachments ) ) {
            return true;
        }

        foreach ( $attachments as $attachmentId ) {
            $sourceTerms = wp_get_object_terms( (int) $attachmentId, self::SOURCE_TAXONOMY, [ 'fields' => 'ids' ] );

            if ( is_wp_error( $sourceTerms ) || empty( $sourceTerms ) ) {
                $progress->skipped++;
                continue;
            }

            $targetTermId = $idMap[ (int) $sourceTerms[0] ] ?? 0;

            if ( $targetTermId > 0 ) {
                $this->folderRepository->assignFile( (int) $attachmentId, $targetTermId );
                $progress->created++;
            } else {
                $progress->skipped++;
            }

            $progress->processed++;
        }

        $progress->offset += $batchSize;

        $more = get_posts( [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'offset'         => $progress->offset,
            'fields'         => 'ids',
            'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                [
                    'taxonomy' => self::SOURCE_TAXONOMY,
                    'operator' => 'EXISTS',
                ],
            ],
        ] );

        return empty( $more );
    }

    private function countAssignedAttachments(): int {
        return count( get_posts( [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                [
                    'taxonomy' => self::SOURCE_TAXONOMY,
                    'operator' => 'EXISTS',
                ],
            ],
        ] ) );
    }

    /** @param array<int,int> $map */
    private function saveIdMap( array $map ): void {
        update_option( 'mdpai_migration_idmap_' . self::SLUG, $map, false );
    }

    /** @return array<int,int> */
    private function loadIdMap(): array {
        return (array) get_option( 'mdpai_migration_idmap_' . self::SLUG, [] );
    }
}
