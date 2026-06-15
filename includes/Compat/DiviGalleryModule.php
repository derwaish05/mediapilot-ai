<?php

declare(strict_types=1);

namespace MediaPilotAI\Compat;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderRepository;
use MediaPilotAI\Gallery\GalleryRenderer;

/**
 * Divi Builder Module — MediaPilot Gallery (S42).
 *
 * Extends ET_Builder_Module to register an "MediaPilot Gallery" module inside the
 * Divi page builder. Settings mirror the [mdpai_gallery] shortcode options.
 *
 * Registration: class is loaded inside an `et_builder_ready` callback from
 * Plugin::registerServices() — bails silently if Divi is not active.
 *
 * @package MediaPilotAI\Compat
 * @since   1.0.0
 */
class DiviGalleryModule extends \ET_Builder_Module {

    /** @var string */
    public $slug = 'et_pb_mdpai_gallery';

    /** @var string */
    public $vb_support = 'on';

    /** @var FolderRepository */
    private FolderRepository $folderRepository;

    // -------------------------------------------------------------------------
    // Initialisation
    // -------------------------------------------------------------------------

    public function init(): void {
        $this->folderRepository = new FolderRepository();

        $this->name             = __( 'MediaPilot Gallery', 'mediapilot-ai');
        $this->main_css_element = '%%order_class%%';

        $this->settings_modal_toggles = [
            'general' => [
                'toggles' => [
                    'source'  => __( 'Source', 'mediapilot-ai'),
                    'layout'  => __( 'Layout', 'mediapilot-ai'),
                    'options' => __( 'Options', 'mediapilot-ai'),
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Fields (settings)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array<string, mixed>>
     */
    public function get_fields(): array {
        return [
            'folder_id' => [
                'label'           => __( 'Folder', 'mediapilot-ai'),
                'type'            => 'select',
                'options'         => $this->getFolderOptions(),
                'default'         => '0',
                'toggle_slug'     => 'source',
                'description'     => __( 'Select the MediaPilot folder to display as a gallery.', 'mediapilot-ai'),
            ],
            'layout' => [
                'label'           => __( 'Layout', 'mediapilot-ai'),
                'type'            => 'select',
                'options'         => [
                    'grid'     => __( 'Grid', 'mediapilot-ai'),
                    'masonry'  => __( 'Masonry', 'mediapilot-ai'),
                    'flex'     => __( 'Flex', 'mediapilot-ai'),
                    'carousel' => __( 'Carousel', 'mediapilot-ai'),
                ],
                'default'         => 'grid',
                'toggle_slug'     => 'layout',
            ],
            'columns' => [
                'label'           => __( 'Columns', 'mediapilot-ai'),
                'type'            => 'range',
                'default'         => '3',
                'range_settings'  => [
                    'min'  => 1,
                    'max'  => 8,
                    'step' => 1,
                ],
                'unitless'        => true,
                'toggle_slug'     => 'layout',
            ],
            'gap' => [
                'label'           => __( 'Gap (px)', 'mediapilot-ai'),
                'type'            => 'range',
                'default'         => '16',
                'range_settings'  => [
                    'min'  => 0,
                    'max'  => 64,
                    'step' => 1,
                ],
                'unitless'        => true,
                'toggle_slug'     => 'layout',
            ],
            'image_size' => [
                'label'           => __( 'Image Size', 'mediapilot-ai'),
                'type'            => 'select',
                'options'         => [
                    'thumbnail' => __( 'Thumbnail', 'mediapilot-ai'),
                    'medium'    => __( 'Medium', 'mediapilot-ai'),
                    'large'     => __( 'Large', 'mediapilot-ai'),
                    'full'      => __( 'Full', 'mediapilot-ai'),
                ],
                'default'         => 'medium',
                'toggle_slug'     => 'layout',
            ],
            'lightbox' => [
                'label'           => __( 'Enable Lightbox', 'mediapilot-ai'),
                'type'            => 'yes_no_button',
                'options'         => [
                    'on'  => __( 'Yes', 'mediapilot-ai'),
                    'off' => __( 'No', 'mediapilot-ai'),
                ],
                'default'         => 'on',
                'toggle_slug'     => 'options',
            ],
            'caption' => [
                'label'           => __( 'Show Caption', 'mediapilot-ai'),
                'type'            => 'yes_no_button',
                'options'         => [
                    'on'  => __( 'Yes', 'mediapilot-ai'),
                    'off' => __( 'No', 'mediapilot-ai'),
                ],
                'default'         => 'off',
                'toggle_slug'     => 'options',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed> $attrs
     * @param  string|null          $content
     * @param  string               $renderSlug
     * @return string
     */
    public function render( $attrs, $content, $renderSlug ): string {
        $renderer = new GalleryRenderer( $this->folderRepository );

        return $renderer->render( [
            'folderId'  => absint( $this->props['folder_id'] ?? 0 ),
            'layout'    => sanitize_key( (string) ( $this->props['layout']     ?? 'grid' ) ),
            'columns'   => (int) ( $this->props['columns']   ?? 3 ),
            'gap'       => (int) ( $this->props['gap']       ?? 16 ),
            'lightbox'  => ( $this->props['lightbox'] ?? 'on' ) === 'on',
            'caption'   => ( $this->props['caption']  ?? 'off' ) === 'on',
            'imageSize' => sanitize_key( (string) ( $this->props['image_size'] ?? 'medium' ) ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private function getFolderOptions(): array {
        $options = [ '0' => __( '— Select folder —', 'mediapilot-ai') ];
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
            $options[ $id ] = str_repeat( '— ', $depth ) . $name;
            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                $this->flattenToOptions( $node['children'], $options, $depth + 1 );
            }
        }
    }
}
