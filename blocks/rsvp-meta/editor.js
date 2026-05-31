/**
 * RSVP Meta block — editor.
 *
 * Interactive RSVP picker rendered directly in the block canvas.
 * Clicking a value writes to nop_indieweb_rsvp via useEntityProp.
 *
 * No build step — window.wp globals only.
 */
( function ( blocks, element, data, coreData ) {
	'use strict';

	var el            = element.createElement;
	var useSelect     = data.useSelect;
	var useEntityProp = coreData.useEntityProp;

	// ── Config ──────────────────────────────────────────────────────────────────

	// Colours mirror the AA-compliant values in render.php so the editor preview
	// matches the front-end (the brighter originals failed WCAG AA on white).
	var RSVP_OPTIONS = [
		{ value: 'yes',        label: 'Going',       color: '#15803d' },
		{ value: 'maybe',      label: 'Maybe',       color: '#92400e' },
		{ value: 'interested', label: 'Interested',  color: '#1d4ed8' },
		{ value: 'no',         label: 'Not going',   color: '#b91c1c' },
	];

	// ── RSVP Picker ─────────────────────────────────────────────────────────────

	function RsvpPicker( props ) {
		var current  = props.value;
		var onChange = props.onChange;

		var buttons = RSVP_OPTIONS.map( function ( opt ) {
			var isActive = current === opt.value;
			return el( 'button', {
				key:       opt.value,
				className: 'nop-rsvp-option' + ( isActive ? ' is-active' : '' ),
				onClick:   function () { onChange( opt.value ); },
				style: {
					'--rsvp-color': opt.color,
				},
				type: 'button',
			}, opt.label );
		} );

		return el( 'div', {
			className:    'nop-rsvp-picker',
			role:         'group',
			'aria-label': 'RSVP status',
		}, buttons );
	}

	// ── Block registration ───────────────────────────────────────────────────────

	blocks.registerBlockType( 'nop-indieweb/rsvp-meta', {

		edit: function () {
			var postType = useSelect( function ( select ) {
				var store = select( 'core/editor' );
				return store && store.getCurrentPostType ? ( store.getCurrentPostType() || 'post' ) : 'post';
			}, [] );

			var entityResult = useEntityProp( 'postType', postType, 'meta' );
			var meta         = entityResult[0];
			var setMeta      = entityResult[1];

			if ( ! meta ) {
				return el( 'div', { className: 'nop-rsvp-meta nop-rsvp-meta--placeholder' },
					el( RsvpPicker, { value: 'yes', onChange: function () {} } )
				);
			}

			var rsvpValue = String( meta.nop_indieweb_rsvp || 'yes' );
			var eventUrl  = String( meta.nop_indieweb_in_reply_to || '' );

			function handleChange( newValue ) {
				var updated = {};
				Object.keys( meta ).forEach( function ( k ) { updated[ k ] = meta[ k ]; } );
				updated.nop_indieweb_rsvp = newValue;
				setMeta( updated );
			}

			var currentOption = RSVP_OPTIONS.find( function ( o ) { return o.value === rsvpValue; } )
				|| RSVP_OPTIONS[0];

			return el( 'div', { className: 'nop-rsvp-meta' },
				el( RsvpPicker, { value: rsvpValue, onChange: handleChange } ),
				el( 'p', { className: 'nop-rsvp-meta__status' },
					el( 'span', {
						className: 'nop-rsvp-badge',
						style: { '--rsvp-color': currentOption.color },
					}, currentOption.label )
				),
				eventUrl && el( 'p', { className: 'nop-rsvp-meta__event' },
					'Event: ',
					el( 'a', {
						href:    '#',
						onClick: function ( e ) { e.preventDefault(); },
					}, eventUrl )
				)
			);
		},

		save: function () {
			return null;
		},
	} );

} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.data,
	window.wp.coreData
);
