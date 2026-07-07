/**
 * Post Footer — front-end behaviour.
 *
 * Delegates the like fetch/animation/rollback to the shared helper (the pill
 * stays live after liking so its caret keeps working).
 * The caret next to the like/share count reveals a facepile panel without
 * triggering the pill's own action. Plus the comment pill scroll-to-form and
 * the share pill's Web Share / clipboard fallback.
 */
( function () {
	'use strict';

	var __ = ( window.wp && window.wp.i18n ) ? window.wp.i18n.__ : function ( s ) { return s; };

	if ( window.nopIndieWeb && window.nopIndieWeb.attachLikeAction ) {
		window.nopIndieWeb.attachLikeAction( {
			rootSelector:   '.nop-post-footer',
			buttonSelector: '.nop-post-footer__pill--like',
			countSelector:  '.nop-post-footer__pill-count',
			statusClass:    'nop-post-footer__status',
			onLiked: function ( _el, btn ) {
				btn.setAttribute( 'aria-label', __( 'Liked', 'nop-indieweb' ) );
			},
		} );
	}

	// ── Caret reveals (who liked / reposted) ──────────────────────────────────
	// The caret lives inside the pill's button, so stop the event before it
	// reaches the pill's like/share handler.
	function toggleReveal( caret ) {
		var root  = caret.closest( '.nop-post-footer' );
		var key   = caret.getAttribute( 'data-reveal' );
		if ( ! root || ! key ) { return; }
		var panel = root.querySelector( '.nop-post-footer__reveal-panel[data-panel="' + key + '"]' );
		if ( ! panel ) { return; }
		var open = panel.hasAttribute( 'hidden' );
		if ( open ) { panel.removeAttribute( 'hidden' ); } else { panel.setAttribute( 'hidden', '' ); }
		caret.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		caret.classList.toggle( 'is-open', open );
	}

	document.querySelectorAll( '.nop-post-footer__reveal' ).forEach( function ( caret ) {
		caret.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			e.preventDefault();
			toggleReveal( caret );
		} );
		caret.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar' ) {
				e.stopPropagation();
				e.preventDefault();
				toggleReveal( caret );
			}
		} );
	} );

	// ── Comment pill → scroll to the reply textarea ───────────────────────────
	var commentPill = document.querySelector( '.nop-post-footer__pill--link' );
	if ( commentPill ) {
		commentPill.addEventListener( 'click', function ( e ) {
			var textarea = document.getElementById( 'comment' );
			if ( ! textarea ) { return; }
			e.preventDefault();
			var reduced = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

			// Estimate how long smooth-scroll will take based on distance to centre.
			var dist  = Math.abs( textarea.getBoundingClientRect().top - window.innerHeight / 2 );
			var delay = reduced ? 0 : Math.min( Math.max( dist * 0.4, 150 ), 600 );

			textarea.scrollIntoView( { behavior: reduced ? 'instant' : 'smooth', block: 'center' } );

			setTimeout( function () {
				textarea.focus( { preventScroll: true } );
				textarea.classList.add( 'nop-textarea-invite' );
				setTimeout( function () { textarea.classList.remove( 'nop-textarea-invite' ); }, 1000 );
			}, delay );
		} );
	}

	// ── Share pill → Web Share API with clipboard fallback ────────────────────
	document.querySelectorAll( '.nop-post-footer__pill--share' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var url       = btn.getAttribute( 'data-url' ) || window.location.href;
			var title     = btn.getAttribute( 'data-title' ) || document.title;
			var origLabel = btn.getAttribute( 'aria-label' );

			if ( navigator.share ) {
				navigator.share( { url: url, title: title } ).catch( function () {} );
				return;
			}

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( url ).then( function () {
					btn.classList.add( 'is-copied' );
					btn.setAttribute( 'aria-label', __( 'Copied!', 'nop-indieweb' ) );
					setTimeout( function () {
						btn.classList.remove( 'is-copied' );
						btn.setAttribute( 'aria-label', origLabel );
					}, 2000 );
				} ).catch( function () {} );
			}
		} );
	} );
} )();
