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
} )();
