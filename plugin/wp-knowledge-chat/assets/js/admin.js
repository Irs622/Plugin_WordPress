/**
 * WP Knowledge Chat admin screens: "select all" on the Knowledge Base table and
 * confirmation prompts on destructive Analytics & Leads buttons.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var topAll = document.getElementById( 'wkc-select-all' );
		var botAll = document.getElementById( 'wkc-select-all-bottom' );
		var boxes = document.querySelectorAll( '.wkc-page-checkbox:not(:disabled)' );

		function syncAll( checked ) {
			boxes.forEach( function ( box ) {
				box.checked = checked;
			} );
			if ( topAll ) {
				topAll.checked = checked;
			}
			if ( botAll ) {
				botAll.checked = checked;
			}
		}

		if ( topAll ) {
			topAll.addEventListener( 'change', function () {
				syncAll( this.checked );
			} );
		}
		if ( botAll ) {
			botAll.addEventListener( 'change', function () {
				syncAll( this.checked );
			} );
		}

		document.querySelectorAll( 'form[data-wkc-confirm]' ).forEach( function ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				if ( ! window.confirm( form.getAttribute( 'data-wkc-confirm' ) ) ) {
					event.preventDefault();
				}
			} );
		} );
	} );
}() );
