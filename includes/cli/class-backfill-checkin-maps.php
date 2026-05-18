<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Cli;

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
	 * : Regenerate the map even when one is already cached.
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

		$post_ids = get_posts( [
			'post_type'      => 'post',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => [
				[
					'taxonomy' => Kind_Taxonomy::TAXONOMY,
					'field'    => 'slug',
					'terms'    => 'checkin',
				],
			],
		] );

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

			$existing = (string) get_post_meta( $post_id, 'nop_indieweb_map_url', true );
			if ( ! $force && '' !== $existing ) {
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

			$url = \NOP\IndieWeb\nop_indieweb_get_or_cache_map_image( $post_id, $lat, $lng, 620, 310, $api_key );
			$api_calls++;
			if ( '' === $url ) {
				$failed++;
				continue;
			}
			$generated++;
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
}
