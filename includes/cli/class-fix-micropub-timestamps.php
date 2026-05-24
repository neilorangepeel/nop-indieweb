<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Cli;

use NOP\IndieWeb\Kind\Kind_Taxonomy;
use WP_CLI;

/**
 * `wp nop-indieweb fix-micropub-timestamps`
 *
 * OwnYourSwarm sends the checkin time as UTC in the Micropub `published` field,
 * so posts imported that way have post_date = post_date_gmt (both UTC). For
 * checkins in BST months (last Sunday in March – last Sunday in October) in the
 * Europe/London timezone, post_date should be UTC+1.
 *
 * This command finds Swarm checkins whose raw_payload is a Micropub request
 * (keys: type, properties) where post_date = post_date_gmt, and whose stored
 * date falls inside a BST window, then shifts post_date forward by 60 minutes.
 *
 * Idempotent: once post_date ≠ post_date_gmt the post is skipped.
 */
class Fix_Micropub_Timestamps {

	/**
	 * Fix post_date on OwnYourSwarm checkins stored 1 hour early during BST.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without updating anything.
	 *
	 * [--limit=<n>]
	 * : Stop after updating this many posts.
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );
		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

		$post_ids = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => $limit > 0 ? $limit * 3 : -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => [
				[
					'taxonomy' => Kind_Taxonomy::TAXONOMY,
					'field'    => 'slug',
					'terms'    => 'checkin',
				],
			],
			'meta_query'     => [
				'relation' => 'AND',
				[ 'key' => 'nop_indieweb_service',     'value' => 'swarm' ],
				[ 'key' => 'nop_indieweb_raw_payload', 'compare' => 'EXISTS' ],
			],
		] );

		$total   = count( $post_ids );
		$updated = 0;
		$skipped = 0;
		$failed  = 0;

		WP_CLI::log( "Scanning {$total} Swarm posts for Micropub BST offset issues…" );

		foreach ( $post_ids as $post_id ) {
			if ( $limit > 0 && $updated >= $limit ) {
				break;
			}

			$post = get_post( $post_id );

			// Already has a timezone offset applied — nothing to do.
			if ( $post->post_date !== $post->post_date_gmt ) {
				$skipped++;
				continue;
			}

			// Only process Micropub-style payloads (type + properties).
			$raw     = get_post_meta( $post_id, 'nop_indieweb_raw_payload', true );
			$payload = json_decode( $raw, true );
			if ( ! is_array( $payload ) || ! isset( $payload['type'], $payload['properties'] ) ) {
				$skipped++;
				continue;
			}

			// Check whether the stored UTC date falls inside a BST window.
			$ts    = strtotime( $post->post_date_gmt . ' UTC' );
			$month = (int) gmdate( 'n', $ts );
			$day   = (int) gmdate( 'j', $ts );
			$year  = (int) gmdate( 'Y', $ts );

			if ( ! $this->is_bst( $ts, $month, $year ) ) {
				$skipped++;
				continue;
			}

			$new_post_date = gmdate( 'Y-m-d H:i:s', $ts + 3600 );

			if ( $dry_run ) {
				WP_CLI::log( sprintf(
					'  ~ #%d  %s → %s  (+60 min BST)',
					$post_id, $post->post_date, $new_post_date
				) );
				$updated++;
				continue;
			}

			$result = wp_update_post( [
				'ID'        => $post_id,
				'post_date' => $new_post_date,
				// post_date_gmt stays as-is — it is the correct UTC time.
			], true );

			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( "  ✗ #{$post_id} " . $result->get_error_message() );
				$failed++;
			} else {
				WP_CLI::log( "  ✓ #{$post_id}  {$post->post_date} → {$new_post_date}" );
				$updated++;
			}
		}

		WP_CLI::success( sprintf(
			'%s%d updated · %d skipped · %d failed',
			$dry_run ? '[DRY RUN] ' : '',
			$updated,
			$skipped,
			$failed
		) );
	}

	/**
	 * Returns true when $ts (UTC) falls inside the Europe/London BST window.
	 *
	 * BST runs from 01:00 UTC on the last Sunday of March to 01:00 UTC on the
	 * last Sunday of October. Rather than maintaining a full tz database, we
	 * compute the exact transition Sundays for the given year.
	 */
	private function is_bst( int $ts, int $month, int $year ): bool {
		if ( $month < 3 || $month > 10 ) {
			return false;
		}
		if ( $month > 3 && $month < 10 ) {
			return true;
		}

		$bst_start = $this->last_sunday_at_1am( $year, 3 );
		$bst_end   = $this->last_sunday_at_1am( $year, 10 );

		return $ts >= $bst_start && $ts < $bst_end;
	}

	private function last_sunday_at_1am( int $year, int $month ): int {
		// Last day of the month.
		$last_day = (int) gmdate( 'j', gmmktime( 0, 0, 0, $month + 1, 0, $year ) );
		// Walk back from the last day to find Sunday (0).
		$day = $last_day;
		while ( (int) gmdate( 'w', gmmktime( 0, 0, 0, $month, $day, $year ) ) !== 0 ) {
			$day--;
		}
		return gmmktime( 1, 0, 0, $month, $day, $year );
	}
}
