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
 * `wp nop-indieweb backfill-exercise-maps`
 *
 * Re-renders exercise route maps as the full GPS polyline, re-parsed from each
 * workout's stored .gpx (nop_indieweb_exercise_gpx_url). Use it to re-skin
 * exercise maps or recover after a bad render — it only ever draws the route,
 * never a single-marker, so it can't flatten a track. Workouts without a usable
 * GPX are skipped (their existing map is left untouched).
 */
class Backfill_Exercise_Maps {

	/**
	 * Re-render exercise route maps from stored GPX.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would happen without making API calls or writing files.
	 *
	 * [--force]
	 * : Re-render even where a map is already cached.
	 *
	 * [--limit=<n>]
	 * : Stop after rendering this many maps. Re-run to continue.
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );
		$force   = isset( $assoc_args['force'] );
		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

		$api_key = trim( (string) \NOP\IndieWeb\nop_indieweb_get_option( 'maps.geoapify_api_key', '' ) );
		if ( '' === $api_key ) {
			WP_CLI::error( 'No Geoapify API key configured (Settings → Swarm → Geoapify API key).' );
		}

		$post_ids = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one-off maintenance query, not a hot path
			'tax_query'      => [
				[ 'taxonomy' => Kind_Taxonomy::TAXONOMY, 'field' => 'slug', 'terms' => 'exercise' ],
			],
		] );

		$total = count( $post_ids );
		WP_CLI::log( sprintf( 'Found %d exercise post(s).', $total ) );
		if ( 0 === $total ) {
			return;
		}

		$upload    = wp_upload_dir();
		$progress  = \WP_CLI\Utils\make_progress_bar( $dry_run ? 'Inspecting' : 'Rendering', $total );
		$rendered  = $cached = $no_gpx = $failed = $api_calls = 0;

		foreach ( $post_ids as $post_id ) {
			$progress->tick();

			if ( $limit > 0 && $api_calls >= $limit ) {
				break;
			}

			if ( ! $force && '' !== (string) get_post_meta( $post_id, 'nop_indieweb_exercise_map_url', true ) ) {
				$cached++;
				continue;
			}

			$gpx_url = (string) get_post_meta( $post_id, 'nop_indieweb_exercise_gpx_url', true );
			if ( '' === $gpx_url ) {
				$no_gpx++;
				continue;
			}
			$path = str_replace( $upload['baseurl'], $upload['basedir'], $gpx_url );
			if ( ! file_exists( $path ) ) {
				$no_gpx++;
				continue;
			}

			if ( $dry_run ) {
				$rendered++;
				continue;
			}

			$parsed = \NOP\IndieWeb\nop_indieweb_parse_gpx( (string) file_get_contents( $path ) );
			if ( count( $parsed['points'] ) < 2 ) {
				$no_gpx++;
				continue;
			}

			$url = \NOP\IndieWeb\nop_indieweb_render_route_map( $post_id, $parsed['points'], $api_key );
			$api_calls++;
			if ( '' === $url ) {
				$failed++;
				WP_CLI::log( "  ✗ #{$post_id} route render failed" );
				continue;
			}
			$rendered++;
		}

		$progress->finish();

		$limit_note = ( $limit > 0 && $api_calls >= $limit ) ? ' [--limit reached, re-run to continue]' : '';
		WP_CLI::success( sprintf(
			'%s%d route(s) rendered · %d already cached · %d without usable GPX · %d failed%s',
			$dry_run ? '[DRY RUN] ' : '',
			$rendered,
			$cached,
			$no_gpx,
			$failed,
			$limit_note
		) );
	}
}
