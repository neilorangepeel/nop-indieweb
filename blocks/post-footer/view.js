/**
 * Post Footer — the `nop-indieweb/post-footer` Interactivity store.
 *
 * The like pill runs on the shared likes store (assets/js/likes.js). This one
 * owns the rest of the row: the caret that reveals who liked / reposted (it
 * sits inside a pill's button, so it stops the click reaching the pill), the
 * comment pill's jump to the reply box, and the share pill's Web Share with a
 * clipboard fallback.
 */
import { store, getContext, withSyncEvent } from '@wordpress/interactivity';

const reducedMotion = () =>
	window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

const { state, actions } = store( 'nop-indieweb/post-footer', {
	state: {
		open: {},
		get revealOpen() {
			return !! state.open[ getContext().panel ];
		},
		get shareLabel() {
			const context = getContext();
			return context.copied ? context.copiedLabel : context.label;
		},
	},
	actions: {
		toggleReveal: withSyncEvent( ( event ) => {
			event.stopPropagation();
			event.preventDefault();
			const { panel } = getContext();
			state.open[ panel ] = ! state.open[ panel ];
		} ),
		revealKey: withSyncEvent( ( event ) => {
			if ( 'Enter' === event.key || ' ' === event.key ) {
				actions.toggleReveal( event );
			}
		} ),
		jumpToReply: withSyncEvent( ( event ) => {
			const textarea = document.getElementById( 'comment' );
			if ( ! textarea ) {
				return;
			}
			event.preventDefault();
			const reduced = reducedMotion();
			const distance = Math.abs(
				textarea.getBoundingClientRect().top - window.innerHeight / 2
			);
			const delay = reduced
				? 0
				: Math.min( Math.max( distance * 0.4, 150 ), 600 );
			textarea.scrollIntoView( {
				behavior: reduced ? 'instant' : 'smooth',
				block: 'center',
			} );
			setTimeout( () => {
				textarea.focus( { preventScroll: true } );
				textarea.classList.add( 'nop-textarea-invite' );
				setTimeout(
					() => textarea.classList.remove( 'nop-textarea-invite' ),
					1000
				);
			}, delay );
		} ),
		*share( event ) {
			const { navigator } = window;
			const button = event.currentTarget;
			const url = button.dataset.url || window.location.href;
			const title = button.dataset.title || document.title;

			if ( navigator.share ) {
				try {
					yield navigator.share( { url, title } );
				} catch {}
				return;
			}
			if ( navigator.clipboard?.writeText ) {
				const context = getContext();
				try {
					yield navigator.clipboard.writeText( url );
					context.copied = true;
					yield new Promise( ( resolve ) =>
						setTimeout( resolve, 2000 )
					);
					context.copied = false;
				} catch {}
			}
		},
	},
} );
