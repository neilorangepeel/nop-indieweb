/**
 * Syndication panel — block editor sidebar.
 *
 * Shows a checkbox per enabled syndicator (Mastodon, Bluesky).
 * Checked by default. Unchecking persists to nop_indieweb_syndicate_to meta,
 * which the syndication manager reads on publish to skip that platform.
 *
 * No build step — window.wp globals only.
 */
( function ( plugins, editPost, element, data, components, i18n ) {
	'use strict';

	var el          = element.createElement;
	var useSelect   = data.useSelect;
	var useDispatch = data.useDispatch;
	var Panel       = editPost.PluginDocumentSettingPanel;
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

		var editPost = useDispatch( 'core/editor' ).editPost;

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
		// Default to all syndicators if no explicit selection yet.
		var activeTargets = ( Array.isArray( selected ) && selected.length )
			? selected
			: syndicators.map( function ( s ) { return s.slug; } );

		function toggle( slug, checked ) {
			var next = checked
				? activeTargets.concat( [ slug ] ).filter( function ( v, i, a ) { return a.indexOf( v ) === i; } )
				: activeTargets.filter( function ( s ) { return s !== slug; } );
			editPost( { meta: { nop_indieweb_syndicate_to: next } } );
		}

		return el( Panel, { name: 'nop-indieweb-syndication', title: __( 'Syndicate to', 'nop-indieweb' ) },
			el( 'div', { className: 'nop-panel-fields' },
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
	window.wp.editPost,
	window.wp.element,
	window.wp.data,
	window.wp.components,
	window.wp.i18n
);
