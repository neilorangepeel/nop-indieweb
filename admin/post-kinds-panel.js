/**
 * Post Kinds panel — block editor sidebar.
 *
 * Renders a kind selector and any per-kind URL/meta fields. The list of
 * kinds and their per-kind behaviour come from PHP via
 * window.nopIndieWebKindsPanel (built by Kind_Taxonomy::get_editor_panel_config()
 * and localized in Post_Kinds_Panel::enqueue()).
 *
 * To add a new kind: add the term in Kind_Taxonomy::FLAT_KINDS and an entry
 * in get_editor_panel_config(). No JS change required.
 *
 * Behaviour:
 *   - Empty post + kind selected  → starter layout applied automatically.
 *   - Post with content + kind    → "Apply layout" button offered in panel.
 *   - First field with a valid URL → auto-fills an empty title (hostname),
 *     gated by the kind's title_from_url flag.
 *
 * No build step — window.wp globals only.
 */
( function ( plugins, editPost, element, data, components, i18n, blocks ) {
	'use strict';

	var el            = element.createElement;
	var useState      = element.useState;
	var useSelect     = data.useSelect;
	var useDispatch   = data.useDispatch;
	var parse         = blocks.parse;
	var Panel         = editPost.PluginDocumentSettingPanel;
	var SelectControl = components.SelectControl;
	var TextControl   = components.TextControl;
	var Button        = components.Button;
	var __            = i18n.__;

	// ── Config from PHP ─────────────────────────────────────────────────────────

	var panelData = window.nopIndieWebKindsPanel || { terms: [], config: {} };
	var KIND_CONFIG = panelData.config || {};
	var KIND_TERMS  = panelData.terms  || [];

	var termSlugToId = {};
	var termIdToSlug = {};
	KIND_TERMS.forEach( function ( t ) {
		termSlugToId[ t.slug ] = t.id;
		termIdToSlug[ t.id ]   = t.slug;
	} );

	var DROPDOWN_OPTIONS = [ { value: '', label: __( '— None —', 'nop-indieweb' ) } ];
	Object.keys( KIND_CONFIG ).forEach( function ( slug ) {
		DROPDOWN_OPTIONS.push( { value: slug, label: KIND_CONFIG[ slug ].label } );
	} );

	var EMPTY_KIND = { label: '', fields: [], layout: '', title_from_url: false };

	// ── Helpers ─────────────────────────────────────────────────────────────────

	function domainFromUrl( url ) {
		try { return new URL( url ).hostname; } catch ( e ) { return url; }
	}

	function isValidUrl( url ) {
		try { new URL( url ); return true; } catch ( e ) { return false; }
	}

	// Post is considered empty if it has no blocks, or exactly one empty paragraph.
	function isEditorEmpty( blockList ) {
		if ( ! blockList || blockList.length === 0 ) {
			return true;
		}
		if ( blockList.length === 1 ) {
			var b = blockList[ 0 ];
			return b.name === 'core/paragraph' && ! ( b.attributes && b.attributes.content );
		}
		return false;
	}

	// ── Panel component ─────────────────────────────────────────────────────────

	function PostKindsPanel() {
		var meta = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		}, [] );

		var currentTermIds = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'nop_kind' ) || [];
		}, [] );

		var title = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'title' ) || '';
		}, [] );

		var currentBlocks = useSelect( function ( select ) {
			return select( 'core/block-editor' ).getBlocks();
		}, [] );

		var editorDispatch = useDispatch( 'core/editor' );
		var blockDispatch  = useDispatch( 'core/block-editor' );

		var offeredState   = useState( null );
		var offeredKind    = offeredState[ 0 ];
		var setOfferedKind = offeredState[ 1 ];

		// Derive kind slug: prefer live taxonomy attribute, fall back to meta read-cache.
		var kind = ( currentTermIds.length > 0 && termIdToSlug[ currentTermIds[ 0 ] ] )
			? termIdToSlug[ currentTermIds[ 0 ] ]
			: ( meta[ 'nop_indieweb_post_kind' ] || '' );
		var config = KIND_CONFIG[ kind ] || EMPTY_KIND;

		function applyLayout( kindValue ) {
			var c = KIND_CONFIG[ kindValue ];
			if ( ! c || ! c.layout ) {
				return;
			}
			blockDispatch.resetBlocks( parse( c.layout ) );
			setOfferedKind( null );
		}

		function setKind( newKind ) {
			var termId = termSlugToId[ newKind ];
			var update = { nop_kind: ( newKind && termId ) ? [ termId ] : [] };
			editorDispatch.editPost( update );

			var newConfig = KIND_CONFIG[ newKind ];
			if ( newConfig && newConfig.layout ) {
				if ( isEditorEmpty( currentBlocks ) ) {
					applyLayout( newKind );
				} else {
					setOfferedKind( newKind );
				}
			} else {
				setOfferedKind( null );
			}
		}

		function setMeta( key, value ) {
			var update = { meta: {} };
			update.meta[ key ] = value;

			// Auto-fill an empty title with the hostname of a valid URL when the
			// kind opts in. Non-URL values fall through harmlessly.
			if ( ! title && value && config.title_from_url && isValidUrl( value ) ) {
				update.title = domainFromUrl( value );
			}

			editorDispatch.editPost( update );
		}

		var hasFields = config.fields.length > 0;
		var hasOffer  = !! ( offeredKind && KIND_CONFIG[ offeredKind ] );

		var children = [
			el( SelectControl, {
				key:                     'kind-select',
				label:                   __( 'Kind', 'nop-indieweb' ),
				value:                   kind,
				options:                 DROPDOWN_OPTIONS,
				onChange:                setKind,
				__nextHasNoMarginBottom: ! hasFields && ! hasOffer,
			} ),
		];

		if ( hasOffer ) {
			children.push(
				el( 'div', { key: 'layout-offer', style: { marginTop: '8px' } },
					el( Button, {
						variant: 'secondary',
						size:    'small',
						onClick: function () { applyLayout( offeredKind ); },
					}, __( 'Apply ', 'nop-indieweb' ) + KIND_CONFIG[ offeredKind ].label + __( ' layout', 'nop-indieweb' ) ),
					el( 'p', {
						style: { fontSize: '11px', color: '#757575', margin: '4px 0 0' },
					}, __( 'Replaces current content.', 'nop-indieweb' ) )
				)
			);
		}

		config.fields.forEach( function ( field, i ) {
			var isLast = ( i === config.fields.length - 1 );

			if ( field.type === 'select' ) {
				children.push( el( SelectControl, {
					key:                     field.key,
					label:                   field.label,
					value:                   meta[ field.key ] || field.options[ 0 ].value,
					options:                 field.options,
					onChange:                function ( v ) { setMeta( field.key, v ); },
					__nextHasNoMarginBottom: isLast,
				} ) );
			} else {
				children.push( el( TextControl, {
					key:                     field.key,
					label:                   field.label,
					type:                    'url',
					value:                   meta[ field.key ] || '',
					placeholder:             'https://',
					onChange:                function ( v ) { setMeta( field.key, v ); },
					__nextHasNoMarginBottom: isLast,
				} ) );
			}
		} );

		return el.apply( null, [ Panel, { name: 'nop-indieweb-post-kind', title: __( 'Post Kind', 'nop-indieweb' ) } ].concat( children ) );
	}

	plugins.registerPlugin( 'nop-indieweb-post-kinds-panel', { render: PostKindsPanel } );

	// Hide the default Gutenberg taxonomy panel for nop_kind. Kind is conceptually
	// single-select (see Kind_Taxonomy doc-comment) and is managed by the panel
	// above; the checkbox-list panel WordPress auto-generates lets users assign
	// multiple terms and confuses the single canonical write path.
	data.dispatch( 'core/edit-post' ).removeEditorPanel( 'taxonomy-panel-nop_kind' );

} )(
	window.wp.plugins,
	window.wp.editPost,
	window.wp.element,
	window.wp.data,
	window.wp.components,
	window.wp.i18n,
	window.wp.blocks
);
