<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Webmention;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Caches per-post like / repost / reply totals in post meta.
 *
 * Without this every count is derived by counting comment rows, which costs
 * roughly six queries per post. That is affordable on a single post and not
 * affordable in an archive loop, so listing pages could never show engagement.
 *
 * The cached total folds in the imported_* offsets, so a consumer reads one
 * number and adds nothing to it.
 */
class Engagement_Counts {

	public const LIKES   = 'nop_engagement_likes';
	public const REPOSTS = 'nop_engagement_reposts';
	public const REPLIES = 'nop_engagement_replies';

	/** Post IDs queued for recount, flushed once on shutdown. */
	private array $pending = [];

	public function register(): void {
		foreach ( [ 'wp_insert_comment', 'edit_comment', 'deleted_comment', 'trashed_comment', 'untrashed_comment', 'spammed_comment', 'unspammed_comment' ] as $hook ) {
			add_action( $hook, [ $this, 'queue_from_comment_id' ], 10, 1 );
		}
		add_action( 'transition_comment_status', [ $this, 'queue_from_transition' ], 10, 3 );

		// A webmention's type arrives as comment meta *after* the comment row is
		// inserted, so the insert alone would bucket every webmention as a reply.
		add_action( 'added_comment_meta',   [ $this, 'queue_from_comment_meta' ], 10, 3 );
		add_action( 'updated_comment_meta', [ $this, 'queue_from_comment_meta' ], 10, 3 );

		// Editing an imported_* offset changes the total without touching a comment.
		add_action( 'added_post_meta',   [ $this, 'queue_from_post_meta' ], 10, 3 );
		add_action( 'updated_post_meta', [ $this, 'queue_from_post_meta' ], 10, 3 );

		add_action( 'shutdown', [ $this, 'flush' ] );
	}

	// ── Queueing ──────────────────────────────────────────────────────────────

	public function queue_from_comment_id( int|string $comment_id ): void {
		$comment = get_comment( (int) $comment_id );
		if ( $comment ) {
			$this->pending[ (int) $comment->comment_post_ID ] = true;
		}
	}

	public function queue_from_transition( string $new_status, string $old_status, \WP_Comment $comment ): void {
		$this->pending[ (int) $comment->comment_post_ID ] = true;
	}

	public function queue_from_comment_meta( int|string $meta_id, int|string $comment_id, string $meta_key ): void {
		if ( 'webmention_type' === $meta_key ) {
			$this->queue_from_comment_id( $comment_id );
		}
	}

	public function queue_from_post_meta( int|string $meta_id, int|string $post_id, string $meta_key ): void {
		if ( 'nop_indieweb_imported_likes' === $meta_key || 'nop_indieweb_imported_reposts' === $meta_key ) {
			$this->pending[ (int) $post_id ] = true;
		}
	}

	/**
	 * Recount every post queued this request.
	 *
	 * Deferring matters during a backfeed run: fifty likes landing on one post
	 * queue fifty times and recount once.
	 */
	public function flush(): void {
		$ids           = array_keys( $this->pending );
		$this->pending = [];
		foreach ( $ids as $post_id ) {
			$this->recount( $post_id );
		}
	}

	// ── Counting ──────────────────────────────────────────────────────────────

	/**
	 * Recompute and store the three totals.
	 *
	 * @return array{likes:int,reposts:int,replies:int}
	 */
	public function recount( int $post_id ): array {
		$counts = $this->calculate( $post_id );

		foreach ( [ self::LIKES => 'likes', self::REPOSTS => 'reposts', self::REPLIES => 'replies' ] as $meta_key => $bucket ) {
			// Absence means zero. Storing a 0 would put a row on all 17k posts
			// and make "has engagement" an EXISTS query that always matches.
			if ( $counts[ $bucket ] > 0 ) {
				update_post_meta( $post_id, $meta_key, $counts[ $bucket ] );
			} else {
				delete_post_meta( $post_id, $meta_key );
			}
		}

		return $counts;
	}

	/**
	 * Count without writing. Buckets exactly as nop_wm_get_data() does:
	 * `like` and `repost` split out, everything else — including `bookmark`
	 * and `mention` — counts as a reply.
	 *
	 * @return array{likes:int,reposts:int,replies:int}
	 */
	public function calculate( int $post_id ): array {
		if ( ! $post_id ) {
			return [ 'likes' => 0, 'reposts' => 0, 'replies' => 0 ];
		}

		$replies_wp = (int) get_comments( [
			'post_id' => $post_id,
			'type'    => 'comment',
			'status'  => 'approve',
			'count'   => true,
		] );

		$replies_wm = (int) get_comments( [
			'post_id'    => $post_id,
			'type'       => 'webmention',
			'status'     => 'approve',
			'count'      => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- runs on comment write and in the CLI backfill, never in a render loop
			'meta_query' => [ [
				'relation' => 'OR',
				[ 'key' => 'webmention_type', 'compare' => 'NOT EXISTS' ],
				[ 'key' => 'webmention_type', 'value' => [ 'like', 'repost' ], 'compare' => 'NOT IN' ],
			] ],
		] );

		return [
			'likes'   => $this->count_type( $post_id, 'like' ) + (int) get_post_meta( $post_id, 'nop_indieweb_imported_likes', true ),
			'reposts' => $this->count_type( $post_id, 'repost' ) + (int) get_post_meta( $post_id, 'nop_indieweb_imported_reposts', true ),
			'replies' => $replies_wp + $replies_wm,
		];
	}

	private function count_type( int $post_id, string $type ): int {
		return (int) get_comments( [
			'post_id'    => $post_id,
			'type'       => 'webmention',
			'status'     => 'approve',
			'count'      => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- runs on comment write and in the CLI backfill, never in a render loop
			'meta_query' => [ [ 'key' => 'webmention_type', 'value' => $type ] ],
		] );
	}

	// ── Reading ───────────────────────────────────────────────────────────────

	/**
	 * The three totals for a post, from cache.
	 *
	 * A post with no engagement stores nothing, so "all three absent" is
	 * ambiguous: it means either zero, or not yet stamped by the backfill.
	 *
	 * $count_if_missing resolves that ambiguity, and the right answer differs
	 * by caller. On a single post, counting is worth four queries to be right
	 * before the backfill has run. In a listing it is not: most posts are
	 * quiet, so the fallback fires for nearly every card and reintroduces the
	 * N+1 this class exists to remove — measured at 3.6 comment queries per
	 * card over 20 posts. Listings pass false and treat absent as zero, which
	 * is correct once `wp nop-indieweb backfill-engagement` has run.
	 *
	 * @return array{likes:int,reposts:int,replies:int}
	 */
	public static function get( int $post_id, bool $count_if_missing = true ): array {
		$likes   = get_post_meta( $post_id, self::LIKES, true );
		$reposts = get_post_meta( $post_id, self::REPOSTS, true );
		$replies = get_post_meta( $post_id, self::REPLIES, true );

		if ( '' === $likes && '' === $reposts && '' === $replies ) {
			return $count_if_missing
				? ( new self() )->calculate( $post_id )
				: [ 'likes' => 0, 'reposts' => 0, 'replies' => 0 ];
		}

		return [
			'likes'   => (int) $likes,
			'reposts' => (int) $reposts,
			'replies' => (int) $replies,
		];
	}
}
