/**
 * Shared editor registration for blocks rendered via ServerSideRender.
 *
 * Each SSR block's editor.js calls:
 *   window.nopIndieweb.registerSSRBlock( 'nop-indieweb/<name>' );
 *
 * The site editor reports its template as the "current post" with a
 * string slug ID, so we only pass urlQueryArgs.post_id when we have a
 * real numeric post — keeps render.php's integer validation happy.
 */
( function ( blocks, element, ssr, data ) {
	'use strict';

	var el        = element.createElement;
	var SSR       = ssr.default || ssr;
	var useSelect = data.useSelect;

	window.nopIndieweb = window.nopIndieweb || {};

	window.nopIndieweb.registerSSRBlock = function ( name ) {
		blocks.registerBlockType( name, {

			edit: function ( props ) {
				var postId = useSelect( function ( select ) {
					var store = select( 'core/editor' );
					if ( ! store ) { return null; }
					var type = store.getCurrentPostType ? store.getCurrentPostType() : null;
					var id   = store.getCurrentPostId   ? store.getCurrentPostId()   : null;
					return ( type === 'post' && typeof id === 'number' && id > 0 ) ? id : null;
				}, [] );

				return el( SSR, {
					block:        name,
					attributes:   props.attributes,
					urlQueryArgs: postId ? { post_id: postId } : {},
				} );
			},

			save: function () {
				return null;
			},
		} );
	};

} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.serverSideRender,
	window.wp.data
);
