<?php

declare(strict_types=1);

namespace MediaPilotAI\Compat;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderRepository;
use MediaPilotAI\Gallery\GalleryRenderer;

/**
 * Elementor Widget — MediaPilot Gallery (S42).
 *
 * Registers an Elementor widget called "MediaPilot Gallery" that lets users pick a
 * folder and configure the same options as the [mdpai_gallery] shortcode.
 *
 * Rendering is delegated to GalleryRenderer so output is identical to the
 * block and shortcode.
 *
 * Also handles the Elementor media modal folder-tree upgrade: the bridge
 * script (registered by PageBuilderCompat) is already injected on
 * `elementor/editor/after_enqueue_scripts`; this widget additionally
 * enqueues the gallery CSS so previews inside the Elementor canvas render
 * correctly.
 *
 * Registration: called inside `elementor/widgets/register` from
 * Plugin::registerServices() — bails silently if Elementor is not active.
 *
 * @package MediaPilotAI\Compat
 * @since   1.0.0
 */
class ElementorGalleryWidget extends \Elementor\Widget_Base {

    public function __construct(
        array $data = [],
        ?array $args = null,
        private readonly FolderRepository $folderRepository = new FolderRepository(),
    ) {
        parent::__construct( $data, $args );
    }

    // -------------------------------------------------------------------------
    // Identity
    // -------------------------------------------------------------------------

    public function get_name(): string {
        return 'mdpai_gallery';
    }

    public function get_title(): string {
        return __( 'MediaPilot Gallery', 'mediapilot-ai');
    }

    public function get_icon(): string {
        return 'eicon-gallery-grid';
    }

    /**
     * @return string[]
     */
    public function get_categories(): array {
        return [ 'media' ];
    }

    /**
     * @return string[]
     */
    public function get_keywords(): array {
        return [ 'gallery', 'folder', 'media', 'mediapilot', 'image' ];
    }

    // -------------------------------------------------------------------------
    // Controls (settings panel)
    // -------------------------------------------------------------------------

    protected function register_controls(): void {
        // ── Section: Source ──────────────────────────────────────────────────
        $this->start_controls_section(
            'section_source',
            [
                'label' => __( 'Source', 'mediapilot-ai'),
                'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'folder_id',
            [
                'label'       => __( 'Folder', 'mediapilot-ai'),
                'type'        => \Elementor\Controls_Manager::SELECT,
                'options'     => $this->getFolderOptions(),
                'default'     => '0',
                'description' => __( 'Select the MediaPilot folder whose images to display.', 'mediapilot-ai'),
            ]
        );

        $this->end_controls_section();

        // ── Section: Layout ──────────────────────────────────────────────────
        $this->start_controls_section(
            'section_layout',
            [
                'label' => __( 'Layout', 'mediapilot-ai'),
                'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'layout',
            [
                'label'   => __( 'Layout', 'mediapilot-ai'),
                'type'    => \Elementor\Controls_Manager::SELECT,
                'default' => 'grid',
                'options' => [
                    'grid'     => __( 'Grid', 'mediapilot-ai'),
                    'masonry'  => __( 'Masonry', 'mediapilot-ai'),
                    'flex'     => __( 'Flex', 'mediapilot-ai'),
                    'carousel' => __( 'Carousel', 'mediapilot-ai'),
                ],
            ]
        );

        $this->add_control(
            'columns',
            [
                'label'   => __( 'Columns', 'mediapilot-ai'),
                'type'    => \Elementor\Controls_Manager::NUMBER,
                'default' => 3,
                'min'     => 1,
                'max'     => 8,
                'step'    => 1,
            ]
        );

        $this->add_control(
            'gap',
            [
                'label'   => __( 'Gap (px)', 'mediapilot-ai'),
                'type'    => \Elementor\Controls_Manager::NUMBER,
                'default' => 16,
                'min'     => 0,
                'max'     => 64,
                'step'    => 1,
            ]
        );

        $this->add_control(
            'image_size',
            [
                'label'   => __( 'Image Size', 'mediapilot-ai'),
                'type'    => \Elementor\Controls_Manager::SELECT,
                'default' => 'medium',
                'options' => [
                    'thumbnail' => __( 'Thumbnail', 'mediapilot-ai'),
                    'medium'    => __( 'Medium', 'mediapilot-ai'),
                    'large'     => __( 'Large', 'mediapilot-ai'),
                    'full'      => __( 'Full', 'mediapilot-ai'),
                ],
            ]
        );

        $this->end_controls_section();

        // ── Section: Options ─────────────────────────────────────────────────
        $this->start_controls_section(
            'section_options',
            [
                'label' => __( 'Options', 'mediapilot-ai'),
                'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'lightbox',
            [
                'label'        => __( 'Lightbox', 'mediapilot-ai'),
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => __( 'On', 'mediapilot-ai'),
                'label_off'    => __( 'Off', 'mediapilot-ai'),
                'return_value' => 'yes',
                'default'      => 'yes',
            ]
        );

        $this->add_control(
            'caption',
            [
                'label'        => __( 'Show Caption', 'mediapilot-ai'),
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => __( 'On', 'mediapilot-ai'),
                'label_off'    => __( 'Off', 'mediapilot-ai'),
                'return_value' => 'yes',
                'default'      => '',
            ]
        );

        $this->end_controls_section();
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    protected function render(): void {
        $settings = $this->get_settings_for_display();

        $renderer = new GalleryRenderer( $this->folderRepository );

        echo $renderer->render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered HTML from trusted internal GalleryRenderer
            'folderId'  => absint( $settings['folder_id'] ?? 0 ),
            'layout'    => sanitize_key( (string) ( $settings['layout']     ?? 'grid' ) ),
            'columns'   => (int) ( $settings['columns']   ?? 3 ),
            'gap'       => (int) ( $settings['gap']       ?? 16 ),
            'lightbox'  => ( $settings['lightbox']        ?? '' ) === 'yes',  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            'caption'   => ( $settings['caption']         ?? '' ) === 'yes',  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            'imageSize' => sanitize_key( (string) ( $settings['image_size'] ?? 'medium' ) ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Returns a flat [id => label] options array for the folder select control.
     * Root folders appear un-indented; sub-folders are prefixed with em-dashes.
     *
     * @return array<string, string>
     */
    private function getFolderOptions(): array {
        $options = [ '0' => __( '— Select folder —', 'mediapilot-ai') ];
        $tree    = $this->folderRepository->getTree( 0 );
        $this->flattenToOptions( $tree, $options, 0 );
        return $options;
    }

    /**
     * @param array<int, array<string, mixed>>  $nodes
     * @param array<string, string>             $options  Accumulator (by-ref).
     * @param int                               $depth
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
