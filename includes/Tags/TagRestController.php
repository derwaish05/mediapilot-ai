<?php

declare(strict_types=1);

namespace MediaPilotAI\Tags;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * REST API controller for the Smart Tags / Labels system.
 *
 * Namespace: mediapilot/v1
 *
 * Routes:
 *   GET    /tags                              — list all tags (with usage counts)
 *   POST   /tags                              — create a tag
 *   PUT    /tags/{id}                         — update tag name / color
 *   DELETE /tags/{id}                         — delete tag + all relationships
 *
 *   GET    /files/{id}/tags                   — get tags for a single attachment
 *   POST   /files/{id}/tags                   — set (replace) tags for an attachment
 *   DELETE /files/{id}/tags/{tag_id}          — remove one tag from an attachment
 *
 *   POST   /files/tags/bulk                   — bulk-assign tags to many attachments
 *
 *   GET    /folders/{id}/smart-rules          — get smart-folder rule set
 *   PUT    /folders/{id}/smart-rules          — save smart-folder rule set
 *   DELETE /folders/{id}/smart-rules          — remove smart-folder rules
 */
class TagRestController {

    private const NS = 'mediapilot/v1';

    public function __construct(
        private readonly TagRepository $tagRepo,
        private readonly TagService    $tagService,
    ) {}

