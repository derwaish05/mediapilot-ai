<?php

declare(strict_types=1);

namespace MediaPilotAI\Compat;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * WPML (WordPress Multilingual Plugin) compatibility layer.
 *
 * Responsibilities:
 *  - Registers the 'mdpai_folder' taxonomy with WPML so folder terms can be
 *    translated per language.
 *  - Filters the folder tree to return only terms that belong to the
 *    currently active WPML language.
 *  - Filters REST folder responses so that language metadata is included.
 *
 * This class is loaded only when WPML is active (detected via the
 * ICL_SITEPRESS_VERSION constant).
 *
 * @package MediaPilotAI\Compat
 * @since   1.0.0
 */
class WpmlIntegration {

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    /**
     * Register hooks. Called from Plugin::boot() when WPML is detected.
     */
    public function register(): void {
        // Tell WPML to translate the folder taxonomy terms.
        add_filter( 'wpml_translatable_taxonomies', [ $this, 'registerTaxonomy' ] );

        // Filter the MediaPilot folder tree by active language.
        add_filter( 'mdpai_folder_tree', [ $this, 'filterTreeByLanguage' ], 10, 2 );

        // Enqueue RTL stylesheet when WPML switches to an RTL language.
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueueRtlStyles' ] );
    }

    // -------------------------------------------------------------------------
    // Hooks
    // -------------------------------------------------------------------------

    /**
     * Add 'mdpai_folder' to the list of taxonomies WPML should translate.
     *
     * @param  string[] $taxonomies
     * @return string[]
     */
    public function registerTaxonomy( array $taxonomies ): array {
        if ( ! in_array( 'mdpai_folder', $taxonomies, true ) ) {
            $taxonomies[] = 'mdpai_folder';
        }

        return $taxonomies;
    }

    /**
     * Remove folder tree nodes whose underlying term is not in the current
     * WPML language.
     *
     * When WPML is active each taxonomy term has a language code stored in
     * the icl_translations table. We use the wpml_object_id filter to resolve
     * the translated term ID; if it does not resolve we omit the folder.
     *
     * @param  array<int, array<string, mixed>> $tree
     * @param  int                              $viewerId  (unused, passed by mdpai_folder_tree filter)
     * @return array<int, array<string, mixed>>
     */
    public function filterTreeByLanguage( array $tree, int $viewerId ): array {
        if ( ! function_exists( 'wpml_get_current_language' ) ) {
            return $tree;
        }

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML-provided filter; the hook name is defined by WPML, this plugin only consumes it.
        $lang = (string) apply_filters( 'wpml_current_language', null );

        return $this->filterNodes( $tree, $lang );
    }

    /**
     * Enqueue RTL stylesheet when WordPress (or WPML) is in RTL mode.
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
     * Recursively removes nodes whose term ID does not have a translation in
     * the given language.
     *
     * @param  array<int, array<string, mixed>> $nodes
     * @param  string                           $lang
     * @return array<int, array<string, mixed>>
     */
    private function filterNodes( array $nodes, string $lang ): array {
        $filtered = [];

        foreach ( $nodes as $node ) {
            $termId = (int) ( $node['id'] ?? 0 );

            // wpml_object_id returns the translated ID in $lang, or null when
            // no translation exists.
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML-provided filter; the hook name is defined by WPML, this plugin only consumes it.
            $translatedId = (int) apply_filters( 'wpml_object_id', $termId, 'mdpai_folder', false, $lang );

            if ( 0 === $translatedId ) {
                continue; // No translation for this language — skip.
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
