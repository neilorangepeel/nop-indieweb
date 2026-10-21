/**
 * Share — the `nop-indieweb/share` Interactivity store: the share sheet where a touch device has one, else the link
 * copied to the clipboard, announced, and the label back after two seconds.
 */
import { store, getContext, withScope } from '@wordpress/interactivity';

store( 'nop-indieweb/share', {
	state: {
		get label() {
			const context = getContext();
			return context.copied ? context.done : context.share;
		},
		get status() {
			const context = getContext();
			return context.copied ? context.done : '';
		},
	},
	actions: {
		*share() {
			const context = getContext();
			if ( navigator.share && window.matchMedia( '(pointer: coarse)' ).matches ) {
				try {
					yield navigator.share( { url: context.url, title: context.title } );
				} catch {}
				return;
			}
			try {
				yield navigator.clipboard.writeText( context.url );
			} catch {
				return;
			}
			context.copied = true;
			window.setTimeout( withScope( () => {
				context.copied = false;
			} ), 2000 );
		},
	},
} );
