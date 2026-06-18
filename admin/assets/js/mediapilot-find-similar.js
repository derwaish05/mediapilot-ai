/* MediaPilot AI — "Find Similar" attachment button handler.
   Localised data: window.MediaPilotFindSimilar { restBase, nonce, i18n }.
   Falls back to window.MediaPilotConfig.restBase/nonce when present. */
(function () {
    'use strict';

    var cfg       = window.MediaPilotFindSimilar || {};
    var REST_BASE = ( window.MediaPilotConfig && window.MediaPilotConfig.restBase ) || cfg.restBase || '';
    var NONCE     = ( window.MediaPilotConfig && window.MediaPilotConfig.nonce ) || cfg.nonce || '';
    var i18n      = cfg.i18n || {};

    function message( container, color, text ) {
        container.textContent = '';
        var p = document.createElement( 'p' );
        p.style.cssText = 'color:' + color + ';font-size:12px;margin:4px 0';
        p.textContent = text;
        container.appendChild( p );
    }

    document.addEventListener( 'click', function ( e ) {
        var btn = e.target.closest( '.mediapilot-find-similar-btn' );
        if ( ! btn ) { return; }

        var id        = btn.dataset.id;
        var container = document.querySelector( '.mediapilot-similar-results[data-id="' + id + '"]' );
        if ( ! container ) { return; }

        btn.disabled    = true;
        btn.textContent = i18n.searching || '';

        fetch( REST_BASE + 'files/similar/' + id, { headers: { 'X-WP-Nonce': NONCE } } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( res ) {
                if ( ! res.success || ! res.data || ! res.data.similar.length ) {
                    message( container, '#666', i18n.none || '' );
                    btn.textContent = i18n.find || '';
                    btn.disabled    = false;
                    return;
                }

                var files = res.data.similar;
                container.textContent = '';

                var count = document.createElement( 'p' );
                count.style.cssText = 'font-size:11px;color:#555;margin:4px 0 6px';
                count.textContent = files.length + ' ' + ( i18n.similar || '' );
                container.appendChild( count );

                files.forEach( function ( f ) {
                    var a = document.createElement( 'a' );
                    a.href   = f.url;
                    a.target = '_blank';
                    a.rel    = 'noopener';
                    a.title  = f.filename;
                    a.style.cssText = 'display:inline-block;margin:2px';

                    var img = document.createElement( 'img' );
                    img.src    = f.thumbnail_url;
                    img.width  = 60;
                    img.height = 60;
                    img.style.cssText = 'object-fit:cover;border-radius:3px;border:1px solid #ddd;vertical-align:top';

                    a.appendChild( img );
                    container.appendChild( a );
                } );

                btn.textContent = i18n.refresh || '';
                btn.disabled    = false;
            } )
            .catch( function () {
                message( container, '#c00', i18n.error || '' );
                btn.textContent = i18n.find || '';
                btn.disabled    = false;
            } );
    } );
}());
