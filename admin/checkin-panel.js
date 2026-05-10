/**
 * Venue panel for checkin posts — block editor sidebar.
 *
 * Replaces the classic PHP metabox. Renders as a native PluginDocumentSettingPanel
 * so it matches the Categories/Tags UI exactly and removes the Meta Boxes compat tab.
 * Only visible on posts where nop_indieweb_post_kind === 'checkin'.
 *
 * Saving is handled automatically by the editor — editPost() marks the post dirty
 * and the REST API persists meta on Save.
 *
 * No build step — window.wp globals only.
 */
( function ( plugins, editPost, element, data, components, i18n ) {
	'use strict';

	var el          = element.createElement;
	var Fragment    = element.Fragment;
	var useSelect   = data.useSelect;
	var useDispatch = data.useDispatch;
	var Panel       = editPost.PluginDocumentSettingPanel;
	var TextControl = components.TextControl;
	var ExternalLink = components.ExternalLink;
	var __          = i18n.__;

	function CheckinPanel() {
		var meta = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		}, [] );

		var editPost = useDispatch( 'core/editor' ).editPost;

		if ( meta['nop_indieweb_post_kind'] !== 'checkin' ) {
			return null;
		}

		var venueName  = meta['nop_indieweb_venue_name']       || '';
		var address    = meta['nop_indieweb_venue_address']     || '';
		var locality   = meta['nop_indieweb_venue_locality']    || '';
		var postcode   = meta['nop_indieweb_venue_postcode']    || '';
		var lat        = meta['nop_indieweb_venue_lat']         || '';
		var lng        = meta['nop_indieweb_venue_lng']         || '';
		var cats       = meta['nop_indieweb_venue_categories']  || [];
		var checkinUrl = meta['nop_indieweb_checkin_url']       || '';
		var service    = meta['nop_indieweb_service']           || '';
		var photos     = meta['nop_indieweb_photos']            || [];

		var addrParts = [ address, locality, postcode ].filter( Boolean );
		var mapUrl    = ( lat && lng )
			? 'https://www.openstreetmap.org/?mlat=' + encodeURIComponent( lat ) + '&mlon=' + encodeURIComponent( lng ) + '&zoom=16'
			: '';

		var serviceLabel = service
			? service.charAt( 0 ).toUpperCase() + service.slice( 1 )
			: 'Swarm';

		return el( Panel, { name: 'nop-indieweb-venue', title: __( 'Venue', 'nop-indieweb' ) },

			el( 'div', { className: 'nop-panel-fields' },

				el( TextControl, {
					label:                   __( 'Name', 'nop-indieweb' ),
					value:                   venueName,
					onChange:                function ( val ) { editPost( { meta: { nop_indieweb_venue_name: val } } ); },
					__nextHasNoMarginBottom: true,
				} ),

				addrParts.length > 0 && el( 'div', { className: 'nop-panel-row' },
					el( 'span', { className: 'nop-panel-label' }, __( 'Address', 'nop-indieweb' ) ),
					el( 'span', { className: 'nop-panel-value' }, addrParts.join( ', ' ) )
				),

				cats.length > 0 && el( 'div', { className: 'nop-panel-row' },
					el( 'span', { className: 'nop-panel-label' }, __( 'Type', 'nop-indieweb' ) ),
					el( 'span', { className: 'nop-panel-value' }, cats.join( ' · ' ) )
				),

				lat && lng && el( 'div', { className: 'nop-panel-row' },
					el( 'span', { className: 'nop-panel-label' }, __( 'Coordinates', 'nop-indieweb' ) ),
					el( 'span', { className: 'nop-panel-value' }, lat + ', ' + lng ),
					mapUrl && el( ExternalLink, { href: mapUrl }, __( 'View on OpenStreetMap', 'nop-indieweb' ) )
				),

				photos.length > 0 && el( 'div', { className: 'nop-panel-row' },
					el( 'span', { className: 'nop-panel-label' },
						__( 'Photos', 'nop-indieweb' ) + ' (' + photos.length + ')'
					),
					el( 'div', { className: 'nop-panel-photos' },
						photos.slice( 0, 6 ).map( function ( url, i ) {
							return el( 'img', { key: i, src: url, className: 'nop-panel-photo', alt: '', loading: 'lazy' } );
						} )
					)
				),

				checkinUrl && el( 'div', { className: 'nop-panel-row nop-panel-row--last' },
					el( 'span', { className: 'nop-panel-label' }, __( 'Source', 'nop-indieweb' ) ),
					el( ExternalLink, { href: checkinUrl }, serviceLabel + ' checkin' )
				)
			)
		);
	}

	plugins.registerPlugin( 'nop-indieweb-checkin-panel', { render: CheckinPanel } );

} )(
	window.wp.plugins,
	window.wp.editPost,
	window.wp.element,
	window.wp.data,
	window.wp.components,
	window.wp.i18n
);
