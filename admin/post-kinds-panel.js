/**
 * Post Kinds panel — block editor sidebar.
 *
 * Renders a kind selector, any per-kind URL/meta fields, and (for kinds that
 * opt in) a kind-specific sub-panel:
 *
 *   sub_panel: 'venue'        → venue details for Checkin posts
 *   sub_panel: 'lookup:tmdb' → TMDB film search picker for Watch posts
 *
 * Config comes from PHP via window.nopIndieWebKindsPanel (built by
 * Kind_Taxonomy::get_editor_panel_config() and localized in Post_Kinds_Panel).
 *
 * No build step — window.wp globals only.
 */
( function ( plugins, editor, editPost, element, data, components, i18n, blocks ) {
	'use strict';

	var el            = element.createElement;
	var useState      = element.useState;
	var useRef        = element.useRef;
	var useSelect     = data.useSelect;
	var useDispatch   = data.useDispatch;
	var Panel           = ( editor && editor.PluginDocumentSettingPanel ) || editPost.PluginDocumentSettingPanel;
	var SelectControl   = components.SelectControl;
	var TextControl     = components.TextControl;
	var TextareaControl = components.TextareaControl;
	var Button          = components.Button;
	var Spinner         = components.Spinner;
	var ExternalLink    = components.ExternalLink;
	var __              = i18n.__;
	var sprintf         = i18n.sprintf;

	// ── Config from PHP ─────────────────────────────────────────────────────────

	var panelData  = window.nopIndieWebKindsPanel || { terms: [], config: {}, restUrl: '' };
	var KIND_CONFIG = panelData.config  || {};
	var KIND_TERMS  = panelData.terms   || [];

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

	function ordinal( n ) {
		var abs = Math.abs( n ), mod = abs % 100;
		if ( mod >= 11 && mod <= 13 ) { return n + 'th'; }
		switch ( abs % 10 ) {
			case 1: return n + 'st';
			case 2: return n + 'nd';
			case 3: return n + 'rd';
			default: return n + 'th';
		}
	}

	function domainFromUrl( url ) {
		try { return new URL( url ).hostname; } catch ( e ) { return url; }
	}

	function isValidUrl( url ) {
		try { new URL( url ); return true; } catch ( e ) { return false; }
	}

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

	// ── Venue sub-panel ─────────────────────────────────────────────────────────

	function VenueSubPanel( props ) {
		var meta       = props.meta;
		var editPostFn = props.editPost;

		// Read venue category taxonomy terms — set by the Foursquare enricher.
		// Fall back to the legacy nop_indieweb_venue_categories meta for older posts.
		var termIds = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'nop_venue_category' ) || [];
		}, [] );
		var termNames = useSelect( function ( select ) {
			return termIds.map( function ( id ) {
				var term = select( 'core' ).getEntityRecord( 'taxonomy', 'nop_venue_category', id );
				return term ? term.name : null;
			} ).filter( Boolean );
		}, [ termIds ] );

		var venueName  = meta['nop_indieweb_venue_name']   || '';
		var address    = meta['nop_indieweb_venue_address'] || '';
		var locality   = meta['nop_indieweb_venue_locality'] || '';
		var postcode   = meta['nop_indieweb_venue_postcode'] || '';
		var lat        = meta['nop_indieweb_venue_lat']      || '';
		var lng        = meta['nop_indieweb_venue_lng']      || '';
		// NOP: needs review — legacy fallback to the old meta-stored categories for
		// posts predating the nop_venue_category taxonomy. Safe to drop once every
		// post has been migrated; kept because that backfill status is your call.
		var cats       = termNames.length > 0 ? termNames : ( meta['nop_indieweb_venue_categories'] || [] );
		var checkinUrl = meta['nop_indieweb_checkin_url']   || '';
		var service    = meta['nop_indieweb_service']       || '';
		var photos     = meta['nop_indieweb_photos']        || [];
		var visitNum   = meta['nop_indieweb_venue_visit_number'] || 0;
		var visitText  = visitNum ? i18n.sprintf( __( '%s Visit', 'nop-indieweb' ), ordinal( visitNum ) ) : '';

		var addrParts    = [ address, locality, postcode ].filter( Boolean );
		var mapUrl       = ( lat && lng )
			? 'https://www.openstreetmap.org/?mlat=' + encodeURIComponent( lat ) + '&mlon=' + encodeURIComponent( lng ) + '&zoom=16'
			: '';
		var serviceLabel = service
			? service.charAt( 0 ).toUpperCase() + service.slice( 1 )
			: 'Swarm';

		return el( 'div', { className: 'nop-panel-fields' },

			el( TextControl, {
				label:                   __( 'Name', 'nop-indieweb' ),
				value:                   venueName,
				onChange:                function ( val ) { editPostFn( { meta: { nop_indieweb_venue_name: val } } ); },
				__nextHasNoMarginBottom: true,
			} ),

			addrParts.length > 0 && el( 'div', { className: 'nop-panel-row' },
				el( 'span', { className: 'nop-panel-label' }, __( 'Address', 'nop-indieweb' ) ),
				el( 'span', { className: 'nop-panel-value' }, addrParts.join( ', ' ) )
			),

			visitText && el( 'div', { className: 'nop-panel-row' },
				el( 'span', { className: 'nop-panel-label' }, __( 'Visit', 'nop-indieweb' ) ),
				el( 'span', { className: 'nop-panel-value' }, visitText )
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
		);
	}

	// ── Lookup picker ────────────────────────────────────────────────────────────

	function LookupPicker( props ) {
		var provider   = props.provider;
		var meta       = props.meta;
		var editPostFn = props.editPost;
		var title      = props.title;

		var queryState   = useState( '' );
		var query        = queryState[0], setQuery = queryState[1];

		var resultsState = useState( [] );
		var results      = resultsState[0], setResults = resultsState[1];

		var loadingState = useState( false );
		var loading      = loadingState[0], setLoading = loadingState[1];

		var errorState   = useState( null );
		var error        = errorState[0], setError = errorState[1];

		var timerRef = useRef( null );

		function onQueryChange( val ) {
			setQuery( val );
			clearTimeout( timerRef.current );
			if ( ! val || val.length < 2 ) {
				setResults( [] );
				return;
			}
			timerRef.current = setTimeout( function () {
				doSearch( val );
			}, 400 );
		}

		function doSearch( q ) {
			setLoading( true );
			setError( null );
			window.wp.apiFetch( {
				path: '/nop-indieweb/v1/lookup?provider=' + encodeURIComponent( provider ) + '&q=' + encodeURIComponent( q ),
			} ).then( function ( data ) {
				setResults( data.results || [] );
			} ).catch( function ( err ) {
				setError( ( err && err.message ) || __( 'Search failed.', 'nop-indieweb' ) );
				setResults( [] );
			} ).finally( function () {
				setLoading( false );
			} );
		}

		function selectResult( result ) {
			var update = { meta: result.meta };
			if ( ! title ) {
				update.title = result.title;
			}
			editPostFn( update );
			setResults( [] );
			setQuery( '' );
		}

		var selectedTitle  = meta['nop_indieweb_film_title']  || '';
		var selectedYear   = meta['nop_indieweb_film_year']   || '';
		var selectedPoster = meta['nop_indieweb_film_poster'] || '';
		var showSelected   = selectedTitle && results.length === 0 && ! loading;

		return el( 'div', { className: 'nop-lookup-picker' },

			el( TextControl, {
				label:                   __( 'Film', 'nop-indieweb' ),
				value:                   query,
				onChange:                onQueryChange,
				placeholder:             __( 'Search by title…', 'nop-indieweb' ),
				__nextHasNoMarginBottom: true,
			} ),

			loading && el( 'p', { className: 'nop-lookup-picker__loading' },
				__( 'Searching…', 'nop-indieweb' )
			),

			error && el( 'p', { className: 'nop-lookup-picker__error' }, error ),

			results.length > 0 && el( 'ul', { className: 'nop-lookup-picker__results' },
				results.map( function ( result ) {
					return el( 'li', {
						key:       result.id,
						className: 'nop-lookup-picker__result',
						role:      'button',
						tabIndex:  0,
						onClick:   function () { selectResult( result ); },
						onKeyDown: function ( e ) {
							if ( e.key === 'Enter' || e.key === ' ' ) { selectResult( result ); }
						},
					},
						result.thumb_url && el( 'img', {
							src:       result.thumb_url,
							alt:       '',
							className: 'nop-lookup-picker__thumb',
							loading:   'lazy',
						} ),
						el( 'span', { className: 'nop-lookup-picker__result-info' },
							el( 'span', { className: 'nop-lookup-picker__result-title' }, result.title ),
							result.year && el( 'span', { className: 'nop-lookup-picker__result-year' }, result.year )
						)
					);
				} )
			),

			showSelected && el( 'div', { className: 'nop-lookup-picker__selected' },
				selectedPoster && el( 'img', {
					src:       selectedPoster,
					alt:       '',
					className: 'nop-lookup-picker__selected-poster',
					loading:   'lazy',
				} ),
				el( 'span', { className: 'nop-lookup-picker__selected-label' },
					selectedTitle + ( selectedYear ? ' (' + selectedYear + ')' : '' )
				)
			)
		);
	}

	// ── RSVP sub-panel ────────────────────────────────────────────────────────

	var RSVP_RESPONSES = [
		{ value: 'yes',        label: __( 'Yes',        'nop-indieweb' ) },
		{ value: 'no',         label: __( 'No',         'nop-indieweb' ) },
		{ value: 'maybe',      label: __( 'Maybe',      'nop-indieweb' ) },
		{ value: 'interested', label: __( 'Interested', 'nop-indieweb' ) },
	];

	function RsvpSubPanel( props ) {
		var meta       = props.meta;
		var editPostFn = props.editPost;
		var title      = props.title;

		// 'idle' | 'loading' | 'found' | 'thin' | 'empty' | 'error'
		var statusState = useState( 'idle' );
		var status      = statusState[0], setStatus = statusState[1];
		var sourceState = useState( '' );
		var source      = sourceState[0], setSource = sourceState[1];

		// The URL the last fetch ran against — so blur after paste doesn't re-fetch
		// the same value, and an unchanged blur is a no-op.
		var fetchedRef = useRef( '' );

		var eventUrl  = meta['nop_indieweb_in_reply_to']         || '';
		var rsvpValue = meta['nop_indieweb_rsvp']                || 'yes';
		var eventName = meta['nop_indieweb_rsvp_event_name']     || '';
		var eventStart= meta['nop_indieweb_rsvp_event_start']    || '';
		var eventLoc  = meta['nop_indieweb_rsvp_event_location'] || '';
		var eventImg  = meta['nop_indieweb_rsvp_event_image']    || '';
		var note      = meta['nop_indieweb_rsvp_note']           || '';

		// Split start into date + time controls so a date-only source (a
		// theatrical run quoted as "Sat 13 Jun 2026") fills the date and leaves
		// the time blank, rather than being silently dropped by a `datetime-local`
		// input that rejects date-only values. Joined back to "YYYY-MM-DD" or
		// "YYYY-MM-DDTHH:MM" when written to meta.
		var startMatch  = ( eventStart || '' ).match( /^(\d{4}-\d{2}-\d{2})(?:T(\d{2}:\d{2}))?/ );
		var startDate   = startMatch ? startMatch[1] : '';
		var startTime   = ( startMatch && startMatch[2] ) ? startMatch[2] : '';

		function setMeta( key, value ) {
			var update = { meta: {} };
			update.meta[ key ] = value;
			editPostFn( update );
		}

		function setStart( date, time ) {
			var iso = '';
			if ( date ) { iso = time ? date + 'T' + time : date; }
			setMeta( 'nop_indieweb_rsvp_event_start', iso );
		}

		function fetchEvent( url ) {
			url = ( url || '' ).trim();
			if ( ! url || ! isValidUrl( url ) || url === fetchedRef.current ) {
				return;
			}
			fetchedRef.current = url;
			setStatus( 'loading' );

			window.wp.apiFetch( {
				path:   '/nop-indieweb/v1/fetch-event',
				method: 'POST',
				data:   { url: url },
			} ).then( function ( data ) {
				if ( ! data || ! data.source ) {
					setStatus( 'empty' );
					setSource( '' );
					return;
				}

				// Pre-fill from the fetch, leaving anything it couldn't find untouched
				// so the author can fill it in by hand. All fields stay editable.
				// dt-end is intentionally not captured — an RSVP records the single
				// day the author is attending, not the event's full run.
				var update = { meta: {} };
				if ( data.name )     { update.meta['nop_indieweb_rsvp_event_name']     = data.name; }
				if ( data.start )    { update.meta['nop_indieweb_rsvp_event_start']    = data.start; }
				if ( data.location ) { update.meta['nop_indieweb_rsvp_event_location'] = data.location; }
				if ( data.image )    { update.meta['nop_indieweb_rsvp_event_image']    = data.image; }
				if ( data.note )     { update.meta['nop_indieweb_rsvp_note']           = data.note; }

				// Fill an empty post title with the event name, matching the
				// title_from_url behaviour of the other URL-response kinds.
				if ( ! title && data.name ) {
					update.title = data.name;
				}

				editPostFn( update );
				// Thin = only a name came back. Usually a page-title fallback or a
				// venue/listings page that exposes an og:title but nothing else.
				// An image alone isn't enough — venue og:image often points at a
				// site-wide logo, not the event's own poster.
				var thin = ! data.start && ! data.location;
				setSource( data.source );
				setStatus( thin ? 'thin' : 'found' );
			} ).catch( function () {
				setStatus( 'error' );
				setSource( '' );
			} );
		}

		var SOURCE_LABELS = {
			mf2:       __( 'microformats h-event', 'nop-indieweb' ),
			jsonld:    __( 'schema.org/Event', 'nop-indieweb' ),
			opengraph: __( 'Open Graph', 'nop-indieweb' ),
			title:     __( 'page title', 'nop-indieweb' ),
		};

		var statusEl = null;
		if ( status === 'loading' ) {
			statusEl = el( 'p', { className: 'nop-rsvp-fetch nop-rsvp-fetch--loading' },
				el( Spinner, {} ),
				el( 'span', {}, __( 'Fetching event details…', 'nop-indieweb' ) )
			);
		} else if ( status === 'found' ) {
			var label = SOURCE_LABELS[ source ];
			statusEl = el( 'p', { className: 'nop-rsvp-fetch nop-rsvp-fetch--found' },
				label
					? sprintf( __( 'Found via %s.', 'nop-indieweb' ), label )
					: __( 'Found event details.', 'nop-indieweb' )
			);
		} else if ( status === 'thin' ) {
			statusEl = el( 'p', { className: 'nop-rsvp-fetch nop-rsvp-fetch--thin' },
				__( 'Only the page title was readable — please fill in the details.', 'nop-indieweb' )
			);
		} else if ( status === 'empty' || status === 'error' ) {
			statusEl = el( 'p', { className: 'nop-rsvp-fetch nop-rsvp-fetch--empty' },
				__( 'Couldn’t find event data — please fill in manually.', 'nop-indieweb' )
			);
		}

		return el( 'div', { className: 'nop-panel-fields nop-rsvp-panel' },

			el( TextControl, {
				label:                   __( 'Event URL', 'nop-indieweb' ),
				type:                    'url',
				value:                   eventUrl,
				placeholder:             'https://',
				onChange:                function ( v ) { setMeta( 'nop_indieweb_in_reply_to', v ); },
				onBlur:                  function () { fetchEvent( eventUrl ); },
				onPaste:                 function ( e ) {
					var pasted = ( e.clipboardData || window.clipboardData ).getData( 'text' );
					if ( pasted ) { setTimeout( function () { fetchEvent( pasted ); }, 0 ); }
				},
				__nextHasNoMarginBottom: true,
			} ),

			statusEl,

			el( SelectControl, {
				label:                   __( 'Response', 'nop-indieweb' ),
				value:                   rsvpValue,
				options:                 RSVP_RESPONSES,
				onChange:                function ( v ) { setMeta( 'nop_indieweb_rsvp', v ); },
				__nextHasNoMarginBottom: true,
			} ),

			el( TextControl, {
				label:                   __( 'Event name', 'nop-indieweb' ),
				value:                   eventName,
				onChange:                function ( v ) { setMeta( 'nop_indieweb_rsvp_event_name', v ); },
				__nextHasNoMarginBottom: true,
			} ),

			// Native date/time pickers. The empty-state rendering is per-browser
			// (Chrome shows the dd/mm/yyyy pattern, Safari shows today's date as
			// a hint) — that's a platform inconsistency the HTML spec leaves to
			// UA discretion. The value-side state (which is what every consumer
			// reads) is empty until the editor actually picks.
			el( 'div', { className: 'nop-rsvp-when' },
				el( TextControl, {
					label:                   __( 'When (date)', 'nop-indieweb' ),
					type:                    'date',
					value:                   startDate,
					autoComplete:            'off',
					onChange:                function ( v ) { setStart( v, startTime ); },
					__nextHasNoMarginBottom: true,
				} ),
				el( TextControl, {
					label:                   __( 'When (time)', 'nop-indieweb' ),
					type:                    'time',
					value:                   startTime,
					autoComplete:            'off',
					onChange:                function ( v ) { setStart( startDate, v ); },
					__nextHasNoMarginBottom: true,
				} )
			),

			el( TextControl, {
				label:                   __( 'Location (optional)', 'nop-indieweb' ),
				value:                   eventLoc,
				onChange:                function ( v ) { setMeta( 'nop_indieweb_rsvp_event_location', v ); },
				__nextHasNoMarginBottom: true,
			} ),

			// Hot-linked event poster. Just a confirmation thumbnail + a
			// "remove" affordance — no upload control, the URL only changes
			// via the event-URL fetch (the parser pulls it from u-photo /
			// schema.org image / og:image). The Wayback save on publish
			// preserves the image binary so a future theme can wire a
			// dead-link fallback when the venue CDN URL eventually 404s.
			eventImg ? el( 'div', { className: 'nop-rsvp-poster' },
				el( 'img', { src: eventImg, alt: '', referrerPolicy: 'no-referrer', loading: 'lazy' } ),
				el( Button, {
					variant:  'link',
					isDestructive: true,
					onClick:  function () { setMeta( 'nop_indieweb_rsvp_event_image', '' ); },
				}, __( 'Remove poster', 'nop-indieweb' ) )
			) : null,

			el( TextareaControl, {
				label:                   __( 'Note (optional)', 'nop-indieweb' ),
				value:                   note,
				rows:                    3,
				onChange:                function ( v ) { setMeta( 'nop_indieweb_rsvp_note', v ); },
				__nextHasNoMarginBottom: true,
			} )
		);
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

		var kind = ( currentTermIds.length > 0 && termIdToSlug[ currentTermIds[ 0 ] ] )
			? termIdToSlug[ currentTermIds[ 0 ] ]
			: ( meta[ 'nop_indieweb_post_kind' ] || '' );
		var config = KIND_CONFIG[ kind ] || EMPTY_KIND;

		function applyLayout( kindValue ) {
			var c = KIND_CONFIG[ kindValue ];
			if ( ! c || ! c.layout ) {
				return;
			}
			blockDispatch.resetBlocks( blocks.parse( c.layout ) );
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

			if ( ! title && value && config.title_from_url && isValidUrl( value ) ) {
				update.title = domainFromUrl( value );
			}

			editorDispatch.editPost( update );
		}

		var hasFields   = config.fields && config.fields.length > 0;
		var hasOffer    = !! ( offeredKind && KIND_CONFIG[ offeredKind ] );
		var subPanel    = config.sub_panel || null;

		var children = [
			el( SelectControl, {
				key:                     'kind-select',
				label:                   __( 'Kind', 'nop-indieweb' ),
				value:                   kind,
				options:                 DROPDOWN_OPTIONS,
				onChange:                setKind,
				__nextHasNoMarginBottom: ! hasFields && ! hasOffer && ! subPanel,
			} ),
		];

		if ( hasOffer ) {
			children.push(
				el( 'div', { key: 'layout-offer', className: 'nop-layout-offer' },
					el( Button, {
						variant: 'secondary',
						size:    'small',
						onClick: function () { applyLayout( offeredKind ); },
					/* translators: %s: post kind label, e.g. "Reply" */
					}, i18n.sprintf( __( 'Apply %s layout', 'nop-indieweb' ), KIND_CONFIG[ offeredKind ].label ) ),
					el( 'p', { className: 'nop-layout-offer__hint' },
						__( 'Replaces current content.', 'nop-indieweb' )
					)
				)
			);
		}

		if ( config.fields ) {
			config.fields.forEach( function ( field, i ) {
				var isLast = ( i === config.fields.length - 1 ) && ! subPanel;

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
		}

		if ( subPanel === 'venue' ) {
			children.push( el( VenueSubPanel, {
				key:      'venue-sub-panel',
				meta:     meta,
				editPost: editorDispatch.editPost,
			} ) );
		} else if ( subPanel === 'rsvp' ) {
			children.push( el( RsvpSubPanel, {
				key:      'rsvp-sub-panel',
				meta:     meta,
				editPost: editorDispatch.editPost,
				title:    title,
			} ) );
		} else if ( subPanel && subPanel.indexOf( 'lookup:' ) === 0 ) {
			var provider = subPanel.slice( 7 );
			children.push( el( LookupPicker, {
				key:      'lookup-picker',
				provider: provider,
				meta:     meta,
				editPost: editorDispatch.editPost,
				title:    title,
			} ) );
		}

		return el.apply( null, [ Panel, { name: 'nop-indieweb-post-kind', title: __( 'Post Kind', 'nop-indieweb' ) } ].concat( children ) );
	}

	plugins.registerPlugin( 'nop-indieweb-post-kinds-panel', { render: PostKindsPanel } );

	// Hide the default Gutenberg taxonomy panels for nop_kind and nop_venue_category.
	// nop_kind is managed by the Post Kind selector above; nop_venue_category is
	// shown read-only in the Venue sub-panel, which only appears for checkin posts.
	var dispatchStore = data.dispatch( 'core/editor' );
	if ( ! dispatchStore || typeof dispatchStore.removeEditorPanel !== 'function' ) {
		// Older WP: the panel API lived on core/edit-post. Guard it the same way
		// so a missing dispatch doesn't throw.
		dispatchStore = data.dispatch( 'core/edit-post' );
	}
	if ( dispatchStore && typeof dispatchStore.removeEditorPanel === 'function' ) {
		dispatchStore.removeEditorPanel( 'taxonomy-panel-nop_kind' );
		dispatchStore.removeEditorPanel( 'taxonomy-panel-nop_venue_category' );
	}

} )(
	window.wp.plugins,
	window.wp.editor,
	window.wp.editPost,
	window.wp.element,
	window.wp.data,
	window.wp.components,
	window.wp.i18n,
	window.wp.blocks
);
