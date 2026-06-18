/* MediaPilot AI — Replace File / Restore Version UI (Edit Media + media modal).
   Localised data: window.MediaPilotReplace.i18n
   REST endpoint + nonce come from each control's data-api / data-nonce. */
(function () {
    'use strict';

    var i18n = ( window.MediaPilotReplace && window.MediaPilotReplace.i18n ) || {};

    // ── Restore version buttons ───────────────────────────────────────────────
    document.querySelectorAll( '.mediapilot-restore-btn' ).forEach( function ( btn ) {
        btn.addEventListener( 'click', async function () {
            if ( ! window.confirm( i18n.restoreConfirm || '' ) ) { return; }

            var api   = btn.dataset.api;
            var nonce = btn.dataset.nonce;
            btn.disabled    = true;
            btn.textContent = i18n.restoring || '';

            try {
                var res  = await fetch( api, { method: 'POST', headers: { 'X-WP-Nonce': nonce } } );
                var data = await res.json();
                if ( data.success ) {
                    btn.textContent = i18n.restored || '';
                    setTimeout( function () { window.location.reload(); }, 1200 );
                } else {
                    btn.textContent = i18n.error || '';
                    window.alert( data.message || i18n.restoreFailed || '' );
                    btn.disabled = false;
                }
            } catch ( e ) {
                btn.textContent = i18n.error || '';
                btn.disabled = false;
            }
        } );
    } );

    // ── Replace file controls ─────────────────────────────────────────────────
    document.querySelectorAll( '.mediapilot-replace-wrap' ).forEach( function ( wrap ) {
        var input  = wrap.querySelector( '.mediapilot-replace-input' );
        var btn    = wrap.querySelector( '.mediapilot-replace-btn' );
        var status = wrap.querySelector( '.mediapilot-replace-status' );
        var api    = wrap.dataset.api;
        var nonce  = wrap.dataset.nonce;

        if ( ! input || ! btn || ! status ) { return; }

        input.addEventListener( 'change', function () {
            btn.disabled = ! this.files.length;
        } );

        btn.addEventListener( 'click', async function () {
            if ( ! input.files.length ) { return; }

            btn.disabled       = true;
            status.textContent = i18n.uploading || '';
            status.style.color = '#64748b';

            var fd = new FormData();
            fd.append( 'file', input.files[0] );

            try {
                var res  = await fetch( api, { method: 'POST', headers: { 'X-WP-Nonce': nonce }, body: fd } );
                var data = await res.json();

                if ( data.success ) {
                    status.textContent = i18n.replaced || '';
                    status.style.color = '#16a34a';
                    setTimeout( function () { window.location.reload(); }, 1200 );
                } else {
                    status.textContent = data.message || i18n.errorDot || '';
                    status.style.color = '#ef4444';
                    btn.disabled = false;
                }
            } catch ( e ) {
                status.textContent = e.message;
                status.style.color = '#ef4444';
                btn.disabled = false;
            }
        } );
    } );
}());
