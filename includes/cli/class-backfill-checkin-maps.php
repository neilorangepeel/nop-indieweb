<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Cli;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use NOP\IndieWeb\Kind\Kind_Taxonomy;
use WP_CLI;

class Backfill_Checkin_Maps {

	/**
	 * Backfill cached Geoapify map images on existing check-ins.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would happen without making API calls or writing files.
	 *
	 * [--force]
	 * : Regenerate the map even when one is already cached. Maps already at the
	 * current aspect ratio are skipped without an API call, so repeated
	 * --limit runs walk forward through the archive instead of redoing the
	 * same batch.
	 *
	 * [--limit=<n>]
	 * : Stop after generating this many map images. Re-run until complete.
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

		$query_args = [
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => $limit > 0 ? $limit : -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- low-frequency meta/taxonomy lookup (import, admin, or per-post render cache), not a hot path
			'tax_query'      => [
				[
					'taxonomy' => Kind_Taxonomy::TAXONOMY,
					'field'    => 'slug',
					'terms'    => 'checkin',
				],
			],
		];
		if ( ! $force ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- low-frequency meta/taxonomy lookup (import, admin, or per-post render cache), not a hot path
			$query_args['meta_query'] = [
				[ 'key' => 'nop_indieweb_map_url', 'compare' => 'NOT EXISTS' ],
			];
		}

		$post_ids = get_posts( $query_args );

		$total = count( $post_ids );
		WP_CLI::log( sprintf( 'Found %d check-in post(s).', $total ) );
		if ( 0 === $total ) {
			return;
		}

		$progress  = \WP_CLI\Utils\make_progress_bar( $dry_run ? 'Inspecting' : 'Generating', $total );
		$generated = 0;
		$cached    = 0;
		$no_coords = 0;
		$failed    = 0;
		$api_calls = 0;

		foreach ( $post_ids as $post_id ) {
			$progress->tick();

			if ( $limit > 0 && $api_calls >= $limit ) {
				$cached += ( $total - $generated - $cached - $no_coords - $failed );
				break;
			}

			// Flight check-ins keep an arc in nop_indieweb_map_url — never overwrite
			// it with a single-marker map. backfill-flight-arcs re-renders those.
			if ( '' !== (string) get_post_meta( $post_id, 'nop_indieweb_flight_from_name', true ) ) {
				$cached++;
				continue;
			}

			$existing = (string) get_post_meta( $post_id, 'nop_indieweb_map_url', true );
			if ( ! $force && '' !== $existing ) {
				$cached++;
				continue;
			}

			if ( $force && '' !== $existing && $this->is_current_ratio( $post_id ) ) {
				$cached++;
				continue;
			}

			$lat = (float) get_post_meta( $post_id, 'nop_indieweb_venue_lat', true );
			$lng = (float) get_post_meta( $post_id, 'nop_indieweb_venue_lng', true );
			if ( ! $lat && ! $lng ) {
				$no_coords++;
				continue;
			}

			if ( $dry_run ) {
				$generated++;
				continue;
			}

			if ( $force ) {
				delete_post_meta( $post_id, 'nop_indieweb_map_url' );
			}

			[ $mw, $mh ] = \NOP\IndieWeb\nop_indieweb_map_dimensions();
			$url = \NOP\IndieWeb\nop_indieweb_get_or_cache_map_image( $post_id, $lat, $lng, $mw, $mh, $api_key );
			$api_calls++;
			if ( '' === $url ) {
				$failed++;
				WP_CLI::log( "  ✗ #{$post_id} map failed" );
				continue;
			}
			$generated++;
			WP_CLI::log( "  ✓ #{$post_id} map cached" );
		}

		$progress->finish();

		$limit_note = ( $limit > 0 && $api_calls >= $limit ) ? ' [--limit reached, re-run to continue]' : '';
		WP_CLI::success( sprintf(
			'%s%d generated · %d already cached · %d without coords · %d failed%s',
			$dry_run ? '[DRY RUN] ' : '',
			$generated,
			$cached,
			$no_coords,
			$failed,
			$limit_note
		) );
	}

	/**
	 * Whether the cached PNG for this post is already at the shape the template
	 * expects.
	 *
	 * Compares the ratio rather than the pixel dimensions on purpose: the 2×
	 * retina factor is owned by nop_indieweb_cache_static_map(), and repeating
	 * it here would re-create the drift that nop_indieweb_map_dimensions() was
	 * added to close. Cropping is a ratio problem, so ratio is what we test.
	 */
	private function is_current_ratio( int $post_id ): bool {
		$upload = wp_upload_dir();
		$file   = $upload['basedir'] . "/checkin-maps/checkin-map-{$post_id}.png";
		if ( ! is_readable( $file ) ) {
			return false;
		}

		$size = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a truncated or non-image cache file should regenerate, not warn
		if ( ! is_array( $size ) || empty( $size[1] ) ) {
			return false;
		}

		[ $want_w, $want_h ] = \NOP\IndieWeb\nop_indieweb_map_dimensions();

		return abs( ( (int) $size[0] / (int) $size[1] ) - ( $want_w / $want_h ) ) < 0.01;
	}
}
