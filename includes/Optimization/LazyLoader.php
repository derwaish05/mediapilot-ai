<?php

declare(strict_types=1);

namespace MediaPilotAI\Optimization;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Injects `loading="lazy"` into every `<img>` tag in post content that does
 * not already carry a `loading` attribute.
 *
 * WordPress 5.5+ adds lazy-loading natively to images inserted through the
 * block editor, but content from classic editor, page builders, or raw HTML
 * may still be missing the attribute. This class handles those cases.
 *
 * The filter runs at priority 20 on `the_content`, after most content
 * filters have had a chance to run.
 *
 * @package MediaPilotAI\Optimization
 * @since   1.0.0
 */
class LazyLoader {

    public function register(): void {
        add_filter( 'the_content', [ $this, 'addLazyToContent' ], 20 );

        // Ensure WordPress's own lazy-loading is enabled.
        add_filter( 'wp_lazy_loading_enabled', '__return_true' );
    }

    /**
     * Add `loading="lazy"` to any `<img>` tag in $content that does not
     * already have a `loading` attribute.
     */
    public function addLazyToContent( string $content ): string {
        if ( '' === $content || ! str_contains( $content, '<img' ) ) {
            return $content;
        }

        return preg_replace_callback(
            '/<img\s[^>]*>/i',
            static function ( array $matches ): string {
                $tag = $matches[0];

                // Already has a loading attribute — leave untouched.
                if ( preg_match( '/\bloading\s*=/i', $tag ) ) {
                    return $tag;
                }

                // Insert loading="lazy" right after <img.
                return preg_replace( '/<img\s/i', '<img loading="lazy" ', $tag, 1 ) ?? $tag;
            },
            $content
        ) ?? $content;
    }
}
