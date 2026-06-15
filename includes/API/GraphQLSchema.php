<?php

declare(strict_types=1);

namespace MediaPilotAI\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderRepository;
use MediaPilotAI\Folder\FolderService;
use MediaPilotAI\Taxonomy\FolderTaxonomy;

/**
 * WPGraphQL extension — MediaPilot GraphQL API (S43).
 *
 * Registers custom types, queries, and mutations on the `graphql_register_types`
 * action hook, which WPGraphQL fires after its own core types are in place.
 *
 * Bails silently (no-op) if WPGraphQL is not active.
 *
 * Types registered:
 *   MediaPilotFolder          — folder node with children and nested files.
 *   MediaPilotFile            — attachment metadata shape.
 *   MediaPilotFileConnection  — paginated list of MediaPilotFile nodes.
 *   MediaPilotPageInfo        — pagination metadata.
 *   Mutation payloads  — MediaPilotCreateFolderPayload, MediaPilotMoveFolderPayload,
 *                        MediaPilotDeleteFolderPayload, MediaPilotAssignFilePayload.
 *
 * Queries:
 *   mmpFolders(userId: Int)  — full folder tree.
 *   mmpFolder(id: ID!)       — single folder with children and files.
 *
 * Mutations:
 *   mmpCreateFolder(name, parentId, userId)
 *   mmpMoveFolder(id, newParentId)
 *   mmpDeleteFolder(id, recursive)
 *   mmpAssignFile(fileId, folderId)
 *
 * @package MediaPilotAI\API
 * @since   1.0.0
 */
