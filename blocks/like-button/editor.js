( function () {
	var el  = wp.element.createElement;
	var svg = el( 'svg', {
		className:   'nop-like-button__icon',
		viewBox:     '0 0 24 24',
		fill:        'none',
		stroke:      'currentColor',
		strokeWidth: '2',
		strokeLinecap:  'round',
		strokeLinejoin: 'round',
		'aria-hidden': 'true',
		width:  '16',
		height: '16',
	}, el( 'path', { d: 'M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z' } ) );

	wp.blocks.registerBlockType( 'nop-indieweb/like-button', {
		edit: function () {
			return el(
				'div',
				{ className: 'nop-like-button' },
				el(
					'button',
					{ className: 'nop-like-button__btn', type: 'button', disabled: true },
					svg,
					el( 'span', { className: 'nop-like-button__label' }, 'Like' )
				),
				el( 'span', { className: 'nop-like-button__count', hidden: true }, '0' )
			);
		},
		save: function () {
			return null;
		},
	} );
} )();
