<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Cli;

use NOP\IndieWeb\Kind\Kind_Taxonomy;
use WP_CLI;

/**
 * `wp nop-indieweb fix-swarm-timestamps`
 *
 * Corrects post_date on Swarm checkins that were imported with post_date = post_date_gmt
 * (both UTC) because get_date_from_gmt() doesn't apply timezone offsets in Studio PHP WASM.
 *
 * Reads timeZoneOffset from each post's stored nop_indieweb_raw_payload and recalculates
 * post_date as UTC timestamp + offset seconds. Idempotent when re-run.
 */
class Fix_Swarm_Timestamps {

	/**
	 * Fix post_date on Swarm checkins imported with wrong timezone.
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

		$query_args = [
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => $limit > 0 ? $limit : -1,
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
		];

		$post_ids = get_posts( $query_args );
		$total    = count( $post_ids );

		WP_CLI::log( sprintf( 'Found %d Swarm checkin post(s) with stored payload.', $total ) );
		if ( 0 === $total ) {
			WP_CLI::success( '0 updated · nothing to do.' );
			return;
		}

		$progress = \WP_CLI\Utils\make_progress_bar( $dry_run ? 'Inspecting' : 'Fixing', $total );
		$updated  = 0;
		$skipped  = 0;
		$failed   = 0;

		foreach ( $post_ids as $post_id ) {
			$progress->tick();

			$raw = get_post_meta( $post_id, 'nop_indieweb_raw_payload', true );
			if ( ! $raw ) {
				$skipped++;
				continue;
			}

			$payload = json_decode( $raw, true );
			if ( ! is_array( $payload ) ) {
				$skipped++;
				continue;
			}

			$ts             = (int) ( $payload['createdAt'] ?? 0 );
			$tz_offset_min  = (int) ( $payload['timeZoneOffset'] ?? 0 );

			if ( $ts <= 0 ) {
				$skipped++;
				continue;
			}

			$post_date_gmt = gmdate( 'Y-m-d H:i:s', $ts );
			$post_date     = gmdate( 'Y-m-d H:i:s', $ts + ( $tz_offset_min * 60 ) );

			$post = get_post( $post_id );
			if ( $post->post_date_gmt === $post_date_gmt && $post->post_date === $post_date ) {
				$skipped++;
				continue;
			}

			if ( $dry_run ) {
				WP_CLI::log( sprintf(
					'  ~ #%d  %s → %s  (tz offset %+d min)',
					$post_id, $post->post_date, $post_date, $tz_offset_min
				) );
				$updated++;
				continue;
			}

			$result = wp_update_post( [
				'ID'            => $post_id,
				'post_date'     => $post_date,
				'post_date_gmt' => $post_date_gmt,
			], true );

			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( "  ✗ #{$post_id} " . $result->get_error_message() );
				$failed++;
			} else {
				WP_CLI::log( sprintf(
					'  ✓ #%d  %s → %s',
					$post_id, $post->post_date, $post_date
				) );
				$updated++;
			}
		}

		$progress->finish();

		WP_CLI::success( sprintf(
			'%s%d updated · %d already correct · %d failed',
			$dry_run ? '[DRY RUN] ' : '',
			$updated,
			$skipped,
			$failed
		) );
	}
}
