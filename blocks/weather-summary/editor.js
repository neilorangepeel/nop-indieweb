( function ( blocks, element, blockEditor, ssr, data ) {
	'use strict';

	var el            = element.createElement;
	var SSR           = ssr.default || ssr;
	var useSelect     = data.useSelect;
	var useBlockProps = blockEditor.useBlockProps;

	// Inline SVG icon — Phosphor "cloud-fog" feels right for a summary glyph
	// (atmospheric conditions in words). Matches the visual weight of the
	// weather-icon block's bundled Phosphor SVGs and the weather-temp
	// thermometer.
	var summaryIcon = el( 'svg', {
		xmlns:   'http://www.w3.org/2000/svg',
		viewBox: '0 0 256 256',
	},
		el( 'path', { d: 'M48,144H208', fill: 'none', stroke: 'currentColor', strokeLinecap: 'round', strokeLinejoin: 'round', strokeWidth: 16 } ),
		el( 'path', { d: 'M24,176H120', fill: 'none', stroke: 'currentColor', strokeLinecap: 'round', strokeLinejoin: 'round', strokeWidth: 16 } ),
		el( 'path', { d: 'M152,176h80', fill: 'none', stroke: 'currentColor', strokeLinecap: 'round', strokeLinejoin: 'round', strokeWidth: 16 } ),
		el( 'path', { d: 'M48,208H176', fill: 'none', stroke: 'currentColor', strokeLinecap: 'round', strokeLinejoin: 'round', strokeWidth: 16 } ),
		el( 'path', { d: 'M80,112a80,80,0,1,1,80,80', fill: 'none', stroke: 'currentColor', strokeLinecap: 'round', strokeLinejoin: 'round', strokeWidth: 16 } )
	);

	blocks.registerBlockType( 'nop-indieweb/weather-summary', {
		icon: summaryIcon,
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
					block:        'nop-indieweb/weather-summary',
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
