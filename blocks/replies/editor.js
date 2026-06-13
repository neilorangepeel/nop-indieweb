( function ( blocks, element, blockEditor, components, ssr, data, i18n ) {
	'use strict';

	var el                = element.createElement;
	var SSR               = ssr.default || ssr;
	var useSelect         = data.useSelect;
	var useBlockProps     = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var ToggleControl     = components.ToggleControl;
	var PanelBody         = components.PanelBody;
	var __                = i18n.__;

	blocks.registerBlockType( 'nop-indieweb/replies', {
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
					el( PanelBody, { title: __( 'Replies', 'nop-indieweb' ), initialOpen: true },
						el( ToggleControl, {
							label:    __( 'Show heading', 'nop-indieweb' ),
							help:     __( 'Hide to add your own heading block above the replies.', 'nop-indieweb' ),
							checked:  attributes.showHeading,
							onChange: function ( value ) {
								setAttributes( { showHeading: value } );
							},
						} )
					)
				),
				el( SSR, {
					block:        'nop-indieweb/replies',
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
