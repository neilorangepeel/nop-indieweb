/**
 * Photos — the `nop-indieweb/photos` Interactivity store: steps a block's strip one photo at a time and keeps the
 * count with the scroll. The full-size view is core's Image lightbox. A video (a story) is its own small player: see
 * render.php.
 */
import { store, getContext, getElement, withSyncEvent } from '@wordpress/interactivity';

const reducedMotion = () =>
	window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

// Sound on is the reader's choice, kept for the visit, so every story after the first plays as they left it.
const SOUND = 'nop-photos-sound';
const toggleSound = ( video ) => {
	video.muted = ! video.muted;
	try {
		window.sessionStorage.setItem( SOUND, video.muted ? '0' : '1' );
	} catch {}
};

const videoOf = ( ref ) => ref.closest( '.nop-photos' ).querySelector( 'video' );

const flip = ( video ) => ( video.paused ? video.play().catch( () => {} ) : video.pause() );

// The line across the top follows the video every frame while it plays; its spoken value changes each whole second.
const follow = ( video, position ) => {
	const bar = video.closest( '.nop-photos' ).querySelector( '.nop-photos__progress' );
	const frame = () => {
		if ( ! video.isConnected ) {
			return;
		}
		// Until the video knows its length (it can start before), the line waits at the start.
		const at = video.duration ? video.currentTime / video.duration : 0;
		bar.value = at * 1000;
		bar.style.setProperty( '--nop-progress', at );
		const text = position.replace( '%1$s', Math.floor( video.currentTime ) ).replace( '%2$s', Math.round( video.duration || 0 ) );
		if ( bar.ariaValueText !== text ) {
			bar.ariaValueText = text;
		}
		if ( ! video.paused ) {
			window.requestAnimationFrame( frame );
		}
	};
	window.requestAnimationFrame( frame );
};

let holding = 0;

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
		get soundLabel() {
			const context = getContext();
			return context.muted ? context.soundOn : context.soundOff;
		},
		get isLast() {
			const context = getContext();
			return context.at === context.count - 1;
		},
	},
	actions: {
		toggle() {
			flip( videoOf( getElement().ref ) );
		},
		// A tap or click on the video, unless it ends a held press (the release has already played it).
		tap() {
			const context = getContext();
			if ( context.held ) {
				context.held = false;
				return;
			}
			flip( getElement().ref );
		},
		press( event ) {
			if ( event.button > 0 ) {
				return;
			}
			const context = getContext();
			const video = getElement().ref;
			window.clearTimeout( holding );
			holding = window.setTimeout( () => {
				if ( ! video.paused ) {
					context.held = true;
					video.pause();
				}
			}, 250 );
		},
		release() {
			window.clearTimeout( holding );
			const context = getContext();
			if ( ! context.held ) {
				return;
			}
			getElement().ref.play().catch( () => {} );
			// The click that follows the release is the press's own, not a tap; with none (the pointer left) the next
			// tap counts.
			window.setTimeout( () => {
				context.held = false;
			}, 0 );
		},
		// A long press on a phone would open the video's menu (save, copy) instead of pausing.
		noMenu: withSyncEvent( ( event ) => {
			if ( getContext().held ) {
				event.preventDefault();
			}
		} ),
		played() {
			const context = getContext();
			context.playing = true;
			follow( getElement().ref, context.position );
		},
		paused() {
			getContext().playing = false;
		},
		heard() {
			getContext().muted = getElement().ref.muted;
		},
		sound() {
			toggleSound( videoOf( getElement().ref ) );
		},
		seek() {
			const { ref } = getElement();
			const video = videoOf( ref );
			if ( video.duration ) {
				video.currentTime = ( ref.value / 1000 ) * video.duration;
				ref.style.setProperty( '--nop-progress', ref.value / 1000 );
			}
		},
		// In the entry overlay (the page has the keys elsewhere): K or Space plays and pauses, M turns the sound on
		// and off, J and L step back and on two seconds. Not while typing, not over the lightbox, and not while the
		// entry's conversation is open over the video.
		key: withSyncEvent( ( event ) => {
			const { ref } = getElement();
			const entry = ref.closest( 'body > .nop-entry' );
			if ( ! entry || event.metaKey || event.ctrlKey || event.altKey || event.target.closest?.( 'input, textarea, select, [contenteditable]' ) || entry.querySelector( 'details[data-nop-sheet][open]' ) || document.querySelector( 'dialog:modal, .wp-lightbox-overlay.active' ) ) {
				return;
			}
			const video = ref.querySelector( 'video' );
			const key = event.key.toLowerCase();
			// Space is a focused button's own key.
			if ( 'k' === key || ( ' ' === key && ! event.target.closest?.( 'button, a, summary' ) ) ) {
				flip( video );
			} else if ( 'm' === key ) {
				toggleSound( video );
			} else if ( 'j' === key || 'l' === key ) {
				// The video keeps the time within its length.
				video.currentTime += 'j' === key ? -2 : 2;
				follow( video, getContext().position );
			} else {
				return;
			}
			event.preventDefault();
		} ),
		// A hidden tab stops the video, and the video picks up where it was when the tab is back.
		visibility() {
			const context = getContext();
			const video = getElement().ref.querySelector( 'video' );
			if ( document.hidden ) {
				context.resume = ! video.paused;
				video.pause();
			} else if ( context.resume ) {
				video.play().catch( () => {} );
			}
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
	callbacks: {
		// Start playing, muted unless the reader has turned the sound on; if the browser will not play it with sound
		// yet, it plays muted. With reduced motion it waits for Play.
		start() {
			const context = getContext();
			const video = getElement().ref.querySelector( 'video' );
			video.muted = true;
			try {
				video.muted = '1' !== window.sessionStorage.getItem( SOUND );
			} catch {}
			context.muted = video.muted;
			if ( reducedMotion() ) {
				return;
			}
			video.play().catch( () => {
				if ( ! video.muted ) {
					video.muted = true;
					video.play().catch( () => {} );
				}
			} );
		},
	},
} );
