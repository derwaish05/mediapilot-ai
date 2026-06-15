<?php

declare(strict_types=1);

namespace MediaPilotAI\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderRepository;
use MediaPilotAI\Gallery\GalleryRenderer;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST routes for gallery operations (S39).
 *
 * Namespace: mediapilot/v1
 *
 * Routes:
 *   GET /gallery/preview   Renders gallery HTML for the Visual Shortcode Builder preview.
 *
 * @package MediaPilotAI\API
 * @since   1.0.0
 */
class GalleryRestController {

    private const NAMESPACE = 'mediapilot/v1';

    public function __construct(
        private readonly FolderRepository $folderRepository,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function register(): void {
        register_rest_route(
            self::NAMESPACE,
            '/gallery/preview',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'getPreview' ],
                'permission_callback' => [ $this, 'permUploadFiles' ],
                'args'                => [
                    'folder_id' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ],
                    'layout' => [
                        'type'              => 'string',
                        'default'           => 'grid',
                        'enum'              => [ 'grid', 'masonry', 'flex', 'carousel' ],
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'columns' => [
                        'type'              => 'integer',
                        'default'           => 3,
                        'minimum'           => 1,
                        'maximum'           => 8,
                        'sanitize_callback' => 'absint',
                    ],
                    'gap' => [
                        'type'              => 'integer',
                        'default'           => 16,
                        'minimum'           => 0,
                        'maximum'           => 64,
                        'sanitize_callback' => 'absint',
                    ],
                    'lightbox' => [
                        'type'              => 'boolean',
                        'default'           => true,
                        'sanitize_callback' => 'rest_sanitize_boolean',
                    ],
                    'caption' => [
                        'type'              => 'boolean',
                        'default'           => false,
                        'sanitize_callback' => 'rest_sanitize_boolean',
                    ],
                    'image_size' => [
                        'type'              => 'string',
                        'default'           => 'medium',
                        'enum'              => [ 'thumbnail', 'medium', 'large', 'full' ],
                        'sanitize_callback' => 'sanitize_key',
                    ],
                ],
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Callback
    // -------------------------------------------------------------------------

    /**
     * GET /gallery/preview
     *
     * Renders the gallery HTML for the supplied parameters and returns it
     * as a string so the builder can display a live preview.
     *
     * Also returns the ready-to-paste shortcode string.
     */
    public function getPreview( WP_REST_Request $request ): WP_REST_Response {
        $folderId  = (int) $request->get_param( 'folder_id' );
        $layout    = (string) $request->get_param( 'layout' );
        $columns   = (int) $request->get_param( 'columns' );
        $gap       = (int) $request->get_param( 'gap' );
        $lightbox  = (bool) $request->get_param( 'lightbox' );
        $caption   = (bool) $request->get_param( 'caption' );
        $imageSize = (string) $request->get_param( 'image_size' );

        $renderer = new GalleryRenderer( $this->folderRepository );

        $html = $renderer->render( [
            'folderId'  => $folderId,
            'layout'    => $layout,
            'columns'   => $columns,
            'gap'       => $gap,
            'lightbox'  => $lightbox,
            'caption'   => $caption,
            'imageSize' => $imageSize,
        ] );

        // Build the shortcode string to show alongside the preview.
        $shortcode = sprintf(
            '[mdpai_gallery folder="%d" layout="%s" columns="%d" gap="%d" lightbox="%s" caption="%s" image_size="%s"]',
            $folderId,
            esc_attr( $layout ),
            $columns,
            $gap,
            $lightbox ? 'true' : 'false',
            $caption  ? 'true' : 'false',
            esc_attr( $imageSize )
        );

        return new WP_REST_Response(
            [
                'success' => true,
                'data'    => [
                    'html'      => $html,
                    'shortcode' => $shortcode,
                ],
            ],
            200
        );
    }

    // -------------------------------------------------------------------------
    // Permission
    // -------------------------------------------------------------------------

    public function permUploadFiles(): bool {
        return current_user_can( 'upload_files' );
    }
}
