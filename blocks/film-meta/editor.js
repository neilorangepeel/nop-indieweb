/**
 * Film Meta block — editor.
 *
 * Renders an interactive star picker directly in the block canvas.
 * Clicking the stars writes to nop_indieweb_film_rating via useEntityProp
 * so the change is immediately saved with the post — no sidebar needed.
 *
 * No build step — window.wp globals only.
 */
( function ( blocks, element, data, coreData, i18n ) {
	'use strict';

	var el               = element.createElement;
	var useState         = element.useState;
	var useSelect        = data.useSelect;
	var useEntityProp    = coreData.useEntityProp;
	var __               = i18n.__;
	var sprintf          = i18n.sprintf;

	// ── Star picker ─────────────────────────────────────────────────────────────

	/**
	 * Renders 5 interactive stars. Each star is split into left (half) and right
	 * (full) click zones so you can set 0.5-step ratings by clicking either side.
	 * Hover previews the target rating before committing.
	 */
	function StarPicker( props ) {
		var rating   = props.rating || 0;
		var onChange = props.onChange;

		var hoverState    = useState( null );
		var hovered       = hoverState[0];
		var setHovered    = hoverState[1];

		var display = hovered !== null ? hovered : rating;

		var groups = [];
		for ( var i = 1; i <= 5; i++ ) {
			groups.push( StarGroup( el, i, display, setHovered, onChange ) );
		}

		return el( 'div', {
			className:    'nop-film-star-picker',
			role:         'group',
			/* translators: %s: rating value out of 5 */
			'aria-label': sprintf( __( 'Film rating: %s out of 5 stars', 'nop-indieweb' ), ( hovered !== null ? hovered : rating ) ),
			onMouseLeave: function () { setHovered( null ); },
		}, groups );
	}

	function StarGroup( el, starNum, display, setHovered, onChange ) {
		var halfVal = starNum - 0.5;
		var fullVal = starNum;

		var isFull = display >= fullVal;
		var isHalf = ! isFull && display >= halfVal;

		return el( 'span', { key: starNum, className: 'nop-film-star-group' },
			// Left half → half-star value
			el( 'span', {
				className:    'nop-film-star-zone nop-film-star-zone--left',
				onMouseEnter: function () { setHovered( halfVal ); },
				onClick:      function () { onChange( halfVal ); },
				role:         'button',
				tabIndex:     0,
				/* translators: %s: rating value */
				'aria-label': sprintf( __( '%s stars', 'nop-indieweb' ), halfVal ),
				onKeyDown:    function ( e ) { if ( e.key === 'Enter' || e.key === ' ' ) { e.preventDefault(); onChange( halfVal ); } },
			} ),
			// Right half → full-star value
			el( 'span', {
				className:    'nop-film-star-zone nop-film-star-zone--right',
				onMouseEnter: function () { setHovered( fullVal ); },
				onClick:      function () { onChange( fullVal ); },
				role:         'button',
				tabIndex:     0,
				/* translators: %s: rating value */
				'aria-label': sprintf( __( '%s stars', 'nop-indieweb' ), fullVal ),
				onKeyDown:    function ( e ) { if ( e.key === 'Enter' || e.key === ' ' ) { e.preventDefault(); onChange( fullVal ); } },
			} ),
			// Visual glyph — pointer-events: none so clicks hit the zones
			el( 'span', {
				className:    'nop-film-star-glyph ' + ( isFull ? 'is-full' : isHalf ? 'is-half' : 'is-empty' ),
				'aria-hidden': 'true',
			}, '★' )
		);
	}

	// ── Helpers ─────────────────────────────────────────────────────────────────

	function formatWatchDate( iso ) {
		if ( ! iso ) { return ''; }
		var d = new Date( iso + 'T00:00:00' ); // force local timezone parse
		if ( isNaN( d.getTime() ) ) { return iso; }
		return d.toLocaleDateString( undefined, { year: 'numeric', month: 'long', day: 'numeric' } );
	}

	// ── Block registration ───────────────────────────────────────────────────────

	blocks.registerBlockType( 'nop-indieweb/film-meta', {

		edit: function () {
			var postType = useSelect( function ( select ) {
				var store = select( 'core/editor' );
				return store && store.getCurrentPostType ? ( store.getCurrentPostType() || 'post' ) : 'post';
			}, [] );

			var entityResult  = useEntityProp( 'postType', postType, 'meta' );
			var meta          = entityResult[0];
			var setMeta       = entityResult[1];

			// Template editor or entity not yet loaded — show placeholder.
			if ( ! meta ) {
				return el( 'div', { className: 'nop-film-meta nop-film-meta--placeholder' },
					el( StarPicker, { rating: 0, onChange: function () {} } )
				);
			}

			var rating    = parseFloat( meta.nop_indieweb_film_rating || 0 );
			var filmYear  = String( meta.nop_indieweb_film_year || '' );
			var watchDate = String( meta.nop_indieweb_watch_date || '' );
			var rewatch   = meta.nop_indieweb_film_rewatch === '1';
			var sourceUrl = String( meta.nop_indieweb_source_url || '' );

			function handleRatingChange( newRating ) {
				var updated = {};
				// Spread meta manually — Object.assign works without spread syntax
				Object.keys( meta ).forEach( function ( k ) { updated[ k ] = meta[ k ]; } );
				updated.nop_indieweb_film_rating = String( newRating );
				setMeta( updated );
			}

			var metaItems = [];
			if ( filmYear ) {
				metaItems.push( el( 'span', { key: 'year', className: 'nop-film-meta__year' }, filmYear ) );
			}
			if ( watchDate ) {
				metaItems.push( el( 'span', { key: 'date', className: 'nop-film-meta__date' },
					/* translators: %s: formatted watch date */
					sprintf( __( 'Watched %s', 'nop-indieweb' ), formatWatchDate( watchDate ) )
				) );
			}
			if ( rewatch ) {
				metaItems.push( el( 'span', { key: 'rewatch', className: 'nop-film-meta__rewatch' }, __( 'Rewatch', 'nop-indieweb' ) ) );
			}
			if ( sourceUrl ) {
				metaItems.push( el( 'a', {
					key:       'source',
					className: 'nop-film-meta__source',
					href:      '#',
					onClick:   function ( e ) { e.preventDefault(); },
					target:    '_blank',
					rel:       'noopener',
				}, __( 'View on Letterboxd', 'nop-indieweb' ) ) );
			}

			return el( 'div', { className: 'nop-film-meta' },
				el( 'div', { className: 'nop-film-meta__rating' },
					el( StarPicker, { rating: rating, onChange: handleRatingChange } ),
					rating > 0 && el( 'span', { className: 'nop-film-meta__rating-value' },
						rating % 1 === 0 ? rating.toFixed( 0 ) : rating.toFixed( 1 )
					)
				),
				metaItems.length > 0 && el( 'div', { className: 'nop-film-meta__row' }, metaItems )
			);
		},

		save: function () {
			// Frontend output is handled by render.php.
			return null;
		},
	} );

} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.data,
	window.wp.coreData,
	window.wp.i18n
);
