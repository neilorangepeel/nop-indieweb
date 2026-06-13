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
