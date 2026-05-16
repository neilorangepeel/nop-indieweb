( function ( blocks, element, blockEditor, ssr, data ) {
	'use strict';

	var el            = element.createElement;
	var SSR           = ssr.default || ssr;
	var useSelect     = data.useSelect;
	var useBlockProps = blockEditor.useBlockProps;

	blocks.registerBlockType( 'nop-indieweb/weather-icon', {
		edit: function ( props ) {
			var blockProps = useBlockProps();

			var postId = useSelect( function ( select ) {
				var store = select( 'core/editor' );
				if ( ! store ) { return null; }
				var type = store.getCurrentPostType ? store.getCurrentPostType() : null;
				var id   = store.getCurrentPostId   ? store.getCurrentPostId()   : null;
				return ( type === 'post' && typeof id === 'number' && id > 0 ) ? id : null;
			}, [] );

			return el( 'div', blockProps,
				el( SSR, {
					block:        'nop-indieweb/weather-icon',
					attributes:   props.attributes,
					urlQueryArgs: postId ? { post_id: postId } : {},
				} )
			);
		},

		save: function () {
			return null;
		},
	} );

} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.serverSideRender,
	window.wp.data
);
