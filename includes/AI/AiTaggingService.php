<?php

declare(strict_types=1);

namespace MediaPilotAI\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Tags\TagRepository;
use MediaPilotAI\Folder\FolderRepository;
use MediaPilotAI\Taxonomy\FolderTaxonomy;

/**
 * AI Auto-Tagging & Organisation Service (S47).
 *
 * Orchestrates AI image analysis, MediaPilot tag creation, and optional
 * folder assignment for newly uploaded or manually re-tagged images.
 *
 * Flow on image upload:
 *  1. `onUpload()` is triggered at priority 20 on `add_attachment`.
 *  2. A WP Cron single event `mdpai_ai_tag_attachment` is scheduled so the
 *     upload HTTP response is not delayed by the API call.
 *  3. `analyzeAttachment()` sends the image to the configured provider,
 *     creates/finds MediaPilot tags for every label ≥ confidence_threshold, and
 *     optionally auto-assigns the file to the best-matching folder.
 *
 * Attachment meta keys:
 *   _mdpai_ai_tags            array  Stored label results (label, confidence, tag_id).
 *   _mdpai_ai_folder_suggest  array  Best folder suggestion (folder_id, folder_name, confidence).
 *   _mdpai_ai_tagged_at       string ISO 8601 timestamp of last analysis.
 *   _mdpai_ai_provider        string Provider used ('aws' | 'google').
 *   _mdpai_ai_error           string Last error message; empty string on success.
 *
 * @package MediaPilotAI\AI
 * @since   1.0.0
 */
class AiTaggingService {

    private const META_AI_TAGS    = '_mdpai_ai_tags';
    private const META_AI_SUGGEST = '_mdpai_ai_folder_suggest';
    private const META_AI_AT      = '_mdpai_ai_tagged_at';
    private const META_AI_PROV    = '_mdpai_ai_provider';
    private const META_AI_ERROR   = '_mdpai_ai_error';

    /** Default hex colour for AI-generated tags — indigo distinguishes them from user tags. */
    private const AI_TAG_COLOR = '#6366f1';

