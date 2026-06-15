<?php

declare(strict_types=1);

namespace MediaPilotAI\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Media\MediaReplacer;
use MediaPilotAI\Media\VersionControl;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST endpoint for Media Replacement (S60).
 *
 * Namespace: mediapilot/v1
 *
 * Routes:
 *   POST  /files/{id}/replace   Upload a new file and replace attachment {id}.
 *   GET   /files/{id}/versions  Return version history for attachment {id}.
 *
 * @package MediaPilotAI\API
 * @since   1.0.0
 */
class ReplaceRestController {

    private const NAMESPACE = 'mediapilot/v1';

    public function __construct(
        private readonly MediaReplacer  $replacer,
        private readonly VersionControl $versionControl = new VersionControl(),
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function register(): void {
        // POST /files/{id}/replace — upload + replace
        register_rest_route( self::NAMESPACE, '/files/(?P<id>\d+)/replace', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'replace' ],
            'permission_callback' => [ $this, 'permEditAttachment' ],
            'args'                => [
                'id' => [
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ],
                'notes' => [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        // GET /files/{id}/versions — version history
        register_rest_route( self::NAMESPACE, '/files/(?P<id>\d+)/versions', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'history' ],
            'permission_callback' => [ $this, 'permEditAttachment' ],
            'args'                => [
                'id' => [
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        // POST /files/{id}/versions/{versionId}/restore — rollback
        register_rest_route( self::NAMESPACE, '/files/(?P<id>\d+)/versions/(?P<version_id>\d+)/restore', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'restore' ],
            'permission_callback' => [ $this, 'permEditAttachment' ],
            'args'                => [
                'id' => [
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ],
                'version_id' => [
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

    public function replace( WP_REST_Request $request ): WP_REST_Response {
        $id    = (int) $request->get_param( 'id' );
        $notes = (string) $request->get_param( 'notes' );

        // Validate the custom nonce sent by the media modal / edit screen.
        $nonce = (string) ( $request->get_header( 'x-wp-nonce' ) ?? '' );

        if ( ! wp_verify_nonce( $nonce, 'mdpai_replace_' . $id ) && ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_REST_Response(
                [ 'success' => false, 'message' => __( 'Invalid nonce.', 'mediapilot-ai') ],
                403
            );
        }

        // Retrieve the uploaded file from the request.
        $files = $request->get_file_params();

        if ( empty( $files['file'] ) || $files['file']['error'] !== UPLOAD_ERR_OK ) {
            $errorCode = $files['file']['error'] ?? -1;

            return new WP_REST_Response(
                /* translators: %d: PHP upload error code */
                [ 'success' => false, 'message' => sprintf( __( 'Upload error (code %d).', 'mediapilot-ai'), $errorCode ) ],
                400
            );
        }

        $tmpPath     = (string) $files['file']['tmp_name'];
        $newFilename = (string) $files['file']['name'];

        try {
            $result = $this->replacer->replace( $id, $tmpPath, $newFilename, $notes );
        } catch ( \UnexpectedValueException $e ) {
            // Identical file — treat as a 409 Conflict (informational, not an error).
            return new WP_REST_Response(
                [ 'success' => false, 'code' => 'identical_file', 'message' => $e->getMessage() ],
                409
            );
        } catch ( \RuntimeException $e ) {
            return new WP_REST_Response(
                [ 'success' => false, 'message' => $e->getMessage() ],
                422
            );
        }

        return new WP_REST_Response( [
            'success' => true,
            'data'    => $result,
        ], 200 );
    }

    public function history( WP_REST_Request $request ): WP_REST_Response {
        $id   = (int) $request->get_param( 'id' );
        $rows = $this->replacer->getHistory( $id );

        return new WP_REST_Response( [
            'success' => true,
            'data'    => $rows,
        ], 200 );
    }

    /**
     * POST /files/{id}/versions/{version_id}/restore
     *
     * Rolls an attachment back to a previously archived version.
     */
    public function restore( WP_REST_Request $request ): WP_REST_Response {
        $versionId = (int) $request->get_param( 'version_id' );

        try {
            $result = $this->versionControl->restoreVersion( $versionId );
        } catch ( \InvalidArgumentException $e ) {
            return new WP_REST_Response(
                [ 'success' => false, 'code' => 'not_found', 'message' => $e->getMessage() ],
                404
            );
        } catch ( \RuntimeException $e ) {
            return new WP_REST_Response(
                [ 'success' => false, 'message' => $e->getMessage() ],
                500
            );
        }

        return new WP_REST_Response( [ 'success' => true, 'data' => $result ], 200 );
    }

    // -------------------------------------------------------------------------
    // Permission
    // -------------------------------------------------------------------------

    /**
     * Replace/restore/history all act on a specific attachment, so the user must
     * be able to edit that attachment — not merely upload files in general.
     */
    public function permEditAttachment( \WP_REST_Request $request ): bool {
        $id = (int) $request['id'];

        return current_user_can( 'upload_files' )
            && $id > 0
            && current_user_can( 'edit_post', $id );
    }
}
