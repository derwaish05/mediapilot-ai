<?php

declare(strict_types=1);

namespace MediaPilotAI\Compat;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderRepository;
use MediaPilotAI\Gallery\GalleryRenderer;

// Guard: Bricks\Element must exist before PHP can parse the class declaration.
// The autoloader loads this file during the `init` hook; if Bricks is inactive
// the parent class is absent and PHP would fatal without this early return.
if ( ! class_exists( '\Bricks\Element' ) ) {
    return;
}

/**
 * Bricks Builder Element — MediaPilot Gallery (S42).
 *
 * Extends \Bricks\Element to register an "MediaPilot Gallery" element in the
 * Bricks Builder panel. Controls mirror the [mdpai_gallery] shortcode options.
 *
 * Registration: the class is registered via \Bricks\Elements::register_element()
 * inside an `init` callback from Plugin::registerServices() — bails silently
 * if Bricks Builder is not active.
 *
 * @package MediaPilotAI\Compat
 * @since   1.0.0
 */
class BricksGalleryElement extends \Bricks\Element {

    /** @var string */
    public $category = 'media';

    /** @var string */
    public $name = 'mediapilot-gallery';

    /** @var string */
    public $icon = 'ti-image';

    /** @var string */
    public $label = 'MediaPilot Gallery';

    /** @var FolderRepository */
    private FolderRepository $folderRepository;

    // -------------------------------------------------------------------------
    // Static registration entry point
    // -------------------------------------------------------------------------

    /**
     * Register this element with Bricks.
     * Called on `init` after checking Bricks is active.
     */
    public static function register( FolderRepository $folderRepository ): void {
        if ( ! class_exists( '\Bricks\Elements' ) ) {
            return;
        }

        // Store the repository in a static property so the constructor can
        // access it (Bricks instantiates via class name without arguments).
        self::$sharedRepo = $folderRepository;

        \Bricks\Elements::register_element( __FILE__, 'mediapilot-gallery', self::class );
    }

    /** @var FolderRepository|null  Shared across instances (Bricks owns instantiation). */
    private static ?FolderRepository $sharedRepo = null;

    public function __construct( $element = [] ) {
        parent::__construct( $element );
        $this->folderRepository = self::$sharedRepo ?? new FolderRepository();
    }

    // -------------------------------------------------------------------------
    // Controls
    // -------------------------------------------------------------------------

    public function set_controls(): void {
        // ── Source ──────────────────────────────────────────────────────────
        $this->controls['folder_id'] = [
            'tab'         => 'content',
            'group'       => 'source',
            'label'       => esc_html__( 'Folder', 'mediapilot-ai'),
            'type'        => 'select',
            'options'     => $this->getFolderOptions(),
            'default'     => '0',
            'description' => esc_html__( 'Select the MediaPilot folder to display.', 'mediapilot-ai'),
        ];

        // ── Layout ──────────────────────────────────────────────────────────
        $this->controls['layout'] = [
            'tab'     => 'content',
            'group'   => 'layout',
            'label'   => esc_html__( 'Layout', 'mediapilot-ai'),
            'type'    => 'select',
            'options' => [
                'grid'     => esc_html__( 'Grid', 'mediapilot-ai'),
                'masonry'  => esc_html__( 'Masonry', 'mediapilot-ai'),
                'flex'     => esc_html__( 'Flex', 'mediapilot-ai'),
                'carousel' => esc_html__( 'Carousel', 'mediapilot-ai'),
            ],
            'default' => 'grid',
        ];

        $this->controls['columns'] = [
            'tab'     => 'content',
            'group'   => 'layout',
            'label'   => esc_html__( 'Columns', 'mediapilot-ai'),
            'type'    => 'number',
            'min'     => 1,
            'max'     => 8,
            'step'    => 1,
            'default' => 3,
        ];

        $this->controls['gap'] = [
            'tab'     => 'content',
            'group'   => 'layout',
            'label'   => esc_html__( 'Gap (px)', 'mediapilot-ai'),
            'type'    => 'number',
            'min'     => 0,
            'max'     => 64,
            'step'    => 1,
            'default' => 16,
        ];

        $this->controls['image_size'] = [
            'tab'     => 'content',
            'group'   => 'layout',
            'label'   => esc_html__( 'Image Size', 'mediapilot-ai'),
            'type'    => 'select',
            'options' => [
                'thumbnail' => esc_html__( 'Thumbnail', 'mediapilot-ai'),
                'medium'    => esc_html__( 'Medium', 'mediapilot-ai'),
                'large'     => esc_html__( 'Large', 'mediapilot-ai'),
                'full'      => esc_html__( 'Full', 'mediapilot-ai'),
            ],
            'default' => 'medium',
        ];

        // ── Options ─────────────────────────────────────────────────────────
        $this->controls['lightbox'] = [
            'tab'     => 'content',
            'group'   => 'options',
            'label'   => esc_html__( 'Lightbox', 'mediapilot-ai'),
            'type'    => 'checkbox',
            'default' => true,
        ];

        $this->controls['caption'] = [
            'tab'     => 'content',
            'group'   => 'options',
            'label'   => esc_html__( 'Show Caption', 'mediapilot-ai'),
            'type'    => 'checkbox',
            'default' => false,
        ];
    }

    // -------------------------------------------------------------------------
    // Control groups
    // -------------------------------------------------------------------------

    public function set_control_groups(): void {
        $this->control_groups['source']  = [ 'title' => esc_html__( 'Source', 'mediapilot-ai') ];
        $this->control_groups['layout']  = [ 'title' => esc_html__( 'Layout', 'mediapilot-ai') ];
        $this->control_groups['options'] = [ 'title' => esc_html__( 'Options', 'mediapilot-ai') ];
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render(): void {
        $settings = $this->settings;

        $renderer = new GalleryRenderer( $this->folderRepository );

        echo $renderer->render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered HTML from trusted internal GalleryRenderer
            'folderId'  => absint( $settings['folder_id'] ?? 0 ),
            'layout'    => sanitize_key( (string) ( $settings['layout']     ?? 'grid' ) ),
            'columns'   => (int) ( $settings['columns']   ?? 3 ),
            'gap'       => (int) ( $settings['gap']       ?? 16 ),
            'lightbox'  => (bool) ( $settings['lightbox'] ?? true ),
            'caption'   => (bool) ( $settings['caption']  ?? false ),
            'imageSize' => sanitize_key( (string) ( $settings['image_size'] ?? 'medium' ) ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private function getFolderOptions(): array {
        $options = [ '0' => esc_html__( '— Select folder —', 'mediapilot-ai') ];
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
