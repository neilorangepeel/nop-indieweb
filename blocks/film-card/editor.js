( function () {
	var el = wp.element.createElement;

	wp.blocks.registerBlockType( 'nop-indieweb/film-card', {
		edit: function () {
			return el(
				'div',
				{ className: 'nop-film-card' },
				el( 'div', { className: 'nop-film-card__poster nop-film-card__poster--empty' } ),
				el(
					'div',
					{ className: 'nop-film-card__body' },
					el( 'p', { className: 'nop-film-card__stars' }, '★★★★☆' ),
					el( 'p', { className: 'nop-film-card__title' },
						el( 'a', null, 'Film Title' )
					),
					el( 'p', { className: 'nop-film-card__meta' }, '2024' )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )();
