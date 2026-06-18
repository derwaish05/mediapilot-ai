/* MediaPilot AI — Client Portal settings page (create / revoke share links).
   Localised data: window.MediaPilotPortalSettings { apiRoot, nonce, i18n }. */
( function () {
	'use strict';

	var cfg      = window.MediaPilotPortalSettings || {};
	var API_ROOT = cfg.apiRoot || '';
	var NONCE    = cfg.nonce || '';
	var i18n     = cfg.i18n || {};

	function apiRequest( method, path, body ) {
		return fetch( API_ROOT + path, {
			method: method,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': NONCE
			},
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( r ) { return r.json(); } );
	}

	// Create share link.
	var createBtn = document.getElementById( 'mediapilot-share-create' );
	if ( createBtn ) {
		createBtn.addEventListener( 'click', async function () {
			var folderId = document.getElementById( 'mediapilot-share-folder' ).value;
			if ( ! folderId ) { window.alert( i18n.selectFolder || '' ); return; }

			var btn = this;
			btn.disabled    = true;
			btn.textContent = i18n.creating || '';

			var errEl = document.getElementById( 'mediapilot-share-error' );
			errEl.style.display = 'none';

			var body = {
				password:     document.getElementById( 'mediapilot-share-password' ).value,
				expires_at:   document.getElementById( 'mediapilot-share-expires' ).value || null,
				header_color: document.getElementById( 'mediapilot-share-color' ).value,
				logo_url:     document.getElementById( 'mediapilot-share-logo' ).value
			};

			try {
				var res = await apiRequest( 'POST', '/folders/' + folderId + '/share', body );
				if ( res.success ) {
					window.location.reload();
				} else {
					errEl.textContent   = res.message || ( i18n.errorCreating || '' );
					errEl.style.display = 'block';
				}
			} catch ( e ) {
				errEl.textContent   = e.message;
				errEl.style.display = 'block';
			} finally {
				btn.disabled    = false;
				btn.textContent = i18n.generate || '';
			}
		} );
	}

	// Revoke links.
	document.querySelectorAll( '.mediapilot-revoke-btn' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', async function () {
			if ( ! window.confirm( this.dataset.confirm ) ) { return; }
			var id = this.dataset.id;
			try {
				var res = await apiRequest( 'DELETE', '/shares/' + id );
				if ( res.success ) {
					var row = document.querySelector( 'tr[data-id="' + id + '"]' );
					if ( row ) { row.remove(); }
				}
			} catch ( e ) {
				window.alert( e.message );
			}
		} );
	} );
}() );
