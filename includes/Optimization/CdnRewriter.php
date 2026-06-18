<?php

declare(strict_types=1);

namespace MediaPilotAI\Optimization;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * CDN URL rewriting for attachment URLs.
 *
 * Replaces the local uploads base URL with a CDN base URL in:
 *   - `wp_get_attachment_url`
 *   - `wp_calculate_image_srcset` (all srcset candidates)
 *   - `the_content` (inline <img src> and srcset attributes)
 *   - `wp_get_attachment_image_src`
 *
 * Supported providers (all work with a site-owner-supplied CDN base URL):
 *   cloudflare, bunnycdn, cloudfront, or any custom CDN host prefix.
 * The CDN base URL is entered by the site administrator in the plugin
 * settings; the plugin does not call any hard-coded remote host.
 *
 * The provider field currently drives the settings UI label only;
 * all rewrites are performed via the single cdn_base_url value.
 *
 * @package MediaPilotAI\Optimization
 * @since   1.0.0
 */
class CdnRewriter {

    /** Absolute URL prefix for the local uploads directory (no trailing slash). */
    private string $uploadsBaseUrl;

    /** CDN base URL (no trailing slash). */
    private string $cdnBaseUrl;

    public function __construct( string $cdnBaseUrl ) {
        $uploadsDir           = wp_get_upload_dir();
        $this->uploadsBaseUrl = rtrim( (string) $uploadsDir['baseurl'], '/' );
        // Sanitise the admin-supplied CDN base URL so every rewrite (including
        // into the_content) outputs a safe URL — escaping late at the source.
        $this->cdnBaseUrl     = esc_url_raw( rtrim( $cdnBaseUrl, '/' ) );
    }

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function register(): void {
        if ( '' === $this->cdnBaseUrl ) {
            return; // No CDN configured — no-op.
        }

        add_filter( 'wp_get_attachment_url',          [ $this, 'rewriteUrl' ],    20 );
        add_filter( 'wp_calculate_image_srcset',      [ $this, 'rewriteSrcset' ], 20 );
        add_filter( 'the_content',                    [ $this, 'rewriteContent' ], 20 );
        add_filter( 'wp_get_attachment_image_src',    [ $this, 'rewriteImageSrc' ], 20 );
    }

    // -------------------------------------------------------------------------
    // Filter callbacks
    // -------------------------------------------------------------------------

    /**
     * Rewrite a single attachment URL.
     */
    public function rewriteUrl( string $url ): string {
        return $this->swap( $url );
    }

    /**
     * Rewrite all URLs in a srcset array.
     *
     * @param  array<int, array{url: string, descriptor: string, value: int}> $sources
     * @return array<int, array{url: string, descriptor: string, value: int}>
     */
    public function rewriteSrcset( array $sources ): array {
        foreach ( $sources as &$source ) {
            if ( isset( $source['url'] ) ) {
                $source['url'] = $this->swap( (string) $source['url'] );
            }
        }
        return $sources;
    }

    /**
     * Rewrite all upload URLs found in post content.
     */
    public function rewriteContent( string $content ): string {
        if ( '' === $content || '' === $this->uploadsBaseUrl ) {
            return $content;
        }

        return str_replace(
            $this->uploadsBaseUrl,
            $this->cdnBaseUrl,
            $content
        );
    }

    /**
     * Rewrite the URL inside a `wp_get_attachment_image_src()` result array.
     *
     * @param  array|false $image  [ url, width, height, is_intermediate ] or false.
     * @return array|false
     */
    public function rewriteImageSrc( $image ) {
        if ( ! is_array( $image ) || ! isset( $image[0] ) ) {
            return $image;
        }
        $image[0] = $this->swap( (string) $image[0] );
        return $image;
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function swap( string $url ): string {
        if ( str_starts_with( $url, $this->uploadsBaseUrl ) ) {
            return $this->cdnBaseUrl . substr( $url, strlen( $this->uploadsBaseUrl ) );
        }
        return $url;
    }
}
