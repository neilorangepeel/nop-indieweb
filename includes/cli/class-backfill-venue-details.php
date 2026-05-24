<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Cli;

use NOP\IndieWeb\Kind\Kind_Taxonomy;
use NOP\IndieWeb\Kind\Venue_Category_Taxonomy;
use NOP\IndieWeb\Venue\Foursquare_Enricher;
use NOP\IndieWeb\Venue\Geoapify_Geocoder;
use WP_CLI;

/**
 * `wp nop-indieweb backfill-venue-details`
 *
 * For every check-in with a Foursquare venue ID (nop_indieweb_venue_uid),
 * fetches authoritative address fields (street, locality, region, country,
 * postcode) and categories from the FSQ Places API and writes them back to
 * post meta. Coordinates are NOT updated here — FSQ's geocodes field is a
 * paid premium field; coordinates come from search_venue() results.
 *
 * Idempotent: without --force, posts that already have a locality
 * AND venue categories are skipped (treat them as already enriched).
 */
class Backfill_Venue_Details {

	/**
	 * Backfill address and categories from Foursquare venue details.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would change without writing anything.
	 *
	 * [--force]
	 * : Re-fetch and overwrite even posts that already have full data.
	 *
	 * [--limit=<n>]
	 * : Stop after this many FSQ API calls. Re-run until complete.
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );
		$force   = isset( $assoc_args['force'] );
		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

		$api_key = (string) \NOP\IndieWeb\nop_indieweb_get_option( 'venue.foursquare_api_key', '' );
		if ( '' === $api_key ) {
			WP_CLI::error( 'No Foursquare API key configured (Settings → Swarm → Foursquare API key).' );
		}

		$meta_query = [
			'relation' => 'AND',
			[
				'relation' => 'OR',
				[ 'key' => 'nop_indieweb_venue_uid',    'value' => '', 'compare' => '!=' ],
				[ 'key' => 'nop_indieweb_venue_url',    'value' => '', 'compare' => '!=' ],
				[ 'key' => 'nop_indieweb_venue_fsq_id', 'value' => '', 'compare' => '!=' ],
			],
		];
		if ( ! $force ) {
			$meta_query[] = [
				'relation' => 'OR',
				[ 'key' => 'nop_indieweb_venue_locality', 'compare' => 'NOT EXISTS' ],
				[ 'key' => 'nop_indieweb_venue_locality', 'value' => '', 'compare' => '=' ],
			];
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
		WP_CLI::log( sprintf( 'Found %d check-in post(s) with a FSQ venue ID.', $total ) );
		if ( 0 === $total ) {
			return;
		}

		$progress  = \WP_CLI\Utils\make_progress_bar( $dry_run ? 'Inspecting' : 'Enriching', $total );
		$updated   = 0;
		$skipped   = 0;
		$no_data   = 0;
		$api_calls = 0;

		foreach ( $post_ids as $post_id ) {
			$progress->tick();

			if ( $limit > 0 && $api_calls >= $limit ) {
				$skipped += ( $total - $updated - $skipped - $no_data );
				break;
			}

			// Without --force, skip posts that already have authoritative FSQ data.
			// locality (not address) is the reliable signal — address in imported data
			// may be a venue name rather than a real street.
			if ( ! $force ) {
				$has_locality = '' !== (string) get_post_meta( $post_id, 'nop_indieweb_venue_locality', true );
				$existing     = wp_get_post_terms( $post_id, Venue_Category_Taxonomy::TAXONOMY );
				$has_cats     = ! is_wp_error( $existing ) && $existing;
				if ( $has_locality && $has_cats ) {
					$skipped++;
					continue;
				}
			}

			$venue_id = (string) get_post_meta( $post_id, 'nop_indieweb_venue_uid', true );
			if ( '' === $venue_id ) {
				$venue_url = (string) get_post_meta( $post_id, 'nop_indieweb_venue_url', true );
				$venue_id  = Foursquare_Enricher::extract_venue_id( $venue_url );
			}
			if ( '' === $venue_id ) {
				$venue_id = (string) get_post_meta( $post_id, 'nop_indieweb_venue_fsq_id', true );
			}
			if ( '' === $venue_id ) {
				$no_data++;
				continue;
			}

			$details  = Foursquare_Enricher::fetch_venue_details( $venue_id );
			$api_calls++;

			if ( ! $details ) {
				$no_data++;
				continue;
			}

			// If FSQ returned no locality, fall back to Geoapify reverse geocoding.
			if ( '' === $details['locality'] ) {
				$lat = (float) get_post_meta( $post_id, 'nop_indieweb_venue_lat', true );
				$lng = (float) get_post_meta( $post_id, 'nop_indieweb_venue_lng', true );
				if ( $lat || $lng ) {
					$geo = Geoapify_Geocoder::reverse_geocode( $lat, $lng );
					foreach ( [ 'address', 'locality', 'region', 'country', 'postcode' ] as $field ) {
						if ( '' === $details[ $field ] && '' !== ( $geo[ $field ] ?? '' ) ) {
							$details[ $field ] = $geo[ $field ];
						}
					}
				}
			}

			if ( $dry_run ) {
				$current_title = get_the_title( $post_id );
				$venue_name    = (string) get_post_meta( $post_id, 'nop_indieweb_venue_name', true );
				$new_title     = ( $details['locality'] && $venue_name && ! str_contains( $current_title, $details['locality'] ) )
					? $venue_name . ', ' . $details['locality']
					: null;
				WP_CLI::line( sprintf(
					'  #%d %s → %s, %s [%s]%s',
					$post_id,
					$current_title,
					$details['address'] ?: '(no address)',
					$details['locality'] ?: '(no locality)',
					implode( ', ', $details['categories'] ) ?: 'no categories',
					$new_title ? " [title → {$new_title}]" : ''
				) );
				$updated++;
				continue;
			}

			if ( $details['address'] ) {
				update_post_meta( $post_id, 'nop_indieweb_venue_address', $details['address'] );
			}
			if ( $details['locality'] ) {
				update_post_meta( $post_id, 'nop_indieweb_venue_locality', $details['locality'] );
			}
			if ( $details['region'] ) {
				update_post_meta( $post_id, 'nop_indieweb_venue_region', $details['region'] );
			}
			if ( $details['country'] ) {
				update_post_meta( $post_id, 'nop_indieweb_venue_country', $details['country'] );
			}
			if ( $details['postcode'] ) {
				update_post_meta( $post_id, 'nop_indieweb_venue_postcode', $details['postcode'] );
			}
			if ( $details['categories'] ) {
				wp_set_object_terms( $post_id, $details['categories'], Venue_Category_Taxonomy::TAXONOMY );
			}

			// Backfill the venue_uid if it was previously missing (derived from URL).
			if ( '' === (string) get_post_meta( $post_id, 'nop_indieweb_venue_uid', true ) ) {
				update_post_meta( $post_id, 'nop_indieweb_venue_uid', $venue_id );
			}

			// Update the post title to include locality when it was missing at ingest time.
			if ( $details['locality'] ) {
				$current_title = get_the_title( $post_id );
				$venue_name    = (string) get_post_meta( $post_id, 'nop_indieweb_venue_name', true );
				if ( $venue_name && ! str_contains( $current_title, $details['locality'] ) ) {
					wp_update_post( [ 'ID' => $post_id, 'post_title' => $venue_name . ', ' . $details['locality'] ] );
				}
			}

			$updated++;
		}

		$progress->finish();

		$limit_note = ( $limit > 0 && $api_calls >= $limit ) ? ' [--limit reached, re-run to continue]' : '';
		WP_CLI::success( sprintf(
			'%s%d updated · %d skipped (already complete) · %d returned no data%s',
			$dry_run ? '[DRY RUN] ' : '',
			$updated,
			$skipped,
			$no_data,
			$limit_note
		) );
	}
}
