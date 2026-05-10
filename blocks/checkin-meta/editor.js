/**
 * Checkin Meta block — editor.
 *
 * Uses ServerSideRender so the editor shows the live checkin metadata
 * exactly as the frontend renders it.
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

	blocks.registerBlockType( 'nop-indieweb/checkin-meta', {

		edit: function ( props ) {
			var postId = useSelect( function ( select ) {
				var store = select( 'core/editor' );
				if ( ! store ) { return null; }
				var type = store.getCurrentPostType ? store.getCurrentPostType() : null;
				var id   = store.getCurrentPostId   ? store.getCurrentPostId()   : null;
				// The site editor reports the template as the "current post" with a
				// string slug ID (e.g. "theme//slug"), not a numeric post ID.
				// Only pass post_id for actual posts so the integer validation passes.
				return ( type === 'post' && typeof id === 'number' && id > 0 ) ? id : null;
			}, [] );

			return el( SSR, {
				block:        'nop-indieweb/checkin-meta',
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
