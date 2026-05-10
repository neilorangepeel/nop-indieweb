/**
 * Checkin Card block — editor.
 *
 * Uses ServerSideRender so the editor shows the live card (venue, photos,
 * map link) exactly as the frontend renders it.
 *
 * InspectorControls intentionally live in a PHP meta box rather than here —
 * in WP 6.x the block canvas is an <iframe> and React portals from inside
 * the iframe to the sidebar crash the canvas context.
 *
 * No build step — window.wp globals only.
 */
( function ( blocks, element, ssr, data ) {
	'use strict';

	var el       = element.createElement;
	var SSR      = ssr.default || ssr;
	var useSelect = data.useSelect;

	blocks.registerBlockType( 'nop-indieweb/checkin-card', {

		edit: function ( props ) {
			var postId = useSelect( function ( select ) {
				// core/editor is only populated in the post editor, not the site editor.
				var store = select( 'core/editor' );
				return ( store && store.getCurrentPostId ) ? store.getCurrentPostId() : null;
			}, [] );

			return el( SSR, {
				block:        'nop-indieweb/checkin-card',
				attributes:   props.attributes,
				urlQueryArgs: postId ? { post_id: postId } : {},
			} );
		},

		save: function () {
			// Server-side rendered via render.php — no static output.
			return null;
		},
	} );

} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.serverSideRender,
	window.wp.data
);
