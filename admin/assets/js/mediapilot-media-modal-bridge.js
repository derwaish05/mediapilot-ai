/**
 * MediaPilot Media Modal Bridge
 *
 * Injects the MediaPilot folder sidebar into any wp.media frame opened by a
 * page builder or editor — Elementor, Classic Editor, Gutenberg (via
 * wp.media calls), Divi, WPBakery.
 *
 * Strategy:
 *   1. Wait for wp.media to be available.
 *   2. Override wp.media() to intercept every new media frame.
 *   3. On frame `open`, inject #mediapilot-modal-sidebar into the modal DOM and
 *      dispatch mediapilot:modal-open so the React app can mount its folder tree.
 *   4. On frame `close`, dispatch mediapilot:modal-close.
 *   5. Pass mdpai_folder_id back to the frame's media query so the grid
 *      filters by the selected folder.
 *
 * Gutenberg note:
 *   The block editor uses its own MediaUpload component which internally
 *   calls wp.media(). The intercept below catches that call. Folder
 *   filtering in Gutenberg also works via the REST filter registered in
 *   PageBuilderCompat::filterRestMediaQuery().
 *
 * @since 1.0.0
 */
(function () {
    'use strict';

    // Guard: bail if MediaPilotConfig wasn't injected (not an MediaPilot-enabled page).
    if ( typeof window.MediaPilotConfig === 'undefined' ) {
        return;
    }

    var SIDEBAR_ID   = 'mediapilot-modal-sidebar';
    var SIDEBAR_W    = 220; // px — matches the default sidebar width
    var activeFolderId = -1; // -1 = All Media (no filter)

    // -------------------------------------------------------------------------
    // 1. Intercept wp.media frame creation
    // -------------------------------------------------------------------------

    /**
     * Wait for wp.media to be defined, then wrap it so we can hook into
     * every frame that any builder creates.
     */
    function interceptWpMedia() {
        if ( typeof window.wp === 'undefined' || typeof window.wp.media === 'undefined' ) {
            setTimeout( interceptWpMedia, 200 );
            return;
        }

        var originalMedia = window.wp.media;

        // Wrap wp.media() so we intercept every new frame.
        window.wp.media = function ( attributes ) {
            var frame = originalMedia( attributes );

            // Skip if this is Divi's or WPBakery's special frame.
            if ( window._mmpIsDiviMediaOpen && window._mmpIsDiviMediaOpen() ) {
                return frame;
            }
            if ( window._mmpIsVCMediaOpen && window._mmpIsVCMediaOpen() ) {
                return frame;
            }

            frame.on( 'open', function () {
                onFrameOpen( frame );
            } );

            frame.on( 'close', function () {
                onFrameClose( frame );
            } );

            // Patch the frame's library query so folder filtering works.
            frame.on( 'ready', function () {
                patchFrameQuery( frame );
            } );

            return frame;
        };

        // Copy all properties/methods from the original wp.media onto the wrapper.
        Object.keys( originalMedia ).forEach( function ( key ) {
            window.wp.media[ key ] = originalMedia[ key ];
        } );

        // Also hook into any already-open frame (e.g. Elementor's pre-opened modal).
        hookExistingFrame();
    }

    // -------------------------------------------------------------------------
    // 2. Frame open / close handlers
    // -------------------------------------------------------------------------

    function onFrameOpen( frame ) {
        // Allow React to settle before injecting.
        setTimeout( function () {
            injectSidebar( frame );
        }, 150 );

        window.dispatchEvent( new CustomEvent( 'mediapilot:modal-open', {
            detail: { frame: frame }
        } ) );
    }

    function onFrameClose( frame ) {
        var sidebar = document.getElementById( SIDEBAR_ID );
        if ( sidebar && sidebar.parentNode ) {
            sidebar.parentNode.removeChild( sidebar );
        }

        window.dispatchEvent( new CustomEvent( 'mediapilot:modal-close', {
            detail: { frame: frame }
        } ) );

        activeFolderId = -1;
    }

    // -------------------------------------------------------------------------
    // 3. Sidebar DOM injection
    // -------------------------------------------------------------------------

    function injectSidebar( frame ) {
        // Remove any leftover sidebar from a previous frame.
        var old = document.getElementById( SIDEBAR_ID );
        if ( old && old.parentNode ) {
            old.parentNode.removeChild( old );
        }

        // Find the modal's content area. Different builders render the modal
        // in slightly different containers; try each in order.
        var contentArea = (
            document.querySelector( '.media-frame-content' ) ||
            document.querySelector( '.media-modal-content .media-frame-content' ) ||
            document.querySelector( '.media-modal .media-frame-content' )
        );

        if ( ! contentArea ) {
            // Modal DOM not ready yet — retry.
            setTimeout( function () { injectSidebar( frame ); }, 200 );
            return;
        }

        // Already injected.
        if ( document.getElementById( SIDEBAR_ID ) ) {
            return;
        }

        // Build the sidebar container.
        var sidebar       = document.createElement( 'div' );
        sidebar.id        = SIDEBAR_ID;
        sidebar.className = 'mediapilot-modal-sidebar';
        sidebar.setAttribute( 'aria-label', 'Media Folders' );
        sidebar.style.cssText = [
            'width:' + SIDEBAR_W + 'px',
            'min-width:' + SIDEBAR_W + 'px',
            'flex-shrink:0',
            'height:100%',
            'overflow-y:auto',
            'border-right:1px solid #ddd',
            'background:#f6f7f7',
            'position:relative',
            'z-index:1',
        ].join( ';' );

        // Turn contentArea into a flex row and prepend our sidebar.
        contentArea.style.cssText += ';display:flex!important;flex-direction:row;overflow:hidden;';

        // Wrap existing children in an inner column (avoid re-wrapping).
        var inner = contentArea.querySelector( '.mediapilot-modal-content-inner' );
        if ( ! inner ) {
            inner           = document.createElement( 'div' );
            inner.className = 'mediapilot-modal-content-inner';
            inner.style.cssText = 'flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0;';

            Array.prototype.slice.call( contentArea.children ).forEach( function ( child ) {
                inner.appendChild( child );
            } );
            contentArea.appendChild( inner );
        }

        contentArea.insertBefore( sidebar, inner );

        // Signal React to mount a compact folder tree inside this sidebar.
        window.dispatchEvent( new CustomEvent( 'mediapilot:mount-modal-sidebar', {
            detail: {
                containerId:  SIDEBAR_ID,
                initialTree:  ( window.MediaPilotConfig && window.MediaPilotConfig.initialTree ) || [],
                activeFolderId: activeFolderId,
                onFolderSelect: function ( folderId ) {
                    activeFolderId = folderId;
                    applyFolderFilter( frame, folderId );
                },
            }
        } ) );
    }

    // -------------------------------------------------------------------------
    // 4. Folder filter application
    // -------------------------------------------------------------------------

    /**
     * Updates the frame's media library query with the selected folder ID
     * so the media grid re-fetches and shows only that folder's files.
     *
     * Works with:
     *   - Classic WP media frame (Backbone)
     *   - Elementor media controls
     *   - Gutenberg (the modal internally uses wp.media for some flows)
     */
    function applyFolderFilter( frame, folderId ) {
        try {
            var state   = frame.state();
            var library = state && state.get( 'library' );

            if ( library && library.props ) {
                if ( folderId === -1 ) {
                    // Clear filter.
                    library.props.unset( 'mdpai_folder_id' );
                } else {
                    library.props.set( 'mdpai_folder_id', folderId );
                }

                // Backbone mirror: set on the query object's props too.
                if ( library.mirroring && library.mirroring.props ) {
                    if ( folderId === -1 ) {
                        library.mirroring.props.unset( 'mdpai_folder_id' );
                    } else {
                        library.mirroring.props.set( 'mdpai_folder_id', folderId );
                    }
                }

                library.reset();
            }
        } catch ( e ) {
            // Non-standard frame (e.g. Elementor custom modal) — fall back to
            // dispatching a custom event that the React store can handle.
            window.dispatchEvent( new CustomEvent( 'mediapilot:folder-changed', {
                detail: { folderId: folderId }
            } ) );
        }
    }

    /**
     * Patches a frame's library props to include mdpai_folder_id in AJAX queries.
     * Called on frame `ready` so the initial load also respects any pre-set folder.
     */
    function patchFrameQuery( frame ) {
        try {
            var state   = frame.state();
            var library = state && state.get( 'library' );

            if ( ! library ) {
                return;
            }

            // Override toJSON on the query's props so mdpai_folder_id is included
            // in every AJAX request to wp-admin/admin-ajax.php?action=query-attachments.
            var originalProps = library.props;
            if ( originalProps && ! originalProps._mmpPatched ) {
                var origToJSON = originalProps.toJSON.bind( originalProps );

                originalProps.toJSON = function () {
                    var json = origToJSON();
                    if ( activeFolderId !== -1 ) {
                        json.mdpai_folder_id = activeFolderId;
                    }
                    return json;
                };

                originalProps._mmpPatched = true;
            }
        } catch ( e ) {
            // Silently ignore — not all frames expose state/library.
        }
    }

    // -------------------------------------------------------------------------
    // 5. Hook into already-open frames (Elementor pre-opens its frame)
    // -------------------------------------------------------------------------

    function hookExistingFrame() {
        // Elementor opens a media frame on page load; hook into it if present.
        setTimeout( function () {
            if (
                typeof window.wp !== 'undefined' &&
                typeof window.wp.media !== 'undefined' &&
                typeof window.wp.media.frame !== 'undefined' &&
                window.wp.media.frame
            ) {
                var frame = window.wp.media.frame;

                if ( ! frame._mmpHooked ) {
                    frame.on( 'open',  function () { onFrameOpen( frame ); } );
                    frame.on( 'close', function () { onFrameClose( frame ); } );
                    frame.on( 'ready', function () { patchFrameQuery( frame ); } );
                    frame._mmpHooked = true;
                }
            }
        }, 500 );
    }

    // -------------------------------------------------------------------------
    // 6. Gutenberg: listen for the block editor's MediaUpload open events
    // -------------------------------------------------------------------------

    /**
     * The Gutenberg block editor dispatches a custom DOM event when its
     * MediaUpload component opens. We listen for it and inject our sidebar.
     *
     * Note: Gutenberg also registers `core/media-library` store — we don't
     * touch that store to avoid conflicts with @wordpress/data.
     */
    function listenForGutenbergMedia() {
        // Gutenberg fires this when the media modal opens from a block.
        document.addEventListener( 'click', function ( e ) {
            var target = e.target;

            // Detect clicks on Gutenberg media upload buttons.
            var isGBUploadBtn = (
                ( target.classList && target.classList.contains( 'editor-media-placeholder__upload-button' ) ) ||
                ( target.classList && target.classList.contains( 'block-editor-media-replace-flow__toggle' ) ) ||
                ( target.closest && target.closest( '[class*="MediaUpload"]' ) !== null )
            );

            if ( isGBUploadBtn ) {
                // Allow wp.media frame to open, then inject.
                setTimeout( function () {
                    var contentArea = document.querySelector( '.media-modal .media-frame-content' );
                    if ( contentArea && ! document.getElementById( SIDEBAR_ID ) ) {
                        injectSidebar( window.wp && window.wp.media && window.wp.media.frame );
                    }
                }, 400 );
            }
        }, true );
    }

    // -------------------------------------------------------------------------
    // 7. Boot
    // -------------------------------------------------------------------------

    interceptWpMedia();
    listenForGutenbergMedia();

}());
