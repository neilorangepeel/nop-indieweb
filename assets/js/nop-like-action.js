/**
 * Shared like-action handler.
 *
 * Wires a click on a like button to the REST endpoint stored on its host
 * element's data-* attributes, with optimistic count update, animation,
 * graceful rollback, and a polite live-region announcement for failures.
 *
 * No nonce is sent: the /like route is public (permission_callback
 * __return_true), and a page-cached nonce outlives its validity — a stale
 * X-WP-Nonce header makes the REST cookie-auth layer reject the request
 * outright, silently breaking likes on long-cached pages.
 *
 * Used by both blocks/like-button/view.js (standalone heart pill) and
 * blocks/post-footer/view.js (compact interaction row). Without this shared
 * helper the two blocks would carry near-identical fetch/animation code.
 *
 * Exposed as window.nopIndieWeb.attachLikeAction so block view scripts can
 * call it without a build step.
 *
 * @param {Object}      cfg
 * @param {string}      cfg.rootSelector    Outer wrapper carrying data-endpoint, data-post-id.
 * @param {string}      cfg.buttonSelector  The clickable element inside the wrapper.
 * @param {string}      cfg.countSelector   The count display inside the button.
 * @param {string}      cfg.iconSelector    The icon element animated on click (used to scope animationend).
 * @param {string}      cfg.statusClass     Class name for the inserted aria-live status region.
 * @param {Function?}   cfg.onLiked         Optional callback invoked once the server confirms the like.
 *                                          Receives the host element. Use to update labels/aria-pressed.
 * @param {Function?}   cfg.formatLabel     Optional formatter for count aria-label. Defaults to "N likes".
 */
( function () {
	'use strict';

	// Resolve wp.i18n.__ when available, else identity — keeps this script
	// working even if wp-i18n didn't load, and lets the strings be extracted.
	var i18n = ( window.wp && window.wp.i18n ) ? window.wp.i18n : null;
	var __   = i18n ? i18n.__ : function ( s ) { return s; };
	var sprintf = i18n && i18n.sprintf ? i18n.sprintf : function ( fmt, n ) { return fmt.replace( '%d', n ); };

	function attachLikeAction( cfg ) {
		var roots = document.querySelectorAll( cfg.rootSelector );
		if ( ! roots.length ) {
			return;
		}

		var formatLabel = cfg.formatLabel || function ( n ) {
			/* translators: %d: number of likes */
			return sprintf( __( '%d likes', 'nop-indieweb' ), n );
		};
		var failMessage = cfg.failMessage || __( 'Could not save like. Please try again.', 'nop-indieweb' );

		roots.forEach( function ( el ) {
			var btn = el.querySelector( cfg.buttonSelector );
			// Count is scoped to the root, not the button — like-button puts the
			// count next to the button (sibling), post-footer puts it inside.
			// Scoping to the root supports both layouts.
			var countEl = el.querySelector( cfg.countSelector );

			if ( ! btn || ! countEl ) {
				return;
			}

			// Lock via an is-liked/is-busy class, never the disabled attribute:
			// the button stays interactive after liking (post-footer reveals the
			// facepile by clicking the count, which a disabled button would
			// swallow) and keyboard/screen-reader focus isn't dropped by
			// disabling the element that currently holds it.
			if ( btn.classList.contains( 'is-liked' ) ) {
				return;
			}

			// Live region announces async state changes to screen readers.
			var statusEl = document.createElement( 'span' );
			statusEl.className = cfg.statusClass;
			statusEl.setAttribute( 'role', 'status' );
			statusEl.setAttribute( 'aria-live', 'polite' );
			el.appendChild( statusEl );

			// Clean up animation class once it finishes so it can replay if needed.
			btn.addEventListener( 'animationend', function () {
				btn.classList.remove( 'is-animating' );
			} );

			btn.addEventListener( 'click', function () {
				// Guard re-entry without disabling, so the count stays clickable.
				if ( btn.classList.contains( 'is-busy' ) || btn.classList.contains( 'is-liked' ) ) {
					return;
				}
				btn.classList.add( 'is-busy' );
				btn.classList.add( 'is-animating' );
				statusEl.textContent = '';

				// Optimistic increment so the UI reacts before the request lands.
				var previousCount   = parseInt( countEl.textContent, 10 ) || 0;
				var optimisticCount = previousCount + 1;
				countEl.textContent = optimisticCount;
				countEl.setAttribute( 'aria-label', formatLabel( optimisticCount ) );
				countEl.hidden = false;

				fetch( el.dataset.endpoint, {
					method:  'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { post_id: parseInt( el.dataset.postId, 10 ) } ),
				} )
					.then( function ( r ) {
						// Treat error statuses (429 rate-limit, 5xx) as failures so
						// the optimistic count rolls back instead of sticking.
						if ( ! r.ok ) {
							throw new Error( 'like failed: ' + r.status );
						}
						return r.json();
					} )
					.then( function ( data ) {
						el.classList.add( 'is-liked' );
						btn.classList.remove( 'is-busy' );
						btn.classList.add( 'is-liked' );
						btn.setAttribute( 'aria-pressed', 'true' );

						if ( typeof cfg.onLiked === 'function' ) {
							cfg.onLiked( el, btn );
						}

						// Correct the optimistic count with the server-authoritative value.
						if ( typeof data.count === 'number' ) {
							countEl.textContent = data.count;
							countEl.setAttribute( 'aria-label', formatLabel( data.count ) );
							countEl.hidden = data.count === 0;
						}
					} )
					.catch( function () {
						btn.classList.remove( 'is-busy' );
						btn.classList.remove( 'is-animating' );
						countEl.textContent = previousCount;
						countEl.setAttribute( 'aria-label', formatLabel( previousCount ) );
						countEl.hidden = previousCount === 0;
						statusEl.textContent = failMessage;
					} );
			} );
		} );
	}

	window.nopIndieWeb = window.nopIndieWeb || {};
	window.nopIndieWeb.attachLikeAction = attachLikeAction;
} )();
