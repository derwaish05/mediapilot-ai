<?php

declare(strict_types=1);

namespace MediaPilotAI\Compat;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderRepository;
use MediaPilotAI\Gallery\GalleryRenderer;

/**
 * WPBakery Page Builder Element — MediaPilot Gallery (S42).
 *
 * Registers an "MediaPilot Gallery" element via `vc_map()` and a companion
 * shortcode `[mdpai_gallery_vc]` that WPBakery renders for both the
 * frontend and the backend editor.
 *
 * Registration: `register()` is called on `vc_before_init` from
 * Plugin::registerServices() — bails silently if WPBakery is not active.
 *
 * @package MediaPilotAI\Compat
 * @since   1.0.0
 */
class WPBakeryGalleryElement {

    public function __construct(
        private readonly FolderRepository $folderRepository,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function register(): void {
        if ( ! function_exists( 'vc_map' ) ) {
            return;
        }

        vc_map( [
            'name'        => __( 'MediaPilot Gallery', 'mediapilot-ai'),
            'base'        => 'mdpai_gallery_vc',
            'description' => __( 'Display images from a MediaPilot AI folder.', 'mediapilot-ai'),
            'category'    => __( 'Media', 'mediapilot-ai'),
            'icon'        => 'vc_general vc_el_icon vc_icon-vc-gallery',
            'params'      => $this->buildParams(),
        ] );

        add_shortcode( 'mdpai_gallery_vc', [ $this, 'renderShortcode' ] );
    }

    // -------------------------------------------------------------------------
    // Shortcode callback
    // -------------------------------------------------------------------------

    /**
     * WPBakery renders the element by calling this shortcode.
     *
     * @param  array<string, mixed>|string $attrs
     * @return string  Safe HTML.
     */
    public function renderShortcode( array|string $attrs ): string {
        $attrs = shortcode_atts(
            [
                'folder_id'  => 0,
                'layout'     => 'grid',
                'columns'    => 3,
                'gap'        => 16,
                'image_size' => 'medium',
                'lightbox'   => 'yes',
                'caption'    => 'no',
            ],
            is_array( $attrs ) ? $attrs : [],
            'mdpai_gallery_vc'
        );

        $renderer = new GalleryRenderer( $this->folderRepository );

        return $renderer->render( [
            'folderId'  => absint( $attrs['folder_id'] ),
            'layout'    => sanitize_key( (string) $attrs['layout'] ),
            'columns'   => (int) $attrs['columns'],
            'gap'       => (int) $attrs['gap'],
            'lightbox'  => ( $attrs['lightbox'] === 'yes' ),
            'caption'   => ( $attrs['caption']  === 'yes' ),
            'imageSize' => sanitize_key( (string) $attrs['image_size'] ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build the `vc_map` params array.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildParams(): array {
        return [
            [
                'type'        => 'dropdown',
                'heading'     => __( 'Folder', 'mediapilot-ai'),
                'param_name'  => 'folder_id',
                'value'       => $this->getFolderOptions(),
                'description' => __( 'Select the MediaPilot folder to display.', 'mediapilot-ai'),
                'group'       => __( 'Source', 'mediapilot-ai'),
            ],
            [
                'type'       => 'dropdown',
                'heading'    => __( 'Layout', 'mediapilot-ai'),
                'param_name' => 'layout',
                'value'      => [
                    __( 'Grid', 'mediapilot-ai')     => 'grid',
                    __( 'Masonry', 'mediapilot-ai')  => 'masonry',
                    __( 'Flex', 'mediapilot-ai')     => 'flex',
                    __( 'Carousel', 'mediapilot-ai') => 'carousel',
                ],
                'std'        => 'grid',
                'group'      => __( 'Layout', 'mediapilot-ai'),
            ],
            [
                'type'       => 'number',
                'heading'    => __( 'Columns', 'mediapilot-ai'),
                'param_name' => 'columns',
                'value'      => 3,
                'min'        => 1,
                'max'        => 8,
                'group'      => __( 'Layout', 'mediapilot-ai'),
            ],
            [
                'type'       => 'number',
                'heading'    => __( 'Gap (px)', 'mediapilot-ai'),
                'param_name' => 'gap',
                'value'      => 16,
                'min'        => 0,
                'max'        => 64,
                'group'      => __( 'Layout', 'mediapilot-ai'),
            ],
            [
                'type'       => 'dropdown',
                'heading'    => __( 'Image Size', 'mediapilot-ai'),
                'param_name' => 'image_size',
                'value'      => [
                    __( 'Thumbnail', 'mediapilot-ai') => 'thumbnail',
                    __( 'Medium', 'mediapilot-ai')    => 'medium',
                    __( 'Large', 'mediapilot-ai')     => 'large',
                    __( 'Full', 'mediapilot-ai')      => 'full',
                ],
                'std'        => 'medium',
                'group'      => __( 'Layout', 'mediapilot-ai'),
            ],
            [
                'type'       => 'checkbox',
                'heading'    => __( 'Lightbox', 'mediapilot-ai'),
                'param_name' => 'lightbox',
                'value'      => [ __( 'Enable lightbox', 'mediapilot-ai') => 'yes' ],
                'std'        => 'yes',
                'group'      => __( 'Options', 'mediapilot-ai'),
            ],
            [
                'type'       => 'checkbox',
                'heading'    => __( 'Show Caption', 'mediapilot-ai'),
                'param_name' => 'caption',
                'value'      => [ __( 'Show image captions', 'mediapilot-ai') => 'yes' ],
                'std'        => '',
                'group'      => __( 'Options', 'mediapilot-ai'),
            ],
        ];
    }

    /**
     * WPBakery dropdown `value` key uses `[Label => value]` format.
     *
     * @return array<string, string>
     */
    private function getFolderOptions(): array {
        $options = [ __( '— Select folder —', 'mediapilot-ai') => '0' ];
        $tree    = $this->folderRepository->getTree( 0 );
        $this->flattenToOptions( $tree, $options, 0 );
        return $options;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<string, string>            $options
     * @param int                              $depth
     */
    private function flattenToOptions( array $nodes, array &$options, int $depth ): void {
        foreach ( $nodes as $node ) {
            $id   = (string) ( $node['id']   ?? 0 );
            $name = (string) ( $node['name'] ?? '' );
            $label = str_repeat( '— ', $depth ) . $name;
            $options[ $label ] = $id;
            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                $this->flattenToOptions( $node['children'], $options, $depth + 1 );
            }
        }
    }
}
