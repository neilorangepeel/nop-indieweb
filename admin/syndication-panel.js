/**
 * Syndication panel — block editor sidebar.
 *
 * Shows a checkbox per enabled syndicator (Mastodon, Bluesky).
 * Checked by default. Unchecking persists to nop_indieweb_syndicate_to meta,
 * which the syndication manager reads on publish to skip that platform.
 *
 * No build step — window.wp globals only.
 */
( function ( plugins, editor, editPost, element, data, components, i18n ) {
	'use strict';

	var el          = element.createElement;
	var useSelect   = data.useSelect;
	var useDispatch = data.useDispatch;
	var Panel       = ( editor && editor.PluginDocumentSettingPanel ) || editPost.PluginDocumentSettingPanel;
	var CheckboxControl = components.CheckboxControl;
	var __          = i18n.__;

	var syndicators = window.nopIndiewebSyndication ? window.nopIndiewebSyndication.syndicators : [];

	if ( ! syndicators.length ) {
		return;
	}

	function SyndicationPanel() {
		var meta = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		}, [] );

		var editPostFn = useDispatch( 'core/editor' ).editPost;

		var status = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'status' );
		}, [] );

		// Hide panel on already-published posts — syndication is one-shot.
		if ( status === 'publish' ) {
			var syndication = meta['nop_indieweb_syndication'] || [];
			if ( syndication.length ) {
				return null;
			}
		}

		var selected = meta['nop_indieweb_syndicate_to'];
		// 'none' is the explicit "this site only" sentinel — show nothing checked.
		// An empty/missing selection defaults to all syndicators.
		var isNone = Array.isArray( selected ) && selected.indexOf( 'none' ) !== -1;
		var activeTargets = isNone
			? []
			: ( Array.isArray( selected ) && selected.length )
				? selected
				: syndicators.map( function ( s ) { return s.slug; } );

		function toggle( slug, checked ) {
			var next = checked
				? activeTargets.concat( [ slug ] ).filter( function ( v, i, a ) { return a.indexOf( v ) === i; } )
				: activeTargets.filter( function ( s ) { return s !== slug; } );
			// Unchecking the last platform stores the sentinel, not an empty array —
			// empty has always meant "use defaults" elsewhere in the plugin.
			editPostFn( { meta: { nop_indieweb_syndicate_to: next.length ? next : [ 'none' ] } } );
		}

		return el( Panel, { name: 'nop-indieweb-syndication', title: __( 'Syndicate to', 'nop-indieweb' ) },
			el( 'div', { className: 'nop-syndication-targets' },
				syndicators.map( function ( syndicator ) {
					return el( CheckboxControl, {
						key:                     syndicator.slug,
						label:                   syndicator.label,
						checked:                 activeTargets.indexOf( syndicator.slug ) !== -1,
						onChange:                function ( checked ) { toggle( syndicator.slug, checked ); },
						__nextHasNoMarginBottom: true,
					} );
				} )
			)
		);
	}

	plugins.registerPlugin( 'nop-indieweb-syndication-panel', { render: SyndicationPanel } );

} )(
	window.wp.plugins,
	window.wp.editor,
	window.wp.editPost,
	window.wp.element,
	window.wp.data,
	window.wp.components,
	window.wp.i18n
);
