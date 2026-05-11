/**
 * Post Kinds panel — block editor sidebar.
 *
 * Appears on all posts. Shows a Kind selector and the relevant meta fields
 * for the selected kind. Selecting any kind auto-sets the post format to
 * "status". Filling in a URL auto-fills the post title if it is still empty.
 *
 * No build step — window.wp globals only.
 */
( function ( plugins, editPost, element, data, components, i18n ) {
	'use strict';

	var el            = element.createElement;
	var useSelect     = data.useSelect;
	var useDispatch   = data.useDispatch;
	var Panel         = editPost.PluginDocumentSettingPanel;
	var SelectControl = components.SelectControl;
	var TextControl   = components.TextControl;
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
						{ value: 'yes',        label: __( 'Yes',       'nop-indieweb' ) },
						{ value: 'no',         label: __( 'No',        'nop-indieweb' ) },
						{ value: 'maybe',      label: __( 'Maybe',     'nop-indieweb' ) },
						{ value: 'interested', label: __( 'Interested', 'nop-indieweb' ) },
					],
				},
			],
		},
	];

	var KIND_MAP = {};
	KINDS.forEach( function ( k ) { KIND_MAP[ k.value ] = k; } );

	// ── Auto-title patterns (mirrors PHP service class logic) ──────────────────

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

	// ── Panel component ─────────────────────────────────────────────────────────

	function PostKindsPanel() {
		var meta = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		}, [] );

		var title = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'title' ) || '';
		}, [] );

		var dispatch = useDispatch( 'core/editor' );

		var kind   = meta['nop_indieweb_post_kind'] || '';
		var config = KIND_MAP[ kind ] || KIND_MAP[''];

		function setKind( newKind ) {
			var update = { meta: { nop_indieweb_post_kind: newKind } };
			if ( newKind ) {
				update.format = 'status';
			}
			dispatch.editPost( update );
		}

		function setMeta( key, value ) {
			var update = { meta: {} };
			update.meta[ key ] = value;

			// Auto-fill an empty title when a valid URL is entered.
			if ( ! title && value && isValidUrl( value ) && TITLE_PATTERNS[ kind ] ) {
				update.title = TITLE_PATTERNS[ kind ]( value );
			}

			dispatch.editPost( update );
		}

		// Build the panel's children as an explicit array so we can spread it
		// into createElement without a wrapper element.
		var children = [
			el( SelectControl, {
				key:                     'kind-select',
				label:                   __( 'Kind', 'nop-indieweb' ),
				value:                   kind,
				options:                 KINDS.map( function ( k ) { return { value: k.value, label: k.label }; } ),
				onChange:                setKind,
				__nextHasNoMarginBottom: config.fields.length === 0,
			} ),
		];

		config.fields.forEach( function ( field, i ) {
			var isLast = ( i === config.fields.length - 1 );

			if ( field.type === 'select' ) {
				children.push( el( SelectControl, {
					key:                     field.key,
					label:                   field.label,
					value:                   meta[ field.key ] || field.options[0].value,
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
	window.wp.i18n
);
