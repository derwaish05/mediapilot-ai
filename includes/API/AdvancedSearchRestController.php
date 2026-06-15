<?php

declare(strict_types=1);

namespace MediaPilotAI\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Search\AdvancedSearchService;
use MediaPilotAI\AI\SmartSearchService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST routes for Advanced Search (S44) and AI Smart Search (S48).
 *
 * Namespace: mediapilot/v1
 *
 * Routes:
 *   GET  /search                      Run the advanced search.
 *                                     Add ?ai=true for AI-enhanced semantic search (S48).
 *   GET  /search/filters              List the current user's saved filters.
 *   POST /search/filters              Save a new named filter.
 *   DELETE /search/filters/{id}       Delete a saved filter.
 *
 * @package MediaPilotAI\API
 * @since   1.0.0
 */
class AdvancedSearchRestController {

    private const NAMESPACE = 'mediapilot/v1';

    public function __construct(
        private readonly AdvancedSearchService $searchService,
        private readonly ?SmartSearchService   $smartSearch = null,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function register(): void {
        // GET /search
        register_rest_route( self::NAMESPACE, '/search', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'search' ],
            'permission_callback' => [ $this, 'permUploadFiles' ],
            'args'                => $this->searchArgs(),
        ] );

        // GET /search/filters
        register_rest_route( self::NAMESPACE, '/search/filters', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'listFilters' ],
            'permission_callback' => [ $this, 'permUploadFiles' ],
        ] );

        // POST /search/filters
        register_rest_route( self::NAMESPACE, '/search/filters', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'saveFilter' ],
            'permission_callback' => [ $this, 'permUploadFiles' ],
            'args'                => [
                'name'   => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'params' => [
                    'type'     => 'object',
                    'required' => true,
                ],
            ],
        ] );

        // DELETE /search/filters/{id}
        register_rest_route( self::NAMESPACE, '/search/filters/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'deleteFilter' ],
            'permission_callback' => [ $this, 'permUploadFiles' ],
            'args'                => [
                'id' => [
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Callbacks
    // -------------------------------------------------------------------------

    public function search( WP_REST_Request $request ): WP_REST_Response {
        $params = [
            'q'               => (string) ( $request->get_param( 'q' )               ?? '' ),
            'folder'          => $request->get_param( 'folder' ) !== null ? (int) $request->get_param( 'folder' ) : -1,
            'type'            => (string) ( $request->get_param( 'type' )            ?? '' ),
            'date_from'       => (string) ( $request->get_param( 'date_from' )       ?? '' ),
            'date_to'         => (string) ( $request->get_param( 'date_to' )         ?? '' ),
            'size_min'        => (int)    ( $request->get_param( 'size_min' )        ?? 0 ),
            'size_max'        => (int)    ( $request->get_param( 'size_max' )        ?? 0 ),
            'missing_alt'     => (bool)   ( $request->get_param( 'missing_alt' )     ?? false ),
            'used'            => (string) ( $request->get_param( 'used' )            ?? '' ),
            'camera'          => (string) ( $request->get_param( 'camera' )          ?? '' ),
            'date_taken_from' => (string) ( $request->get_param( 'date_taken_from' ) ?? '' ),
            'date_taken_to'   => (string) ( $request->get_param( 'date_taken_to' )   ?? '' ),
            'color'           => (string) ( $request->get_param( 'color' )           ?? '' ),
            'orientation'     => (string) ( $request->get_param( 'orientation' )     ?? '' ),
            'iso'             => (string) ( $request->get_param( 'iso' )             ?? '' ),
            'aperture'        => (string) ( $request->get_param( 'aperture' )        ?? '' ),
            'focal_length'    => (string) ( $request->get_param( 'focal_length' )    ?? '' ),
            'page'            => (int)    ( $request->get_param( 'page' )            ?? 1 ),
            'per_page'        => (int)    ( $request->get_param( 'per_page' )        ?? 40 ),
        ];

        $useAi = (bool) ( $request->get_param( 'ai' ) ?? false );

        if ( $useAi && $this->smartSearch !== null ) {
            $result = $this->smartSearch->search( $params );
        } else {
            $result = $this->searchService->search( $params );
        }

        return new WP_REST_Response( [ 'success' => true, 'data' => $result ], 200 );
    }

    public function listFilters( WP_REST_Request $request ): WP_REST_Response {
        $filters = $this->searchService->listFilters( get_current_user_id() );
        return new WP_REST_Response( [ 'success' => true, 'data' => $filters ], 200 );
    }

    public function saveFilter( WP_REST_Request $request ): WP_REST_Response {
        $name   = (string) $request->get_param( 'name' );
        $params = (array)  $request->get_param( 'params' );
        $id     = $this->searchService->saveFilter( get_current_user_id(), $name, $params );

        return new WP_REST_Response( [ 'success' => true, 'data' => [ 'id' => $id ] ], 201 );
    }

    public function deleteFilter( WP_REST_Request $request ): WP_REST_Response {
        $id      = (int) $request->get_param( 'id' );
        $deleted = $this->searchService->deleteFilter( get_current_user_id(), $id );

        if ( ! $deleted ) {
            return new WP_REST_Response(
                [ 'success' => false, 'message' => __( 'Saved filter not found.', 'mediapilot-ai') ],
                404
            );
        }

        return new WP_REST_Response( [ 'success' => true, 'data' => [ 'deleted_id' => $id ] ], 200 );
    }

    public function permUploadFiles(): bool {
        return current_user_can( 'upload_files' );
    }

    // -------------------------------------------------------------------------
    // Arg definitions
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array<string, mixed>>
     */
    private function searchArgs(): array {
        return [
            'ai' => [
                'type'              => 'boolean',
                'default'           => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ],
            'q' => [
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'folder' => [
                'type'              => 'integer',
                'default'           => -1,
                'sanitize_callback' => static fn ( $v ) => (int) $v,
            ],
            'type' => [
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'date_from' => [
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'date_to' => [
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'size_min' => [
                'type'              => 'integer',
                'default'           => 0,
                'sanitize_callback' => 'absint',
            ],
            'size_max' => [
                'type'              => 'integer',
                'default'           => 0,
                'sanitize_callback' => 'absint',
            ],
            'missing_alt' => [
                'type'              => 'boolean',
                'default'           => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ],
            'used' => [
                'type'              => 'string',
                'default'           => '',
                'enum'              => [ '', 'true', 'false' ],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'camera' => [
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'date_taken_from' => [
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'date_taken_to' => [
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'color' => [
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'orientation' => [
                'type'              => 'string',
                'default'           => '',
                'enum'              => [ '', 'landscape', 'portrait', 'square' ],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'iso' => [
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'aperture' => [
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'focal_length' => [
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'page' => [
                'type'              => 'integer',
                'default'           => 1,
                'minimum'           => 1,
                'sanitize_callback' => 'absint',
            ],
            'per_page' => [
                'type'              => 'integer',
                'default'           => 40,
                'minimum'           => 1,
                'maximum'           => 100,
                'sanitize_callback' => 'absint',
            ],
        ];
    }
}
