/* MediaPilot AI — WooCommerce product gallery "Sync Now" meta box button.
   Localised data: window.MediaPilotWooSync.i18n
   The REST URL and nonce are read from the button's data-url / data-nonce. */
( function () {
	'use strict';

	var i18n   = ( window.MediaPilotWooSync && window.MediaPilotWooSync.i18n ) || {};
	var btn    = document.getElementById( 'mediapilot-woo-sync-now' );
	var result = document.getElementById( 'mediapilot-woo-sync-result' );

	if ( ! btn || ! result ) {
		return;
	}

	btn.addEventListener( 'click', function () {
		btn.disabled       = true;
		result.style.color = '';
		result.textContent = i18n.syncing || '';

		fetch( btn.dataset.url, {
			method:  'POST',
			headers: {
				'X-WP-Nonce':   btn.dataset.nonce,
				'Content-Type': 'application/json'
			},
			body: '{}'
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( data ) {
			if ( data && data.success ) {
				result.style.color = 'green';
				result.textContent = ( data.data && data.data.message )
					? data.data.message
					: ( i18n.synced || '' );
			} else {
				result.style.color = '#cc1818';
				result.textContent = ( data && data.message )
					? data.message
					: ( i18n.failed || '' );
			}
			btn.disabled = false;
		} )
		.catch( function () {
			result.style.color = '#cc1818';
			result.textContent = i18n.reqFailed || '';
			btn.disabled = false;
		} );
	} );
} )();
