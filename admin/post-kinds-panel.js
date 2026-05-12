/**
 * Post Kinds panel — block editor sidebar.
 *
 * Appears on all posts. Shows a Kind selector and the relevant meta fields
 * for the selected kind. Selecting any kind auto-sets the post format to
 * "status". Filling in a URL auto-fills the post title if it is still empty.
 *
 * Layout behaviour:
 *   - Empty post + kind selected  → starter layout applied automatically.
 *   - Post with content + kind    → "Apply layout" button offered in panel.
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

	// ── Kind definitions ────────────────────────────────────────────────────────

	var KINDS = [
		{
			value:  '',
			label:  __( '— None —', 'nop-indieweb' ),
			fields: [],
		},
		{
			value:  'note',
			label:  __( 'Note', 'nop-indieweb' ),
			fields: [],
		},
		{
			value:  'bookmark',
			label:  __( 'Bookmark', 'nop-indieweb' ),
			fields: [
				{ key: 'nop_indieweb_bookmark_of', label: __( 'Bookmark of', 'nop-indieweb' ) },
			],
		},
		{
			value:  'reply',
			label:  __( 'Reply', 'nop-indieweb' ),
			fields: [
				{ key: 'nop_indieweb_in_reply_to', label: __( 'In reply to', 'nop-indieweb' ) },
			],
		},
		{
			value:  'like',
			label:  __( 'Like', 'nop-indieweb' ),
			fields: [
				{ key: 'nop_indieweb_like_of', label: __( 'Like of', 'nop-indieweb' ) },
			],
		},
		{
			value:  'repost',
			label:  __( 'Repost', 'nop-indieweb' ),
			fields: [
				{ key: 'nop_indieweb_repost_of', label: __( 'Repost of', 'nop-indieweb' ) },
			],
		},
		{
			value:  'rsvp',
			label:  __( 'RSVP', 'nop-indieweb' ),
			fields: [
				{ key: 'nop_indieweb_in_reply_to', label: __( 'Event URL', 'nop-indieweb' ) },
				{
					key:     'nop_indieweb_rsvp',
					label:   __( 'Response', 'nop-indieweb' ),
					type:    'select',
					options: [
						{ value: 'yes',        label: __( 'Yes',        'nop-indieweb' ) },
						{ value: 'no',         label: __( 'No',         'nop-indieweb' ) },
						{ value: 'maybe',      label: __( 'Maybe',      'nop-indieweb' ) },
						{ value: 'interested', label: __( 'Interested', 'nop-indieweb' ) },
					],
				},
			],
		},
	];

	var KIND_MAP = {};
	KINDS.forEach( function ( k ) { KIND_MAP[ k.value ] = k; } );

	// ── Starter layouts ─────────────────────────────────────────────────────────
	// These populate the post content area when a kind is selected on an empty post.
	// Bound buttons mirror the template markup: the URL binding reads from post meta
	// set in the sidebar, but the button itself always has static content so it never
	// renders as empty in the block editor.
	// rsvp-meta is server-rendered but shows a placeholder when no data is set.

	function boundButton( metaKey, label ) {
		return [
			'<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"left"}} -->',
			'<div class="wp-block-buttons">',
			'<!-- wp:button {"metadata":{"bindings":{"url":{"source":"core/post-meta","args":{"key":"' + metaKey + '"}}}},"className":"is-style-outline"} -->',
			'<div class="wp-block-button is-style-outline">',
			'<a class="wp-block-button__link wp-element-button" href="#" target="_blank" rel="noreferrer noopener">',
			label + ' →',
			'</a></div>',
			'<!-- /wp:button -->',
			'</div>',
			'<!-- /wp:buttons -->',
		].join( '' );
	}

	var LAYOUTS = {
		note:     '<!-- wp:paragraph /-->',
		bookmark: boundButton( 'nop_indieweb_bookmark_of',  'View Bookmark' )   + '<!-- wp:paragraph /-->',
		reply:    boundButton( 'nop_indieweb_in_reply_to',  'View Original Post' ) + '<!-- wp:paragraph /-->',
		like:     boundButton( 'nop_indieweb_like_of',      'View Post' ),
		repost:   boundButton( 'nop_indieweb_repost_of',    'View Original' ),
		rsvp:     '<!-- wp:nop-indieweb/rsvp-meta /--><!-- wp:paragraph /-->',
	};

	// ── Auto-title helpers ──────────────────────────────────────────────────────

	var MONTHS = [ 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ];

	function domainFromUrl( url ) {
		try { return new URL( url ).hostname; } catch ( e ) { return url; }
	}

	function todayLabel() {
		var d = new Date();
		return d.getDate() + ' ' + MONTHS[ d.getMonth() ] + ' ' + d.getFullYear();
	}

	var TITLE_PATTERNS = {
		bookmark: function ( url ) { return 'Bookmarked · ' + domainFromUrl( url ); },
		reply:    function ( url ) { return todayLabel() + ' · Reply to ' + domainFromUrl( url ); },
		like:     function ( url ) { return 'Liked · ' + domainFromUrl( url ); },
		repost:   function ( url ) { return 'Reposted · ' + domainFromUrl( url ); },
		rsvp:     function ( url ) { return 'RSVP · ' + domainFromUrl( url ); },
	};

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

	// ── Term ID lookup (seeded from PHP via wp_localize_script) ────────────────

	var termSlugToId = {};
	var termIdToSlug = {};
	( window.nopIndieWebKindTerms || [] ).forEach( function ( t ) {
		termSlugToId[ t.slug ] = t.id;
		termIdToSlug[ t.id ]   = t.slug;
	} );

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
		var config = KIND_MAP[ kind ] || KIND_MAP[ '' ];

		function applyLayout( kindValue ) {
			var layout = LAYOUTS[ kindValue ];
			if ( ! layout ) {
				return;
			}
			blockDispatch.resetBlocks( parse( layout ) );
			setOfferedKind( null );
		}

		function setKind( newKind ) {
			var termId = termSlugToId[ newKind ];
			var update = {};
			update.nop_kind = ( newKind && termId ) ? [ termId ] : [];
			if ( newKind ) {
				update.format = 'status';
			}
			editorDispatch.editPost( update );

			if ( newKind && LAYOUTS[ newKind ] ) {
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

			// Auto-fill an empty title when a valid URL is entered.
			if ( ! title && value && isValidUrl( value ) && TITLE_PATTERNS[ kind ] ) {
				update.title = TITLE_PATTERNS[ kind ]( value );
			}

			editorDispatch.editPost( update );
		}

		var hasFields  = config.fields.length > 0;
		var hasOffer   = !! ( offeredKind && KIND_MAP[ offeredKind ] );

		var children = [
			el( SelectControl, {
				key:                     'kind-select',
				label:                   __( 'Kind', 'nop-indieweb' ),
				value:                   kind,
				options:                 KINDS.map( function ( k ) { return { value: k.value, label: k.label }; } ),
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
					}, __( 'Apply ', 'nop-indieweb' ) + KIND_MAP[ offeredKind ].label + __( ' layout', 'nop-indieweb' ) ),
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

} )(
	window.wp.plugins,
	window.wp.editPost,
	window.wp.element,
	window.wp.data,
	window.wp.components,
	window.wp.i18n,
	window.wp.blocks
);
