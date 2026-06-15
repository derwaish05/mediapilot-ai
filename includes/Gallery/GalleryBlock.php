<?php

declare(strict_types=1);

namespace MediaPilotAI\Gallery;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderRepository;

/**
 * Registers the `mediapilot/gallery` Gutenberg block.
 *
 * The block is dynamic (server-side rendered). The edit component lives in
 * admin/assets/js/block-mediapilot-gallery.js and uses wp.* globals provided by the
 * Gutenberg runtime — no build step required.
 *
 * Frontend CSS is registered as a named style handle and is only output by
 * WordPress when the block is actually present on the rendered page.
 *
 * @package MediaPilotAI\Gallery
 * @since   1.0.0
 */
class GalleryBlock {

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct(
        private readonly FolderRepository $folderRepository,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Register all hooks. Called once from Plugin::registerServices().
     */
    public function register(): void {
        add_action( 'init',                        [ $this, 'registerBlock' ] );
        add_action( 'enqueue_block_editor_assets', [ $this, 'enqueueEditorAssets' ] );
    }

    // -------------------------------------------------------------------------
    // Hook callbacks
    // -------------------------------------------------------------------------

    /**
     * Register the block type and the frontend style handle.
     *
     * The 'style' key registers a CSS handle that WordPress only enqueues when
     * this block appears on the rendered page.
     */
    public function registerBlock(): void {
        // Register frontend CSS (loaded only when block is present on page).
        wp_register_style(
            'mediapilot-gallery-block',
            MDPAI_URL . 'admin/assets/css/block-mediapilot-gallery.css',
            [],
            MDPAI_VERSION
        );

        // Register lightbox JS — enqueued lazily from renderBlock() when needed.
        wp_register_script(
            'mediapilot-gallery-lightbox',
            MDPAI_URL . 'admin/assets/js/mediapilot-gallery-lightbox.js',
            [],
            MDPAI_VERSION,
            true
        );

        // Register carousel JS — enqueued lazily from renderBlock() when needed.
        wp_register_script(
            'mediapilot-gallery-carousel',
            MDPAI_URL . 'admin/assets/js/mediapilot-gallery-carousel.js',
            [],
            MDPAI_VERSION,
            true
        );

        register_block_type(
            'mediapilot/gallery',
            [
                'api_version'     => 3,
                'title'           => __( 'MediaPilot Gallery', 'mediapilot-ai'),
                'description'     => __( 'Display images from a MediaPilot AI folder.', 'mediapilot-ai'),
                'category'        => 'media',
                'icon'            => 'format-gallery',
                'attributes'      => $this->blockAttributes(),
                'supports'        => [
                    'html'    => false,
                    'align'   => [ 'wide', 'full' ],
                    'spacing' => [ 'margin' => true, 'padding' => true ],
                ],
                'render_callback' => [ $this, 'renderBlock' ],
                'style'           => 'mediapilot-gallery-block',
            ]
        );
    }

    /**
     * Enqueue the block editor JS and editor-only CSS.
     *
     * Fires on `enqueue_block_editor_assets` so assets are available in the
     * Gutenberg editor but not on the frontend.
     */
    public function enqueueEditorAssets(): void {
        wp_enqueue_script(
            'mediapilot-gallery-block',
            MDPAI_URL . 'admin/assets/js/block-mediapilot-gallery.js',
            [
                'wp-blocks',
                'wp-element',
                'wp-block-editor',
                'wp-components',
                'wp-i18n',
                'wp-api-fetch',
            ],
            MDPAI_VERSION,
            true
        );

        wp_enqueue_style(
            'mediapilot-gallery-block-editor',
            MDPAI_URL . 'admin/assets/css/block-mediapilot-gallery-editor.css',
            [ 'wp-edit-blocks' ],
            MDPAI_VERSION
        );
    }

    /**
     * Server-side render callback — called by WordPress for each `mediapilot/gallery`
     * block instance on the page.
     *
     * @param  array<string, mixed> $attributes  Block attributes.
     * @return string  HTML output.
     */
    public function renderBlock( array $attributes ): string {
        // Enqueue lightbox JS when the block uses it (runs during content render,
        // so wp_enqueue_script will place it in wp_footer).
        if ( ! empty( $attributes['lightbox'] ) ) {
            wp_enqueue_script( 'mediapilot-gallery-lightbox' );
        }

        // Enqueue carousel JS when layout is carousel.
        if ( 'carousel' === ( $attributes['layout'] ?? '' ) ) {
            wp_enqueue_script( 'mediapilot-gallery-carousel' );
        }

        $renderer = new GalleryRenderer( $this->folderRepository );
        return $renderer->render( $attributes );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Attribute schema — must match the attributes object in block-mediapilot-gallery.js.
     *
     * @return array<string, array<string, mixed>>
     */
    private function blockAttributes(): array {
        return [
            'folderId'   => [ 'type' => 'integer', 'default' => 0 ],
            'folderName' => [ 'type' => 'string',  'default' => '' ],
            'layout'     => [
                'type'    => 'string',
                'default' => 'grid',
                'enum'    => [ 'grid', 'masonry', 'flex', 'carousel' ],
            ],
            'columns'    => [ 'type' => 'integer', 'default' => 3 ],
            'gap'        => [ 'type' => 'integer', 'default' => 16 ],
            'lightbox'   => [ 'type' => 'boolean', 'default' => true ],
            'caption'    => [ 'type' => 'boolean', 'default' => false ],
            'imageSize'  => [
                'type'    => 'string',
                'default' => 'medium',
                'enum'    => [ 'thumbnail', 'medium', 'large', 'full' ],
            ],
        ];
    }
}
