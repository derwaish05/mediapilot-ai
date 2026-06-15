<?php

declare(strict_types=1);

namespace MediaPilotAI\Media;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Bulk metadata updater for attachment posts.
 *
 * Accepts an array of items, each containing an attachment ID and one or more
 * editable fields (alt, title, caption, description), and persists them via
 * wp_update_post() / update_post_meta().
 *
 * @package MediaPilotAI\Media
 * @since   1.0.0
 */
class BatchMetaService {

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Update metadata for a batch of attachments.
     *
     * Each item in $items must have at least an 'id' key. Any combination of
     * the following optional keys will be applied when present:
     *   alt         string  Stored in _wp_attachment_image_alt post meta.
     *   title       string  Mapped to post_title.
     *   caption     string  Mapped to post_excerpt.
     *   description string  Mapped to post_content.
     *
     * @param  array<int, array<string, mixed>> $items  Array of item payloads.
     * @return array{ updated: int, failed: int[], errors: array<int, string> }
     */
    public function saveBatch( array $items ): array {
        $updated = 0;
        $failed  = [];
        $errors  = [];

        foreach ( $items as $item ) {
            $id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;

            if ( $id <= 0 ) {
                continue;
            }

            // Verify the attachment exists and the current user can edit it.
            $post = get_post( $id );
            if ( ! $post || 'attachment' !== $post->post_type ) {
                $failed[]    = $id;
                $errors[$id] = 'Attachment not found.';
                continue;
            }

            if ( ! current_user_can( 'edit_post', $id ) ) {
                $failed[]    = $id;
                $errors[$id] = 'Permission denied.';
                continue;
            }

            $postData = [ 'ID' => $id ];

            if ( isset( $item['title'] ) ) {
                $postData['post_title'] = sanitize_text_field( (string) $item['title'] );
            }

            if ( isset( $item['caption'] ) ) {
                $postData['post_excerpt'] = sanitize_textarea_field( (string) $item['caption'] );
            }

            if ( isset( $item['description'] ) ) {
                $postData['post_content'] = wp_kses_post( (string) $item['description'] );
            }

            // Update post fields when there is at least one (title/caption/description).
            if ( count( $postData ) > 1 ) {
                $result = wp_update_post( $postData, true );

                if ( is_wp_error( $result ) ) {
                    $failed[]    = $id;
                    $errors[$id] = $result->get_error_message();
                    continue;
                }
            }

            // ALT text is stored in post meta, not on the post row.
            if ( isset( $item['alt'] ) ) {
                update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $item['alt'] ) );
            }

            $updated++;
        }

        return [
            'updated' => $updated,
            'failed'  => $failed,
            'errors'  => $errors,
        ];
    }

    // -------------------------------------------------------------------------
    // Helper — fetch editable metadata for a list of attachment IDs
    // -------------------------------------------------------------------------

    /**
     * Returns editable metadata arrays for the supplied attachment IDs.
     *
     * Keys per item: id, filename, title, alt, caption, description,
     *                thumbnail_url, mime_type.
     *
     * Invalid / non-attachment IDs are silently skipped.
     *
     * @param  int[] $ids
     * @return array<int, array<string, mixed>>
     */
    public function getMetaList( array $ids ): array {
        $items = [];

        foreach ( $ids as $rawId ) {
            $id   = absint( $rawId );
            $post = get_post( $id );

            if ( ! $post || 'attachment' !== $post->post_type ) {
                continue;
            }

            $filePath     = get_attached_file( $id );
            $thumbnailUrl = wp_get_attachment_image_url( $id, 'thumbnail' );
            if ( false === $thumbnailUrl || '' === $thumbnailUrl ) {
                $thumbnailUrl = (string) wp_get_attachment_url( $id );
            }

            $items[] = [
                'id'           => $id,
                'filename'     => is_string( $filePath ) ? basename( $filePath ) : '',
                'title'        => (string) $post->post_title,
                'alt'          => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
                'caption'      => (string) $post->post_excerpt,
                'description'  => (string) $post->post_content,
                'thumbnail_url'=> $thumbnailUrl,
                'mime_type'    => (string) $post->post_mime_type,
            ];
        }

        return $items;
    }
}
