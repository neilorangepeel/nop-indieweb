( function ( blocks, element, blockEditor, components, ssr, data ) {
	'use strict';

	var el                = element.createElement;
	var SSR               = ssr.default || ssr;
	var useSelect         = data.useSelect;
	var useBlockProps     = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var SelectControl     = components.SelectControl;
	var PanelBody         = components.PanelBody;

	var FIELD_OPTIONS = [
		{ label: 'Venue Name',      value: 'name' },
		{ label: 'Street Address',  value: 'address' },
		{ label: 'City / Town',     value: 'locality' },
		{ label: 'Region',          value: 'region' },
		{ label: 'Country',         value: 'country' },
		{ label: 'Postcode',        value: 'postcode' },
		{ label: 'City & Country',  value: 'locality_country' },
		{ label: 'Full Address',    value: 'full_address' },
		{ label: 'Latitude',        value: 'lat' },
		{ label: 'Longitude',       value: 'lng' },
		{ label: 'Altitude',        value: 'altitude' },
	];

	blocks.registerBlockType( 'nop-indieweb/venue-field', {
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
					el( PanelBody, { title: 'Venue Field', initialOpen: true },
						el( SelectControl, {
							label:    'Field',
							value:    attributes.field,
							options:  FIELD_OPTIONS,
							onChange: function ( value ) {
								setAttributes( { field: value } );
							},
						} )
					)
				),
				el( SSR, {
					block:        'nop-indieweb/venue-field',
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
