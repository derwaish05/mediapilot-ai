<?php

declare(strict_types=1);

namespace MediaPilotAI\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderRepository;
use MediaPilotAI\Taxonomy\FolderTaxonomy;

/**
 * WooCommerce Gallery Folder Sync (S40).
 *
 * Links a WooCommerce product to an MediaPilot folder so the product gallery
 * (`_product_image_gallery`) stays in sync with the folder contents.
 *
 * Behaviour:
 *  - Meta box on the product edit screen lets editors pick a linked folder.
 *  - Saving the meta box immediately runs a full sync.
 *  - Whenever a file is added to / removed from any folder, every product
 *    linked to that folder is re-synced automatically.
 *  - A "Sync Now" REST endpoint (`POST mediapilot/v1/woo/sync/{product_id}`) lets
 *    the meta box button trigger an on-demand sync without a page reload.
 *  - `do_action('mdpai_woo_gallery_sync', $productId, $folderId, $attachmentIds)`
 *    fires after every sync for developer extensibility.
 *
 * @package MediaPilotAI\WooCommerce
 * @since   1.0.0
 */
class ProductGallerySync {

    private const META_LINKED_FOLDER = '_mdpai_linked_folder';
    private const META_BOX_ID        = 'mdpai_woo_folder_sync';
    private const REST_NAMESPACE      = 'mediapilot/v1';
    private const MAX_IMAGES          = 500;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct(
        private readonly FolderRepository $folderRepository,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Register all hooks. Called from Plugin::registerServices().
     * Bails silently if WooCommerce is not active.
     */
    public function register(): void {
        if ( ! $this->wooActive() ) {
            return;
        }

        // Auto-sync when files are added to or removed from a folder.
        add_action( 'added_term_relationship',   [ $this, 'onTermAdded' ],    10, 3 );
        add_action( 'deleted_term_relationships', [ $this, 'onTermsRemoved' ], 10, 3 );

        // Meta box on the product edit screen.
        add_action( 'add_meta_boxes',     [ $this, 'addMetaBox' ] );
        add_action( 'save_post_product',  [ $this, 'saveMetaBox' ] );

        // REST route for "Sync Now" button.
        add_action( 'rest_api_init', [ $this, 'registerRestRoute' ] );
    }

    // -------------------------------------------------------------------------
    // Auto-sync — taxonomy hooks
    // -------------------------------------------------------------------------

    /**
     * Fires after a single term relationship is added.
     *
     * @param int    $objectId  Attachment post ID.
     * @param int    $ttId      Term-taxonomy ID (not term ID).
     * @param string $taxonomy  Taxonomy slug.
     */
    public function onTermAdded( int $objectId, int $ttId, string $taxonomy ): void {
        if ( $taxonomy !== FolderTaxonomy::TAXONOMY ) {
            return;
        }

        $folderId = $this->termIdFromTtId( $ttId );
        if ( $folderId > 0 ) {
            $this->syncProductsForFolder( $folderId );
        }
    }

    /**
     * Fires after one or more term relationships are deleted.
     *
     * @param int    $objectId  Attachment post ID.
     * @param int[]  $ttIds     Term-taxonomy IDs removed.
     * @param string $taxonomy  Taxonomy slug.
     */
    public function onTermsRemoved( int $objectId, array $ttIds, string $taxonomy ): void {
        if ( $taxonomy !== FolderTaxonomy::TAXONOMY ) {
            return;
        }

        foreach ( $ttIds as $ttId ) {
            $folderId = $this->termIdFromTtId( (int) $ttId );
            if ( $folderId > 0 ) {
                $this->syncProductsForFolder( $folderId );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Meta Box
    // -------------------------------------------------------------------------

    public function addMetaBox(): void {
        add_meta_box(
            self::META_BOX_ID,
            __( 'MediaPilot — Sync Gallery from Folder', 'mediapilot-ai'),
            [ $this, 'renderMetaBox' ],
            'product',
            'side',
            'default'
        );
    }

    public function renderMetaBox( \WP_Post $post ): void {
        $linkedFolder = (int) get_post_meta( $post->ID, self::META_LINKED_FOLDER, true );
        $folders      = $this->getFlatFolderList();
        $restUrl      = rest_url( self::REST_NAMESPACE . '/woo/sync/' . $post->ID );
        $nonce        = wp_create_nonce( 'wp_rest' );

        wp_nonce_field( 'mdpai_woo_meta_box', 'mdpai_woo_nonce' );
        ?>
        <p>
            <label for="mdpai_linked_folder">
                <strong><?php esc_html_e( 'Linked MediaPilot Folder', 'mediapilot-ai'); ?></strong>
            </label><br>
            <select id="mdpai_linked_folder" name="mdpai_linked_folder" style="width:100%;margin-top:4px;">
                <option value="0"><?php esc_html_e( '— None —', 'mediapilot-ai'); ?></option>
                <?php foreach ( $folders as $folder ) : ?>
                    <option value="<?php echo esc_attr( (string) $folder['id'] ); ?>"
                            <?php selected( $linkedFolder, $folder['id'] ); ?>>
                        <?php echo esc_html( str_repeat( '— ', $folder['depth'] ) . $folder['name'] ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p style="font-size:12px;color:#646970;margin-top:4px;">
            <?php esc_html_e( 'Images from the selected folder are added to the product gallery automatically when files change.', 'mediapilot-ai'); ?>
        </p>
        <p style="margin-top:8px;">
            <button type="button" id="mediapilot-woo-sync-now" class="button button-secondary"
                    style="width:100%;"
                    data-url="<?php echo esc_url( $restUrl ); ?>"
                    data-nonce="<?php echo esc_attr( $nonce ); ?>">
                <?php esc_html_e( 'Sync Now', 'mediapilot-ai'); ?>
            </button>
            <span id="mediapilot-woo-sync-result"
                  style="display:block;margin-top:6px;font-size:12px;min-height:1em;"></span>
        </p>
        <?php ob_start(); ?>
        (function () {
            var btn    = document.getElementById('mediapilot-woo-sync-now');
            var result = document.getElementById('mediapilot-woo-sync-result');
            if ( ! btn ) return;

            btn.addEventListener('click', function () {
                btn.disabled       = true;
                result.style.color = '';
                result.textContent = <?php echo wp_json_encode( __( 'Syncing…', 'mediapilot-ai') ); ?>;

                fetch(btn.dataset.url, {
                    method:  'POST',
                    headers: {
                        'X-WP-Nonce':   btn.dataset.nonce,
                        'Content-Type': 'application/json',
                    },
                    body: '{}',
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if ( data && data.success ) {
                        result.style.color = 'green';
                        result.textContent = ( data.data && data.data.message )
                            ? data.data.message
                            : <?php echo wp_json_encode( __( 'Synced!', 'mediapilot-ai') ); ?>;
                    } else {
                        result.style.color = '#cc1818';
                        result.textContent = ( data && data.message )
                            ? data.message
                            : <?php echo wp_json_encode( __( 'Sync failed.', 'mediapilot-ai') ); ?>;
                    }
                    btn.disabled = false;
                })
                .catch(function () {
                    result.style.color = '#cc1818';
                    result.textContent = <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mediapilot-ai') ); ?>;
                    btn.disabled = false;
                });
            });
        })();
        <?php
        wp_add_inline_script( 'mediapilot-admin', (string) ob_get_clean() );
    }

    public function saveMetaBox( int $postId ): void {
        // Verify nonce.
        if ( empty( $_POST['mdpai_woo_nonce'] )
            || ! wp_verify_nonce(
                sanitize_text_field( wp_unslash( $_POST['mdpai_woo_nonce'] ) ),
                'mdpai_woo_meta_box'
            )
        ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $postId ) ) {
            return;
        }

        $folderId = absint( $_POST['mdpai_linked_folder'] ?? 0 );
        update_post_meta( $postId, self::META_LINKED_FOLDER, $folderId );

        // Trigger an immediate sync whenever the folder link changes.
        if ( $folderId > 0 ) {
            $this->syncGallery( $postId );
        }
    }

    // -------------------------------------------------------------------------
    // REST route — "Sync Now" button
    // -------------------------------------------------------------------------

    public function registerRestRoute(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/woo/sync/(?P<product_id>\d+)',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'restSyncNow' ],
                'permission_callback' => [ $this, 'permEditProducts' ],
                'args'                => [
                    'product_id' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    /**
     * POST mediapilot/v1/woo/sync/{product_id}
     *
     * @param  \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function restSyncNow( \WP_REST_Request $request ): \WP_REST_Response {
        $productId = (int) $request->get_param( 'product_id' );
        $folderId  = (int) get_post_meta( $productId, self::META_LINKED_FOLDER, true );

        if ( $folderId <= 0 ) {
            return new \WP_REST_Response(
                [
                    'success' => false,
                    'message' => __( 'No MediaPilot folder linked to this product.', 'mediapilot-ai'),
                ],
                422
            );
        }

        $count = $this->syncGallery( $productId );

        return new \WP_REST_Response(
            [
                'success' => true,
                'data'    => [
                    'message' => sprintf(
                        /* translators: %d: number of images synced */
                        _n( '%d image synced.', '%d images synced.', $count, 'mediapilot-ai'),
                        $count
                    ),
                    'count'   => $count,
                ],
            ],
            200
        );
    }

    public function permEditProducts(): bool {
        return current_user_can( 'edit_products' );
    }

    // -------------------------------------------------------------------------
    // Core sync logic
    // -------------------------------------------------------------------------

    /**
     * Find all products linked to $folderId and re-sync each one.
     *
     * Called automatically when folder membership changes.
     *
     * @param int $folderId  MediaPilot folder term ID.
     */
    private function syncProductsForFolder( int $folderId ): void {
        $productIds = $this->getLinkedProductIds( $folderId );

        foreach ( $productIds as $productId ) {
            $this->syncGallery( $productId );
        }
    }

    /**
     * Sync the WooCommerce product gallery from the linked MediaPilot folder.
     *
     * Reads all image attachments in the linked folder (up to MAX_IMAGES),
     * writes them to `_product_image_gallery` (comma-separated IDs — the
     * WooCommerce standard), then fires `mdpai_woo_gallery_sync`.
     *
     * @param  int $productId  WooCommerce product post ID.
     * @return int  Number of images written to the gallery.
     */
    public function syncGallery( int $productId ): int {
        $folderId = (int) get_post_meta( $productId, self::META_LINKED_FOLDER, true );

        if ( $folderId <= 0 ) {
            return 0;
        }

        $attachmentIds = $this->getImageIdsInFolder( $folderId );

        // WooCommerce stores the gallery as comma-separated attachment IDs.
        update_post_meta(
            $productId,
            '_product_image_gallery',
            implode( ',', $attachmentIds )
        );

        /**
         * Fires after MediaPilot syncs a WooCommerce product gallery from a folder.
         *
         * @since 1.0.0
         *
         * @param int   $productId      WooCommerce product post ID.
         * @param int   $folderId       MediaPilot folder term ID that was synced.
         * @param int[] $attachmentIds  Attachment IDs now in the gallery.
         */
        do_action( 'mdpai_woo_gallery_sync', $productId, $folderId, $attachmentIds );

        return count( $attachmentIds );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Return all image attachment IDs assigned to $folderId.
     *
     * @param  int $folderId  MediaPilot folder term ID.
     * @return int[]
     */
    private function getImageIdsInFolder( int $folderId ): array {
        $query = new \WP_Query( [
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'posts_per_page' => self::MAX_IMAGES,
            'no_found_rows'  => true,
            'fields'         => 'ids',
            'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                [
                    'taxonomy' => FolderTaxonomy::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => [ $folderId ],
                    'operator' => 'IN',
                ],
            ],
        ] );

        return array_map( 'intval', $query->posts );
    }

