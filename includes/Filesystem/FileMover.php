<?php

declare(strict_types=1);

namespace MediaPilotAI\Filesystem;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Handles physical file operations for Real Filesystem Mode.
 *
 * Moves an attachment's main file and all registered image sizes to a new
 * directory, then updates all WordPress metadata and post content URLs to
 * reflect the new location.
 *
 * @package MediaPilotAI\Filesystem
 * @since   1.0.0
 */
class FileMover {

    /**
     * Post-meta key that stores the original file path before the first move.
     * Used to restore a file to its original location if needed.
     */
    public const META_ORIG_PATH = '_mdpai_orig_path';

    public function __construct() {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Move an attachment's main file and all registered image sizes to
     * $destDir, then update all WordPress metadata and post-content URLs.
     *
     * @param  int    $id       Attachment post ID.
     * @param  string $destDir  Absolute path to the destination directory.
     * @return bool             True on success, false on failure.
     */
    public function moveFile( int $id, string $destDir ): bool {
        $srcPath = (string) get_attached_file( $id );

        if ( ! file_exists( $srcPath ) ) {
            return false;
        }

        $basename = basename( $srcPath );
        $destPath = rtrim( $destDir, '/' ) . '/' . $basename;

        if ( $srcPath === $destPath ) {
            return true;
        }

        if ( ! wp_mkdir_p( $destDir ) ) {
            return false;
        }

        // Store original path in meta if not already set (for restore support).
        if ( ! get_post_meta( $id, self::META_ORIG_PATH, true ) ) {
            update_post_meta( $id, self::META_ORIG_PATH, $srcPath );
        }

        $oldUrl = (string) wp_get_attachment_url( $id );

        $info         = wp_get_upload_dir();
        $uploadsBase    = $info['basedir'];
        $uploadsBaseUrl = $info['baseurl'];

        if ( ! rename( $srcPath, $destPath ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
            return false;
        }

        // Move all registered image sizes.
        $metadata = wp_get_attachment_metadata( $id );
        $srcDir   = dirname( $srcPath );

        if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $sizeKey => $sizeData ) {
                $sizeSrc  = $srcDir  . '/' . $sizeData['file'];
                $sizeDest = $destDir . '/' . $sizeData['file'];

                if ( file_exists( $sizeSrc ) ) {
                    rename( $sizeSrc, $sizeDest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
                }
            }
        }

        // Update attachment metadata with new relative path.
        $newRelativePath = str_replace( $uploadsBase . '/', '', $destPath );

        if ( is_array( $metadata ) ) {
            $metadata['file'] = $newRelativePath;
        } else {
            $metadata = [ 'file' => $newRelativePath ];
        }

        wp_update_attachment_metadata( $id, $metadata );

        // Update _wp_attached_file meta.
        update_post_meta( $id, '_wp_attached_file', $newRelativePath );

        // Update guid in wp_posts.
        global $wpdb;

        $newUrl = $uploadsBaseUrl . '/' . ltrim( $newRelativePath, '/' );

        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->posts,
            [ 'guid' => $newUrl ],
            [ 'ID'   => $id ]
        );

        clean_post_cache( $id );

        // Update all URLs in post content and meta.
        $this->updateAllUrls( $oldUrl, $newUrl );

        return true;
    }

    /**
     * Replace all occurrences of $oldUrl with $newUrl across post_content and
     * postmeta (serialization-aware).
     *
     * @param  string $oldUrl  The URL before the file was moved.
     * @param  string $newUrl  The URL after the file was moved.
     * @return int             Number of postmeta rows updated.
     */
    public function updateAllUrls( string $oldUrl, string $newUrl ): int {
        if ( $oldUrl === $newUrl ) {
            return 0;
        }

        global $wpdb;

        // Update post_content in bulk via SQL REPLACE().
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
                $oldUrl,
                $newUrl,
                '%' . $wpdb->esc_like( $oldUrl ) . '%'
            )
        );

        // Fetch and update postmeta rows that contain the old URL.
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE %s",
                '%' . $wpdb->esc_like( $oldUrl ) . '%'
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return 0;
        }

        $updated = 0;

        foreach ( $rows as $row ) {
            if ( is_serialized( $row['meta_value'] ) ) {
                $unserialized = unserialize( $row['meta_value'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
                $replaced     = $this->recursiveReplace( $oldUrl, $newUrl, $unserialized );
                $newValue     = serialize( $replaced ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
            } else {
                $newValue = str_replace( $oldUrl, $newUrl, $row['meta_value'] );
            }

            if ( $newValue === $row['meta_value'] ) {
                continue;
            }

            $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->postmeta,
                [ 'meta_value' => $newValue ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                [ 'meta_id'    => $row['meta_id'] ]
            );

            $updated++;
        }

        return $updated;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Recursively replace $old with $new in strings and arrays.
     *
     * @param  string $old   String to search for.
     * @param  string $new   Replacement string.
     * @param  mixed  $data  The value to process.
     * @return mixed         The processed value.
     */
    private function recursiveReplace( string $old, string $new, mixed $data ): mixed {
        if ( is_string( $data ) ) {
            return str_replace( $old, $new, $data );
        }

        if ( is_array( $data ) ) {
            return array_map( fn( $v ) => $this->recursiveReplace( $old, $new, $v ), $data );
        }

        return $data;
    }
}
