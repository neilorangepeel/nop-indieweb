<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Cli;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use NOP\IndieWeb\Venue\Foursquare_Enricher;
use NOP\IndieWeb\Kind\Kind_Taxonomy;
use NOP\IndieWeb\Kind\Venue_Category_Taxonomy;
use WP_CLI;

/**
 * `wp nop-indieweb backfill-venue-categories`
 *
 * Walks every check-in that has no nop_venue_category terms, looks the
 * venue up via the Foursquare Places API, and assigns the returned
 * categories as terms. Cached per-venue, so multiple visits to the same
 * place cost one API call across the entire run.
 */
class Backfill_Venue_Categories {

	/**
	 * Backfill venue categories on existing check-ins.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything.
	 *
	 * [--force]
	 * : Re-tag every check-in, even ones that already have venue category terms.
	 *
	 * [--search-by-name]
	 * : For check-ins without a Foursquare venue URL (e.g. Facebook imports),
	 *   search FSQ by venue name and coordinates instead of skipping. Also stores
	 *   the matched fsq_id as nop_indieweb_venue_uid so future fetches are fast.
	 *
	 * [--limit=<n>]
	 * : Stop after processing this many posts that required an API call. Useful for
	 *   staying within Studio WP-CLI's 120s timeout. Re-run until complete.
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$dry_run     = isset( $assoc_args['dry-run'] );
		$force       = isset( $assoc_args['force'] );
		$search_mode = isset( $assoc_args['search-by-name'] );
		$limit       = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

		$api_key = (string) \NOP\IndieWeb\nop_indieweb_get_option( 'venue.foursquare_api_key', '' );
		if ( '' === $api_key ) {
			WP_CLI::error( 'No Foursquare API key configured (Settings → Swarm → Foursquare API key).' );
		}

		$post_ids = get_posts( [
			'post_type'      => 'post',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- low-frequency meta/taxonomy lookup (import, admin, or per-post render cache), not a hot path
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

		$progress   = \WP_CLI\Utils\make_progress_bar( $dry_run ? 'Inspecting' : 'Enriching', $total );
		$updated    = 0;
		$skipped    = 0;
		$no_venue   = 0;
		$no_cats    = 0;
		$searched   = 0;
		$api_calls  = 0;

		foreach ( $post_ids as $post_id ) {
			$progress->tick();

			if ( $limit > 0 && $api_calls >= $limit ) {
				$skipped += ( $total - $updated - $skipped - $no_venue - $no_cats );
				break;
			}

			if ( ! $force ) {
				$existing = wp_get_post_terms( $post_id, Venue_Category_Taxonomy::TAXONOMY );
				if ( ! is_wp_error( $existing ) && $existing ) {
					$skipped++;
					continue;
				}
			}

			$venue_url = (string) get_post_meta( $post_id, 'nop_indieweb_venue_url', true );
			$venue_id  = Foursquare_Enricher::extract_venue_id( $venue_url );

			if ( '' === $venue_id ) {
				if ( ! $search_mode ) {
					$no_venue++;
					continue;
				}

				// No Foursquare URL — search by venue name + coordinates (e.g. Facebook imports).
				$name = (string) get_post_meta( $post_id, 'nop_indieweb_venue_name', true );
				$lat  = (string) get_post_meta( $post_id, 'nop_indieweb_venue_lat', true );
				$lng  = (string) get_post_meta( $post_id, 'nop_indieweb_venue_lng', true );

				if ( '' === $name ) {
					$no_venue++;
					continue;
				}

				$match = Foursquare_Enricher::search_venue( $name, $lat, $lng );
				$api_calls++;
				if ( ! $match ) {
					$no_cats++;
					continue;
				}

				if ( ! $dry_run ) {
					if ( $match['categories'] ) {
						wp_set_object_terms( $post_id, $match['categories'], Venue_Category_Taxonomy::TAXONOMY );
					}
					if ( $match['fsq_id'] ) {
						update_post_meta( $post_id, 'nop_indieweb_venue_uid', $match['fsq_id'] );
						update_post_meta( $post_id, 'nop_indieweb_venue_url', 'https://foursquare.com/v/' . $match['fsq_id'] );
					}
					// Populate coordinates from FSQ when the post has none (Facebook tagged places).
					if ( $match['lat'] && $match['lng'] && '' === $lat ) {
						update_post_meta( $post_id, 'nop_indieweb_venue_lat', $match['lat'] );
						update_post_meta( $post_id, 'nop_indieweb_venue_lng', $match['lng'] );
					}
				}

				if ( ! $match['categories'] ) {
					$no_cats++;
					continue;
				}
				$searched++;
				$updated++;
				continue;
			}

			$cats = Foursquare_Enricher::fetch_categories( $venue_id );
			$api_calls++;
			if ( ! $cats ) {
				$no_cats++;
				continue;
			}

			if ( ! $dry_run ) {
				wp_set_object_terms( $post_id, $cats, Venue_Category_Taxonomy::TAXONOMY );
			}
			$updated++;
		}

		$progress->finish();

		$search_note = $search_mode ? sprintf( ' (%d via name search)', $searched ) : '';
		$limit_note  = ( $limit > 0 && $api_calls >= $limit ) ? ' [--limit reached, re-run to continue]' : '';
		WP_CLI::success( sprintf(
			'%s%d updated%s · %d already tagged · %d without venue ID · %d returned no categories%s',
			$dry_run ? '[DRY RUN] ' : '',
			$updated,
			$search_note,
			$skipped,
			$no_venue,
			$no_cats,
			$limit_note
		) );
	}
}