    /**
     * Return IDs of all products whose `_mdpai_linked_folder` equals $folderId.
     *
     * @param  int $folderId
     * @return int[]
     */
    private function getLinkedProductIds( int $folderId ): array {
        $query = new \WP_Query( [
            'post_type'      => 'product',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
            'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                [
                    'key'     => self::META_LINKED_FOLDER,
                    'value'   => $folderId,
                    'type'    => 'NUMERIC',
                    'compare' => '=',
                ],
            ],
        ] );

        return array_map( 'intval', $query->posts );
    }

    /**
     * Flatten the folder tree into a depth-annotated list for the select element.
     *
     * Each item: [ 'id' => int, 'name' => string, 'depth' => int ]
     *
     * @return array<int, array{id:int, name:string, depth:int}>
     */
    private function getFlatFolderList(): array {
        $tree = $this->folderRepository->getTree( 0 ); // global mode
        $flat = [];
        $this->flattenTree( $tree, $flat, 0 );
        return $flat;
    }

    /**
     * Recursively flatten a folder tree node into $flat.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @param array<int, array{id:int, name:string, depth:int}> $flat  Accumulator (by-ref).
     * @param int $depth
     */
    private function flattenTree( array $nodes, array &$flat, int $depth ): void {
        foreach ( $nodes as $node ) {
            $flat[] = [
                'id'    => (int)    ( $node['id']   ?? 0 ),
                'name'  => (string) ( $node['name'] ?? '' ),
                'depth' => $depth,
            ];
            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                $this->flattenTree( $node['children'], $flat, $depth + 1 );
            }
        }
    }

    /**
     * Resolve a term-taxonomy ID to an MediaPilot folder term ID.
     *
     * `added_term_relationship` provides a term-taxonomy ID, not a term ID.
     *
     * @param  int $ttId  Term-taxonomy ID.
     * @return int  Term ID, or 0 on failure.
     */
    private function termIdFromTtId( int $ttId ): int {
        $term = get_term_by( 'term_taxonomy_id', $ttId, FolderTaxonomy::TAXONOMY );
        return ( $term instanceof \WP_Term ) ? (int) $term->term_id : 0;
    }

    /**
     * Returns true when the WooCommerce plugin is active and the required
     * classes / functions are available.
     */
    private function wooActive(): bool {
        return function_exists( 'WC' ) || class_exists( 'WooCommerce' );
    }
}
