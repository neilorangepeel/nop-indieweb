( function () {
	'use strict';

	document.querySelectorAll( '.nop-like-button' ).forEach( function ( el ) {
		var btn     = el.querySelector( '.nop-like-button__btn' );
		var countEl = el.querySelector( '.nop-like-button__count' );

		if ( ! btn || btn.disabled ) {
			return;
		}

		// Live region announces async state changes to screen readers.
		var statusEl = document.createElement( 'span' );
		statusEl.className = 'screen-reader-text';
		statusEl.setAttribute( 'role', 'status' );
		statusEl.setAttribute( 'aria-live', 'polite' );
		el.appendChild( statusEl );

		// Clean up animation class once it finishes so it can replay if needed.
		btn.addEventListener( 'animationend', function () {
			btn.classList.remove( 'is-animating' );
		} );

		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			btn.classList.add( 'is-animating' );
			statusEl.textContent = '';

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
						countEl.setAttribute( 'aria-label', data.count + ' likes' );
						countEl.hidden = data.count === 0;
					}
				} )
				.catch( function () {
					btn.disabled = false;
					btn.classList.remove( 'is-animating' );
					statusEl.textContent = 'Could not save like. Please try again.';
				} );
		} );
	} );
} )();
