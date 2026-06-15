<?php

declare(strict_types=1);

namespace MediaPilotAI\Compat;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Folder\FolderRepository;
use MediaPilotAI\Folder\FolderService;
use MediaPilotAI\Taxonomy\FolderTaxonomy;

/**
 * Page Builder Compatibility layer.
 *
 * Ensures the MediaPilot folder sidebar is available inside every page builder's
 * media modal, not just the standalone Media Library screen.
 *
 * Supported builders:
 *   - Gutenberg (Block Editor)    — REST media_query_args filter + wp.media bridge
 *   - Elementor                   — enqueue on elementor editor + wp.media bridge
 *   - Classic Editor              — wp.media bridge (standard WP media frame)
 *   - Divi                        — asset guard + wp.media bridge (no conflicts expected)
 *   - WPBakery Page Builder       — asset guard + wp.media bridge (no conflicts expected)
 *
 * Strategy:
 *   WordPress's `wp_enqueue_media` action fires whenever any code calls
 *   wp_enqueue_media() — this is the hook all builders use to load the WP
 *   media frame. We piggyback on it to ensure our JS/CSS and MediaPilotConfig are
 *   always present when a media modal can open.
 *
 *   A shared JS bridge (`mediapilot-media-modal-bridge`) then listens for the
 *   global `wp.media` frame `open` event and injects the folder sidebar
 *   inside the modal's DOM — the same approach used on upload.php.
 *
 * @package MediaPilotAI\Compat
 * @since   1.0.0
 */
class PageBuilderCompat {

    private const BRIDGE_HANDLE = 'mediapilot-media-modal-bridge';

    public function __construct(
        private readonly FolderRepository $folderRepository,
        private readonly FolderService    $folderService,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Register all compatibility hooks. Called once from Plugin::registerServices().
     */
    public function register(): void {
        // Fires whenever wp_enqueue_media() is called — catches all builders.
        add_action( 'wp_enqueue_media', [ $this, 'onEnqueueMedia' ] );

        // Elementor-specific: also hook after Elementor enqueues its editor scripts.
        add_action( 'elementor/editor/after_enqueue_scripts', [ $this, 'onElementorEditorScripts' ] );

        // Gutenberg: filter media query args when mdpai_folder_id is present in REST request.
        add_filter( 'ajax_query_attachments_args', [ $this, 'filterMediaQueryArgs' ] );

        // REST API media query (Gutenberg block editor media panel).
        add_filter( 'rest_attachment_query', [ $this, 'filterRestMediaQuery' ], 10, 2 );
    }

    // -------------------------------------------------------------------------
    // Hook callbacks
    // -------------------------------------------------------------------------

    /**
     * Fires whenever any builder (or WP core) calls wp_enqueue_media().
     *
     * Enqueues MediaPilot assets and injects MediaPilotConfig so the modal bridge can boot.
     * Safe to call multiple times — wp_enqueue_* is idempotent.
     */
    public function onEnqueueMedia(): void {
        // Skip on the standalone media library (upload.php) — handled by
        // MediaLibraryIntegration::enqueueAssets() + injectSidebarMount().
        global $pagenow;
        if ( 'upload.php' === ( $pagenow ?? '' ) ) {
            return;
        }

        $this->enqueueAssets();
        $this->injectMediaPilotConfig();
        $this->enqueueBridgeScript();
    }

    /**
     * Elementor fires this after its own editor scripts are enqueued.
     * Ensures assets are present even if wp_enqueue_media was called earlier.
     */
    public function onElementorEditorScripts(): void {
        $this->enqueueAssets();
        $this->injectMediaPilotConfig();
        $this->enqueueBridgeScript();
    }

    /**
     * Filters the AJAX attachment query used by the classic WP media modal
     * (wp.media backbone) to support mdpai_folder_id-based filtering.
     *
     * This covers Classic Editor, Elementor image controls, and any builder
     * that calls wp.media and then queries via AJAX.
     *
     * @param  array<string, mixed> $args  Existing query args.
     * @return array<string, mixed>
     */
    public function filterMediaQueryArgs( array $args ): array {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $folderId = isset( $_REQUEST['query']['mdpai_folder_id'] )
            ? (int) $_REQUEST['query']['mdpai_folder_id']  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            : -1;

        if ( -1 === $folderId ) {
            return $args;
        }

        if ( 0 === $folderId ) {
            // Uncategorized.
            $args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                [
                    'taxonomy' => FolderTaxonomy::TAXONOMY,
                    'operator' => 'NOT EXISTS',
                ],
            ];

            return $args;
        }

        $args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
            [
                'taxonomy' => FolderTaxonomy::TAXONOMY,
                'field'    => 'term_id',
                'terms'    => [ $folderId ],
                'operator' => 'IN',
            ],
        ];

