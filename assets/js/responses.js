/**
 * Responses — the `nop-indieweb/responses` Interactivity store, owned by the
 * reply form (nop_wm_render_comment_form()).
 *
 * - Posts the form to wp-comments-post.php in place and shows the reply under
 *   it (marked "Awaiting approval" when held). Missing or wrong fields are
 *   named beside the field (aria-invalid, aria-describedby) and focused;
 *   server errors (wp_die() pages) are read into the form's live region.
 * - The name is asked for when it is needed: a first reply sent without one
 *   opens "Who's replying?" (name, email) and waits; sending again posts it.
 *   The name is then remembered (core's comment cookies, always set): a
 *   returning visitor sees "Replying as Name · Not you?", and "Not you?"
 *   forgets it. Cmd/Ctrl+Enter posts; Escape leaves the field.
 * - A mention from the visitor's own site posts source and target to the
 *   webmention endpoint.
 * - Threads a reply from any Reply link on the page by setting comment_parent
 *   and focusing the form, instead of moving the form as comment-reply.js did.
 * - The summary of a details holding the form (a band's Reply) goes to the
 *   form: it focuses the box, opening the details first if it was closed.
 * - While the tab is visible, checks every two minutes (for up to half an
 *   hour) whether new responses have arrived, and offers to show them.
 */
import {
	store,
	getContext,
	getElement,
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
		get invalidComment() {
			return !! getContext().errors.comment;
		},
		get invalidAuthor() {
			return !! getContext().errors.author;
		},
		get invalidEmail() {
			return !! getContext().errors.email;
		},
		get invalidMention() {
			return !! getContext().errors.mention;
		},
		get freshLabel() {
			const { fresh } = getContext();
			return (
				1 === fresh ? state.i18n.freshOne : state.i18n.freshOther
			).replace( '%d', fresh );
		},
	},
	actions: {
		// Once the form is used it stays open in full (is-engaged): folding back as focus left it moved everything
		// under a press on its other ways to reply, so the click landed somewhere else.
		engage() {
			getContext().engaged = true;
		},
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
		keys: withSyncEvent( ( event ) => {
			if ( 'Enter' === event.key && ( event.metaKey || event.ctrlKey ) ) {
				event.preventDefault();
				event.target.form.requestSubmit();
			} else if ( 'Escape' === event.key ) {
				event.target.blur();
			}
		} ),
		clearError: ( event ) => {
			getContext().errors[ event.target.name ] = '';
		},
		// The name and email are kept in the context (the fields are bound to it), so a re-render keeps what was typed.
		typed: ( event ) => {
			const context = getContext();
			context[ event.target.name ] = event.target.value;
			context.errors[ event.target.name ] = '';
		},
		// Forget the remembered commenter (core's cookies are not HttpOnly) and ask again.
		forget() {
			const context = getContext();
			const { hash, path, domain } = state.cookie;
			for ( const name of [ 'author', 'email', 'url' ] ) {
				document.cookie = `comment_${ name }_${ hash }=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=${ path }${
					domain ? `; domain=${ domain }` : ''
				}`;
			}
			context.me = '';
			context.author = '';
			context.email = '';
			context.editing = true;
			window.requestAnimationFrame( () =>
				document.getElementById( 'author' )?.focus()
			);
		},
		submit: withSyncEvent( function* ( event ) {
			event.preventDefault();
			const context = getContext();
			if ( context.sending ) {
				return;
			}
			const form = event.target;
			const data = new FormData( form );
			// Name what is missing beside its field, and go there.
			const check = {
				comment: ! String( data.get( 'comment' ) || '' ).trim()
					? state.i18n.noComment
					: '',
				author:
					form.author && ! String( data.get( 'author' ) || '' ).trim()
						? state.i18n.noName
						: '',
				email:
					form.email &&
					( form.email.required && ! form.email.value
						? state.i18n.noEmail
						: form.email.value && ! form.email.validity.valid
						? state.i18n.badEmail
						: '' ),
			};
			Object.assign( context.errors, check );
			const first = Object.keys( check ).find( ( name ) => check[ name ] );
			// The first send without a name asks who is replying (the name step opens, not an error); the reply waits.
			if ( 'author' === first && ! context.editing ) {
				context.editing = true;
				context.errors.author = '';
				window.requestAnimationFrame( () => form.author?.focus() );
				return;
			}
			if ( first ) {
				if ( 'comment' !== first ) {
					context.editing = true;
				}
				window.requestAnimationFrame( () => form[ first ]?.focus() );
				return;
			}
			context.sending = true;
			context.status = state.i18n.sending;

			try {
				const response = yield fetch( form.action, {
					method: 'POST',
					body: data,
					credentials: 'same-origin',
				} );
				// Core answers a refused comment with its error page, sometimes as a 200: a reply that was taken redirects to
				// the post, so still being at wp-comments-post.php means it was not.
				if ( ! response.ok || response.url.includes( 'wp-comments-post.php' ) ) {
					const page = new window.DOMParser().parseFromString(
						yield response.text(),
						'text/html'
					);
					const message = page
						.querySelector( '.wp-die-message, body p' )
						?.textContent.trim();
					throw new Error( message || state.i18n.failed );
				}
				const held = /[?&](unapproved|held)=/.test( response.url );
				context.sent = {
					author: String( data.get( 'author' ) || '' ) || context.me,
					text: String( data.get( 'comment' ) || '' ),
					held,
				};
				// Remembered from now on (core's cookie): the fields fold away.
				if ( data.get( 'author' ) ) {
					context.me = String( data.get( 'author' ) );
					context.editing = false;
				}
				form.querySelector( 'textarea' ).blur();
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
		mention: withSyncEvent( function* ( event ) {
			event.preventDefault();
			const context = getContext();
			const form = event.target;
			if ( ! form.source.value || ! form.source.validity.valid ) {
				context.errors.mention = state.i18n.badUrl;
				form.source.focus();
				return;
			}
			context.errors.mention = '';
			context.status = state.i18n.mentioning;
			try {
				const response = yield fetch( form.action, {
					method: 'POST',
					body: new URLSearchParams( new FormData( form ) ),
				} );
				if ( ! response.ok ) {
					const body = yield response.json().catch( () => ( {} ) );
					throw new Error( body.message || state.i18n.failed );
				}
				context.status = state.i18n.mentioned;
				form.reset();
			} catch ( error ) {
				context.errors.mention = error.message || state.i18n.failed;
				context.status = '';
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
			// The details holding this form (the Reply on a band): its summary goes to the form. Already open (the
			// form always on show), it stays open and only focuses; closed, it opens as well.
			const { ref } = getElement();
			const details = ref.closest( 'details' );
			const summary = details?.querySelector( ':scope > summary' );
			const toForm = ( event ) => {
				if ( details.open ) {
					event.preventDefault();
				}
				window.requestAnimationFrame( () => ref.querySelector( 'textarea' )?.focus() );
			};
			summary?.addEventListener( 'click', toForm );
			return () => {
				clearInterval( timer );
				summary?.removeEventListener( 'click', toForm );
			};
		},
	},
} );
