/**
 * Syndication Panel — editor.
 * Uses ServerSideRender so the editor preview matches the front end exactly,
 * including the "no syndication, render nothing" path.
 */
( function ( blocks, element, ssr, data ) {
	'use strict';

	var el        = element.createElement;
	var SSR       = ssr.default || ssr;
	var useSelect = data.useSelect;

	blocks.registerBlockType( 'nop-indieweb/syndication-panel', {

		edit: function ( props ) {
			var postId = useSelect( function ( select ) {
				var store = select( 'core/editor' );
				if ( ! store ) { return null; }
				var type = store.getCurrentPostType ? store.getCurrentPostType() : null;
				var id   = store.getCurrentPostId   ? store.getCurrentPostId()   : null;
				return ( type === 'post' && typeof id === 'number' && id > 0 ) ? id : null;
			}, [] );

			return el( SSR, {
				block:        'nop-indieweb/syndication-panel',
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
