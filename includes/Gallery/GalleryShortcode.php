<?php

declare(strict_types=1);

namespace MediaPilotAI\Gallery;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderRepository;

/**
 * Registers and handles the [mdpai_gallery] shortcode.
 *
 * Syntax:
 *   [mdpai_gallery folder="42" layout="grid" columns="3" gap="16" lightbox="true" image_size="medium"]
 *
 * Parameters:
 *   folder     (int)    Folder term ID — required; 0 renders nothing.
 *   layout     (string) grid | masonry | flex  (default: grid)
 *   columns    (int)    1–8                    (default: 3)
 *   gap        (int)    0–64 px                (default: 16)
 *   lightbox   (bool)   true | false           (default: true)
 *   image_size (string) thumbnail|medium|large|full (default: medium)
 *
 * Assets (CSS + lightbox JS) are enqueued only when the shortcode is actually
 * present on the page, keeping clean pages free of unused requests.
 *
 * Rendering is delegated to GalleryRenderer so block and shortcode share
 * identical output HTML.
 *
 * @package MediaPilotAI\Gallery
 * @since   1.0.0
 */
class GalleryShortcode {

    /** Tracks whether the lightbox script has already been enqueued this request. */
    private bool $lightboxEnqueued = false;

    /** Tracks whether the carousel script has already been enqueued this request. */
    private bool $carouselEnqueued = false;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct(
        private readonly FolderRepository $folderRepository,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function register(): void {
        add_shortcode( 'mdpai_gallery', [ $this, 'renderShortcode' ] );
    }

    // -------------------------------------------------------------------------
    // Shortcode callback
    // -------------------------------------------------------------------------

    /**
     * Process the [mdpai_gallery] shortcode and return HTML.
     *
     * WP calls this during content rendering (`the_content` filter), so scripts
     * enqueued here will be output in wp_footer (in_footer = true) or in
     * wp_head if the hook hasn't fired yet.
     *
     * @param  array<string, mixed>|string $attrs  Raw shortcode attributes.
     * @return string  Safe HTML gallery markup.
     */
    public function renderShortcode( array|string $attrs ): string {
        $attrs = shortcode_atts(
            [
                'folder'     => 0,
                'layout'     => 'grid',
                'columns'    => 3,
                'gap'        => 16,
                'lightbox'   => 'true',
                'image_size' => 'medium',
                'caption'    => 'false',
            ],
            is_array( $attrs ) ? $attrs : [],
            'mdpai_gallery'
        );

        // Normalise to the same shape GalleryRenderer / GalleryBlock use.
        $attributes = [
            'folderId'   => absint( $attrs['folder'] ),
            'layout'     => sanitize_key( (string) $attrs['layout'] ),
            'columns'    => max( 1, min( 8,  (int) $attrs['columns'] ) ),
            'gap'        => max( 0, min( 64, (int) $attrs['gap'] ) ),
            'lightbox'   => filter_var( $attrs['lightbox'], FILTER_VALIDATE_BOOLEAN ),
            'imageSize'  => sanitize_key( (string) $attrs['image_size'] ),
            'caption'    => filter_var( $attrs['caption'], FILTER_VALIDATE_BOOLEAN ),
        ];

        // Enqueue CSS (+ lightbox/carousel JS if needed) — no-op after first call.
        $this->enqueueAssets( $attributes['lightbox'], $attributes['layout'] );

        $renderer = new GalleryRenderer( $this->folderRepository );

        return $renderer->render( $attributes );
    }

    // -------------------------------------------------------------------------
    // Asset enqueueing
    // -------------------------------------------------------------------------

    /**
     * Enqueue the gallery CSS and (if lightbox is enabled) the vanilla JS
     * lightbox. Runs inside the shortcode callback so assets are only loaded
     * on pages that actually contain the shortcode.
     *
     * The CSS handle 'mediapilot-gallery-block' is registered by GalleryBlock::registerBlock()
     * on `init`. If it hasn't been registered yet (e.g. on non-admin requests
     * before the block hook fires), we register it here as a fallback.
     */
    private function enqueueAssets( bool $lightbox, string $layout = 'grid' ): void {
        // Frontend CSS — shared with the block, registered on init.
        if ( ! wp_style_is( 'mediapilot-gallery-block', 'registered' ) ) {
            wp_register_style(
                'mediapilot-gallery-block',
                MDPAI_URL . 'admin/assets/css/block-mediapilot-gallery.css',
                [],
                MDPAI_VERSION
            );
        }

        wp_enqueue_style( 'mediapilot-gallery-block' );

        // Lightbox JS — enqueued once per page even if multiple galleries exist.
        if ( $lightbox && ! $this->lightboxEnqueued ) {
            wp_enqueue_script(
                'mediapilot-gallery-lightbox',
                MDPAI_URL . 'admin/assets/js/mediapilot-gallery-lightbox.js',
                [],         // no dependencies — vanilla JS
                MDPAI_VERSION,
                true        // load in wp_footer
            );

            $this->lightboxEnqueued = true;
        }

        // Carousel JS — enqueued once per page when carousel layout is used.
        if ( 'carousel' === $layout && ! $this->carouselEnqueued ) {
            wp_enqueue_script(
                'mediapilot-gallery-carousel',
                MDPAI_URL . 'admin/assets/js/mediapilot-gallery-carousel.js',
                [],
                MDPAI_VERSION,
                true
            );

            $this->carouselEnqueued = true;
        }
    }
}
