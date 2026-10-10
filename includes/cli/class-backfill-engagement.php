<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Cli;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use NOP\IndieWeb\Webmention\Engagement_Counts;
use WP_CLI;

/**
 * `wp nop-indieweb backfill-engagement`
 *
 * Stamps the cached like / repost / reply totals that Engagement_Counts keeps
 * current going forward. Without this pass, posts that already carry
 * engagement fall back to counting comment rows on every render.
 *
 * Only posts that could have a non-zero total are visited: those with an
 * approved comment, and those carrying an imported_* offset. Everything else
 * is zero by definition, so walking all 17k posts would buy nothing. Posts
 * that hold a stale total but are no longer candidates get cleared.
 *
 * Idempotent, and walks forward by post ID so a --limit run resumes with
 * --after rather than redoing the first batch.
 */
class Backfill_Engagement {

	/**
	 * Recompute cached engagement totals.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything.
	 *
	 * [--limit=<n>]
	 * : Process at most this many posts, lowest post ID first.
	 *
	 * [--after=<id>]
	 * : Resume: only consider posts with an ID greater than this.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nop-indieweb backfill-engagement --dry-run
	 *     wp nop-indieweb backfill-engagement --limit=500
	 *     wp nop-indieweb backfill-engagement --limit=500 --after=4096
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		global $wpdb;

		$dry_run = isset( $assoc_args['dry-run'] );
		$limit   = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 0;
		$after   = isset( $assoc_args['after'] ) ? (int) $assoc_args['after'] : 0;

		$counts = new Engagement_Counts();

		// Posts that can have a non-zero total.
		$with_comments = $wpdb->get_col( "SELECT DISTINCT comment_post_ID FROM {$wpdb->comments} WHERE comment_approved = '1'" );
		$with_imported = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s ) AND meta_value <> '' AND meta_value <> '0'",
			'nop_indieweb_imported_likes',
			'nop_indieweb_imported_reposts'
		) );

		// Posts already holding a total, so stale ones can be cleared.
		$with_cached = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s, %s )",
			Engagement_Counts::LIKES,
			Engagement_Counts::REPOSTS,
			Engagement_Counts::REPLIES
		) );

		$candidates = array_map( 'intval', array_unique( array_merge( $with_comments, $with_imported, $with_cached ) ) );
		sort( $candidates );

		if ( $after ) {
			$candidates = array_values( array_filter( $candidates, static fn( int $id ): bool => $id > $after ) );
		}

		$total_candidates = count( $candidates );
		if ( $limit ) {
			$candidates = array_slice( $candidates, 0, $limit );
		}

		if ( ! $candidates ) {
			WP_CLI::success( 'Nothing to do — no posts carry engagement.' );
			return;
		}

		$changed = 0;
		$cleared = 0;
		$last_id = 0;

		foreach ( $candidates as $post_id ) {
			$last_id = $post_id;

			$before = [
				'likes'   => (int) get_post_meta( $post_id, Engagement_Counts::LIKES, true ),
				'reposts' => (int) get_post_meta( $post_id, Engagement_Counts::REPOSTS, true ),
				'replies' => (int) get_post_meta( $post_id, Engagement_Counts::REPLIES, true ),
			];
			$after_counts = $counts->calculate( $post_id );

			if ( $before === $after_counts ) {
				continue;
			}

			if ( ! $dry_run ) {
				$counts->recount( $post_id );
			}

			$is_clear = 0 === array_sum( $after_counts );
			if ( $is_clear ) {
				$cleared++;
			} else {
				$changed++;
			}

			WP_CLI::log( sprintf(
				'%-6s #%-6d %2d♥ %2d⇄ %2d💬  %s',
				$is_clear ? 'clear' : 'set',
				$post_id,
				$after_counts['likes'],
				$after_counts['reposts'],
				$after_counts['replies'],
				(string) get_the_title( $post_id )
			) );
		}

		$remaining = $total_candidates - count( $candidates );

		WP_CLI::success( sprintf(
			'%sVisited %d of %d candidate post(s): %d stamped, %d cleared.%s',
			$dry_run ? '[dry-run] ' : '',
			count( $candidates ),
			$total_candidates,
			$changed,
			$cleared,
			$remaining > 0 ? sprintf( ' %d left — resume with --after=%d', $remaining, $last_id ) : ''
		) );
	}
}
