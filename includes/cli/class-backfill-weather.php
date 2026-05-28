<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Cli;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use NOP\IndieWeb\Kind\Kind_Taxonomy;
use WP_CLI;

/**
 * `wp nop-indieweb backfill-weather`
 *
 * Walks every check-in that has coordinates but no weather data, then calls
 * Weather_Fetcher::enrich_post() using the post's recorded date so historical
 * conditions (not today's weather) are stored.
 *
 * Idempotent: posts already enriched (nop_indieweb_weather_provider is set)
 * are skipped. Safe to re-run after partial failures or --limit batches.
 */
class Backfill_Weather {

	/**
	 * Backfill Pirate Weather data on existing check-ins.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without making API calls or writing anything.
	 *
	 * [--force]
	 * : Re-fetch weather even for posts that already have weather data.
	 *
	 * [--limit=<n>]
	 * : Stop after this many API calls. Re-run until complete.
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );
		$force   = isset( $assoc_args['force'] );
		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

		$api_key = (string) \NOP\IndieWeb\nop_indieweb_get_option( 'weather.pirate_weather_api_key', '' );
		if ( '' === $api_key ) {
			WP_CLI::error( 'No Pirate Weather API key configured (Settings → Swarm → Weather).' );
		}

		$meta_query = [
			'relation' => 'AND',
			[ 'key' => 'nop_indieweb_venue_lat', 'compare' => 'EXISTS' ],
			[ 'key' => 'nop_indieweb_venue_lng', 'compare' => 'EXISTS' ],
		];
		if ( ! $force ) {
			$meta_query[] = [ 'key' => 'nop_indieweb_weather_provider', 'compare' => 'NOT EXISTS' ];
		}

		$post_ids = get_posts( [
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
			'meta_query'     => $meta_query,
		] );

		$total = count( $post_ids );
		WP_CLI::log( sprintf( 'Found %d check-in post(s) to process.', $total ) );
		if ( 0 === $total ) {
			return;
		}

		$progress  = \WP_CLI\Utils\make_progress_bar( $dry_run ? 'Inspecting' : 'Enriching', $total );
		$enriched  = 0;
		$skipped   = 0;
		$failed    = 0;
		$api_calls = 0;

		foreach ( $post_ids as $post_id ) {
			$progress->tick();

			if ( $limit > 0 && $api_calls >= $limit ) {
				$skipped += ( $total - $enriched - $skipped - $failed );
				break;
			}

			$lat = (float) get_post_meta( $post_id, 'nop_indieweb_venue_lat', true );
			$lng = (float) get_post_meta( $post_id, 'nop_indieweb_venue_lng', true );
			$ts  = (int) get_post_timestamp( $post_id, 'date_gmt' );

			if ( ( 0.0 === $lat && 0.0 === $lng ) || $ts <= 0 ) {
				$skipped++;
				continue;
			}

			if ( $dry_run ) {
				$enriched++;
				continue;
			}

			$ok = \NOP\IndieWeb\Weather\Weather_Fetcher::enrich_post( $post_id, $lat, $lng, $ts );
			$api_calls++;
			if ( $ok ) {
				$enriched++;
				WP_CLI::log( "  ✓ #{$post_id} weather set (" . gmdate( 'Y-m-d', $ts ) . ")" );
			} else {
				$failed++;
				WP_CLI::log( "  ✗ #{$post_id} failed" );
			}

			usleep( 250000 );
		}

		$progress->finish();

		$limit_note = ( $limit > 0 && $api_calls >= $limit ) ? ' [--limit reached, re-run to continue]' : '';
		WP_CLI::success( sprintf(
			'%s%d enriched · %d skipped · %d failed%s',
			$dry_run ? '[DRY RUN] ' : '',
			$enriched,
			$skipped,
			$failed,
			$limit_note
		) );
	}
}
