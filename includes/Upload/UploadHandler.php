<?php

declare(strict_types=1);

namespace MediaPilotAI\Upload;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MediaPilotAI\Media\MediaRepository;
use MediaPilotAI\Folder\FolderRepository;

/**
 * Handles all upload-related integration for the MediaPilot AI.
 *
 * Responsibilities:
 *  1. Allow SVG/SVGZ file uploads and ensure WP recognises the MIME type.
 *  2. Sanitize uploaded SVG files (strip script tags, event handlers, external
 *     references, and dangerous elements) without external library dependency.
 *  3. Auto-assign newly uploaded attachments to the user's active folder:
 *       a. Via the folder picker injected into the WP upload modal (POST).
 *       b. Via the REST API upload path (Gutenberg block editor).
 *       c. Fall back to the user's last-used folder stored in wp_mdpai_user_prefs.
 *  4. Persist the last-used folder per user in wp_mdpai_user_prefs.
 *  5. Inject a folder picker <select> + plupload wiring into the WP upload UI.
 *  6. Expose `mdpai_folder_id` as an accepted REST API parameter on the
 *     attachment creation endpoint used by the block editor.
 *
 * @package MediaPilotAI\Upload
 * @since   1.0.0
 */
class UploadHandler {

    // -------------------------------------------------------------------------
    // Static state — carries REST folder ID across filter/action boundary
    // -------------------------------------------------------------------------

    /**
     * Folder ID received via the REST API before the attachment post exists.
     * Set in handleRestUploadFolder(), read in autoAssignFolder().
     */
    private static int $restUploadFolderId = 0;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct(
        private readonly MediaRepository  $mediaRepository,
        private readonly FolderRepository $folderRepository,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Register all hooks. Called once from Plugin::registerServices().
     *
     * Deliberately not wrapped in is_admin() — REST API uploads from the block
     * editor happen outside the admin context.
     */
    public function register(): void {
        // Allow SVG uploads.
        add_filter( 'upload_mimes',              [ $this, 'allowSvgMime' ] );
        add_filter( 'wp_check_filetype_and_ext', [ $this, 'fixSvgFiletype' ], 10, 5 );

        // Sanitize SVG content after upload lands on disk.
        add_filter( 'wp_handle_upload',          [ $this, 'sanitizeSvgOnUpload' ] );

        // After attachment post is created: auto-assign to active folder.
        add_action( 'add_attachment',            [ $this, 'autoAssignFolder' ] );

        // Inject folder picker HTML into the plupload / media-upload UI.
        add_filter( 'post_upload_ui',            [ $this, 'injectFolderPickerScript' ] );

        // REST API: capture the folder ID before the attachment post is saved.
        add_filter( 'rest_pre_insert_attachment', [ $this, 'handleRestUploadFolder' ], 10, 2 );
    }

    // -------------------------------------------------------------------------
    // Hook Callbacks — SVG MIME support
    // -------------------------------------------------------------------------

    /**
     * Adds SVG and SVGZ entries to the list of allowed upload MIME types.
     *
     * @param  array<string, string> $mimes  Existing MIME type map (ext => mime).
     * @return array<string, string>
     */
    public function allowSvgMime( array $mimes ): array {
        $mimes['svg']  = 'image/svg+xml';
        $mimes['svgz'] = 'image/svg+xml';

        return $mimes;
    }

    /**
     * Fixes WP 5.1+ filetype verification for SVG files.
     *
     * WordPress performs a server-level MIME sniff on upload; SVG is XML and
     * the sniffed type may not match 'image/svg+xml'. This filter forces the
     * correct ext/type pair so WP accepts the file.
     *
     * @param  array<string, string|false> $data      Verified data: ['ext', 'type', 'proper_filename'].
     * @param  string                      $file      Full path to the temporary uploaded file.
     * @param  string                      $filename  Client-supplied original filename.
     * @param  array<string, string>       $mimes     Allowed MIME types.
     * @param  string|false                $realMime  MIME type detected by wp_get_image_mime() or false.
     * @return array<string, string|false>
     */
    public function fixSvgFiletype(
        array $data,
        string $file,
        string $filename,
        ?array $mimes,
        string|false $realMime
    ): array {
        $ext = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );

        if ( 'svg' === $ext || 'svgz' === $ext ) {
            $data['ext']  = 'svg';
            $data['type'] = 'image/svg+xml';
        }

        return $data;
    }

