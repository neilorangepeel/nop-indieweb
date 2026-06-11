<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Cli;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use NOP\IndieWeb\Services\Note;
use WP_CLI;

/**
 * `wp nop-indieweb repair-photo-sideloads`
 *
 * Re-sideloads photos for posts that have nop_indieweb_photos stored but no
 * nop_indieweb_photo_ids — posts where sideloading failed at import time.
 * Strips any remote-URL image blocks written as fallback and replaces them
 * with proper local wp:image blocks referencing the new attachments.
 */
class Repair_Photo_Sideloads {

	/**
	 * Re-sideload photos for posts where sideloading previously failed.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would be done without making any changes.
	 *
	 * [--post-id=<id>]
	 * : Process only the specified post ID.
	 *
	 * [--limit=<n>]
	 * : Stop after processing this many posts (default: all).
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$limit   = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 0;
		$only_id = isset( $assoc_args['post-id'] ) ? (int) $assoc_args['post-id'] : 0;

		$query_args = [
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => ( $only_id > 0 ) ? 1 : ( $limit > 0 ? $limit : -1 ),
			'fields'         => 'ids',
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- low-frequency repair command, not a hot path
			'meta_query'     => [
				'relation' => 'AND',
				[
					'key'     => 'nop_indieweb_photos',
					'compare' => 'EXISTS',
				],
				[
					'relation' => 'OR',
					[
						'key'     => 'nop_indieweb_photo_ids',
						'compare' => 'NOT EXISTS',
					],
					[
						'key'   => 'nop_indieweb_photo_ids',
						'value' => 'a:0:{}',
					],
				],
			],
		];

		if ( $only_id > 0 ) {
			$query_args['p'] = $only_id;
		}

		$post_ids = get_posts( $query_args );
		$total    = count( $post_ids );

		if ( ! $total ) {
			WP_CLI::success( 'No posts need photo repair.' );
			return;
		}

		WP_CLI::log( sprintf(
			'Found %d post(s) to repair%s.',
			$total,
			$dry_run ? ' (dry run)' : ''
		) );

		$note    = new Note();
		$fixed   = 0;
		$skipped = 0;
		$failed  = 0;

		foreach ( $post_ids as $post_id ) {
			$photos = get_post_meta( $post_id, 'nop_indieweb_photos', true );
			if ( ! is_array( $photos ) || ! $photos ) {
				WP_CLI::log( "  ~ #{$post_id}  no photo URLs stored — skipped" );
				$skipped++;
				continue;
			}

			$count = count( $photos );

			if ( $dry_run ) {
				WP_CLI::log( "  ~ #{$post_id}  would sideload {$count} photo(s): " . implode( ', ', $photos ) );
				$fixed++;
				continue;
			}

			$ok = $note->repair_photo_sideloads( $post_id );
			if ( $ok ) {
				WP_CLI::log( "  ✓ #{$post_id}  sideloaded {$count} photo(s)" );
				$fixed++;
			} else {
				WP_CLI::warning( "  ✗ #{$post_id}  sideload failed — check debug.log" );
				$failed++;
			}
		}

		WP_CLI::success( sprintf(
			'%s%d fixed · %d skipped · %d failed',
			$dry_run ? '[DRY RUN] ' : '',
			$fixed,
			$skipped,
			$failed
		) );
	}
}
