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

	blocks.registerBlockType( 'nop-indieweb/weather-temp', {
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