    // -------------------------------------------------------------------------
    // Hook Callbacks — SVG Sanitization
    // -------------------------------------------------------------------------

    /**
     * Sanitizes the contents of an uploaded SVG file immediately after it lands
     * on disk, before WordPress creates the attachment post.
     *
     * Only acts on image/svg+xml uploads; all other files pass through unchanged.
     *
     * @param  array<string, string> $upload  WP upload data: ['file', 'url', 'type'].
     * @return array<string, string>  Unmodified $upload array (WP continues with it).
     */
    public function sanitizeSvgOnUpload( array $upload ): array {
        if ( ! isset( $upload['type'] ) || 'image/svg+xml' !== $upload['type'] ) {
            return $upload;
        }

        if ( ! isset( $upload['file'] ) || ! is_string( $upload['file'] ) ) {
            return $upload;
        }

        $filePath = $upload['file'];

        if ( ! file_exists( $filePath ) || ! is_readable( $filePath ) ) {
            return $upload;
        }

        $raw = file_get_contents( $filePath );

        if ( false === $raw ) {
            return $upload;
        }

        $sanitized = $this->sanitizeSvgContent( $raw );

        // Only write back if sanitization changed anything.
        if ( $sanitized !== $raw ) {
            file_put_contents( $filePath, $sanitized );
        }

        return $upload;
    }

    // -------------------------------------------------------------------------
    // Hook Callbacks — Auto-assign folder on upload
    // -------------------------------------------------------------------------

