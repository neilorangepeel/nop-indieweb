( function () {
	'use strict';

	document.querySelectorAll( '.nop-post-footer' ).forEach( function ( el ) {
		var btn     = el.querySelector( '.nop-post-footer__pill--like' );
		var countEl = btn ? btn.querySelector( '.nop-post-footer__pill-count' ) : null;

		if ( ! btn || btn.disabled || ! countEl ) {
			return;
		}

		var statusEl = document.createElement( 'span' );
		statusEl.className = 'nop-post-footer__status';
		statusEl.setAttribute( 'role', 'status' );
		statusEl.setAttribute( 'aria-live', 'polite' );
		el.appendChild( statusEl );

		btn.addEventListener( 'animationend', function () {
			btn.classList.remove( 'is-animating' );
		} );

		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			btn.classList.add( 'is-animating' );
			statusEl.textContent = '';

			var previousCount   = parseInt( countEl.textContent, 10 ) || 0;
			var optimisticCount = previousCount + 1;
			countEl.textContent = optimisticCount;
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
					btn.classList.add( 'is-liked' );
					btn.setAttribute( 'aria-pressed', 'true' );
					btn.setAttribute( 'aria-label', 'Liked' );

					if ( typeof data.count === 'number' ) {
						countEl.textContent = data.count;
						countEl.setAttribute( 'aria-label', data.count + ' likes' );
						countEl.hidden = data.count === 0;
					}
				} )
				.catch( function () {
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