    public function __construct(
        private readonly TagRepository    $tagRepo,
        private readonly FolderRepository $folderRepo,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function register(): void {
        // Hook after UploadHandler::autoAssignFolder() (priority 10).
        add_action( 'add_attachment', [ $this, 'onUpload' ], 20 );

        // WP Cron handler: called when the scheduled event fires.
        add_action( 'mdpai_ai_tag_attachment', [ $this, 'analyzeAttachment' ] );
    }

    // -------------------------------------------------------------------------
    // Upload hook
    // -------------------------------------------------------------------------

    /**
     * Schedule async AI analysis for newly uploaded images.
     *
     * Only image/* MIME types are eligible. Non-image uploads are skipped silently.
     */
    public function onUpload( int $attachmentId ): void {
        $settings = $this->getSettings();

        if ( ( $settings['provider'] ?? 'none' ) === 'none' ) {
            return;
        }

        $post = get_post( $attachmentId );
        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        if ( ! str_starts_with( (string) $post->post_mime_type, 'image/' ) ) {
            return;
        }

        // Schedule for immediate execution (next cron tick).
        wp_schedule_single_event( time(), 'mdpai_ai_tag_attachment', [ $attachmentId ] );
    }

    // -------------------------------------------------------------------------
    // Analysis
    // -------------------------------------------------------------------------

    /**
     * Send an attachment to the configured AI provider, persist the results,
     * create MediaPilot tags, and optionally assign the file to the best matching folder.
     *
     * Also called directly by the REST `/ai-retag` route and the WP-CLI command.
     *
     * @param  int $attachmentId
     * @return array{tags: array<int, array<string, mixed>>, suggestion: array<string, mixed>|null}
     * @throws \RuntimeException  When no provider is configured or the API call fails.
     */
    public function analyzeAttachment( int $attachmentId ): array {
        $settings = $this->getSettings();
        $adapter  = $this->buildAdapter( $settings );

        if ( $adapter === null || ! $adapter->isConfigured() ) {
            throw new \RuntimeException( 'No AI provider is configured.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $imageUrl = (string) wp_get_attachment_url( $attachmentId );
        if ( '' === $imageUrl ) {
            throw new \RuntimeException( "Cannot resolve URL for attachment {$attachmentId}." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        // --- Call AI provider ---
        try {
            $rawLabels = $adapter->analyzeImage( $imageUrl, $attachmentId );
            delete_post_meta( $attachmentId, self::META_AI_ERROR );
        } catch ( \RuntimeException $e ) {
            update_post_meta( $attachmentId, self::META_AI_ERROR, $e->getMessage() );
            throw $e;
        }

        $threshold    = (float) ( $settings['confidence_threshold'] ?? 70.0 );
        $aboveThresh  = array_filter(
            $rawLabels,
            static fn( array $l ) => $l['confidence'] >= $threshold
        );

        // --- Create / find tags ---
        $storedTags = [];
        foreach ( $aboveThresh as $item ) {
            $label = sanitize_text_field( $item['label'] );
            if ( '' === $label ) {
                continue;
            }

            $baseSlug = sanitize_title( $label );
            $existing = $this->tagRepo->findBySlug( $baseSlug );
            $tagId    = $existing
                ? (int) $existing['id']
                : $this->tagRepo->create( $label, $this->tagRepo->generateUniqueSlug( $label ), self::AI_TAG_COLOR );

            $storedTags[] = [
                'label'      => $label,
                'confidence' => round( $item['confidence'], 2 ),
                'tag_id'     => $tagId,
            ];
        }

        // Add tags without removing any the user may have set manually.
        if ( ! empty( $storedTags ) ) {
            $this->tagRepo->addTagsForAttachment( $attachmentId, array_column( $storedTags, 'tag_id' ) );
        }

        // --- Persist AI meta ---
        update_post_meta( $attachmentId, self::META_AI_TAGS, $storedTags );
        update_post_meta( $attachmentId, self::META_AI_AT,   gmdate( 'c' ) );
        update_post_meta( $attachmentId, self::META_AI_PROV, $settings['provider'] ?? '' );

        // --- Folder suggestion ---
        $suggestion = $this->suggestFolder( $rawLabels );

        if ( $suggestion !== null ) {
            update_post_meta( $attachmentId, self::META_AI_SUGGEST, $suggestion );

            $autoAssign  = (bool)  ( $settings['auto_assign']           ?? false );
            $autoThresh  = (float) ( $settings['auto_assign_threshold']  ?? 85.0 );
            $wasAssigned = false;

            if ( $autoAssign && $suggestion['confidence'] >= $autoThresh ) {
                wp_set_object_terms( $attachmentId, [ (int) $suggestion['folder_id'] ], FolderTaxonomy::TAXONOMY );
                $wasAssigned = true;
            }

            /**
             * Fires after AI generates a folder suggestion for an attachment.
             *
             * Third-party code can show a UI toast or take custom action here.
             *
             * @param int                  $attachmentId
             * @param array<string, mixed> $suggestion   {folder_id, folder_name, confidence}
             * @param bool                 $wasAssigned  True when file was auto-assigned.
             */
            do_action( 'mdpai_ai_folder_suggestion', $attachmentId, $suggestion, $wasAssigned );
        } else {
            delete_post_meta( $attachmentId, self::META_AI_SUGGEST );
        }

        return [
            'tags'       => $storedTags,
            'suggestion' => $suggestion,
        ];
    }

    // -------------------------------------------------------------------------
    // Meta accessors
    // -------------------------------------------------------------------------

    /**
     * Return stored AI tags for the given attachment.
     *
     * @return array<int, array{label: string, confidence: float, tag_id: int}>
     */
    public function getAiTags( int $attachmentId ): array {
        $raw = get_post_meta( $attachmentId, self::META_AI_TAGS, true );
        return is_array( $raw ) ? $raw : [];
    }

    /**
     * Return the full AI meta payload used by the REST GET /ai-tags route.
     *
     * @return array{
     *   tags: array<int, array<string, mixed>>,
     *   tagged_at: string,
     *   provider: string,
     *   error: string,
     *   suggestion: array<string, mixed>|null,
     * }
     */
    public function getAiTagsForApi( int $attachmentId ): array {
        return [
            'tags'       => $this->getAiTags( $attachmentId ),
            'tagged_at'  => (string) get_post_meta( $attachmentId, self::META_AI_AT,    true ),
            'provider'   => (string) get_post_meta( $attachmentId, self::META_AI_PROV,  true ),
            'error'      => (string) get_post_meta( $attachmentId, self::META_AI_ERROR, true ),
            'suggestion' => $this->getAiFolderSuggestion( $attachmentId ),
        ];
    }

    /** @return array<string, mixed>|null */
    public function getAiFolderSuggestion( int $attachmentId ): ?array {
        $raw = get_post_meta( $attachmentId, self::META_AI_SUGGEST, true );
        return is_array( $raw ) && ! empty( $raw ) ? $raw : null;
    }

    // -------------------------------------------------------------------------
    // Batch tagging (used by WP-CLI)
    // -------------------------------------------------------------------------

    /**
     * Run AI tagging across all image attachments, optionally scoped to a folder.
     *
     * @param  int $folderId  0 = all; positive = restrict to this folder's images.
     * @param  int $limit     Maximum number of images to process; 0 = no limit.
     * @return array{processed: int, tagged: int, errors: int}
     */
    public function tagAll( int $folderId = 0, int $limit = 0 ): array {
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => $limit > 0 ? $limit : -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ];

        if ( $folderId > 0 ) {
            $args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                [
                    'taxonomy' => FolderTaxonomy::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => [ $folderId ],
                ],
            ];
        }

        $ids = get_posts( $args );

        $processed = 0;
        $tagged    = 0;
        $errors    = 0;

        foreach ( $ids as $id ) {
            $processed++;
            try {
                $this->analyzeAttachment( (int) $id );
                $tagged++;
            } catch ( \RuntimeException ) {
                $errors++;
            }
        }

        return compact( 'processed', 'tagged', 'errors' );
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function getSettings(): array {
        $saved = get_option( AiSettingsPage::OPTION_NAME, [] );
        return array_merge( AiSettingsPage::defaults(), is_array( $saved ) ? $saved : [] );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $settings */
    private function buildAdapter( array $settings ): ?AiTaggingAdapter {
        return match ( $settings['provider'] ?? 'none' ) {
            'aws'    => new AwsRekognitionAdapter( $settings ),
            'google' => new GoogleVisionAdapter( $settings ),
            default  => null,
        };
    }

    /**
     * Find the existing folder whose name best matches any of the AI labels.
     *
     * Scoring: case-insensitive substring match weighted by label confidence.
     * Returns null when no folder name overlaps with any label.
     *
     * @param  array<int, array{label: string, confidence: float}> $labels
     * @return array{folder_id: int, folder_name: string, confidence: float}|null
     */
    private function suggestFolder( array $labels ): ?array {
        if ( empty( $labels ) ) {
            return null;
        }

        $flatFolders    = $this->flattenTree( $this->folderRepo->getTree( 0 ) );
        $best           = null;
        $bestConfidence = 0.0;

        foreach ( $labels as $item ) {
            $labelLc = strtolower( trim( $item['label'] ) );
            if ( '' === $labelLc ) {
                continue;
            }

            foreach ( $flatFolders as $folder ) {
                $folderLc = strtolower( trim( (string) $folder['name'] ) );
                if ( '' === $folderLc ) {
                    continue;
                }

                if ( str_contains( $folderLc, $labelLc ) || str_contains( $labelLc, $folderLc ) ) {
                    if ( $item['confidence'] > $bestConfidence ) {
                        $bestConfidence = $item['confidence'];
                        $best           = [
                            'folder_id'   => (int)    $folder['id'],
                            'folder_name' => (string) $folder['name'],
                            'confidence'  => round( $item['confidence'], 2 ),
                        ];
                    }
                }
            }
        }

        return $best;
    }

    /**
     * Recursively flatten the folder tree into a plain list.
     *
     * @param  array<int, array<string, mixed>> $nodes
     * @return array<int, array<string, mixed>>
     */
    private function flattenTree( array $nodes ): array {
        $flat = [];
        foreach ( $nodes as $node ) {
            $flat[] = $node;
            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                array_push( $flat, ...$this->flattenTree( $node['children'] ) );
            }
        }
        return $flat;
    }
}
