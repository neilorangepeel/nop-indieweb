/**
 * Photos — the `nop-indieweb/photos` Interactivity store: steps a block's strip one photo at a time, keeps the dots
 * with the scroll, and opens the full-size viewer (a modal <dialog>) at the photo clicked.
 */
import { store, getContext, getElement } from '@wordpress/interactivity';

const reducedMotion = () =>
	window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

// The strip in view: the viewer's while it is open, else the block's own.
const strip = ( ref ) => {
	const block = ref.closest( '.nop-photos' );
	const viewer = block.querySelector( '.nop-photos__viewer' );
	return ( viewer.open ? viewer : block ).querySelector( '.nop-photos__track' );
};

const show = ( track, at, instant ) =>
	track.scrollTo( {
		left: at * track.clientWidth,
		behavior: instant || reducedMotion() ? 'instant' : 'smooth',
	} );

const step = ( by ) => {
	const context = getContext();
	const at = Math.min( context.count - 1, Math.max( 0, context.at + by ) );
	show( strip( getElement().ref ), at );
};

store( 'nop-indieweb/photos', {
	state: {
		get isCurrent() {
			const context = getContext();
			return context.dot === context.at;
		},
		get isFirst() {
			return 0 === getContext().at;
		},
		get isLast() {
			const context = getContext();
			return context.at === context.count - 1;
		},
	},
	actions: {
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
		open() {
			const { ref } = getElement();
			const viewer = ref.closest( '.nop-photos' ).querySelector( '.nop-photos__viewer' );
			viewer.showModal();
			show( viewer.querySelector( '.nop-photos__track' ), getContext().photo, true );
		},
		close() {
			getElement().ref.closest( 'dialog' ).close();
		},
		// Back on the strip, at the photo the viewer was showing.
		closed() {
			const { ref } = getElement();
			show( ref.closest( '.nop-photos' ).querySelector( '.nop-photos__track' ), getContext().at, true );
		},
	},
} );
