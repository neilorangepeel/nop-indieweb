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
		statusEl.className = 'nop-like-button__status';
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

			// Optimistically increment the count before the API responds.
			var previousCount    = parseInt( countEl.textContent, 10 ) || 0;
			var optimisticCount  = previousCount + 1;
			countEl.textContent  = optimisticCount;
			countEl.setAttribute( 'aria-label', optimisticCount + ' likes' );
			countEl.hidden = false;

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

					// Correct the optimistic count with the authoritative server value.
					if ( typeof data.count === 'number' ) {
						countEl.textContent = data.count;
						countEl.setAttribute( 'aria-label', data.count + ' likes' );
						countEl.hidden = data.count === 0;
					}
				} )
				.catch( function () {
					// Roll back the optimistic update so the UI stays truthful.
					btn.disabled = false;
					btn.classList.remove( 'is-animating' );
					countEl.textContent = previousCount;
					countEl.setAttribute( 'aria-label', previousCount + ' likes' );
					countEl.hidden = previousCount === 0;
					statusEl.textContent = 'Could not save like. Please try again.';
				} );
		} );
	} );
} )();
