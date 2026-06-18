/* MediaPilot AI — migration progress poller (Settings → Migration).
   Localised data: window.MediaPilotMigration { slug, restBase, nonce, i18n }. */
( function () {
	'use strict';

	var cfg      = window.MediaPilotMigration || {};
	var slug     = cfg.slug || '';
	var restBase = cfg.restBase || '';
	var nonce    = cfg.nonce || '';
	var i18n     = cfg.i18n || {};

	if ( ! slug ) {
		return;
	}

	function poll() {
		fetch( restBase + '?slug=' + encodeURIComponent( slug ), { headers: { 'X-WP-Nonce': nonce } } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				var pct   = data.total > 0 ? Math.round( ( data.processed / data.total ) * 100 ) : ( data.status === 'done' ? 100 : 0 );
				var bar   = document.querySelector( '.mediapilot-migration-bar[data-slug="' + slug + '"]' );
				var pctEl = document.querySelector( '.mediapilot-migration-pct[data-slug="' + slug + '"]' );
				var stat  = document.querySelector( '.mediapilot-migration-status[data-slug="' + slug + '"]' );

				if ( bar ) { bar.style.width = pct + '%'; }
				if ( pctEl ) { pctEl.textContent = pct + '% (' + data.processed + '/' + data.total + ')'; }

				if ( data.status !== 'running' ) {
					if ( stat ) { stat.textContent = data.status === 'done' ? ( i18n.done || '' ) : ( i18n.error || '' ); }
					setTimeout( function () { window.location.reload(); }, 1500 );
				} else {
					setTimeout( poll, 5000 );
				}
			} )
			.catch( function () { setTimeout( poll, 10000 ); } );
	}

	setTimeout( poll, 5000 );
}() );
