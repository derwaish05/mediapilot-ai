<?php

declare(strict_types=1);

namespace MediaPilotAI\Compat;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderRepository;
use MediaPilotAI\Gallery\GalleryRenderer;

/**
 * Beaver Builder Module — MediaPilot Gallery (S42).
 *
 * Extends FLBuilderModule so users can insert and configure an MediaPilot Gallery
 * directly inside the Beaver Builder drag-and-drop editor.
 *
 * Registration: FLBuilder::register_module() is called in the static
 * `register()` method, which is invoked on `init` from Plugin::registerServices()
 * — bails silently if Beaver Builder is not active.
 *
 * @package MediaPilotAI\Compat
 * @since   1.0.0
 */
class BeaverBuilderGalleryModule extends \FLBuilderModule {

    // -------------------------------------------------------------------------
    // Registration (static entry point)
    // -------------------------------------------------------------------------

    /**
     * Register the module and its form config with Beaver Builder.
     * Called once on `init` after checking FLBuilder is available.
     */
    public static function register( FolderRepository $folderRepository ): void {
        \FLBuilder::register_module(
            self::class,
            [
                'source' => [
                    'title'    => __( 'Source', 'mediapilot-ai'),
                    'sections' => [
                        'folder_section' => [
                            'title'  => '',
                            'fields' => [
                                'folder_id' => [
                                    'type'    => 'select',
                                    'label'   => __( 'Folder', 'mediapilot-ai'),
                                    'default' => '0',
                                    'options' => self::buildFolderOptions( $folderRepository ),
                                    'help'    => __( 'Choose the MediaPilot folder to display as a gallery.', 'mediapilot-ai'),
                                ],
                            ],
                        ],
                    ],
                ],
                'layout' => [
                    'title'    => __( 'Layout', 'mediapilot-ai'),
                    'sections' => [
                        'layout_section' => [
                            'title'  => '',
                            'fields' => [
                                'layout' => [
                                    'type'    => 'select',
                                    'label'   => __( 'Layout', 'mediapilot-ai'),
                                    'default' => 'grid',
                                    'options' => [
                                        'grid'     => __( 'Grid', 'mediapilot-ai'),
                                        'masonry'  => __( 'Masonry', 'mediapilot-ai'),
                                        'flex'     => __( 'Flex', 'mediapilot-ai'),
                                        'carousel' => __( 'Carousel', 'mediapilot-ai'),
                                    ],
                                ],
                                'columns' => [
                                    'type'    => 'unit',
                                    'label'   => __( 'Columns', 'mediapilot-ai'),
                                    'default' => '3',
                                    'slider'  => [
                                        'min'  => 1,
                                        'max'  => 8,
                                        'step' => 1,
                                    ],
                                    'units'   => [ '' ],
                                ],
                                'gap' => [
                                    'type'    => 'unit',
                                    'label'   => __( 'Gap (px)', 'mediapilot-ai'),
                                    'default' => '16',
                                    'slider'  => [
                                        'min'  => 0,
                                        'max'  => 64,
                                        'step' => 1,
                                    ],
                                    'units'   => [ '' ],
                                ],
                                'image_size' => [
                                    'type'    => 'select',
                                    'label'   => __( 'Image Size', 'mediapilot-ai'),
                                    'default' => 'medium',
                                    'options' => [
                                        'thumbnail' => __( 'Thumbnail', 'mediapilot-ai'),
                                        'medium'    => __( 'Medium', 'mediapilot-ai'),
                                        'large'     => __( 'Large', 'mediapilot-ai'),
                                        'full'      => __( 'Full', 'mediapilot-ai'),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'options' => [
                    'title'    => __( 'Options', 'mediapilot-ai'),
                    'sections' => [
                        'options_section' => [
                            'title'  => '',
                            'fields' => [
                                'lightbox' => [
                                    'type'    => 'select',
                                    'label'   => __( 'Lightbox', 'mediapilot-ai'),
                                    'default' => '1',
                                    'options' => [
                                        '1' => __( 'Enabled', 'mediapilot-ai'),
                                        '0' => __( 'Disabled', 'mediapilot-ai'),
                                    ],
                                ],
                                'caption' => [
                                    'type'    => 'select',
                                    'label'   => __( 'Show Caption', 'mediapilot-ai'),
                                    'default' => '0',
                                    'options' => [
                                        '1' => __( 'Yes', 'mediapilot-ai'),
                                        '0' => __( 'No', 'mediapilot-ai'),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct() {
        parent::__construct( [
            'name'            => __( 'MediaPilot Gallery', 'mediapilot-ai'),
            'description'     => __( 'Display images from a MediaPilot AI folder.', 'mediapilot-ai'),
            'category'        => __( 'Media', 'mediapilot-ai'),
            'partial_refresh' => true,
        ] );
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    /**
     * Called by Beaver Builder to render the module on the frontend
     * and inside the live editor preview.
     */
    public function render(): void {
        $folderRepository = new FolderRepository();
        $renderer         = new GalleryRenderer( $folderRepository );

        echo $renderer->render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered HTML from trusted internal GalleryRenderer
            'folderId'  => absint( $this->settings->folder_id ?? 0 ),
            'layout'    => sanitize_key( (string) ( $this->settings->layout     ?? 'grid' ) ),
            'columns'   => (int) ( $this->settings->columns   ?? 3 ),
            'gap'       => (int) ( $this->settings->gap       ?? 16 ),
            'lightbox'  => (bool) ( $this->settings->lightbox ?? 1 ),
            'caption'   => (bool) ( $this->settings->caption  ?? 0 ),
            'imageSize' => sanitize_key( (string) ( $this->settings->image_size ?? 'medium' ) ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @param  FolderRepository $repo
     * @return array<string, string>
     */
    private static function buildFolderOptions( FolderRepository $repo ): array {
        $options = [ '0' => __( '— Select folder —', 'mediapilot-ai') ];
        $tree    = $repo->getTree( 0 );
        self::flattenTree( $tree, $options, 0 );
        return $options;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<string, string>            $options
     * @param int                              $depth
     */
    private static function flattenTree( array $nodes, array &$options, int $depth ): void {
        foreach ( $nodes as $node ) {
            $id   = (string) ( $node['id']   ?? 0 );
            $name = (string) ( $node['name'] ?? '' );
            $options[ $id ] = str_repeat( '— ', $depth ) . $name;
            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                self::flattenTree( $node['children'], $options, $depth + 1 );
            }
        }
    }
}
