<?php

declare(strict_types=1);

namespace MediaPilotAI\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Search\AdvancedSearchService;

/**
 * AI Smart Search Service (S48).
 *
 * Extends the Advanced Search with semantic (synonym-aware) retrieval powered
 * by the AI label index built by AiTaggingService (S47).
 *
 * Search flow when `ai=true` is requested:
 *  1. Expand the query term into a synonym group (e.g. "car" → car, vehicle,
 *     automobile, auto, transport).
 *  2. Query wp_mdpai_tags by name (LIKE substring) for any expanded term.
 *  3. Resolve matching attachment IDs from wp_mdpai_tag_relationships.
 *  4. If AI matches are found: run AdvancedSearchService with post__in
 *     restricted to those IDs (all other filters still apply).
 *  5. Fallback: if no AI labels match, run a standard text search via
 *     AdvancedSearchService with the original query.
 *
 * Synonym groups can be extended by third-party plugins via the
 * `mdpai_ai_search_synonyms` filter, which receives and must return
 * the full array<int, string[]> groups list.
 *
 * @package MediaPilotAI\AI
 * @since   1.0.0
 */
class SmartSearchService {

    /**
     * Built-in synonym groups.
     * Each inner array is a group: every term in the group is considered
     * equivalent for search purposes.
     *
     * @var array<int, string[]>
     */
    private const SYNONYMS = [
        [ 'car', 'vehicle', 'automobile', 'auto', 'transport' ],
        [ 'dog', 'puppy', 'canine', 'pet', 'animal' ],
        [ 'cat', 'kitten', 'feline', 'pet', 'animal' ],
        [ 'person', 'people', 'human', 'man', 'woman', 'face', 'portrait' ],
        [ 'food', 'meal', 'dish', 'cuisine', 'cooking', 'drink' ],
        [ 'nature', 'outdoor', 'outdoors', 'landscape', 'scenery', 'environment' ],
        [ 'building', 'architecture', 'structure', 'house', 'office', 'home' ],
        [ 'sky', 'cloud', 'clouds', 'weather' ],
        [ 'logo', 'brand', 'icon', 'symbol', 'graphic', 'design', 'badge' ],
        [ 'text', 'writing', 'typography', 'font', 'sign', 'label' ],
        [ 'flower', 'plant', 'floral', 'bloom', 'garden', 'botanical' ],
        [ 'tree', 'forest', 'wood', 'woodland', 'plant' ],
        [ 'water', 'ocean', 'sea', 'lake', 'river', 'pool', 'beach', 'coast' ],
        [ 'mountain', 'hill', 'peak', 'summit', 'landscape', 'terrain' ],
        [ 'city', 'urban', 'street', 'town', 'downtown', 'skyline' ],
        [ 'sport', 'sports', 'fitness', 'exercise', 'athletic', 'activity', 'workout' ],
        [ 'technology', 'tech', 'computer', 'device', 'digital', 'electronic' ],
        [ 'music', 'audio', 'sound', 'instrument', 'song', 'concert' ],
        [ 'art', 'painting', 'illustration', 'drawing', 'creative', 'artwork' ],
        [ 'travel', 'tourism', 'trip', 'vacation', 'holiday', 'journey' ],
        [ 'business', 'office', 'meeting', 'corporate', 'work', 'professional' ],
        [ 'child', 'kid', 'baby', 'infant', 'toddler', 'youth' ],
    ];

    public function __construct(
        private readonly AdvancedSearchService $searchService,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Run an AI-enhanced search against the stored label index.
     *
     * When AI labels are found for the query (or its synonyms), results are
     * restricted to those attachments but all other filter params still apply.
     * Falls back transparently to a standard text search when no labels match.
     *
     * @param  array<string, mixed> $params  Same params accepted by AdvancedSearchService::search().
     * @return array{
     *   files: array<int, array<string, mixed>>,
     *   total: int,
     *   pages: int,
     *   current_page: int,
     *   ai_enhanced: bool,
     *   expanded_terms: string[],
     * }
     */
    public function search( array $params ): array {
        $q = trim( (string) ( $params['q'] ?? '' ) );

        if ( '' === $q ) {
            // No query — just run normal search with remaining params.
            $result               = $this->searchService->search( $params );
            $result['ai_enhanced']    = false;
            $result['expanded_terms'] = [];
            return $result;
        }

        $expandedTerms = $this->expandQuery( $q );
        $aiIds         = $this->findByLabels( $expandedTerms );

        if ( ! empty( $aiIds ) ) {
            // Merge AI-found IDs as a post__in constraint.
            $aiParams          = $params;
            $aiParams['q']     = ''; // skip WP text search; we have exact IDs
            $aiParams['post__in'] = $aiIds;

            $result                   = $this->searchService->search( $aiParams );
            $result['ai_enhanced']    = true;
            $result['expanded_terms'] = $expandedTerms;
            return $result;
        }

        // Fallback: standard text search.
        $result                   = $this->searchService->search( $params );
        $result['ai_enhanced']    = false;
        $result['expanded_terms'] = $expandedTerms;
        return $result;
    }

    /**
     * Expand a single query term into itself plus any synonym-group siblings.
     *
     * Checks the built-in synonym table (extended via `mdpai_ai_search_synonyms`)
     * for a group that contains $q and returns all group members.
     *
     * @param  string $q  Lowercased, trimmed query term.
     * @return string[]   The original term plus any synonyms found.
     */
    public function expandQuery( string $q ): array {
        $q      = strtolower( trim( $q ) );
        $terms  = [ $q ];

        /** @var array<int, string[]> $groups */
        $groups = (array) apply_filters( 'mdpai_ai_search_synonyms', self::SYNONYMS );

        foreach ( $groups as $group ) {
            if ( ! is_array( $group ) ) {
                continue;
            }
            if ( in_array( $q, $group, true ) ) {
                foreach ( $group as $synonym ) {
                    if ( ! in_array( $synonym, $terms, true ) ) {
                        $terms[] = $synonym;
                    }
                }
                break; // only one group can match
            }
        }

        return $terms;
    }

    // -------------------------------------------------------------------------
    // Private — AI label index query
    // -------------------------------------------------------------------------

    /**
     * Find attachment IDs whose MediaPilot tags match any of the given terms
     * (case-insensitive substring match against tag names).
     *
     * Queries the custom tables created in S2:
     *   wp_mdpai_tags              — tag name index (includes AI-generated tags)
     *   wp_mdpai_tag_relationships — many-to-many pivot (tag_id, attachment_id)
     *
     * @param  string[] $terms
     * @return int[]
     */
    private function findByLabels( array $terms ): array {
        global $wpdb;

        if ( empty( $terms ) ) {
            return [];
        }

        $tagsTable = $wpdb->prefix . 'mdpai_tags';
        $relTable  = $wpdb->prefix . 'mdpai_tag_relationships';

        // Build OR clause: one LIKE condition per term.
        $conditions = [];
        foreach ( $terms as $term ) {
            $conditions[] = $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                't.name LIKE %s',
                '%' . $wpdb->esc_like( $term ) . '%'
            );
        }

        $where = implode( ' OR ', $conditions );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            "SELECT DISTINCT r.attachment_id
             FROM {$tagsTable} t
             INNER JOIN {$relTable} r ON r.tag_id = t.id
             WHERE {$where}"
        ); // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return array_map( 'intval', $ids ?? [] );
    }
}
