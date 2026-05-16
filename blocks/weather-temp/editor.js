( function ( blocks, element, blockEditor, components, ssr, data ) {
	'use strict';

	var el                = element.createElement;
	var SSR               = ssr.default || ssr;
	var useSelect         = data.useSelect;
	var useBlockProps     = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var SelectControl     = components.SelectControl;
	var ToggleControl     = components.ToggleControl;
	var PanelBody         = components.PanelBody;

	var UNIT_OPTIONS = [
		{ label: '°C (Celsius)',    value: 'c' },
		{ label: '°F (Fahrenheit)', value: 'f' },
	];

	// Inline SVG icon — Phosphor "thermometer-simple". The Dashicons set has no
	// thermometer glyph, and block.json only accepts dashicon slugs, so the
	// icon must be passed here in JS. Matches the visual weight of the
	// weather-icon block's Phosphor SVGs.
	var thermometerIcon = el( 'svg', {
		xmlns:  'http://www.w3.org/2000/svg',
		viewBox: '0 0 256 256',
	},
		el( 'line', { x1: 128, y1: 160, x2: 128, y2: 88, fill: 'none', stroke: 'currentColor', strokeLinecap: 'round', strokeLinejoin: 'round', strokeWidth: 16 } ),
		el( 'circle', { cx: 128, cy: 184, r: 24, fill: 'none', stroke: 'currentColor', strokeLinecap: 'round', strokeLinejoin: 'round', strokeWidth: 16 } ),
		el( 'path', { d: 'M96,48a32,32,0,0,1,64,0v90a56,56,0,1,1-64,0Z', fill: 'none', stroke: 'currentColor', strokeLinecap: 'round', strokeLinejoin: 'round', strokeWidth: 16 } )
	);

	blocks.registerBlockType( 'nop-indieweb/weather-temp', {
		icon: thermometerIcon,
		edit: function ( props ) {
			var blockProps    = useBlockProps();
			var attributes    = props.attributes;
			var setAttributes = props.setAttributes;

			var postId = useSelect( function ( select ) {
				var store = select( 'core/editor' );
				if ( ! store ) { return null; }
				var type = store.getCurrentPostType ? store.getCurrentPostType() : null;
				var id   = store.getCurrentPostId   ? store.getCurrentPostId()   : null;
				return ( type === 'post' && typeof id === 'number' && id > 0 ) ? id : null;
			}, [] );

			return el( 'div', blockProps,
				el( InspectorControls, null,
					el( PanelBody, { title: 'Weather Temperature', initialOpen: true },
						el( SelectControl, {
							label:    'Unit',
							value:    attributes.unit,
							options:  UNIT_OPTIONS,
							onChange: function ( value ) {
								setAttributes( { unit: value } );
							},
						} ),
						el( ToggleControl, {
							label:    'Show unit symbol (°C / °F)',
							checked:  attributes.showSymbol,
							onChange: function ( value ) {
								setAttributes( { showSymbol: value } );
							},
						} )
					)
				),
				el( SSR, {
					block:        'nop-indieweb/weather-temp',
					attributes:   attributes,
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
	window.wp.components,
	window.wp.serverSideRender,
	window.wp.data
);
