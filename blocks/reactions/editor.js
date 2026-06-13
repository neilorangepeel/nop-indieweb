( function ( blocks, element, blockEditor, components, ssr, data, i18n ) {
	'use strict';

	var el                = element.createElement;
	var SSR               = ssr.default || ssr;
	var useSelect         = data.useSelect;
	var useBlockProps     = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var RangeControl      = components.RangeControl;
	var ToggleControl     = components.ToggleControl;
	var PanelBody         = components.PanelBody;
	var __                = i18n.__;

	blocks.registerBlockType( 'nop-indieweb/reactions', {
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
					el( PanelBody, { title: __( 'Reactions', 'nop-indieweb' ), initialOpen: true },
						el( RangeControl, {
							label:    __( 'Maximum avatars per group', 'nop-indieweb' ),
							value:    attributes.maxAvatars,
							min:      1,
							max:      12,
							onChange: function ( value ) {
								setAttributes( { maxAvatars: value } );
							},
						} ),
						el( ToggleControl, {
							label:    __( 'Show count numbers', 'nop-indieweb' ),
							checked:  attributes.showCounts,
							onChange: function ( value ) {
								setAttributes( { showCounts: value } );
							},
						} )
					)
				),
				el( SSR, {
					block:        'nop-indieweb/reactions',
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
	window.wp.data,
	window.wp.i18n
);
