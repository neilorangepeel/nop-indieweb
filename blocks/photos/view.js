/**
 * Photos — the `nop-indieweb/photos` Interactivity store: steps a block's strip one photo at a time and keeps the
 * count with the scroll. The full-size view is core's Image lightbox.
 */
import { store, getContext, getElement } from '@wordpress/interactivity';

const reducedMotion = () =>
	window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

const step = ( by ) => {
	const context = getContext();
	const at = Math.min( context.count - 1, Math.max( 0, context.at + by ) );
	const track = getElement().ref.closest( '.nop-photos' ).querySelector( '.nop-photos__track' );
	track.scrollTo( {
		left: at * track.clientWidth,
		behavior: reducedMotion() ? 'instant' : 'smooth',
	} );
};

store( 'nop-indieweb/photos', {
	state: {
		// "2 / 5", at the photo's foot.
		get countLabel() {
			const context = getContext();
			return `${ context.at + 1 } / ${ context.count }`;
		},
		get isFirst() {
			return 0 === getContext().at;
		},
		get playLabel() {
			const context = getContext();
			return context.playing ? context.pause : context.play;
		},
		get isLast() {
			const context = getContext();
			return context.at === context.count - 1;
		},
	},
	actions: {
		toggle() {
			const video = getElement().ref.closest( '.nop-photos' ).querySelector( 'video' );
			if ( video.paused ) {
				video.play();
			} else {
				video.pause();
			}
		},
		played() {
			getContext().playing = true;
		},
		paused() {
			getContext().playing = false;
		},
		previous() {
			step( -1 );
		},
		next() {
			step( 1 );
		},
		follow() {
			const { ref } = getElement();
			if ( ref.clientWidth ) {
				getContext().at = Math.round( ref.scrollLeft / ref.clientWidth );
			}
		},
	},
} );
