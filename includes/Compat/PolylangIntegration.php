<?php

declare(strict_types=1);

namespace MediaPilotAI\Compat;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Polylang compatibility layer.
 *
 * Responsibilities:
 *  - Registers the 'mdpai_folder' taxonomy with Polylang so folder terms can be
 *    translated per language.
 *  - Filters the folder tree to return only terms that belong to the
 *    current Polylang language.
 *  - Enqueues the RTL stylesheet when an RTL language is active.
 *
 * This class is loaded only when Polylang is active (detected via the
 * POLYLANG_VERSION constant or the 'pll_languages_list' filter).
 *
 * @package MediaPilotAI\Compat
 * @since   1.0.0
 */
class PolylangIntegration {

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    /**
     * Register hooks. Called from Plugin::boot() when Polylang is detected.
     */
    public function register(): void {
        // Tell Polylang to make the folder taxonomy translatable.
        add_filter( 'pll_get_taxonomies', [ $this, 'registerTaxonomy' ], 10, 2 );

        // Filter the folder tree to the active Polylang language.
        add_filter( 'mdpai_folder_tree', [ $this, 'filterTreeByLanguage' ], 10, 2 );

        // Enqueue RTL stylesheet.
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueueRtlStyles' ] );
    }

    // -------------------------------------------------------------------------
    // Hooks
    // -------------------------------------------------------------------------

    /**
     * Add 'mdpai_folder' to the list of Polylang-translatable taxonomies.
     *
     * @param  string[] $taxonomies  Keyed array of taxonomy slugs.
     * @param  bool     $hide        Whether Polylang is hiding untranslated items.
     * @return string[]
     */
    public function registerTaxonomy( array $taxonomies, bool $hide ): array {
        $taxonomies['mdpai_folder'] = 'mdpai_folder';
        return $taxonomies;
    }

    /**
     * Remove folder tree nodes whose term is not in the active Polylang
     * language.
     *
     * @param  array<int, array<string, mixed>> $tree
     * @param  int                              $viewerId  (unused)
     * @return array<int, array<string, mixed>>
     */
    public function filterTreeByLanguage( array $tree, int $viewerId ): array {
        if ( ! function_exists( 'pll_current_language' ) ) {
            return $tree;
        }

        $lang = (string) pll_current_language();

        if ( '' === $lang ) {
            return $tree;
        }

        return $this->filterNodes( $tree, $lang );
    }

    /**
     * Enqueue RTL stylesheet when the current language is RTL.
     */
    public function enqueueRtlStyles(): void {
        if ( ! is_rtl() ) {
            return;
        }

        wp_enqueue_style(
            'mediapilot-rtl',
            plugin_dir_url( dirname( __DIR__ ) ) . 'admin/assets/css/rtl.css',
            [ 'mediapilot-admin' ],
            MDPAI_VERSION
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Recursively removes nodes whose term does not belong to the given
     * Polylang language slug.
     *
     * Polylang stores language per term via term meta. We use
     * pll_get_term_language() when available.
     *
     * @param  array<int, array<string, mixed>> $nodes
     * @param  string                           $lang
     * @return array<int, array<string, mixed>>
     */
    private function filterNodes( array $nodes, string $lang ): array {
        if ( ! function_exists( 'pll_get_term_language' ) ) {
            return $nodes;
        }

        $filtered = [];

        foreach ( $nodes as $node ) {
            $termId   = (int) ( $node['id'] ?? 0 );
            $termLang = (string) pll_get_term_language( $termId, 'slug' );

            // If no language is assigned (e.g. term predates Polylang) keep it.
            if ( '' !== $termLang && $termLang !== $lang ) {
                continue;
            }

            // Recurse into children.
            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                $node['children'] = $this->filterNodes( (array) $node['children'], $lang );
            }

            $filtered[] = $node;
        }

        return $filtered;
    }
}