class GraphQLSchema {

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct(
        private readonly FolderRepository $folderRepository,
        private readonly FolderService    $folderService,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function register(): void {
        if ( ! function_exists( 'register_graphql_object_type' ) ) {
            return; // WPGraphQL not active.
        }

        add_action( 'graphql_register_types', [ $this, 'registerTypes' ] );
    }

    // -------------------------------------------------------------------------
    // Types, queries and mutations
    // -------------------------------------------------------------------------

    public function registerTypes(): void {
        $this->registerMediaPilotPageInfoType();
        $this->registerMediaPilotFileType();
        $this->registerMediaPilotFileConnectionType();
        $this->registerMediaPilotFolderType();
        $this->registerMutationPayloadTypes();
        $this->registerQueries();
        $this->registerMutations();
    }

    // -------------------------------------------------------------------------
    // Type definitions
    // -------------------------------------------------------------------------

    private function registerMediaPilotPageInfoType(): void {
        register_graphql_object_type( 'MediaPilotPageInfo', [
            'description' => __( 'Pagination metadata for an MediaPilot file list.', 'mediapilot-ai'),
            'fields'      => [
                'total'       => [
                    'type'        => 'Int',
                    'description' => __( 'Total number of files matching the query.', 'mediapilot-ai'),
                ],
                'hasNextPage' => [
                    'type'        => 'Boolean',
                    'description' => __( 'Whether more files exist after the current page.', 'mediapilot-ai'),
                ],
            ],
        ] );
    }

    private function registerMediaPilotFileType(): void {
        register_graphql_object_type( 'MediaPilotFile', [
            'description' => __( 'A WordPress media attachment inside an MediaPilot folder.', 'mediapilot-ai'),
            'fields'      => [
                'id'         => [
                    'type'        => [ 'non_null' => 'ID' ],
                    'description' => __( 'Attachment post ID.', 'mediapilot-ai'),
                ],
                'databaseId' => [
                    'type'        => 'Int',
                    'description' => __( 'Numeric attachment post ID.', 'mediapilot-ai'),
                ],
                'title'      => [
                    'type'        => 'String',
                    'description' => __( 'Attachment title (post_title).', 'mediapilot-ai'),
                ],
                'url'        => [
                    'type'        => 'String',
                    'description' => __( 'Direct URL to the attachment file.', 'mediapilot-ai'),
                ],
                'mimeType'   => [
                    'type'        => 'String',
                    'description' => __( 'MIME type, e.g. "image/jpeg".', 'mediapilot-ai'),
                ],
                'fileSize'   => [
                    'type'        => 'Int',
                    'description' => __( 'File size in bytes.', 'mediapilot-ai'),
                ],
                'date'       => [
                    'type'        => 'String',
                    'description' => __( 'Upload date (Y-m-d).', 'mediapilot-ai'),
                ],
                'altText'    => [
                    'type'        => 'String',
                    'description' => __( 'Image ALT text (_wp_attachment_image_alt).', 'mediapilot-ai'),
                ],
            ],
        ] );
    }

    private function registerMediaPilotFileConnectionType(): void {
        register_graphql_object_type( 'MediaPilotFileConnection', [
            'description' => __( 'Paginated list of MediaPilotFile nodes.', 'mediapilot-ai'),
            'fields'      => [
                'nodes'    => [
                    'type'        => [ 'list_of' => 'MediaPilotFile' ],
                    'description' => __( 'The file nodes on this page.', 'mediapilot-ai'),
                ],
                'pageInfo' => [
                    'type'        => 'MediaPilotPageInfo',
                    'description' => __( 'Pagination metadata.', 'mediapilot-ai'),
                ],
            ],
        ] );
    }

    private function registerMediaPilotFolderType(): void {
        register_graphql_object_type( 'MediaPilotFolder', [
            'description' => __( 'An MediaPilot folder (mdpai_folder taxonomy term).', 'mediapilot-ai'),
            'fields'      => [
                'id'       => [
                    'type'        => [ 'non_null' => 'ID' ],
                    'description' => __( 'Folder term ID.', 'mediapilot-ai'),
                ],
                'termId'   => [
                    'type'        => 'Int',
                    'description' => __( 'Numeric folder term ID.', 'mediapilot-ai'),
                ],
                'name'     => [
                    'type'        => 'String',
                    'description' => __( 'Folder name.', 'mediapilot-ai'),
                ],
                'slug'     => [
                    'type'        => 'String',
                    'description' => __( 'Folder slug.', 'mediapilot-ai'),
                ],
                'parent'   => [
                    'type'        => 'Int',
                    'description' => __( 'Parent folder term ID (0 = root).', 'mediapilot-ai'),
                ],
                'color'    => [
                    'type'        => 'String',
                    'description' => __( 'Folder colour hex (mdpai_folder_color meta).', 'mediapilot-ai'),
                ],
                'count'    => [
                    'type'        => 'Int',
                    'description' => __( 'Number of files directly in this folder.', 'mediapilot-ai'),
                ],
                'children' => [
                    'type'        => [ 'list_of' => 'MediaPilotFolder' ],
                    'description' => __( 'Direct child folders.', 'mediapilot-ai'),
                    'resolve'     => function ( array $folder ): array {
                        $children = $this->folderRepository->getChildren( (int) $folder['termId'] );
                        return array_map( [ $this, 'normaliseFolderNode' ], $children );
                    },
                ],
                'files'    => [
                    'type'        => 'MediaPilotFileConnection',
                    'description' => __( 'Paginated files in this folder.', 'mediapilot-ai'),
                    'args'        => [
                        'first'  => [
                            'type'        => 'Int',
                            'description' => __( 'Number of files to return (max 100).', 'mediapilot-ai'),
                        ],
                        'after'  => [
                            'type'        => 'String',
                            'description' => __( 'Offset cursor (numeric page index, 1-based).', 'mediapilot-ai'),
                        ],
                        'search' => [
                            'type'        => 'String',
                            'description' => __( 'Search term to filter files by title.', 'mediapilot-ai'),
                        ],
                    ],
                    'resolve'     => function ( array $folder, array $args ): array {
                        return $this->resolveFiles( (int) $folder['termId'], $args );
                    },
                ],
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Mutation payload types
    // -------------------------------------------------------------------------

    private function registerMutationPayloadTypes(): void {
        register_graphql_object_type( 'MediaPilotCreateFolderPayload', [
            'description' => __( 'Payload returned after mmpCreateFolder.', 'mediapilot-ai'),
            'fields'      => [
                'folder'  => [ 'type' => 'MediaPilotFolder', 'description' => __( 'The newly created folder.', 'mediapilot-ai') ],
                'success' => [ 'type' => 'Boolean' ],
            ],
        ] );

        register_graphql_object_type( 'MediaPilotMoveFolderPayload', [
            'description' => __( 'Payload returned after mmpMoveFolder.', 'mediapilot-ai'),
            'fields'      => [
                'folder'  => [ 'type' => 'MediaPilotFolder', 'description' => __( 'The moved folder with its new parent.', 'mediapilot-ai') ],
                'success' => [ 'type' => 'Boolean' ],
            ],
        ] );

        register_graphql_object_type( 'MediaPilotDeleteFolderPayload', [
            'description' => __( 'Payload returned after mmpDeleteFolder.', 'mediapilot-ai'),
            'fields'      => [
                'deletedId' => [ 'type' => 'ID',      'description' => __( 'The term ID of the deleted folder.', 'mediapilot-ai') ],
                'success'   => [ 'type' => 'Boolean' ],
            ],
        ] );

        register_graphql_object_type( 'MediaPilotAssignFilePayload', [
            'description' => __( 'Payload returned after mmpAssignFile.', 'mediapilot-ai'),
            'fields'      => [
                'fileId'   => [ 'type' => 'ID',      'description' => __( 'The assigned attachment ID.', 'mediapilot-ai') ],
                'folderId' => [ 'type' => 'ID',      'description' => __( 'The target folder term ID.', 'mediapilot-ai') ],
                'success'  => [ 'type' => 'Boolean' ],
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    private function registerQueries(): void {
        // mmpFolders — full folder tree.
        register_graphql_field( 'RootQuery', 'mmpFolders', [
            'type'        => [ 'list_of' => 'MediaPilotFolder' ],
            'description' => __( 'List all MediaPilot folders as a nested tree.', 'mediapilot-ai'),
            'args'        => [
                'userId' => [
                    'type'        => 'Int',
                    'description' => __( 'Filter to folders owned by a specific user (0 = global).', 'mediapilot-ai'),
                ],
            ],
            'resolve'     => function ( $root, array $args ): array {
                $this->requireCap( 'upload_files' );

                $userId = isset( $args['userId'] ) ? (int) $args['userId'] : 0;
                $tree   = $this->folderRepository->getTree( $userId );
                return $this->normaliseFolderTree( $tree );
            },
        ] );

        // mmpFolder — single folder by ID.
        register_graphql_field( 'RootQuery', 'mmpFolder', [
            'type'        => 'MediaPilotFolder',
            'description' => __( 'Retrieve a single MediaPilot folder by its term ID.', 'mediapilot-ai'),
            'args'        => [
                'id' => [
                    'type'        => [ 'non_null' => 'ID' ],
                    'description' => __( 'Folder term ID.', 'mediapilot-ai'),
                ],
            ],
            'resolve'     => function ( $root, array $args ): ?array {
                $this->requireCap( 'upload_files' );

                $folder = $this->folderRepository->getById( absint( $args['id'] ) );
                return $folder ? $this->normaliseFolderNode( $folder ) : null;
            },
        ] );
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    private function registerMutations(): void {
        // mmpCreateFolder
        register_graphql_mutation( 'mmpCreateFolder', [
            'inputFields'         => [
                'name'     => [ 'type' => [ 'non_null' => 'String' ], 'description' => __( 'Folder name.', 'mediapilot-ai') ],
                'parentId' => [ 'type' => 'Int', 'description' => __( 'Parent folder term ID (0 = top-level).', 'mediapilot-ai') ],
                'userId'   => [ 'type' => 'Int', 'description' => __( 'Owner user ID (0 = global).', 'mediapilot-ai') ],
            ],
            'outputFields'        => [
                'folder'  => [ 'type' => 'MediaPilotFolder' ],
                'success' => [ 'type' => 'Boolean' ],
            ],
            'mutateAndGetPayload' => function ( array $input ): array {
                $this->requireCap( 'manage_mdpai_folders' );

                $name     = (string) ( $input['name']     ?? '' );
                $parentId = (int)    ( $input['parentId'] ?? 0 );
                $userId   = (int)    ( $input['userId']   ?? get_current_user_id() );

                try {
                    $termId = $this->folderService->createFolder( $name, $parentId, $userId );
                    $folder = $this->folderRepository->getById( $termId );
                    return [
                        'folder'  => $folder ? $this->normaliseFolderNode( $folder ) : null,
                        'success' => true,
                    ];
                } catch ( \Exception $e ) {
                    throw new \GraphQL\Error\UserError( $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }
            },
        ] );

        // mmpMoveFolder
        register_graphql_mutation( 'mmpMoveFolder', [
            'inputFields'         => [
                'id'          => [ 'type' => [ 'non_null' => 'ID' ], 'description' => __( 'Folder term ID to move.', 'mediapilot-ai') ],
                'newParentId' => [ 'type' => [ 'non_null' => 'Int' ], 'description' => __( 'Target parent term ID (0 = top-level).', 'mediapilot-ai') ],
            ],
            'outputFields'        => [
                'folder'  => [ 'type' => 'MediaPilotFolder' ],
                'success' => [ 'type' => 'Boolean' ],
            ],
            'mutateAndGetPayload' => function ( array $input ): array {
                $this->requireCap( 'manage_mdpai_folders' );

                $termId      = absint( $input['id'] );
                $newParentId = (int) ( $input['newParentId'] ?? 0 );

                try {
                    $success = $this->folderService->moveFolder( $termId, $newParentId );
                    $folder  = $this->folderRepository->getById( $termId );
                    return [
                        'folder'  => $folder ? $this->normaliseFolderNode( $folder ) : null,
                        'success' => $success,
                    ];
                } catch ( \Exception $e ) {
                    throw new \GraphQL\Error\UserError( $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }
            },
        ] );

        // mmpDeleteFolder
        register_graphql_mutation( 'mmpDeleteFolder', [
            'inputFields'         => [
                'id'        => [ 'type' => [ 'non_null' => 'ID' ], 'description' => __( 'Folder term ID to delete.', 'mediapilot-ai') ],
                'recursive' => [ 'type' => 'Boolean', 'description' => __( 'Delete child folders recursively (default false).', 'mediapilot-ai') ],
            ],
            'outputFields'        => [
                'deletedId' => [ 'type' => 'ID' ],
                'success'   => [ 'type' => 'Boolean' ],
            ],
            'mutateAndGetPayload' => function ( array $input ): array {
                $this->requireCap( 'manage_mdpai_folders' );

                $termId    = absint( $input['id'] );
                $recursive = (bool) ( $input['recursive'] ?? false );

                $success = $this->folderService->deleteFolder( $termId, $recursive );
                return [
                    'deletedId' => $termId,
                    'success'   => $success,
                ];
            },
        ] );

        // mmpAssignFile
        register_graphql_mutation( 'mmpAssignFile', [
            'inputFields'         => [
                'fileId'   => [ 'type' => [ 'non_null' => 'ID' ], 'description' => __( 'Attachment post ID.', 'mediapilot-ai') ],
                'folderId' => [ 'type' => [ 'non_null' => 'ID' ], 'description' => __( 'Target folder term ID.', 'mediapilot-ai') ],
            ],
            'outputFields'        => [
                'fileId'   => [ 'type' => 'ID' ],
                'folderId' => [ 'type' => 'ID' ],
                'success'  => [ 'type' => 'Boolean' ],
            ],
            'mutateAndGetPayload' => function ( array $input ): array {
                $this->requireCap( 'upload_files' );

                $fileId   = absint( $input['fileId'] );
                $folderId = absint( $input['folderId'] );

                if ( ! current_user_can( 'edit_post', $fileId ) ) {
                    throw new \GraphQL\Error\UserError( __( 'You are not allowed to edit this file.', 'mediapilot-ai') ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }

                $result = wp_set_object_terms( $fileId, [ $folderId ], FolderTaxonomy::TAXONOMY );

                if ( is_wp_error( $result ) ) {
                    throw new \GraphQL\Error\UserError( $result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }

                return [
                    'fileId'   => $fileId,
                    'folderId' => $folderId,
                    'success'  => true,
                ];
            },
        ] );
    }

    // -------------------------------------------------------------------------
    // Resolvers
    // -------------------------------------------------------------------------

    /**
     * Resolve the `files` field on an MediaPilotFolder node.
     *
     * @param  int   $folderId
     * @param  array<string, mixed> $args  GraphQL field arguments.
     * @return array{ nodes: array<int, array<string, mixed>>, pageInfo: array<string, mixed> }
     */
    private function resolveFiles( int $folderId, array $args ): array {
        $perPage = min( 100, max( 1, (int) ( $args['first'] ?? 20 ) ) );
        $page    = max( 1, (int) ( $args['after'] ?? 1 ) );
        $search  = isset( $args['search'] ) ? (string) $args['search'] : '';

        $queryArgs = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $perPage,
            'paged'          => $page,
            'orderby'        => 'post_title',
            'order'          => 'ASC',
            'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                [
                    'taxonomy' => FolderTaxonomy::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => [ $folderId ],
                    'operator' => 'IN',
                ],
            ],
        ];

        if ( '' !== $search ) {
            $queryArgs['s'] = $search;
        }

        $query = new \WP_Query( $queryArgs );
        $nodes = [];

        foreach ( $query->posts as $post ) {
            if ( ! ( $post instanceof \WP_Post ) ) {
                continue;
            }
            $nodes[] = $this->normaliseFile( $post );
        }

        $total   = (int) $query->found_posts;
        $pages   = (int) $query->max_num_pages;

        return [
            'nodes'    => $nodes,
            'pageInfo' => [
                'total'       => $total,
                'hasNextPage' => $page < $pages,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Data normalisers
    // -------------------------------------------------------------------------

    /**
     * Normalise a raw folder node (from FolderRepository) to the GraphQL shape.
     *
     * @param  array<string, mixed> $folder
     * @return array<string, mixed>
     */
    private function normaliseFolderNode( array $folder ): array {
        return [
            'id'       => (string) ( $folder['id']      ?? 0 ),
            'termId'   => (int)    ( $folder['id']      ?? 0 ),
            'name'     => (string) ( $folder['name']    ?? '' ),
            'slug'     => (string) ( $folder['slug']    ?? '' ),
            'parent'   => (int)    ( $folder['parent']  ?? 0 ),
            'color'    => (string) ( $folder['color']   ?? '#94a3b8' ),
            'count'    => (int)    ( $folder['count']   ?? 0 ),
            'children' => [], // resolved lazily by the `children` field resolver
        ];
    }

    /**
     * Recursively normalise an entire folder tree.
     *
     * @param  array<int, array<string, mixed>> $tree
     * @return array<int, array<string, mixed>>
     */
    private function normaliseFolderTree( array $tree ): array {
        $result = [];
        foreach ( $tree as $node ) {
            $normalised             = $this->normaliseFolderNode( $node );
            $normalised['children'] = $this->normaliseFolderTree( (array) ( $node['children'] ?? [] ) );
            $result[]               = $normalised;
        }
        return $result;
    }

    /**
     * Normalise a WP_Post attachment into the MediaPilotFile GraphQL shape.
     *
     * @param  \WP_Post $post
     * @return array<string, mixed>
     */
    private function normaliseFile( \WP_Post $post ): array {
        $meta     = wp_get_attachment_metadata( $post->ID );
        $fileSize = is_array( $meta ) && isset( $meta['filesize'] )
            ? (int) $meta['filesize']
            : (int) get_post_meta( $post->ID, '_wp_attachment_filesize', true );

        return [
            'id'         => (string) $post->ID,
            'databaseId' => $post->ID,
            'title'      => $post->post_title,
            'url'        => (string) wp_get_attachment_url( $post->ID ),
            'mimeType'   => $post->post_mime_type,
            'fileSize'   => $fileSize,
            'date'       => get_the_date( 'Y-m-d', $post->ID ),
            'altText'    => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
        ];
    }

    // -------------------------------------------------------------------------
    // Permission helpers
    // -------------------------------------------------------------------------

    /**
     * Throw a GraphQL UserError if the current user lacks a capability.
     *
     * @param  string $cap  WordPress capability slug.
     * @throws \GraphQL\Error\UserError
     */
    private function requireCap( string $cap ): void {
        if ( ! current_user_can( $cap ) ) {
            throw new \GraphQL\Error\UserError( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                /* translators: %s: capability name */
                sprintf( __( 'You do not have permission to perform this action (%s).', 'mediapilot-ai'), $cap )  // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message, not HTML output
            );
        }
    }
}