    /**
     * Register all REST routes. Called inside `rest_api_init`.
     */
    public function register(): void {
        // --- Tag CRUD ---------------------------------------------------------
        register_rest_route( self::NS, '/tags', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'listTags' ],
                'permission_callback' => [ $this, 'canView' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'createTag' ],
                'permission_callback' => [ $this, 'canManage' ],
            ],
        ] );

        register_rest_route( self::NS, '/tags/(?P<id>\d+)', [
            [
                'methods'             => \WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'updateTag' ],
                'permission_callback' => [ $this, 'canManage' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'deleteTag' ],
                'permission_callback' => [ $this, 'canManage' ],
            ],
        ] );

        // --- File ↔ Tag -------------------------------------------------------
        register_rest_route( self::NS, '/files/(?P<id>\d+)/tags', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'getFileTags' ],
                'permission_callback' => [ $this, 'canView' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'setFileTags' ],
                'permission_callback' => [ $this, 'canManage' ],
            ],
        ] );

        register_rest_route( self::NS, '/files/(?P<id>\d+)/tags/(?P<tag_id>\d+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'removeFileTag' ],
                'permission_callback' => [ $this, 'canManage' ],
            ],
        ] );

        // --- Bulk tag assignment ----------------------------------------------
        register_rest_route( self::NS, '/files/tags/bulk', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'bulkTagFiles' ],
                'permission_callback' => [ $this, 'canManage' ],
            ],
        ] );

        // --- Smart folder rules (term meta) -----------------------------------
        register_rest_route( self::NS, '/folders/(?P<id>\d+)/smart-rules', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'getSmartRules' ],
                'permission_callback' => [ $this, 'canManage' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'setSmartRules' ],
                'permission_callback' => [ $this, 'canManage' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'deleteSmartRules' ],
                'permission_callback' => [ $this, 'canManage' ],
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Permission callbacks
    // -------------------------------------------------------------------------

    public function canManage(): bool {
        return current_user_can( 'manage_mdpai_folders' );
    }

    public function canView(): bool {
        return is_user_logged_in();
    }

    // -------------------------------------------------------------------------
    // Tag CRUD
    // -------------------------------------------------------------------------

    public function listTags( \WP_REST_Request $request ): \WP_REST_Response {
        $tags = $this->tagRepo->getAll( true );

        return $this->ok( array_map( [ $this, 'formatTag' ], $tags ) );
    }

    public function createTag( \WP_REST_Request $request ): \WP_REST_Response {
        $name = trim( (string) ( $request->get_param( 'name' ) ?? '' ) );

        if ( $name === '' ) {
            return $this->err( 'Tag name is required.', 400 );
        }

        $color = (string) ( $request->get_param( 'color' ) ?? '#3b82f6' );

        if ( ! preg_match( '/^#[0-9a-fA-F]{3,6}$/', $color ) ) {
            $color = '#3b82f6';
        }

        $slug = $this->tagRepo->generateUniqueSlug( $name );
        $id   = $this->tagRepo->create( $name, $slug, $color );
        $tag  = $this->tagRepo->findById( $id );

        return $this->ok( $this->formatTag( $tag ?? [] ), 201 );
    }

    public function updateTag( \WP_REST_Request $request ): \WP_REST_Response {
        $id  = (int) $request->get_param( 'id' );
        $tag = $this->tagRepo->findById( $id );

        if ( ! $tag ) {
            return $this->err( 'Tag not found.', 404 );
        }

        $data = [];

        if ( $request->has_param( 'name' ) ) {
            $name = trim( (string) $request->get_param( 'name' ) );
            if ( $name !== '' ) {
                $data['name'] = $name;
                $data['slug'] = $this->tagRepo->generateUniqueSlug( $name );
            }
        }

        if ( $request->has_param( 'color' ) ) {
            $color = (string) $request->get_param( 'color' );
            if ( preg_match( '/^#[0-9a-fA-F]{3,6}$/', $color ) ) {
                $data['color'] = $color;
            }
        }

        if ( ! empty( $data ) ) {
            $this->tagRepo->update( $id, $data );
        }

        return $this->ok( $this->formatTag( $this->tagRepo->findById( $id ) ?? [] ) );
    }

    public function deleteTag( \WP_REST_Request $request ): \WP_REST_Response {
        $id = (int) $request->get_param( 'id' );

        if ( ! $this->tagRepo->findById( $id ) ) {
            return $this->err( 'Tag not found.', 404 );
        }

        $this->tagRepo->delete( $id );

        return $this->ok( [ 'deleted' => true ] );
    }

    // -------------------------------------------------------------------------
    // File ↔ Tag
    // -------------------------------------------------------------------------

    public function getFileTags( \WP_REST_Request $request ): \WP_REST_Response {
        $id   = (int) $request->get_param( 'id' );
        $tags = $this->tagRepo->getForAttachment( $id );

        return $this->ok( array_map( [ $this, 'formatTag' ], $tags ) );
    }

    public function setFileTags( \WP_REST_Request $request ): \WP_REST_Response {
        $id     = (int) $request->get_param( 'id' );
        $tagIds = array_map( 'intval', (array) ( $request->get_param( 'tag_ids' ) ?? [] ) );

        $this->tagRepo->setTagsForAttachment( $id, $tagIds );

        return $this->ok( [ 'attachment_id' => $id, 'tag_ids' => $tagIds ] );
    }

    public function removeFileTag( \WP_REST_Request $request ): \WP_REST_Response {
        $id    = (int) $request->get_param( 'id' );
        $tagId = (int) $request->get_param( 'tag_id' );

        $this->tagRepo->removeTagFromAttachment( $id, $tagId );

        return $this->ok( [ 'removed' => true ] );
    }

    public function bulkTagFiles( \WP_REST_Request $request ): \WP_REST_Response {
        $attachmentIds = array_filter(
            array_map( 'intval', (array) ( $request->get_param( 'attachment_ids' ) ?? [] ) )
        );
        $tagIds = array_filter(
            array_map( 'intval', (array) ( $request->get_param( 'tag_ids' ) ?? [] ) )
        );

        if ( empty( $attachmentIds ) ) {
            return $this->err( 'No attachments specified.', 400 );
        }
        if ( empty( $tagIds ) ) {
            return $this->err( 'No tags specified.', 400 );
        }

        $mode = $request->get_param( 'mode' ) === 'set' ? 'set' : 'add';

        foreach ( $attachmentIds as $attachId ) {
            if ( $mode === 'set' ) {
                $this->tagRepo->setTagsForAttachment( $attachId, array_values( $tagIds ) );
            } else {
                $this->tagRepo->addTagsForAttachment( $attachId, array_values( $tagIds ) );
            }
        }

        return $this->ok( [ 'updated' => count( $attachmentIds ) ] );
    }

    // -------------------------------------------------------------------------
    // Smart Folder rules
    // -------------------------------------------------------------------------

    public function getSmartRules( \WP_REST_Request $request ): \WP_REST_Response {
        $folderId = (int) $request->get_param( 'id' );
        $raw      = get_term_meta( $folderId, 'mdpai_smart_rules', true );
        $rules    = $raw ? json_decode( (string) $raw, true ) : null;

        return $this->ok( [ 'folder_id' => $folderId, 'rules' => $rules ] );
    }

    public function setSmartRules( \WP_REST_Request $request ): \WP_REST_Response {
        $folderId = (int) $request->get_param( 'id' );
        $rules    = $request->get_param( 'rules' );

        if ( ! is_array( $rules ) ) {
            return $this->err( 'Invalid rules format — expected an object.', 400 );
        }

        $sanitized = [
            'mode'       => in_array( $rules['mode'] ?? '', [ 'AND', 'OR' ], true )
                              ? $rules['mode']
                              : 'AND',
            'conditions' => [],
        ];

        foreach ( (array) ( $rules['conditions'] ?? [] ) as $cond ) {
            $type = $cond['type'] ?? '';

            if ( $type === 'tag' && isset( $cond['tag_id'] ) ) {
                $sanitized['conditions'][] = [
                    'type'   => 'tag',
                    'tag_id' => (int) $cond['tag_id'],
                ];
            } elseif ( $type === 'mime' && ! empty( $cond['mime'] ) ) {
                $sanitized['conditions'][] = [
                    'type' => 'mime',
                    'mime' => sanitize_text_field( $cond['mime'] ),
                ];
            } elseif ( $type === 'date_after' && ! empty( $cond['date'] ) ) {
                $sanitized['conditions'][] = [
                    'type' => 'date_after',
                    'date' => sanitize_text_field( $cond['date'] ),
                ];
            } elseif ( $type === 'date_before' && ! empty( $cond['date'] ) ) {
                $sanitized['conditions'][] = [
                    'type' => 'date_before',
                    'date' => sanitize_text_field( $cond['date'] ),
                ];
            }
        }

        update_term_meta( $folderId, 'mdpai_smart_rules', wp_json_encode( $sanitized ) );

        return $this->ok( [ 'folder_id' => $folderId, 'rules' => $sanitized ] );
    }

    public function deleteSmartRules( \WP_REST_Request $request ): \WP_REST_Response {
        $folderId = (int) $request->get_param( 'id' );

        delete_term_meta( $folderId, 'mdpai_smart_rules' );

        return $this->ok( [ 'folder_id' => $folderId, 'rules' => null ] );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $tag
     * @return array<string, mixed>
     */
    private function formatTag( array $tag ): array {
        return [
            'id'          => (int) ( $tag['id'] ?? 0 ),
            'name'        => (string) ( $tag['name'] ?? '' ),
            'slug'        => (string) ( $tag['slug'] ?? '' ),
            'color'       => (string) ( $tag['color'] ?? '#3b82f6' ),
            'usage_count' => isset( $tag['usage_count'] ) ? (int) $tag['usage_count'] : null,
        ];
    }

    private function ok( mixed $data, int $status = 200 ): \WP_REST_Response {
        return new \WP_REST_Response( [ 'success' => true, 'data' => $data ], $status );
    }

    private function err( string $message, int $status ): \WP_REST_Response {
        return new \WP_REST_Response( [ 'success' => false, 'message' => $message ], $status );
    }
}
