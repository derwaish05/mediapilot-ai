/* MediaPilot AI — WooCommerce product-edit media-frame bridge.
   Positions the folder sidebar inside the wp.media modal and keeps the React
   selection store in sync. No localized data required. */
(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // 1. Wait for wp.media to be available, then hook into frame lifecycle
    // -------------------------------------------------------------------------

    function bootWhenReady() {
        if ( ! window.wp || ! window.wp.media ) {
            setTimeout( bootWhenReady, 200 );
            return;
        }
        hookMediaFrames();
    }

    // -------------------------------------------------------------------------
    // 2. Hook every new wp.media frame that opens on this page
    // -------------------------------------------------------------------------

    function hookMediaFrames() {
        var OriginalFrame = wp.media.view.MediaFrame.Select;

        // Extend the base Select frame so every WC media button inherits the hook.
        wp.media.view.MediaFrame.Select = OriginalFrame.extend({
            initialize: function () {
                OriginalFrame.prototype.initialize.apply( this, arguments );

                var self = this;

                // When the modal finishes rendering, place the sidebar.
                this.on( 'open',   function () { setTimeout( positionSidebar, 100 ); } );
                this.on( 'open',   setTimeout.bind( null, positionSidebar, 300 ) );

                // When the modal closes, reset placement so it re-attaches next time.
                this.on( 'close',  resetSidebarPlacement );
            },
        });
    }

    // -------------------------------------------------------------------------
    // 3. Position #mediapilot-sidebar-portal inside the open modal frame
    // -------------------------------------------------------------------------

    function positionSidebar() {
        var sidebarPortal = document.getElementById( 'mediapilot-sidebar-portal' );
        if ( ! sidebarPortal ) return;
        if ( sidebarPortal.getAttribute( 'data-mediapilot-placed' ) === '1' ) return;

        // The modal may render inside .media-modal or at the top of .media-frame-content.
        var frame = document.querySelector( '.media-modal .media-frame-content' )
                 || document.querySelector( '.media-frame-content' );
        if ( ! frame ) return;

        // Bail if WP backbone hasn't rendered the grid yet.
        if ( ! frame.querySelector( '.wp-filter' ) && ! frame.querySelector( '.attachments-browser' ) ) {
            setTimeout( positionSidebar, 200 );
            return;
        }

        // Turn the frame into a horizontal flex container.
        frame.style.cssText += ';display:flex!important;flex-direction:row;overflow:hidden;';

        // Insert sidebar as first child.
        frame.insertBefore( sidebarPortal, frame.firstChild );
        sidebarPortal.style.cssText = 'flex-shrink:0;height:100%;overflow:hidden;position:relative;display:block;';

        // Wrap WP's children (.wp-filter + .attachments-browser) in a flex column.
        if ( ! document.getElementById( 'mediapilot-media-content-inner' ) ) {
            var inner = document.createElement( 'div' );
            inner.id  = 'mediapilot-media-content-inner';
            inner.style.cssText = 'flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0;';

            Array.prototype.slice.call( frame.children ).forEach( function ( child ) {
                if ( child !== sidebarPortal ) {
                    inner.appendChild( child );
                }
            } );
            frame.appendChild( inner );
        }

        sidebarPortal.setAttribute( 'data-mediapilot-placed', '1' );
        attachMutationObserver();
    }

    // -------------------------------------------------------------------------
    // 4. Reset placement when the modal closes so it re-attaches next open
    // -------------------------------------------------------------------------

    function resetSidebarPlacement() {
        var sidebarPortal = document.getElementById( 'mediapilot-sidebar-portal' );
        if ( sidebarPortal ) {
            sidebarPortal.removeAttribute( 'data-mediapilot-placed' );
            sidebarPortal.style.cssText = 'display:none;';
            // Move portal back to body so it's not lost when the modal DOM is removed.
            document.body.appendChild( sidebarPortal );
        }

        var inner = document.getElementById( 'mediapilot-media-content-inner' );
        if ( inner && inner.parentNode ) {
            // Un-wrap children back into the frame.
            var parent = inner.parentNode;
            Array.prototype.slice.call( inner.children ).forEach( function ( child ) {
                parent.insertBefore( child, inner );
            } );
            parent.removeChild( inner );
        }
    }

    // -------------------------------------------------------------------------
    // 5. Selection observer — keep React selectionStore in sync
    // -------------------------------------------------------------------------

    var _observer = null;

    function attachMutationObserver() {
        if ( _observer ) return;

        var grid = document.querySelector( '.attachments' );
        if ( ! grid ) return;

        _observer = new MutationObserver( function ( mutations ) {
            var relevant = mutations.some( function ( m ) {
                return m.type === 'attributes' && m.attributeName === 'class';
            } );
            if ( relevant ) {
                var ids = [];
                document.querySelectorAll( '.attachment.selected' ).forEach( function ( el ) {
                    var id = parseInt( el.getAttribute( 'data-id' ) || '0', 10 );
                    if ( id > 0 ) ids.push( id );
                } );
                window.dispatchEvent( new CustomEvent( 'mediapilot:selection-change', { detail: { ids: ids } } ) );
            }
        } );

        _observer.observe( grid, { subtree: true, attributes: true, attributeFilter: [ 'class' ] } );
    }

    // -------------------------------------------------------------------------
    // 6. Forward mediapilot:folder-selected → wp.media backbone frame
    // -------------------------------------------------------------------------

    window.addEventListener( 'mediapilot:folder-selected', function ( e ) {
        if ( ! window.wp || ! window.wp.media ) return;

        var frame = wp.media.frame;
        if ( ! frame ) return;

        var state   = frame.state && frame.state();
        var library = state && state.get && state.get( 'library' );
        if ( ! library || ! library.props ) return;

        var folderId = e.detail && e.detail.folderId !== undefined ? e.detail.folderId : null;

        if ( folderId === null ) {
            library.props.unset( 'mdpai_folder_id' );
        } else {
            library.props.set( { mdpai_folder_id: String( folderId ) } );
        }
        library.props.trigger( 'change' );
    } );

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', bootWhenReady );
    } else {
        bootWhenReady();
    }
}());
