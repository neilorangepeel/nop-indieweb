( function ( blocks, element, blockEditor, components, ssr, data, i18n ) {
	'use strict';

	var el                = element.createElement;
	var SSR               = ssr.default || ssr;
	var useSelect         = data.useSelect;
	var useBlockProps     = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var ToggleControl     = components.ToggleControl;
	var TextControl       = components.TextControl;
	var PanelBody         = components.PanelBody;
	var __                = i18n.__;

	blocks.registerBlockType( 'nop-indieweb/comment-form', {
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
					el( PanelBody, { title: __( 'Comment form', 'nop-indieweb' ), initialOpen: true },
						el( ToggleControl, {
							label:    __( 'Show heading', 'nop-indieweb' ),
							checked:  attributes.showHeading,
							onChange: function ( value ) { setAttributes( { showHeading: value } ); },
						} ),
						attributes.showHeading && el( TextControl, {
							label:       __( 'Heading', 'nop-indieweb' ),
							placeholder: __( 'Leave a reply', 'nop-indieweb' ),
							value:       attributes.heading,
							onChange:    function ( value ) { setAttributes( { heading: value } ); },
						} ),
						el( TextControl, {
							label:       __( 'Placeholder', 'nop-indieweb' ),
							placeholder: __( 'Add a comment…', 'nop-indieweb' ),
							value:       attributes.placeholder,
							onChange:    function ( value ) { setAttributes( { placeholder: value } ); },
						} ),
						el( ToggleControl, {
							label:    __( 'Other ways to reply', 'nop-indieweb' ),
							help:     __( 'Links to the post’s copies elsewhere, and a mention from the visitor’s own site.', 'nop-indieweb' ),
							checked:  attributes.showOtherWays,
							onChange: function ( value ) { setAttributes( { showOtherWays: value } ); },
						} )
					)
				),
				el( SSR, {
					block:        'nop-indieweb/comment-form',
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
