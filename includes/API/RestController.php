<?php

declare(strict_types=1);

namespace MediaPilotAI\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderRepository;
use MediaPilotAI\Folder\FolderService;
use MediaPilotAI\Folder\ZipService;
use MediaPilotAI\Media\BatchMetaService;
use MediaPilotAI\Media\MediaService;
use MediaPilotAI\Taxonomy\FolderTaxonomy;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Registers and handles all REST API routes under /wp-json/mediapilot/v1/.
 *
 * Namespace  : mediapilot/v1
 * Base URL   : /wp-json/mediapilot/v1/
 *
 * All endpoints:
 *  - Authenticate via WP REST API's built-in nonce system (X-WP-Nonce header).
 *  - Return { success: true, data: ... } on success.
 *  - Return { success: false, code: string, message: string } on error.
 *  - Use WP_REST_Response with appropriate HTTP status codes.
 *  - Apply filter `mdpai_rest_folder_response` on every folder data response.
 *
 * @package MediaPilotAI\API
 * @since   1.0.0
 */
class RestController {

    private const NAMESPACE = 'mediapilot/v1';

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct(
        private readonly FolderService    $folderService,
        private readonly FolderRepository $folderRepository,
        private readonly MediaService     $mediaService,
        private readonly ZipService       $zipService,
        private readonly BatchMetaService $batchMetaService,
    ) {}

    // -------------------------------------------------------------------------
    // Route Registration
    // -------------------------------------------------------------------------

    /**
     * Register all REST routes. Called on the `rest_api_init` action.
     */
    public function register(): void {
        $this->registerFolderRoutes();
        $this->registerFileRoutes();
        $this->registerUserPrefRoutes();
        $this->registerBatchMetaRoutes();
    }

