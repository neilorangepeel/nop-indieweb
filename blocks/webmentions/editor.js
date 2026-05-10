/**
 * Webmentions block — editor.
 *
 * Uses ServerSideRender so the editor shows live webmention data
 * exactly as the frontend renders it.
 *
 * No build step — window.wp globals only.
 */
( function ( blocks, element, ssr, data ) {
	'use strict';

	var el        = element.createElement;
	var SSR       = ssr.default || ssr;
	var useSelect = data.useSelect;

	blocks.registerBlockType( 'nop-indieweb/webmentions', {

		edit: function ( props ) {
			var postId = useSelect( function ( select ) {
				var store = select( 'core/editor' );
				if ( ! store ) { return null; }
				var type = store.getCurrentPostType ? store.getCurrentPostType() : null;
				var id   = store.getCurrentPostId   ? store.getCurrentPostId()   : null;
				return ( type === 'post' && typeof id === 'number' && id > 0 ) ? id : null;
			}, [] );

			return el( SSR, {
				block:        'nop-indieweb/webmentions',
				attributes:   props.attributes,
				urlQueryArgs: postId ? { post_id: postId } : {},
			} );
		},

		save: function () {
			return null;
		},
	} );

} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.serverSideRender,
	window.wp.data
);
