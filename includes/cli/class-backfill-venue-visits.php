<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Cli;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_CLI;

/**
 * `wp nop-indieweb backfill-venue-visits`
 *
 * Assigns nop_indieweb_venue_visit_number to every checkin post that has a
 * Foursquare venue ID. The number is the 1-indexed position of the checkin
 * ordered by post_date ASC among all checkins at the same venue.
 *
 * Safe to re-run: idempotent. Run once after the initial historical import and
 * again whenever visit ordinals need to be recalculated.
 */
class Backfill_Venue_Visits {

	/**
	 * Backfill venue visit-number ordinals for all checkin posts.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would be updated without writing to the database.
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );

		global $wpdb;

		$venue_ids = $wpdb->get_col(
			"SELECT DISTINCT meta_value
			 FROM {$wpdb->postmeta}
			 WHERE meta_key IN ('nop_indieweb_venue_uid', 'nop_indieweb_venue_fsq_id')
			 AND meta_value != ''"
		);

		$venue_count   = count( $venue_ids );
		$total_updated = 0;
		$multi_venues  = 0;

		WP_CLI::log( "Found {$venue_count} unique venue(s)." );

		foreach ( $venue_ids as $venue_id ) {
			$post_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT p.ID
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
				 WHERE m.meta_key IN ('nop_indieweb_venue_uid', 'nop_indieweb_venue_fsq_id')
				 AND m.meta_value = %s
				 AND p.post_type = 'post'
				 AND p.post_status IN ('publish', 'draft', 'private')
				 ORDER BY p.post_date ASC",
				$venue_id
			) );

			if ( count( $post_ids ) > 1 ) {
				$multi_venues++;
			}

			foreach ( $post_ids as $i => $post_id ) {
				if ( ! $dry_run ) {
					update_post_meta( (int) $post_id, 'nop_indieweb_venue_visit_number', $i + 1 );
				}
				$total_updated++;
			}
		}

		$prefix = $dry_run ? '[DRY RUN] ' : '';
		WP_CLI::success( sprintf(
			'%s%d post(s) updated · %d venue(s) with multiple visits',
			$prefix, $total_updated, $multi_venues
		) );
	}
}
