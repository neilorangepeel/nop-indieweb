/**
 * Likes — the shared `nop-indieweb/likes` Interactivity store.
 *
 * Every like control for a post (the like-button block's heart, the post
 * footer's pill) reads one entry in state.posts, so liking from either updates
 * both. The count rises before the request lands and rolls back if it fails,
 * with the failure announced in the control's live region.
 *
 * No nonce is sent: the /like route is public, and a nonce baked into
 * page-cached HTML outlives its validity and gets the request rejected.
 */
import { store, getContext } from '@wordpress/interactivity';

const { state } = store( 'nop-indieweb/likes', {
	state: {
		get post() {
			return state.posts[ getContext().key ];
		},
		get liked() {
			return state.post.liked;
		},
		get count() {
			return state.post.count;
		},
		get label() {
			return state.post.liked ? state.i18n.liked : state.i18n.like;
		},
		get countLabel() {
			const n = state.post.count;
			return ( 1 === n ? state.i18n.one : state.i18n.other ).replace(
				'%d',
				n
			);
		},
	},
	actions: {
		*like() {
			const context = getContext();
			const post = state.post;
			if ( post.liked || context.busy ) {
				return;
			}
			const previous = post.count;
			context.busy = true;
			context.animating = true;
			context.error = '';
			post.count = previous + 1;

			try {
				const response = yield fetch( state.endpoint, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { post_id: context.postId } ),
				} );
				if ( ! response.ok ) {
					throw new Error( response.status );
				}
				const data = yield response.json();
				post.liked = true;
				if ( 'number' === typeof data.count ) {
					post.count = data.count;
				}
			} catch {
				post.count = previous;
				context.animating = false;
				context.error = state.i18n.failed;
			} finally {
				context.busy = false;
			}
		},
		endAnimation() {
			getContext().animating = false;
		},
	},
} );
