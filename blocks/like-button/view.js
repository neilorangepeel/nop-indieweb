/**
 * Like Button — front-end behaviour.
 *
 * The shared like-action helper does the work; this file just wires the
 * block's selectors to it and provides a label-update callback specific
 * to this block's "Like / Liked" text swap.
 */
( function () {
	'use strict';

	if ( ! window.nopIndieWeb || ! window.nopIndieWeb.attachLikeAction ) {
		return;
	}

	window.nopIndieWeb.attachLikeAction( {
		rootSelector:   '.nop-like-button',
		buttonSelector: '.nop-like-button__btn',
		countSelector:  '.nop-like-button__count',
		statusClass:    'nop-like-button__status',
		onLiked: function ( _el, btn ) {
			var label = btn.querySelector( '.nop-like-button__label' );
			if ( label ) {
				label.textContent = 'Liked';
			}
		},
	} );
} )();
