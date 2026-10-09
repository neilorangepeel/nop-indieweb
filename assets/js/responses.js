/**
 * Responses — the `nop-indieweb/responses` Interactivity store, owned by the
 * reply form (nop_wm_render_comment_form()).
 *
 * - Posts the form to wp-comments-post.php in place and shows the reply under
 *   it, or says it is awaiting moderation. Errors come back as wp_die() pages;
 *   their message is shown in the form's live region.
 * - Threads a reply from any Reply link on the page by setting comment_parent
 *   and focusing the form, instead of moving the form as comment-reply.js did.
 * - While the tab is visible, checks every two minutes (for up to half an
 *   hour) whether new responses have arrived, and offers to show them.
 */
import {
	store,
	getContext,
	withScope,
	withSyncEvent,
} from '@wordpress/interactivity';

const POLL_EVERY = 120000;
const POLL_TIMES = 15;

const { state } = store( 'nop-indieweb/responses', {
	state: {
		get heading() {
			const { replyTo } = getContext();
			return replyTo
				? state.i18n.replying.replace( '%s', replyTo )
				: state.i18n.heading;
		},
		get freshLabel() {
			const { fresh } = getContext();
			return (
				1 === fresh ? state.i18n.freshOne : state.i18n.freshOther
			).replace( '%d', fresh );
		},
	},
	actions: {
		replyTo: withSyncEvent( ( event ) => {
			const link = event.target.closest?.(
				'.comment-reply-link[data-commentid]'
			);
			if ( ! link || event.metaKey || event.ctrlKey || event.shiftKey ) {
				return;
			}
			event.preventDefault();
			const context = getContext();
			context.parent = Number( link.dataset.commentid );
			context.replyTo =
				link
					.closest( '.h-cite' )
					?.querySelector( '.p-name' )
					?.textContent.trim() || '';
			const textarea = document.getElementById( 'comment' );
			textarea?.scrollIntoView( { block: 'center' } );
			textarea?.focus( { preventScroll: true } );
		} ),
		cancelReply: withSyncEvent( ( event ) => {
			event.preventDefault();
			const context = getContext();
			context.parent = 0;
			context.replyTo = '';
		} ),
		submit: withSyncEvent( function* ( event ) {
			event.preventDefault();
			const context = getContext();
			if ( context.sending ) {
				return;
			}
			const form = event.target;
			const data = new FormData( form );
			context.sending = true;
			context.status = state.i18n.sending;

			try {
				const response = yield fetch( form.action, {
					method: 'POST',
					body: data,
					credentials: 'same-origin',
				} );
				if ( ! response.ok ) {
					const page = new window.DOMParser().parseFromString(
						yield response.text(),
						'text/html'
					);
					const message = page
						.querySelector( '.wp-die-message, body p' )
						?.textContent.trim();
					throw new Error( message || state.i18n.failed );
				}
				context.sent = {
					author: context.me || String( data.get( 'author' ) || '' ),
					text: String( data.get( 'comment' ) || '' ),
				};
				const held = response.url.includes( 'unapproved=' );
				context.status = held ? state.i18n.held : state.i18n.posted;
				context.known += held ? 0 : 1;
				context.parent = 0;
				context.replyTo = '';
				form.querySelector( 'textarea' ).value = '';
			} catch ( error ) {
				context.status = error.message || state.i18n.failed;
			} finally {
				context.sending = false;
			}
		} ),
		showFresh() {
			window.location.reload();
		},
	},
	callbacks: {
		watch() {
			const context = getContext();
			let polls = 0;
			const check = withScope( function* () {
				if ( document.hidden ) {
					return;
				}
				polls += 1;
				if ( polls > POLL_TIMES ) {
					clearInterval( timer );
					return;
				}
				try {
					const response = yield fetch(
						`${ state.endpoint }?post_id=${ context.postId }`
					);
					const { responses } = response.ok
						? yield response.json()
						: {};
					if (
						'number' === typeof responses &&
						responses > context.known
					) {
						context.fresh = responses - context.known;
					}
				} catch {}
			} );
			const timer = setInterval( check, POLL_EVERY );
			return () => clearInterval( timer );
		},
	},
} );
