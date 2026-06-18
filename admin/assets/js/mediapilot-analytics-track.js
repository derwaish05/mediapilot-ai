/* MediaPilot AI — media usage analytics tracker (insert / download events).
   Localised data: window.MediaPilotAnalyticsTrack { nonce, restUrl }.
   Self-guards for wp.media availability. */
( function () {
    'use strict';

    var cfg     = window.MediaPilotAnalyticsTrack || {};
    var nonce   = cfg.nonce || '';
    var restUrl = cfg.restUrl || '';

    function trackEvent( attachmentId, eventType ) {
        if ( ! attachmentId || ! restUrl ) { return; }
        fetch( restUrl, {
            method:    'POST',
            keepalive: true,
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce':   nonce
            },
            body: JSON.stringify( {
                attachment_id: attachmentId,
                event_type:    eventType
            } )
        } ).catch( function () {} );
    }

    function resolveModalAttachmentId( el ) {
        var id = 0;
        try {
            if ( window.wp && wp.media && wp.media.frame && wp.media.frame.model && wp.media.frame.model.get ) {
                id = parseInt( wp.media.frame.model.get( 'id' ), 10 ) || 0;
            }
        } catch ( e ) {}
        if ( ! id && el && el.closest ) {
            var holder = el.closest( '[data-id]' );
            if ( holder ) { id = parseInt( holder.getAttribute( 'data-id' ), 10 ) || 0; }
        }
        return id;
    }

    // Track downloads via the modal "Download file" link.
    document.addEventListener( 'click', function ( e ) {
        if ( ! e.target || ! e.target.closest ) { return; }
        var link = e.target.closest( 'a[download]' );
        if ( ! link ) { return; }
        if ( ! link.closest( '.media-modal, .attachment-details, .attachment-info, .media-frame' ) ) { return; }
        var id = resolveModalAttachmentId( link );
        if ( id ) { trackEvent( id, 'download' ); }
    }, true );

    document.addEventListener( 'DOMContentLoaded', function () {
        if ( typeof wp === 'undefined' || ! wp.media ) { return; }

        // Patch: media editor insert -> 'insert' event per attachment.
        if ( wp.media.editor && typeof wp.media.editor.insert === 'function' ) {
            var _insert = wp.media.editor.insert;
            wp.media.editor.insert = function ( html ) {
                try {
                    var state     = wp.media.frame && wp.media.frame.state && wp.media.frame.state();
                    var selection = state && state.get( 'selection' );
                    if ( selection ) {
                        selection.each( function ( model ) {
                            var id = model && model.get( 'id' );
                            if ( id ) { trackEvent( id, 'insert' ); }
                        } );
                    }
                } catch ( e ) {}
                return _insert.apply( this, arguments );
            };
        }
    } );
}() );