    /**
     * Register all /folders routes.
     */
    private function registerFolderRoutes(): void {
        // GET /folders
        register_rest_route(
            self::NAMESPACE,
            '/folders',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'getFolders' ],
                'permission_callback' => [ $this, 'permUploadFiles' ],
                'args'                => [
                    'user_id' => [
                        'type'              => 'integer',
                        'default'           => 0,
                        'sanitize_callback' => 'absint',
                    ],
                    'global' => [
                        'type'              => 'integer',
                        'default'           => 0,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        // POST /folders
        register_rest_route(
            self::NAMESPACE,
            '/folders',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'createFolder' ],
                'permission_callback' => [ $this, 'permManageFolders' ],
                'args'                => [
                    'name' => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'parent_id' => [
                        'type'              => 'integer',
                        'default'           => 0,
                        'sanitize_callback' => 'absint',
                    ],
                    'color' => [
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        // GET /folders/{id}
        register_rest_route(
            self::NAMESPACE,
            '/folders/(?P<id>\d+)',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'getFolder' ],
                'permission_callback' => [ $this, 'permUploadFiles' ],
                'args'                => [
                    'id' => [
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        // PUT /folders/{id}
        register_rest_route(
            self::NAMESPACE,
            '/folders/(?P<id>\d+)',
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'updateFolder' ],
                'permission_callback' => [ $this, 'permManageFolders' ],
                'args'                => [
                    'id' => [
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'name' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'color' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'parent_id' => [
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        // DELETE /folders/{id}
        register_rest_route(
            self::NAMESPACE,
            '/folders/(?P<id>\d+)',
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'deleteFolder' ],
                'permission_callback' => [ $this, 'permManageFolders' ],
                'args'                => [
                    'id' => [
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'recursive' => [
                        'type'              => 'boolean',
                        'default'           => false,
                        'sanitize_callback' => 'rest_sanitize_boolean',
                    ],
                ],
            ]
        );

        // POST /folders/{id}/move
        register_rest_route(
            self::NAMESPACE,
            '/folders/(?P<id>\d+)/move',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'moveFolder' ],
                'permission_callback' => [ $this, 'permManageFolders' ],
                'args'                => [
                    'id' => [
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'parent_id' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        // GET /folders/{id}/files
        register_rest_route(
            self::NAMESPACE,
            '/folders/(?P<id>-?\d+)/files',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'getFolderFiles' ],
                'permission_callback' => [ $this, 'permUploadFiles' ],
                'args'                => [
                    'id' => [
                        'type' => 'integer',
                    ],
                    'sort' => [
                        'type'              => 'string',
                        'default'           => 'date',
                        'enum'              => [ 'name', 'date', 'modified', 'author', 'size' ],
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'order' => [
                        'type'              => 'string',
                        'default'           => 'desc',
                        'enum'              => [ 'asc', 'desc' ],
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
                    'search' => [
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        // GET /folders/{id}/zip
        register_rest_route(
            self::NAMESPACE,
            '/folders/(?P<id>\d+)/zip',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'downloadZip' ],
                'permission_callback' => [ $this, 'permUploadFiles' ],
                'args'                => [
                    'id' => [
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    /**
     * Register all /files routes.
     */
    private function registerFileRoutes(): void {
        // POST /files/assign
        register_rest_route(
            self::NAMESPACE,
            '/files/assign',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'assignFile' ],
                'permission_callback' => [ $this, 'permUploadFiles' ],
                'args'                => [
                    'attachment_id' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ],
                    'folder_id' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        // POST /files/move
        register_rest_route(
            self::NAMESPACE,
            '/files/move',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'moveFiles' ],
                'permission_callback' => [ $this, 'permUploadFiles' ],
                'args'                => [
                    'attachment_ids' => [
                        'type'     => 'array',
                        'required' => true,
                        'items'    => [ 'type' => 'integer' ],
                    ],
                    'folder_id' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        // POST /files/zip — bulk download of selected attachment IDs as a ZIP.
        register_rest_route(
            self::NAMESPACE,
            '/files/zip',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'downloadFilesZip' ],
                'permission_callback' => [ $this, 'permUploadFiles' ],
                'args'                => [
                    'attachment_ids' => [
                        'type'     => 'array',
                        'required' => true,
                        'items'    => [ 'type' => 'integer' ],
                    ],
                ],
            ]
        );

        // GET /files/search
        register_rest_route(
            self::NAMESPACE,
            '/files/search',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'searchFiles' ],
                'permission_callback' => [ $this, 'permUploadFiles' ],
                'args'                => [
                    'q' => [
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'folder_id' => [
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'type' => [
                        'type'              => 'string',
                        'enum'              => [ 'image', 'video', 'audio', 'document' ],
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
                ],
            ]
        );
    }

    /**
     * Register all /user-prefs routes.
     */
    private function registerUserPrefRoutes(): void {
        // GET /user-prefs
        register_rest_route(
            self::NAMESPACE,
            '/user-prefs',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'getUserPrefs' ],
                'permission_callback' => [ $this, 'permRead' ],
            ]
        );

        // PUT /user-prefs
        register_rest_route(
            self::NAMESPACE,
            '/user-prefs',
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'updateUserPrefs' ],
                'permission_callback' => [ $this, 'permRead' ],
                'args'                => [
                    'folder_id' => [
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'sort_files' => [
                        'type'              => 'string',
                        'enum'              => [ 'name', 'date', 'modified', 'author', 'size' ],
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'sort_dir' => [
                        'type'              => 'string',
                        'enum'              => [ 'asc', 'desc' ],
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'sidebar_w' => [
                        'type'    => 'integer',
                        'minimum' => 160,
                        'maximum' => 480,
                    ],
                    'ui_theme' => [
                        'type'              => 'string',
                        'enum'              => [ 'default', 'win11', 'dropbox' ],
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );
    }

    /**
     * Register /files/meta-list and /files/batch-meta routes.
     */
    private function registerBatchMetaRoutes(): void {
        // POST /files/meta-list — fetch editable metadata for an array of IDs.
        register_rest_route(
            self::NAMESPACE,
            '/files/meta-list',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'getMetaList' ],
                'permission_callback' => [ $this, 'permUploadFiles' ],
                'args'                => [
                    'ids' => [
                        'type'     => 'array',
                        'required' => true,
                        'items'    => [ 'type' => 'integer' ],
                    ],
                ],
            ]
        );

        // POST /files/batch-meta — save bulk metadata for multiple attachments.
        register_rest_route(
            self::NAMESPACE,
            '/files/batch-meta',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'saveBatchMeta' ],
                'permission_callback' => [ $this, 'permUploadFiles' ],
                'args'                => [
                    'items' => [
                        'type'     => 'array',
                        'required' => true,
                        'items'    => [
                            'type'       => 'object',
                            'properties' => [
                                'id'          => [ 'type' => 'integer' ],
                                'alt'         => [ 'type' => 'string' ],
                                'title'       => [ 'type' => 'string' ],
                                'caption'     => [ 'type' => 'string' ],
                                'description' => [ 'type' => 'string' ],
                            ],
                            'required' => [ 'id' ],
                        ],
                    ],
                ],
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Folder Callbacks
    // -------------------------------------------------------------------------

    /**
     * GET /folders
     *
     * Returns the full folder tree for the current user (or a specified user
     * for administrators).
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function getFolders( WP_REST_Request $request ): WP_REST_Response {
        $settings        = (array) get_option( 'mdpai_settings', [] );
        $folderMode      = isset( $settings['folder_mode'] ) ? (string) $settings['folder_mode'] : 'global';
        $requestedUserId = (int) $request->get_param( 'user_id' );
        $viewGlobal      = (bool) $request->get_param( 'global' );

        if ( 'global' === $folderMode ) {
            // Global mode: all users share userId=0 tree, ignore any user_id param.
            $userId = 0;
        } elseif ( $viewGlobal && current_user_can( 'manage_options' ) ) {
            // Admin requests global/shared tree in per_user mode (admin override).
            $userId = 0;
        } elseif ( $requestedUserId > 0 ) {
            // Admin-only: view a specific user's tree.
            if ( ! current_user_can( 'manage_options' ) ) {
                return $this->error( 'rest_forbidden', __( 'You do not have permission to view another user\'s folders.', 'mediapilot-ai'), 403 );
            }
            $userId = $requestedUserId;
        } else {
            // Per-user mode default: current user's own tree.
            $userId = get_current_user_id();
        }

        $tree = $this->folderService->getTree( $userId );

        $count = $this->countTreeNodes( $tree );

        $data = apply_filters(
            'mdpai_rest_folder_response',
            [
                'tree'  => $tree,
                'total' => $count,
            ],
            $request
        );

        $response = $this->success( $data );
        $response->header( 'X-MediaPilot-Total', (string) $count );

        return $response;
    }

    /**
     * POST /folders
     *
     * Creates a new folder.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function createFolder( WP_REST_Request $request ): WP_REST_Response {
        $name     = (string) $request->get_param( 'name' );
        $parentId = (int) $request->get_param( 'parent_id' );
        $color    = (string) $request->get_param( 'color' );

        $settings   = (array) get_option( 'mdpai_settings', [] );
        $folderMode = isset( $settings['folder_mode'] ) ? (string) $settings['folder_mode'] : 'global';
        // In global mode folders belong to the shared tree (userId=0).
        $userId = ( 'global' === $folderMode ) ? 0 : get_current_user_id();

        try {
            $termId = $this->folderService->createFolder( $name, $parentId, $userId );
        } catch ( \InvalidArgumentException $e ) {
            return $this->error( 'mdpai_invalid_argument', $e->getMessage(), 400 );
        } catch ( \RuntimeException $e ) {
            return $this->error( 'mdpai_server_error', $e->getMessage(), 500 );
        }

        // Optionally apply color.
        if ( '' !== $color ) {
            $this->folderService->updateColor( $termId, $color );
        }

        $folder = $this->folderRepository->getById( $termId );

        $data = apply_filters(
            'mdpai_rest_folder_response',
            [ 'folder' => $this->formatFolder( $folder ?? [] ) ],
            $request
        );

        return $this->success( $data, 201 );
    }

    /**
     * GET /folders/{id}
     *
     * Returns a single folder by term ID.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function getFolder( WP_REST_Request $request ): WP_REST_Response {
        $id = (int) $request->get_param( 'id' );

        $folder = $this->folderRepository->getById( $id );

        if ( null === $folder ) {
            return $this->error( 'mdpai_folder_not_found', __( 'Folder not found.', 'mediapilot-ai'), 404 );
        }

        $data = apply_filters(
            'mdpai_rest_folder_response',
            [ 'folder' => $this->formatFolder( $folder ) ],
            $request
        );

        return $this->success( $data );
    }

    /**
     * PUT /folders/{id}
     *
     * Updates a folder's name, color, and/or parent.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function updateFolder( WP_REST_Request $request ): WP_REST_Response {
        $id = (int) $request->get_param( 'id' );

        $folder = $this->folderRepository->getById( $id );

        if ( null === $folder ) {
            return $this->error( 'mdpai_folder_not_found', __( 'Folder not found.', 'mediapilot-ai'), 404 );
        }

        try {
            $name     = $request->get_param( 'name' );
            $color    = $request->get_param( 'color' );
            $parentId = $request->get_param( 'parent_id' );

            if ( null !== $name && '' !== $name ) {
                $this->folderService->renameFolder( $id, (string) $name );
            }

            if ( null !== $color && '' !== $color ) {
                $this->folderService->updateColor( $id, (string) $color );
            }

            if ( null !== $parentId ) {
                $this->folderService->moveFolder( $id, (int) $parentId );
            }
        } catch ( \InvalidArgumentException $e ) {
            return $this->error( 'mdpai_invalid_argument', $e->getMessage(), 422 );
        }

        $updated = $this->folderRepository->getById( $id );

        $data = apply_filters(
            'mdpai_rest_folder_response',
            [ 'folder' => $this->formatFolder( $updated ?? [] ) ],
            $request
        );

        return $this->success( $data );
    }

    /**
     * DELETE /folders/{id}
     *
     * Deletes a folder. Optionally recursive.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function deleteFolder( WP_REST_Request $request ): WP_REST_Response {
        $id        = (int) $request->get_param( 'id' );
        $recursive = (bool) rest_sanitize_boolean( $request->get_param( 'recursive' ) );

        $folder = $this->folderRepository->getById( $id );

        if ( null === $folder ) {
            return $this->error( 'mdpai_folder_not_found', __( 'Folder not found.', 'mediapilot-ai'), 404 );
        }

        $this->folderService->deleteFolder( $id, $recursive );

        return $this->success( [ 'deleted' => true, 'id' => $id ] );
    }

    /**
     * POST /folders/{id}/move
     *
     * Moves a folder to a new parent.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function moveFolder( WP_REST_Request $request ): WP_REST_Response {
        $id       = (int) $request->get_param( 'id' );
        $parentId = (int) $request->get_param( 'parent_id' );

        $folder = $this->folderRepository->getById( $id );

        if ( null === $folder ) {
            return $this->error( 'mdpai_folder_not_found', __( 'Folder not found.', 'mediapilot-ai'), 404 );
        }

        try {
            $this->folderService->moveFolder( $id, $parentId );
        } catch ( \InvalidArgumentException $e ) {
            return $this->error( 'mdpai_circular_move', $e->getMessage(), 422 );
        }

        $updated = $this->folderRepository->getById( $id );

        $data = apply_filters(
            'mdpai_rest_folder_response',
            [ 'folder' => $this->formatFolder( $updated ?? [] ) ],
            $request
        );

        return $this->success( $data );
    }

    /**
     * GET /folders/{id}/files
     *
     * Returns paginated attachments in a folder.
     *
     * id > 0  : attachments assigned to that folder term.
     * id == 0 : attachments NOT in any mdpai_folder term (Uncategorized).
     * id == -1: all attachments regardless of folder.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function getFolderFiles( WP_REST_Request $request ): WP_REST_Response {
        $id      = (int) $request->get_param( 'id' );
        $sort    = (string) $request->get_param( 'sort' );
        $order   = (string) $request->get_param( 'order' );
        $page    = max( 1, (int) $request->get_param( 'page' ) );
        $perPage = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
        $search  = (string) $request->get_param( 'search' );

        $args = [
            'sort'     => $sort,
            'order'    => $order,
            'page'     => $page,
            'per_page' => $perPage,
            'search'   => $search,
        ];

        $result = $this->mediaService->getFilesInFolder( $id, $args );

        $total      = (int) $result['total'];
        $totalPages = (int) $result['pages'];

        $response = $this->success(
            [
                'files' => $result['files'],
                'total' => $total,
                'pages' => $totalPages,
            ]
        );

        $response->header( 'X-WP-Total', (string) $total );
        $response->header( 'X-WP-TotalPages', (string) $totalPages );

        return $response;
    }

    /**
     * GET /folders/{id}/zip
     *
     * Streams a ZIP archive of all files in the folder (recursively) to the
     * browser. This method never returns normally — it either streams the file
     * and exits, or returns a WP_REST_Response error.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response  Only returned on error; success exits early.
     */
    public function downloadZip( WP_REST_Request $request ): WP_REST_Response {
        $folderId = (int) $request->get_param( 'id' );

        // Validate the folder exists before handing off to ZipService.
        $folder = $this->folderRepository->getById( $folderId );

        if ( null === $folder ) {
            return $this->error(
                'mdpai_folder_not_found',
                __( 'Folder not found.', 'mediapilot-ai'),
                404
            );
        }

        try {
            // streamFolderZip() streams the file and calls exit — it never returns.
            $this->zipService->streamFolderZip( $folderId );
        } catch ( \RuntimeException $e ) {
            return $this->error(
                'mdpai_zip_error',
                $e->getMessage(),
                500
            );
        }

        // Unreachable — only here to satisfy the return-type declaration.
        // @codeCoverageIgnoreStart
        return $this->error( 'mdpai_zip_error', __( 'Unexpected error.', 'mediapilot-ai'), 500 );
        // @codeCoverageIgnoreEnd
    }

    // -------------------------------------------------------------------------
    // File Callbacks
    // -------------------------------------------------------------------------

    /**
     * POST /files/zip
     *
     * Streams a ZIP archive of the supplied attachment IDs to the browser.
     * The response streams binary data and calls exit on success.
     * On error a normal WP_REST_Response JSON error is returned.
     *
     * Body: { attachment_ids: number[] }
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response  Only returned on error; success exits early.
     */
    public function downloadFilesZip( WP_REST_Request $request ): WP_REST_Response {
        /** @var array<int> $rawIds */
        $rawIds = (array) $request->get_param( 'attachment_ids' );

        if ( empty( $rawIds ) ) {
            return $this->error(
                'mdpai_no_files',
                __( 'No attachment IDs provided.', 'mediapilot-ai'),
                400
            );
        }

        $attachmentIds = array_values(
            array_filter(
                array_map( 'absint', $rawIds ),
                static fn( int $id ): bool => $id > 0
            )
        );

        if ( empty( $attachmentIds ) ) {
            return $this->error(
                'mdpai_no_files',
                __( 'No valid attachment IDs provided.', 'mediapilot-ai'),
                400
            );
        }

        try {
            $this->zipService->streamAttachmentsZip( $attachmentIds, 'media-files' );
        } catch ( \RuntimeException $e ) {
            return $this->error( 'mdpai_zip_error', $e->getMessage(), 500 );
        }

        // Unreachable — satisfies return-type declaration.
        // @codeCoverageIgnoreStart
        return $this->error( 'mdpai_zip_error', __( 'Unexpected error.', 'mediapilot-ai'), 500 );
        // @codeCoverageIgnoreEnd
    }

    /**
     * POST /files/assign
     *
     * Assigns a single attachment to a folder.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function assignFile( WP_REST_Request $request ): WP_REST_Response {
        $attachmentId = (int) $request->get_param( 'attachment_id' );
        $folderId     = (int) $request->get_param( 'folder_id' );

        $success = $this->mediaService->assignFile( $attachmentId, $folderId );

        if ( ! $success ) {
            return $this->error(
                'mdpai_assign_failed',
                __( 'Failed to assign file to folder.', 'mediapilot-ai'),
                500
            );
        }

        return $this->success(
            [
                'attachment_id' => $attachmentId,
                'folder_id'     => $folderId,
            ]
        );
    }

    /**
     * POST /files/move
     *
     * Moves multiple attachments to a folder.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function moveFiles( WP_REST_Request $request ): WP_REST_Response {
        $attachmentIds = (array) $request->get_param( 'attachment_ids' );
        $folderId      = (int) $request->get_param( 'folder_id' );

        $moved = $this->mediaService->moveFiles( $attachmentIds, $folderId );

        return $this->success(
            [
                'moved'     => $moved,
                'folder_id' => $folderId,
            ]
        );
    }

    /**
     * GET /files/search
     *
     * Full-text search across attachments with optional folder and MIME type filters.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function searchFiles( WP_REST_Request $request ): WP_REST_Response {
        $q        = (string) $request->get_param( 'q' );
        $folderId = $request->get_param( 'folder_id' );
        $type     = (string) $request->get_param( 'type' );
        $page     = max( 1, (int) $request->get_param( 'page' ) );
        $perPage  = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );

        $queryArgs = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $perPage,
            'paged'          => $page,
            's'              => $q,
        ];

        // Folder filter.
        if ( null !== $folderId && '' !== $folderId ) {
            $folderId = absint( $folderId );
            $queryArgs['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                [
                    'taxonomy' => FolderTaxonomy::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => [ $folderId ],
                    'operator' => 'IN',
                ],
            ];
        }

        // MIME type filter.
        if ( '' !== $type ) {
            $queryArgs['post_mime_type'] = $this->mapTypeToMime( $type );
        }

        $query = $this->buildAttachmentQuery( $queryArgs );

        $files = [];
        foreach ( $query->posts as $post ) {
            if ( $post instanceof WP_Post ) {
                $files[] = $this->formatAttachment( $post );
            }
        }

        $total      = (int) $query->found_posts;
        $totalPages = (int) $query->max_num_pages;

        $response = $this->success(
            [
                'files' => $files,
                'total' => $total,
                'pages' => $totalPages,
            ]
        );

        $response->header( 'X-WP-Total', (string) $total );
        $response->header( 'X-WP-TotalPages', (string) $totalPages );

        return $response;
    }

    // -------------------------------------------------------------------------
    // User Prefs Callbacks
    // -------------------------------------------------------------------------

    /**
     * GET /user-prefs
     *
     * Returns the current user's preferences, falling back to defaults if no
     * row exists in wp_mdpai_user_prefs.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function getUserPrefs( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        $userId = get_current_user_id();

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT folder_id, sort_files, sort_dir, sidebar_w, ui_theme FROM {$wpdb->prefix}mdpai_user_prefs WHERE user_id = %d LIMIT 1",
                $userId
            ),
            ARRAY_A
        );

        if ( null === $row ) {
            $prefs = $this->getDefaultPrefs();
        } else {
            $prefs = [
                'folder_id'  => isset( $row['folder_id'] ) ? (int) $row['folder_id'] : null,
                'sort_files' => (string) $row['sort_files'],
                'sort_dir'   => (string) $row['sort_dir'],
                'sidebar_w'  => (int) $row['sidebar_w'],
                'ui_theme'   => (string) $row['ui_theme'],
            ];
        }

        return $this->success( [ 'prefs' => $prefs ] );
    }

    /**
     * PUT /user-prefs
     *
     * Upserts the current user's preferences.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function updateUserPrefs( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        $userId  = get_current_user_id();
        $current = $this->getDefaultPrefs();

        // Merge existing DB values as the base, so unset params keep their values.
        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT folder_id, sort_files, sort_dir, sidebar_w, ui_theme FROM {$wpdb->prefix}mdpai_user_prefs WHERE user_id = %d LIMIT 1",
                $userId
            ),
            ARRAY_A
        );

        if ( null !== $row ) {
            $current = [
                'folder_id'  => isset( $row['folder_id'] ) ? (int) $row['folder_id'] : null,
                'sort_files' => (string) $row['sort_files'],
                'sort_dir'   => (string) $row['sort_dir'],
                'sidebar_w'  => (int) $row['sidebar_w'],
                'ui_theme'   => (string) $row['ui_theme'],
            ];
        }

        // Apply only the params that were actually sent in the request body.
        $folderId  = $request->get_param( 'folder_id' );
        $sortFiles = $request->get_param( 'sort_files' );
        $sortDir   = $request->get_param( 'sort_dir' );
        $sidebarW  = $request->get_param( 'sidebar_w' );
        $uiTheme   = $request->get_param( 'ui_theme' );

        if ( null !== $folderId ) {
            $current['folder_id'] = absint( $folderId ) ?: null;
        }

        if ( null !== $sortFiles ) {
            $validSort = [ 'name', 'date', 'modified', 'author', 'size' ];
            if ( in_array( $sortFiles, $validSort, true ) ) {
                $current['sort_files'] = $sortFiles;
            } else {
                return $this->error( 'mdpai_invalid_param', __( 'sort_files must be one of: name, date, modified, author, size.', 'mediapilot-ai'), 400 );
            }
        }

        if ( null !== $sortDir ) {
            if ( in_array( $sortDir, [ 'asc', 'desc' ], true ) ) {
                $current['sort_dir'] = $sortDir;
            } else {
                return $this->error( 'mdpai_invalid_param', __( 'sort_dir must be asc or desc.', 'mediapilot-ai'), 400 );
            }
        }

        if ( null !== $sidebarW ) {
            $sidebarW = (int) $sidebarW;
            if ( $sidebarW >= 160 && $sidebarW <= 480 ) {
                $current['sidebar_w'] = $sidebarW;
            } else {
                return $this->error( 'mdpai_invalid_param', __( 'sidebar_w must be between 160 and 480.', 'mediapilot-ai'), 400 );
            }
        }

        if ( null !== $uiTheme ) {
            if ( in_array( $uiTheme, [ 'default', 'win11', 'dropbox' ], true ) ) {
                $current['ui_theme'] = $uiTheme;
            } else {
                return $this->error( 'mdpai_invalid_param', __( 'ui_theme must be one of: default, win11, dropbox.', 'mediapilot-ai'), 400 );
            }
        }

        $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prefix . 'mdpai_user_prefs',
            [
                'user_id'    => $userId,
                'folder_id'  => $current['folder_id'],
                'sort_files' => $current['sort_files'],
                'sort_dir'   => $current['sort_dir'],
                'sidebar_w'  => $current['sidebar_w'],
                'ui_theme'   => $current['ui_theme'],
            ],
            [ '%d', '%d', '%s', '%s', '%d', '%s' ]
        );

        return $this->success( [ 'prefs' => $current ] );
    }

    // -------------------------------------------------------------------------
    // Batch Meta Callbacks
    // -------------------------------------------------------------------------

    /**
     * POST /files/meta-list
     *
     * Returns editable metadata for a list of attachment IDs.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function getMetaList( WP_REST_Request $request ): WP_REST_Response {
        $ids   = array_map( 'absint', (array) $request->get_param( 'ids' ) );
        $items = $this->batchMetaService->getMetaList( $ids );

        return $this->success( [ 'items' => $items ] );
    }

    /**
     * POST /files/batch-meta
     *
     * Saves editable metadata (alt, title, caption, description) for multiple
     * attachments in a single request.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function saveBatchMeta( WP_REST_Request $request ): WP_REST_Response {
        $items  = (array) $request->get_param( 'items' );
        $result = $this->batchMetaService->saveBatch( $items );

        return $this->success( $result );
    }

    // -------------------------------------------------------------------------
    // Permission Callbacks
    // -------------------------------------------------------------------------

    /**
     * Requires the `upload_files` capability.
     *
     * This is the same capability WordPress core requires to open and browse the
     * Media Library (Author role and above). Endpoints that read or search media
     * are intentionally scoped to it so they expose exactly the media a user can
     * already see in wp-admin — no broader, and no narrower.
     */
    public function permUploadFiles(): bool {
        return current_user_can( 'upload_files' );
    }

    /**
     * Requires the `manage_mdpai_folders` capability (admins + editors by default).
     */
    public function permManageFolders(): bool {
        return current_user_can( 'manage_mdpai_folders' );
    }

    /**
     * Requires the `read` capability — any logged-in user.
     */
    public function permRead(): bool {
        return current_user_can( 'read' );
    }

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    /**
     * Builds a WP_REST_Response with { success: true, data: ... }.
     *
     * @param mixed $data   The payload to nest under the `data` key.
     * @param int   $status HTTP status code (default 200).
     * @return WP_REST_Response
     */
    private function success( mixed $data, int $status = 200 ): WP_REST_Response {
        /**
         * Filters the data payload of any successful MediaPilot REST response.
         *
         * Allows plugins to append, remove, or transform fields in every
         * successful response before it is serialized to JSON.
         *
         * @since 1.0.0
         *
         * @param mixed $data    The response data (array, scalar, or null).
         * @param int   $status  The HTTP status code (typically 200 or 201).
         */
        $data = apply_filters('mdpai_rest_response', $data, $status);

        return new WP_REST_Response(
            [
                'success' => true,
                'data'    => $data,
            ],
            $status
        );
    }

    /**
     * Builds a WP_REST_Response with { success: false, code: ..., message: ... }.
     *
     * @param string $code    Machine-readable error code.
     * @param string $message Human-readable error message.
     * @param int    $status  HTTP status code.
     * @return WP_REST_Response
     */
    private function error( string $code, string $message, int $status ): WP_REST_Response {
        return new WP_REST_Response(
            [
                'success' => false,
                'code'    => $code,
                'message' => $message,
            ],
            $status
        );
    }

    /**
     * Formats a folder array for REST output.
     *
     * Ensures consistent key set and correct types in every folder response.
     *
     * @param array<string, mixed> $folder  Normalised folder array from the repository.
     * @return array<string, mixed>
     */
    private function formatFolder( array $folder ): array {
        return [
            'id'       => isset( $folder['id'] ) ? (int) $folder['id'] : 0,
            'name'     => isset( $folder['name'] ) ? (string) $folder['name'] : '',
            'slug'     => isset( $folder['slug'] ) ? (string) $folder['slug'] : '',
            'parent'   => isset( $folder['parent'] ) ? (int) $folder['parent'] : 0,
            'color'    => isset( $folder['color'] ) ? (string) $folder['color'] : '#94a3b8',
            'user_id'  => isset( $folder['user_id'] ) ? (int) $folder['user_id'] : 0,
            'count'    => isset( $folder['count'] ) ? (int) $folder['count'] : 0,
            'children' => isset( $folder['children'] ) ? (array) $folder['children'] : [],
        ];
    }

    /**
     * Formats a WP_Post attachment for REST output.
     *
     * @param WP_Post $post  Attachment post object.
     * @return array<string, mixed>
     */
    private function formatAttachment( WP_Post $post ): array {
        $id        = (int) $post->ID;
        $url       = (string) wp_get_attachment_url( $id );
        $thumbnail = '';

        $thumbData = wp_get_attachment_image_src( $id, 'thumbnail' );
        if ( is_array( $thumbData ) && isset( $thumbData[0] ) ) {
            $thumbnail = (string) $thumbData[0];
        }

        $meta     = (array) wp_get_attachment_metadata( $id );
        $fileSize = 0;

        // Try to get file size from the absolute path.
        $filePath = get_attached_file( $id );
        if ( is_string( $filePath ) && file_exists( $filePath ) ) {
            $fileSize = (int) filesize( $filePath );
        }

        // Alt text.
        $alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );

        // Current folder assignment.
        $folderId = $this->folderRepository->getFileFolder( $id );

        $filePath = get_attached_file( $id );

        return [
            'id'            => $id,
            'title'         => (string) $post->post_title,
            'url'           => $url,
            'thumbnail'     => $thumbnail,
            'thumbnail_url' => $thumbnail,
            'filename'      => is_string( $filePath ) ? basename( $filePath ) : '',
            'mime_type'     => (string) $post->post_mime_type,
            'file_size'     => $fileSize,
            'date'          => (string) $post->post_date,
            'folder_id'     => $folderId,
            'alt'           => $alt,
            'caption'       => (string) $post->post_excerpt,
            'description'   => (string) $post->post_content,
        ];
    }

    /**
     * Executes a WP_Query for attachments with the supplied arguments.
     *
     * @param array<string, mixed> $args  WP_Query argument array.
     * @return WP_Query
     */
    private function buildAttachmentQuery( array $args ): WP_Query {
        // Ensure we always query attachments.
        $args['post_type']   = 'attachment';
        $args['post_status'] = 'inherit';

        return new WP_Query( $args );
    }

    /**
     * Returns default user preferences matching the wp_mdpai_user_prefs schema.
     *
     * @return array<string, mixed>
     */
    private function getDefaultPrefs(): array {
        return [
            'folder_id'  => null,
            'sort_files' => 'date',
            'sort_dir'   => 'desc',
            'sidebar_w'  => 220,
            'ui_theme'   => 'default',
        ];
    }

    /**
     * Maps the `sort` query param to the WP_Query `orderby` argument.
     *
     * @param string $sort  One of: name, date, modified, author, size.
     * @return string
     */
    private function mapSortToOrderby( string $sort ): string {
        return match ( $sort ) {
            'name'     => 'post_title',
            'date'     => 'post_date',
            'modified' => 'post_modified',
            'author'   => 'post_author',
            'size'     => 'meta_value_num',
            default    => 'post_date',
        };
    }

    /**
     * Maps the `type` query param to a post_mime_type value or array.
     *
     * @param string $type  One of: image, video, audio, document.
     * @return string|string[]
     */
    private function mapTypeToMime( string $type ): string|array {
        return match ( $type ) {
            'image'    => 'image',
            'video'    => 'video',
            'audio'    => 'audio',
            'document' => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'text/plain',
            ],
            default    => '',
        };
    }

    /**
     * Returns a flat array of all mdpai_folder term IDs currently in the database.
     *
     * Used to build the NOT IN tax_query for the Uncategorized filter.
     *
     * @return int[]
     */
    private function getAllFolderTermIds(): array {
        $terms = get_terms( [
            'taxonomy'   => FolderTaxonomy::TAXONOMY,
            'fields'     => 'ids',
            'hide_empty' => false,
            'number'     => 0,
        ] );

        if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
            return [ 0 ]; // Fallback — avoids an empty NOT IN clause.
        }

        return array_map( 'intval', $terms );
    }

    /**
     * Counts the total number of nodes in a (potentially nested) folder tree array.
     *
     * @param array<int, array<string, mixed>> $tree  Nested folder tree.
     * @return int
     */
    private function countTreeNodes( array $tree ): int {
        $count = 0;

        foreach ( $tree as $node ) {
            ++$count;
            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                $count += $this->countTreeNodes( $node['children'] );
            }
        }

        return $count;
    }
}
