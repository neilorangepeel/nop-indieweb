/**
 * Post Footer — front-end behaviour.
 *
 * Delegates the like fetch/animation/rollback to the shared helper.
 * The aria-label swap on liked is the only block-specific bit.
 */
( function () {
	'use strict';

	if ( ! window.nopIndieWeb || ! window.nopIndieWeb.attachLikeAction ) {
		return;
	}

	var __ = ( window.wp && window.wp.i18n ) ? window.wp.i18n.__ : function ( s ) { return s; };

	window.nopIndieWeb.attachLikeAction( {
		rootSelector:   '.nop-post-footer',
		buttonSelector: '.nop-post-footer__pill--like',
		countSelector:  '.nop-post-footer__pill-count',
		statusClass:    'nop-post-footer__status',
		onLiked: function ( _el, btn ) {
			btn.setAttribute( 'aria-label', __( 'Liked', 'nop-indieweb' ) );
		},
	} );

	var commentPill = document.querySelector( '.nop-post-footer__pill--link' );
	if ( commentPill ) {
		commentPill.addEventListener( 'click', function ( e ) {
			var textarea = document.getElementById( 'comment' );
			if ( ! textarea ) { return; }
			e.preventDefault();
			var reduced = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
			textarea.scrollIntoView( { behavior: reduced ? 'instant' : 'smooth', block: 'center' } );
			textarea.focus( { preventScroll: true } );
			var respond = document.getElementById( 'respond' );
			var flashTarget = respond && ( respond.parentElement || respond );
			if ( flashTarget && ! reduced ) {
				flashTarget.classList.add( 'nop-comment-focus-pulse' );
				setTimeout( function () {
					flashTarget.classList.remove( 'nop-comment-focus-pulse' );
				}, 900 );
			}
		} );
	}

	document.querySelectorAll( '.nop-post-footer__pill--share' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var url      = btn.getAttribute( 'data-url' ) || window.location.href;
			var title    = btn.getAttribute( 'data-title' ) || document.title;
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
