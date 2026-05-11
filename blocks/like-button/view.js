( function () {
	'use strict';

	document.querySelectorAll( '.nop-like-button' ).forEach( function ( el ) {
		var btn     = el.querySelector( '.nop-like-button__btn' );
		var countEl = el.querySelector( '.nop-like-button__count' );

		if ( ! btn || btn.disabled ) {
			return;
		}

		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			btn.classList.add( 'is-animating' );

			fetch( el.dataset.endpoint, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   el.dataset.nonce,
				},
				body: JSON.stringify( { post_id: parseInt( el.dataset.postId, 10 ) } ),
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( data ) {
					el.classList.add( 'is-liked' );
					btn.setAttribute( 'aria-pressed', 'true' );
					btn.querySelector( '.nop-like-button__label' ).textContent = 'Liked';

					if ( typeof data.count === 'number' ) {
						countEl.textContent = data.count;
						countEl.hidden      = data.count === 0;
					}
				} )
				.catch( function () {
					// Restore on network failure so the visitor can try again.
					btn.disabled = false;
					btn.classList.remove( 'is-animating' );
				} );
		} );
	} );
} )();
