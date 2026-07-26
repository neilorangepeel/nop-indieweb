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
 * `wp nop-indieweb backfill-flight-arcs`
 *
 * Retro-applies the flight-arc feature to existing check-ins:
 *
 *  - AUTO: flags airport check-ins, then pairs consecutive ones (a departure
 *    and an arrival checked in within the window and far enough apart) so the
 *    arrival post renders the arc — the same logic the live Swarm import uses.
 *  - MANUAL (--map): for lone departure check-ins whose destination isn't in
 *    the data (Facebook never exported it), arcs the departure forward to a
 *    named destination supplied in a JSON map of post_id → {name,lat,lng}.
 */
class Backfill_Flight_Arcs {

	/**
	 * Backfill flight arcs onto existing check-in posts.
	 *
	 * ## OPTIONS
	 *
	 * [--window=<hours>]
	 * : Max gap between a departure and arrival airport check-in for auto-pairing.
	 *   Long-haul + layovers can exceed the live 18h default. Default: 24
	 *
	 * [--map=<path>]
	 * : Path to a JSON file that arcs lone check-ins whose other endpoint isn't in
	 *   the data (Facebook never exported it). Keyed by the post ID the arc renders
	 *   on. Give explicit from/to so direction is right (outbound vs return):
	 *   { "981": { "from": {"name":"Belfast Intl","lat":54.66,"lng":-6.22},
	 *              "to":   {"name":"Lanzarote","lat":28.95,"lng":-13.61} } }
	 *   A shorthand { "972": { "name":"Málaga","lat":36.67,"lng":-4.50 } } is
	 *   treated as the destination of an outbound flight from the post's own venue.
	 *
	 * [--service=<slug>]
	 * : Restrict to check-ins from one source service (e.g. facebook). Default: all.
	 *
	 * [--force]
	 * : Re-render arcs even where a flight is already set.
	 *
	 * [--dry-run]
	 * : Report what would happen without writing meta or rendering maps.
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$window_h = isset( $assoc_args['window'] ) ? (float) $assoc_args['window'] : 24.0;
		$window   = (int) round( $window_h * HOUR_IN_SECONDS );
		$dry_run  = isset( $assoc_args['dry-run'] );
		$force    = isset( $assoc_args['force'] );
		$service  = isset( $assoc_args['service'] ) ? sanitize_key( (string) $assoc_args['service'] ) : '';
		$map_path = isset( $assoc_args['map'] ) ? (string) $assoc_args['map'] : '';

		$api_key = trim( (string) \NOP\IndieWeb\nop_indieweb_get_option( 'maps.geoapify_api_key', '' ) );
		if ( '' === $api_key ) {
			WP_CLI::warning( 'No Geoapify API key configured — flight meta will be set but arc images will not render.' );
		}

		$ids = $this->checkin_ids( $service );
		WP_CLI::log( sprintf( 'Scanning %d check-in(s)%s.', count( $ids ), $service ? " (service={$service})" : '' ) );

		// Collect the timeline once: only check-ins with coordinates. Index by the
		// stable source URL too, so a manual map keyed by it resolves to the right
		// post on any site (post IDs differ between local and production).
		$rows      = [];
		$by_source = [];
		foreach ( $ids as $id ) {
			$lat = (string) get_post_meta( $id, 'nop_indieweb_venue_lat', true );
			$lng = (string) get_post_meta( $id, 'nop_indieweb_venue_lng', true );
			if ( '' === $lat || '' === $lng ) {
				continue;
			}
			$name   = (string) get_post_meta( $id, 'nop_indieweb_venue_name', true );
			$source = (string) get_post_meta( $id, 'nop_indieweb_source_url', true );
			$rows[ $id ] = [
				'id'      => $id,
				'name'    => $name,
				'lat'     => (float) $lat,
				'lng'     => (float) $lng,
				'ts'      => (int) get_post_timestamp( $id, 'date' ),
				'airport' => \NOP\IndieWeb\nop_indieweb_is_airport_venue( [ $name ] ),
			];
			if ( '' !== $source ) {
				$by_source[ $source ] = $id;
			}
		}

		// Flag airports so find_prior_airport_checkin() can see them.
		if ( ! $dry_run ) {
			foreach ( $rows as $r ) {
				if ( $r['airport'] ) {
					update_post_meta( $r['id'], 'nop_indieweb_venue_is_airport', '1' );
				}
			}
		}

		$this->auto_pass( $rows, $window, $window_h, $api_key, $force, $dry_run );

		if ( '' !== $map_path ) {
			$this->manual_pass( $rows, $by_source, $map_path, $api_key, $force, $dry_run );
		}

		WP_CLI::success( $dry_run ? '[DRY RUN] complete.' : 'Backfill complete.' );
	}

	private function auto_pass( array $rows, int $window, float $window_h, string $api_key, bool $force, bool $dry_run ): void {
		$min_km = (float) apply_filters( 'nop_indieweb_flight_min_distance_km', 40.0 );

		$airports = array_values( array_filter( $rows, fn( $r ) => $r['airport'] ) );
		usort( $airports, fn( $a, $b ) => $a['ts'] <=> $b['ts'] );

		WP_CLI::log( sprintf( 'AUTO: %d airport check-in(s), pairing window %.0fh.', count( $airports ), $window_h ) );

		$paired = 0;
		for ( $i = 1; $i < count( $airports ); $i++ ) {
			$arr = $airports[ $i ];
			$dep = $airports[ $i - 1 ];

			$gap_h = ( $arr['ts'] - $dep['ts'] ) / 3600;
			$km    = \NOP\IndieWeb\nop_indieweb_haversine_km( $dep['lat'], $dep['lng'], $arr['lat'], $arr['lng'] );
			if ( $gap_h > $window / 3600 || $km < $min_km ) {
				continue;
			}

			$existing = (string) get_post_meta( $arr['id'], 'nop_indieweb_flight_from_post', true );
			if ( '' !== $existing && ! $force ) {
				continue;
			}

			WP_CLI::line( sprintf( '  ✈ #%d  %s → %s  (%.0fkm, +%.1fh)', $arr['id'], $dep['name'], $arr['name'], $km, $gap_h ) );
			$paired++;
			if ( $dry_run ) {
				continue;
			}

			update_post_meta( $arr['id'], 'nop_indieweb_flight_from_post', $dep['id'] );
			update_post_meta( $arr['id'], 'nop_indieweb_flight_from_name', $dep['name'] );
			update_post_meta( $arr['id'], 'nop_indieweb_flight_from_lat', (string) $dep['lat'] );
			update_post_meta( $arr['id'], 'nop_indieweb_flight_from_lng', (string) $dep['lng'] );
			update_post_meta( $arr['id'], 'nop_indieweb_flight_from_locality', (string) get_post_meta( $dep['id'], 'nop_indieweb_venue_locality', true ) );
			update_post_meta( $arr['id'], 'nop_indieweb_flight_distance_km', (string) round( $km ) );
			if ( '' !== $api_key ) {
				\NOP\IndieWeb\nop_indieweb_render_flight_arc_map( $arr['id'], [ $dep['lat'], $dep['lng'] ], [ $arr['lat'], $arr['lng'] ], $api_key );
			}
		}

		WP_CLI::log( sprintf( '  → %d flight(s) %s.', $paired, $dry_run ? 'would pair' : 'paired' ) );
	}

	private function manual_pass( array $rows, array $by_source, string $map_path, string $api_key, bool $force, bool $dry_run ): void {
		if ( ! file_exists( $map_path ) ) {
			WP_CLI::error( "Map file not found: {$map_path}" );
		}
		$map = json_decode( (string) file_get_contents( $map_path ), true );
		if ( ! is_array( $map ) ) {
			WP_CLI::error( "Map file is not valid JSON: {$map_path}" );
		}

		WP_CLI::log( sprintf( 'MANUAL: %d departure(s) with a supplied destination.', count( $map ) ) );
		$done = 0;

		foreach ( $map as $key => $entry ) {
			// A numeric key is a post ID; anything else (e.g. a Facebook source URL)
			// resolves via the stable source-URL index so the map is portable
			// across sites whose post IDs differ.
			$anchor_id = ctype_digit( (string) $key ) ? (int) $key : ( $by_source[ $key ] ?? 0 );
			$anchor    = $anchor_id ? ( $rows[ $anchor_id ] ?? null ) : null;
			if ( ! $anchor ) {
				WP_CLI::warning( "  {$key}: no matching check-in found — skipped." );
				continue;
			}

			// Explicit from/to preserves direction; shorthand treats the post's
			// own venue as the departure and the entry as the destination.
			$from = $entry['from'] ?? [ 'name' => $anchor['name'], 'lat' => $anchor['lat'], 'lng' => $anchor['lng'] ];
			$to   = $entry['to'] ?? $entry;

			$from_name = (string) ( $from['name'] ?? '' );
			$from_lat  = isset( $from['lat'] ) ? (float) $from['lat'] : 0.0;
			$from_lng  = isset( $from['lng'] ) ? (float) $from['lng'] : 0.0;
			$to_name   = (string) ( $to['name'] ?? '' );
			$to_lat    = isset( $to['lat'] ) ? (float) $to['lat'] : 0.0;
			$to_lng    = isset( $to['lng'] ) ? (float) $to['lng'] : 0.0;
			if ( '' === $from_name || '' === $to_name || ( 0.0 === $to_lat && 0.0 === $to_lng ) || ( 0.0 === $from_lat && 0.0 === $from_lng ) ) {
				WP_CLI::warning( "  #{$anchor_id}: from/to each need name + lat + lng — skipped." );
				continue;
			}

			$existing = (string) get_post_meta( $anchor['id'], 'nop_indieweb_flight_to_name', true );
			if ( '' !== $existing && ! $force ) {
				continue;
			}

			$km = \NOP\IndieWeb\nop_indieweb_haversine_km( $from_lat, $from_lng, $to_lat, $to_lng );
			WP_CLI::line( sprintf( '  ✈ #%d  %s → %s  (%.0fkm)', $anchor['id'], $from_name, $to_name, $km ) );
			$done++;
			if ( $dry_run ) {
				continue;
			}

			update_post_meta( $anchor['id'], 'nop_indieweb_flight_from_name', $from_name );
			update_post_meta( $anchor['id'], 'nop_indieweb_flight_from_lat', (string) $from_lat );
			update_post_meta( $anchor['id'], 'nop_indieweb_flight_from_lng', (string) $from_lng );
			update_post_meta( $anchor['id'], 'nop_indieweb_flight_to_name', $to_name );
			update_post_meta( $anchor['id'], 'nop_indieweb_flight_to_lat', (string) $to_lat );
			update_post_meta( $anchor['id'], 'nop_indieweb_flight_to_lng', (string) $to_lng );
			update_post_meta( $anchor['id'], 'nop_indieweb_flight_distance_km', (string) round( $km ) );
			if ( '' !== $api_key ) {
				\NOP\IndieWeb\nop_indieweb_render_flight_arc_map( $anchor['id'], [ $from_lat, $from_lng ], [ $to_lat, $to_lng ], $api_key );
			}
		}

		WP_CLI::log( sprintf( '  → %d departure(s) %s.', $done, $dry_run ? 'would arc' : 'arced' ) );
	}

	private function checkin_ids( string $service ): array {
		$args = [
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one-off maintenance query, not a hot path
			'tax_query'      => [
				[ 'taxonomy' => Kind_Taxonomy::TAXONOMY, 'field' => 'slug', 'terms' => 'checkin' ],
			],
		];
		if ( '' !== $service ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-off maintenance query, not a hot path
			$args['meta_query'] = [
				[ 'key' => 'nop_indieweb_service', 'value' => $service ],
			];
		}
		return get_posts( $args );
	}
}