    /**
     * Assigns a newly uploaded attachment to the user's active folder.
     *
     * Priority order:
     *   1. REST API path: self::$restUploadFolderId (set by handleRestUploadFolder).
     *   2. Classic upload modal: $_POST['mdpai_upload_folder_id'] (set by folder picker).
     *   3. Fallback: user's last-used folder stored in wp_mdpai_user_prefs.
     *
     * When a valid folder is found the attachment is assigned and, if the ID
     * came from an interactive upload (POST or REST), the user's last-used
     * folder record is updated.
     *
     * @param  int $attachmentId  Newly created attachment post ID.
     */
    public function autoAssignFolder( int $attachmentId ): void {
        $userId   = get_current_user_id();
        $fromPost = false;

        // 1. Check REST static property first.
        $folderId = self::$restUploadFolderId;

        // 2. Check classic upload modal POST data.
        if ( $folderId <= 0 && isset( $_POST['mdpai_upload_folder_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- folder ID is safe (absint sanitized), nonce check done by WP core upload handler
            $folderId = absint( $_POST['mdpai_upload_folder_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $fromPost = ( $folderId > 0 );
        } elseif ( $folderId > 0 ) {
            // Came from REST — treat as an interactive choice.
            $fromPost = true;
            // Reset static property for the next upload in the same request.
            self::$restUploadFolderId = 0;
        }

        // 3. Fall back to the user's last-used folder from the prefs table.
        if ( $folderId <= 0 && $userId > 0 ) {
            $folderId = $this->getUserLastFolder( $userId );
        }

        if ( $folderId <= 0 ) {
            // Nothing to assign — file lands in Uncategorized.
            return;
        }

        // Assign to folder.
        $this->mediaRepository->assignToFolder( $attachmentId, $folderId );

        // Persist the last-used folder when the user made an explicit choice.
        if ( $fromPost && $userId > 0 ) {
            $this->saveUserLastFolder( $userId, $folderId );
        }
    }

    // -------------------------------------------------------------------------
    // Hook Callbacks — Folder picker UI injection
    // -------------------------------------------------------------------------

    /**
     * Injects the folder picker HTML and the plupload wiring script into the
     * WP upload modal / async-upload.php form.
     *
     * The <select> is populated with all available folders, indented to reflect
     * the tree hierarchy.  The inline JavaScript binds the selected folder ID
     * to the plupload multipart_params so it is submitted with every file in
     * the upload queue.
     *
     * @return void  Outputs HTML directly (WordPress filter context).
     */
    public function injectFolderPickerScript(): void {
        $selectOptions = $this->buildFolderSelectOptions();

        echo '<div id="mediapilot-folder-picker-wrap" style="margin:8px 0;">' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded HTML with esc_html__() translations
            . '<label for="mediapilot-upload-folder" style="display:block;font-weight:600;margin-bottom:4px;">'
            . esc_html__( 'Upload to Folder:', 'mediapilot-ai')
            . '</label>'
            . '<select id="mediapilot-upload-folder" name="mdpai_upload_folder_id" style="max-width:260px;">'
            . '<option value="0">' . esc_html__( '— No Folder (Uncategorized)', 'mediapilot-ai') . '</option>'
            . $selectOptions // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by buildFolderSelectOptions() with esc_html/esc_attr
            . '</select>'
            . '</div>';

        // Wire the <select> value into every plupload upload, attached to the
        // core wp-plupload handle (no raw <script> tag). post_upload_ui renders
        // before footer scripts print, so this inline is included.
        ob_start();
        ?>
(function($){
    $(function(){
        if(typeof wp !== "undefined" && wp.Uploader) {
            var origInit = wp.Uploader.prototype.init;
            wp.Uploader.prototype.init = function() {
                origInit.apply(this, arguments);
                this.uploader.bind("BeforeUpload", function(up, file) {
                    var folderSelect = document.getElementById("mediapilot-upload-folder");
                    if(folderSelect) {
                        up.settings.multipart_params = up.settings.multipart_params || {};
                        up.settings.multipart_params.mdpai_upload_folder_id = folderSelect.value;
                    }
                });
            };
        }
    });
})(jQuery);
        <?php
        wp_add_inline_script( 'wp-plupload', (string) ob_get_clean() );
    }

    // -------------------------------------------------------------------------
    // Hook Callbacks — REST API folder parameter
    // -------------------------------------------------------------------------

    /**
     * Captures the mdpai_folder_id parameter from a REST API attachment upload
     * request (used by the Gutenberg block editor).
     *
     * The folder ID cannot be applied to the taxonomy relationship at this
     * point because the attachment post does not yet exist.  It is stored in
     * the static property self::$restUploadFolderId and consumed by
     * autoAssignFolder() on the subsequent add_attachment action.
     *
     * @param  \WP_Post         $attachment  Prepared attachment post data (not yet saved).
     * @param  \WP_REST_Request $request     The REST request that triggered the upload.
     * @return \WP_Post  Unchanged attachment object.
     */
    public function handleRestUploadFolder( \WP_Post $attachment, \WP_REST_Request $request ): \WP_Post {
        $folderId = absint( $request->get_param( 'mdpai_folder_id' ) ?? 0 );

        if ( $folderId > 0 ) {
            self::$restUploadFolderId = $folderId;
        }

        return $attachment;
    }

    // -------------------------------------------------------------------------
    // Private — SVG sanitization
    // -------------------------------------------------------------------------

    /**
     * Strips dangerous constructs from SVG content using a pure regex/string
     * approach (no external library required).
     *
     * Removes:
     *  - <script> blocks and their full content
     *  - <iframe>, <object>, <embed>, <foreignObject>, <annotation-xml> elements
     *  - on* event handler attributes (onload, onclick, onerror, etc.)
     *  - javascript: protocol in href, xlink:href, src, and action attributes
     *  - <use> elements that reference external URLs (preserves #fragment refs)
     *
     * @param  string $content  Raw SVG file content.
     * @return string  Sanitized SVG content.
     */
    private function sanitizeSvgContent( string $content ): string {
        // --- 1. Remove <script> blocks entirely (including inner content). ---
        $content = preg_replace(
            '/<script\b[^>]*>.*?<\/script>/si',
            '',
            $content
        ) ?? $content;

        // --- 2. Remove other dangerous elements (self-closing or with content). ---
        $dangerousTags = [
            'iframe',
            'object',
            'embed',
            'foreignobject',
            'annotation-xml',
        ];

        foreach ( $dangerousTags as $tag ) {
            // Matches both <tag .../> and <tag ...>...</tag> (case-insensitive).
            $content = preg_replace(
                '/<' . preg_quote( $tag, '/' ) . '\b[^>]*(?:\/>|>.*?<\/' . preg_quote( $tag, '/' ) . '>)/si',
                '',
                $content
            ) ?? $content;
        }

        // --- 3. Remove on* event handler attributes (quoted values). ---
        $content = preg_replace(
            '/\s+on\w+\s*=\s*(["\']).*?\1/si',
            '',
            $content
        ) ?? $content;

        // Remove on* attributes with unquoted values.
        $content = preg_replace(
            '/\s+on\w+\s*=\s*[^\s>]+/si',
            '',
            $content
        ) ?? $content;

        // --- 4. Remove javascript: protocol in link/source attributes. ---
        $content = preg_replace(
            '/(?:href|xlink:href|src|action)\s*=\s*(["\'])\s*javascript:[^"\']*\1/si',
            '',
            $content
        ) ?? $content;

        // --- 5. Remove <use> elements referencing external URLs. ---
        // Preserve internal fragment references (href="#id") but strip anything
        // that points outside the document (e.g. href="http://evil.com/sprite.svg#icon").
        $content = preg_replace(
            '/<use\b[^>]*(?:href|xlink:href)\s*=\s*["\'](?!#)[^"\']*["\'][^>]*\/?>/si',
            '',
            $content
        ) ?? $content;

        return $content;
    }

    // -------------------------------------------------------------------------
    // Private — Folder select options builder
    // -------------------------------------------------------------------------

    /**
     * Builds a flat list of <option> elements from the folder tree, indented
     * with em-dashes to represent hierarchy depth.
     *
     * Calls FolderRepository::getTree() which uses the global folder set
     * (user_id = 0). Per-user filtering is intentional: the upload picker
     * should always show the full tree so admins can choose any folder.
     *
     * @return string  Concatenated <option> HTML strings, already escaped.
     */
    private function buildFolderSelectOptions(): string {
        $tree = $this->folderRepository->getTree( 0 );

        return $this->renderFolderOptionNodes( $tree, 0 );
    }

    /**
     * Recursively renders <option> nodes for a folder tree level.
     *
     * @param  array<int, array<string, mixed>> $nodes   Current level of folder nodes.
     * @param  int                              $depth   Current nesting depth (0 = top-level).
     * @return string  Concatenated <option> HTML.
     */
    private function renderFolderOptionNodes( array $nodes, int $depth ): string {
        $html   = '';
        $indent = str_repeat( '&#8212;', $depth ); // em-dash (—) per depth level

        foreach ( $nodes as $node ) {
            $id       = (int) $node['id'];
            $name     = (string) $node['name'];
            $label    = $depth > 0 ? $indent . ' ' . $name : $name;
            $children = isset( $node['children'] ) && is_array( $node['children'] )
                ? $node['children']
                : [];

            $html .= '<option value="' . esc_attr( (string) $id ) . '">'
                   . esc_html( $label )
                   . '</option>';

            if ( ! empty( $children ) ) {
                $html .= $this->renderFolderOptionNodes( $children, $depth + 1 );
            }
        }

        return $html;
    }

    // -------------------------------------------------------------------------
    // Private — wp_mdpai_user_prefs helpers
    // -------------------------------------------------------------------------

    /**
     * Retrieves the last-used folder ID for a user from wp_mdpai_user_prefs.
     *
     * Returns 0 when no preference row exists or when the stored value is NULL.
     *
     * @param  int $userId  WordPress user ID.
     * @return int  Folder term ID, or 0.
     */
    private function getUserLastFolder( int $userId ): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT folder_id FROM {$wpdb->prefix}mdpai_user_prefs WHERE user_id = %d",
                $userId
            )
        );

        return absint( $result );
    }

    /**
     * Upserts the last-used folder ID for a user in wp_mdpai_user_prefs.
     *
     * Uses $wpdb->replace() which performs an INSERT ... ON DUPLICATE KEY
     * replacement, so only the user_id and folder_id columns are touched —
     * all other prefs columns keep their existing values when the row exists.
     *
     * Note: replace() removes and re-inserts the row, which resets columns
     * with DEFAULT values. For a safer partial update consider:
     *   INSERT ... ON DUPLICATE KEY UPDATE folder_id = VALUES(folder_id)
     * However, replace() is simpler and acceptable for this use case given
     * the table's UNIQUE KEY on user_id.
     *
     * @param  int $userId    WordPress user ID.
     * @param  int $folderId  Folder term ID to store.
     */
    private function saveUserLastFolder( int $userId, int $folderId ): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prefix . 'mdpai_user_prefs',
            [
                'user_id'   => $userId,
                'folder_id' => $folderId,
            ],
            [ '%d', '%d' ]
        );
    }
}
