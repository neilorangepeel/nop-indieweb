/**
 * Editor-side registration of the nop-indieweb/post-meta Block Bindings source.
 *
 * The PHP side (class-block-bindings.php) handles front-end rendering. This
 * file mirrors the same field resolution for the editor's live preview —
 * without it, bound paragraphs show the source's generic label
 * ("IndieWeb Post Meta") as their placeholder text in the canvas.
 *
 * Mirrors the PHP logic for:
 *   - Venue field shorthand (field=address → nop_indieweb_venue_address)
 *   - Derived fields (full_address, locality_country, venue_coordinates,
 *     venue_url_host_label, checkin_url_host_label)
 *   - Humanised fallback placeholder for any other field/key.
 *
 * Requires WordPress 6.7+ (registerBlockBindingsSource JS API).
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || typeof wp.blocks.registerBlockBindingsSource !== 'function' ) {
		return;
	}

	function resolveMetaKey( field, key ) {
		if ( key ) {
			return key;
		}
		if ( ! field ) {
			return '';
		}
		// Derived fields don't map to a single meta key.
		if ( DERIVED.includes( field ) ) {
			return '';
		}
		return 'nop_indieweb_venue_' + field;
	}

	var DERIVED = [
		'full_address',
		'locality_country',
		'venue_coordinates',
		'venue_url_host_label',
		'checkin_url_host_label',
	];

	function deriveValue( field, meta ) {
		switch ( field ) {
			case 'full_address': {
				var parts = [
					meta.nop_indieweb_venue_address,
					meta.nop_indieweb_venue_locality,
					meta.nop_indieweb_venue_country,
				].filter( Boolean );
				return parts.length ? parts.join( ', ' ) : null;
			}
			case 'locality_country': {
				var parts2 = [
					meta.nop_indieweb_venue_locality,
					meta.nop_indieweb_venue_country,
				].filter( Boolean );
				return parts2.length ? parts2.join( ', ' ) : null;
			}
			case 'venue_coordinates': {
				var lat = parseFloat( meta.nop_indieweb_venue_lat || 0 );
				var lng = parseFloat( meta.nop_indieweb_venue_lng || 0 );
				if ( ! lat && ! lng ) {
					return null;
				}
				return (
					Math.abs( lat ).toFixed( 3 ) + ' ° ' + ( lat >= 0 ? 'N' : 'S' ) +
					' · ' +
					Math.abs( lng ).toFixed( 3 ) + ' ° ' + ( lng >= 0 ? 'E' : 'W' )
				);
			}
			case 'venue_url_host_label': {
				return hostLabel( meta.nop_indieweb_venue_url );
			}
			case 'checkin_url_host_label': {
				return hostLabel( meta.nop_indieweb_checkin_url );
			}
		}
		return null;
	}

	function hostLabel( url ) {
		if ( ! url ) {
			return null;
		}
		try {
			return 'View on ' + new URL( url ).host;
		} catch ( e ) {
			return 'View on ' + url;
		}
	}

	// Preview values used when no real post meta is available (template editor).
	var PREVIEW_VALUES = {
		name:                     'The Crown Bar',
		address:                  '46 Great Victoria Street',
		locality:                 'Belfast',
		region:                   'County Antrim',
		country:                  'United Kingdom',
		postcode:                 'BT2 7BA',
		locality_country:         'Belfast, United Kingdom',
		full_address:             '46 Great Victoria Street, Belfast, United Kingdom',
		venue_coordinates:        '54.597 ° N · 5.935 ° W',
		venue_url_host_label:     'View on foursquare.com',
		checkin_url_host_label:   'View on swarmapp.com',
	};

	function humanize( field, key ) {
		if ( PREVIEW_VALUES[ field ] ) {
			return PREVIEW_VALUES[ field ];
		}
		if ( PREVIEW_VALUES[ key ] ) {
			return PREVIEW_VALUES[ key ];
		}
		var raw = field || ( key || '' ).replace( /^nop_indieweb_/, '' );
		if ( ! raw ) {
			return 'Venue data';
		}
		return raw.charAt( 0 ).toUpperCase() + raw.slice( 1 ).replace( /_/g, ' ' );
	}

	function resolveValue( binding, meta ) {
		var args  = binding.args || {};
		var field = args.field || '';
		var key   = args.key || '';

		if ( field && DERIVED.includes( field ) ) {
			var derived = deriveValue( field, meta );
			if ( derived ) {
				return derived;
			}
		}

		var resolvedKey = resolveMetaKey( field, key );
		if ( resolvedKey && meta[ resolvedKey ] ) {
			return String( meta[ resolvedKey ] );
		}

		return humanize( field, key );
	}

	wp.blocks.registerBlockBindingsSource( {
		name:           'nop-indieweb/post-meta',
		label:          'IndieWeb Post Meta',
		usesContext:    [ 'postId', 'postType' ],
		getValues: function ( args ) {
			var select   = args.select;
			var context  = args.context || {};
			var bindings = args.bindings || {};

			// Pull the current post's meta from core-data; template editor falls
			// back to humanised previews.
			var meta = {};
			if ( context.postId && context.postType && select && typeof select === 'function' ) {
				try {
					var record = select( 'core' ).getEditedEntityRecord(
						'postType',
						context.postType,
						context.postId
					);
					meta = ( record && record.meta ) || {};
				} catch ( e ) {
					meta = {};
				}
			}

			var result = {};
			Object.keys( bindings ).forEach( function ( attr ) {
				result[ attr ] = resolveValue( bindings[ attr ], meta );
			} );
			return result;
		},
	} );
} )( window.wp );