        return $args;
    }

    /**
     * Filters the REST API attachment query used by the Gutenberg block editor
     * media panel when mdpai_folder_id is present in the request params.
     *
     * @param  array<string, mixed>  $args     WP_Query arguments.
     * @param  \WP_REST_Request      $request  Current REST request.
     * @return array<string, mixed>
     */
    public function filterRestMediaQuery( array $args, \WP_REST_Request $request ): array {
        $folderId = $request->get_param( 'mdpai_folder_id' );

        if ( null === $folderId ) {
            return $args;
        }

        $folderId = (int) $folderId;

        if ( -1 === $folderId ) {
            return $args;
        }

        if ( 0 === $folderId ) {
            $args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                [
                    'taxonomy' => FolderTaxonomy::TAXONOMY,
                    'operator' => 'NOT EXISTS',
                ],
            ];

            return $args;
        }

        $args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
            [
                'taxonomy' => FolderTaxonomy::TAXONOMY,
                'field'    => 'term_id',
                'terms'    => [ $folderId ],
                'operator' => 'IN',
            ],
        ];

        return $args;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function enqueueAssets(): void {
        if ( ! wp_style_is( 'mediapilot-admin', 'enqueued' ) ) {
            wp_enqueue_style(
                'mediapilot-admin',
                MDPAI_URL . 'admin/assets/dist/mediapilot-admin.css',
                [],
                MDPAI_VERSION
            );
        }

        if ( ! wp_script_is( 'mediapilot-admin', 'enqueued' ) ) {
            wp_enqueue_script(
                'mediapilot-admin',
                MDPAI_URL . 'admin/assets/dist/mediapilot-admin.js',
                [],
                MDPAI_VERSION,
                true
            );
        }
    }

    /**
     * Injects window.MediaPilotConfig if not already present.
     * Uses wp_add_inline_script so it executes after mediapilot-admin.js loads.
     */
    private function injectMediaPilotConfig(): void {
        $userId   = get_current_user_id();
        $settings = (array) get_option( 'mdpai_settings', [] );

        $folderMode  = (string) ( $settings['folder_mode'] ?? 'global' );
        $treeUserId  = ( 'per_user' === $folderMode ) ? $userId : 0;
        $initialTree = $this->folderService->getTree( $treeUserId );

        $config = [
            'restUrl'     => rest_url( 'mediapilot/v1' ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'userId'      => $userId,
            'isAdmin'     => current_user_can( 'manage_options' ),
            'folderMode'  => $folderMode,
            'initialTree' => $initialTree,
            'userPrefs'   => [],
            'licenceTier' => 'pro',
            'context'     => 'modal', // signals to React it's in modal mode
        ];

        wp_add_inline_script(
            'mediapilot-admin',
            'if(typeof window.MediaPilotConfig==="undefined"){window.MediaPilotConfig=' . wp_json_encode( $config ) . ';}',
            'before'
        );
    }

    /**
     * Registers and enqueues the media modal bridge script.
     * The bridge listens for wp.media frame events and injects the folder sidebar.
     */
    private function enqueueBridgeScript(): void {
        if ( wp_script_is( self::BRIDGE_HANDLE, 'enqueued' ) ) {
            return;
        }

        wp_register_script(
            self::BRIDGE_HANDLE,
            MDPAI_URL . 'admin/assets/js/mediapilot-media-modal-bridge.js',
            [ 'mediapilot-admin' ],
            MDPAI_VERSION,
            true
        );

        wp_enqueue_script( self::BRIDGE_HANDLE );

        // Conflict guards for Divi and WPBakery — inline, runs before bridge.
        wp_add_inline_script(
            self::BRIDGE_HANDLE,
            $this->buildConflictGuardJs(),
            'before'
        );
    }

    /**
     * Returns inline JS that neutralises known conflicts with Divi and WPBakery.
     *
     * Both builders use namespaced jQuery/backbone code and do not touch the
     * window.MediaPilotConfig or #mediapilot-* DOM IDs, so no conflicts have been observed.
     * The guards below add defensive checks to ensure MediaPilot's bridge bails
     * gracefully if a builder replaces core wp.media globals.
     */
    private function buildConflictGuardJs(): string {
        return <<<'JS'
(function () {
    'use strict';

    // Divi Builder (ET) — Divi replaces wp.media.frame for its own modals.
    // If ET's media frame is open, MediaPilot defers to avoid sidebar duplication.
    window._mmpIsDiviMediaOpen = function () {
        return typeof window.ET_PageBuilderBackbone !== 'undefined' &&
               typeof window.ET_PageBuilderApp !== 'undefined' &&
               document.querySelector('.et-fb-media-modal') !== null;
    };

    // WPBakery (vc_composer) — WPBakery forks wp.media into vc_media.
    // MediaPilot checks for this before injecting to avoid double-sidebar.
    window._mmpIsVCMediaOpen = function () {
        return typeof window.vc !== 'undefined' &&
               document.querySelector('.wpb_edit_form_elements .vc_media_library') !== null;
    };
}());
JS;
    }
}
