<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Cli;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use NOP\IndieWeb\Syndication\Syndication_Manager;
use WP_CLI;

/**
 * `wp nop-indieweb backfill-syndication-flag`
 *
 * Indexes pre-existing syndication outcomes: walks every post that has a
 * syndication status journal and sets/clears the lightweight
 * `nop_indieweb_syndication_failed` flag the admin notice + Networks-tab health
 * query against (the flag is only maintained going forward by the syndication
 * manager, so historical failures need this one-off pass).
 *
 * Idempotent: only writes where the flag disagrees with the journal. Safe to
 * re-run.
 */
class Backfill_Syndication_Flag {

	/**
	 * Reindex the syndication-failure flag across existing posts.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything.
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );

		$ids = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => Syndication_Manager::STATUS_META, // phpcs:ignore WordPress.DB.SlowDBQuery -- one-off maintenance command.
			'no_found_rows'  => true,
		] );

		$flagged = 0;
		$cleared = 0;

		foreach ( $ids as $pid ) {
			$pid     = (int) $pid;
			$journal = get_post_meta( $pid, Syndication_Manager::STATUS_META, true );

			$failed = false;
			if ( is_array( $journal ) ) {
				foreach ( $journal as $entry ) {
					if ( is_array( $entry ) && 'failed' === ( $entry['state'] ?? '' ) ) {
						$failed = true;
						break;
					}
				}
			}

			$has_flag = (bool) get_post_meta( $pid, Syndication_Manager::FAILED_FLAG_META, true );

			if ( $failed && ! $has_flag ) {
				$flagged++;
				if ( ! $dry_run ) {
					update_post_meta( $pid, Syndication_Manager::FAILED_FLAG_META, 1 );
				}
				WP_CLI::log( sprintf( 'flag   #%d  %s', $pid, get_the_title( $pid ) ) );
			} elseif ( ! $failed && $has_flag ) {
				$cleared++;
				if ( ! $dry_run ) {
					delete_post_meta( $pid, Syndication_Manager::FAILED_FLAG_META );
				}
				WP_CLI::log( sprintf( 'clear  #%d  %s', $pid, get_the_title( $pid ) ) );
			}
		}

		if ( ! $dry_run ) {
			( new Syndication_Manager() )->flush_summary_cache();
		}

		WP_CLI::success( sprintf(
			'%sScanned %d post(s): %d flagged, %d cleared.',
			$dry_run ? '[dry-run] ' : '',
			count( $ids ),
			$flagged,
			$cleared
		) );
	}
}
