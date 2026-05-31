( function () {
	var el = wp.element.createElement;

	wp.blocks.registerBlockType( 'nop-indieweb/film-card', {
		edit: function () {
			// Mirror render.php's markup/classes so the editor preview matches the
			// front-end (poster-wrap + nop-film-stars/nop-film-star spans).
			var star = function ( filled ) {
				return el( 'span', {
					className: 'nop-film-star ' + ( filled ? 'is-full' : 'is-empty' ),
					'aria-hidden': 'true',
				}, '★' );
			};
			return el(
				'div',
				{ className: 'nop-film-card' },
				el(
					'div',
					{ className: 'nop-film-card__poster-wrap' },
					el( 'div', { className: 'nop-film-card__poster nop-film-card__poster--empty' } )
				),
				el(
					'div',
					{ className: 'nop-film-card__body' },
					el( 'span', { className: 'nop-film-stars' },
						star( true ), star( true ), star( true ), star( true ), star( false )
					),
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
