<?php
declare( strict_types=1 );

namespace NOP\IndieWeb;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * True when any of a venue's Foursquare category names marks it as an airport.
 * Matches on the word "airport" (Airport, Airport Terminal, International
 * Airport, Airport Lounge…) or "airfield". The match list is filterable.
 */
function nop_indieweb_is_airport_venue( array $categories ): bool {
	$needles = (array) apply_filters( 'nop_indieweb_airport_categories', [ 'airport', 'airfield' ] );
	foreach ( $categories as $category ) {
		$haystack = strtolower( (string) $category );
		foreach ( $needles as $needle ) {
			if ( '' !== $needle && str_contains( $haystack, strtolower( (string) $needle ) ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Great-circle distance between two points in kilometres (haversine).
 */
function nop_indieweb_haversine_km( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
	$earth = 6371.0088;
	$dlat  = deg2rad( $lat2 - $lat1 );
	$dlng  = deg2rad( $lng2 - $lng1 );
	$a     = sin( $dlat / 2 ) ** 2
		+ cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $dlng / 2 ) ** 2;
	return $earth * 2 * asin( min( 1.0, sqrt( $a ) ) );
}

/**
 * Finds the most recent prior airport checkin by the same author within the
 * pairing window (default 18h), so an arrival airport checkin can be linked to
 * the departure it flew from. Returns the post ID or null.
 */
function nop_indieweb_find_prior_airport_checkin( int $post_id, int $author, string $post_date ): ?int {
	$window = (int) apply_filters( 'nop_indieweb_flight_pair_window_seconds', 18 * HOUR_IN_SECONDS );
	$after  = gmdate( 'Y-m-d H:i:s', (int) strtotime( $post_date ) - $window );

	$query = new \WP_Query( [
		'post_type'      => 'post',
		'post_status'    => [ 'publish', 'private', 'draft' ],
		'author'         => $author,
		'post__not_in'   => [ $post_id ],
		'meta_key'       => 'nop_indieweb_venue_is_airport', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		'date_query'     => [
			[ 'column' => 'post_date', 'before' => $post_date, 'inclusive' => false ],
			[ 'column' => 'post_date', 'after' => $after, 'inclusive' => true ],
		],
		'orderby'        => 'date',
		'order'          => 'DESC',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	] );

	return $query->posts ? (int) $query->posts[0] : null;
}

/**
 * If this checkin is at an airport, flags it (so later arrivals can pair against
 * it) and, when a prior airport checkin exists a real flight's distance away,
 * links the two into a flight: stores the departure meta and renders the arc
 * into nop_indieweb_map_url. Returns true when a flight was paired — the caller
 * then skips the single-marker map so the arc stands in its place.
 */
function nop_indieweb_maybe_pair_flight( int $post_id, array $parsed, array $categories, string $geoapify_key ): bool {
	if ( ! nop_indieweb_is_airport_venue( $categories ) ) {
		return false;
	}
	update_post_meta( $post_id, 'nop_indieweb_venue_is_airport', '1' );

	$arr_lat = (float) ( $parsed['venue_lat'] ?? 0 );
	$arr_lng = (float) ( $parsed['venue_lng'] ?? 0 );
	if ( ! $arr_lat && ! $arr_lng ) {
		return false;
	}

	$author    = (int) get_post_field( 'post_author', $post_id );
	$post_date = (string) get_post_field( 'post_date', $post_id );
	$prior     = nop_indieweb_find_prior_airport_checkin( $post_id, $author, $post_date );
	if ( ! $prior ) {
		return false;
	}

	$dep_lat = (float) get_post_meta( $prior, 'nop_indieweb_venue_lat', true );
	$dep_lng = (float) get_post_meta( $prior, 'nop_indieweb_venue_lng', true );
	if ( ! $dep_lat && ! $dep_lng ) {
		return false;
	}

	$distance = nop_indieweb_haversine_km( $dep_lat, $dep_lng, $arr_lat, $arr_lng );
	$min_km   = (float) apply_filters( 'nop_indieweb_flight_min_distance_km', 40.0 );
	if ( $distance < $min_km ) {
		return false;
	}

	update_post_meta( $post_id, 'nop_indieweb_flight_from_post', $prior );
	update_post_meta( $post_id, 'nop_indieweb_flight_from_name', (string) get_post_meta( $prior, 'nop_indieweb_venue_name', true ) );
	update_post_meta( $post_id, 'nop_indieweb_flight_from_lat', (string) $dep_lat );
	update_post_meta( $post_id, 'nop_indieweb_flight_from_lng', (string) $dep_lng );
	update_post_meta( $post_id, 'nop_indieweb_flight_from_locality', (string) get_post_meta( $prior, 'nop_indieweb_venue_locality', true ) );
	update_post_meta( $post_id, 'nop_indieweb_flight_distance_km', (string) round( $distance ) );

	if ( '' !== $geoapify_key ) {
		nop_indieweb_render_flight_arc_map(
			$post_id,
			[ $dep_lat, $dep_lng ],
			[ $arr_lat, $arr_lng ],
			$geoapify_key
		);
	}

	return true;
}
