<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Exercise;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Request;
use WP_REST_Response;

/**
 * REST receiver for workout data pushed by Health Auto Export.
 *
 * HAE's "REST API" automation POSTs Apple Health workouts (with the GPS route,
 * which Shortcuts can't reach) to this endpoint as JSON. Each workout becomes a
 * draft exercise post — title resolved, GPX minted from the route array, map
 * rendered, weather enriched — via the same nop_indieweb_save_exercise_post()
 * path the Strava importer uses.
 *
 * Auth is a shared secret (auto-generated on first read) sent as
 * `Authorization: Bearer <secret>` or `?token=<secret>`. Posts land as drafts
 * so the workout can be retitled before it's published.
 */
class Exercise_Endpoint {

	private const SECRET_OPTION = 'nop_indieweb_exercise_webhook_secret';

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );
	}

	public function register_route(): void {
		register_rest_route( 'nop-indieweb/v1', '/exercise', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle' ],
			'permission_callback' => [ $this, 'authorize' ],
		] );
	}

	/**
	 * The shared secret. Generated and stored on first access.
	 */
	public function secret(): string {
		$secret = (string) get_option( self::SECRET_OPTION, '' );
		if ( '' === $secret ) {
			$secret = wp_generate_password( 40, false );
			update_option( self::SECRET_OPTION, $secret, false );
		}
		return $secret;
	}

	public function authorize( WP_REST_Request $request ): bool {
		$secret = $this->secret();
		$auth   = (string) $request->get_header( 'authorization' );
		if ( $auth && preg_match( '/Bearer\s+(.+)/i', $auth, $m ) ) {
			return hash_equals( $secret, trim( $m[1] ) );
		}
		$token = (string) $request->get_param( 'token' );
		return '' !== $token && hash_equals( $secret, $token );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		// HAE wraps everything in { "data": { "workouts": [...] } }.
		$workouts = $body['data']['workouts'] ?? ( $body['workouts'] ?? [] );
		if ( ! is_array( $workouts ) || ! $workouts ) {
			return new WP_REST_Response( [ 'created' => [], 'skipped' => 0, 'message' => 'No workouts in payload.' ], 200 );
		}

		// With debug mode on, log the field names each workout carries (not the
		// route data) so a first real payload can be checked against the mapping.
		if ( \NOP\IndieWeb\nop_indieweb_get_option( 'debug_mode', false ) ) {
			foreach ( $workouts as $w ) {
				\NOP\IndieWeb\nop_indieweb_log( 'exercise webhook workout', [
					'fields'       => array_keys( (array) $w ),
					'route_points' => is_array( $w['route'] ?? null ) ? count( $w['route'] ) : 0,
				] );
			}
		}

		$api_key = (string) \NOP\IndieWeb\nop_indieweb_get_option( 'maps.geoapify_api_key', '' );
		$created = [];
		$skipped = 0;

		foreach ( $workouts as $w ) {
			$parsed = $this->map_workout( (array) $w );
			if ( ! $parsed ) {
				++$skipped;
				continue;
			}
			if ( $this->already_imported( $parsed['source_id'] ) ) {
				++$skipped;
				continue;
			}
			$post_id = \NOP\IndieWeb\nop_indieweb_save_exercise_post( $parsed, $api_key );
			if ( ! is_wp_error( $post_id ) ) {
				$created[] = $post_id;
			}
		}

		return new WP_REST_Response( [ 'created' => $created, 'skipped' => $skipped ], 201 );
	}

	/**
	 * Maps one Health Auto Export workout object to the save_exercise_post shape.
	 * Returns null if the workout has no usable route.
	 */
	private function map_workout( array $w ): ?array {
		$route = is_array( $w['route'] ?? null ) ? $w['route'] : [];
		$points = [];
		foreach ( $route as $pt ) {
			$lat = $pt['lat'] ?? ( $pt['latitude'] ?? null );
			$lon = $pt['lon'] ?? ( $pt['longitude'] ?? null );
			if ( null === $lat || null === $lon ) {
				continue;
			}
			$points[] = [ (float) $lat, (float) $lon ];
		}
		if ( count( $points ) < 2 ) {
			return null;
		}

		$type  = $this->activity_slug( (string) ( $w['name'] ?? '' ) );
		$start = $points[0];
		$gmt   = gmdate( 'Y-m-d H:i:s', strtotime( (string) ( $w['start'] ?? 'now' ) ) );

		$meta = array_filter( [
			'nop_indieweb_exercise_distance_m'       => $this->to_metres( $w['distance'] ?? null ),
			'nop_indieweb_exercise_duration_s'       => (int) ( $w['duration'] ?? 0 ),
			'nop_indieweb_exercise_calories'         => (int) round( $this->qty( $w['activeEnergyBurned'] ?? ( $w['activeEnergy'] ?? null ) ) ),
			'nop_indieweb_exercise_elevation_gain_m' => $this->to_metres( $w['elevationUp'] ?? null ),
			'nop_indieweb_exercise_elevation_loss_m' => $this->to_metres( $w['elevationDown'] ?? null ),
			'nop_indieweb_exercise_max_speed_ms'     => $this->to_ms( $w['maxSpeed'] ?? null ),
			'nop_indieweb_exercise_avg_heart_rate'   => (int) round( $this->qty( $w['avgHeartRate'] ?? null ) ),
			'nop_indieweb_exercise_max_heart_rate'   => (int) round( $this->qty( $w['maxHeartRate'] ?? null ) ),
		] );

		$gpx_points = array_map(
			static fn( $pt ) => [
				'lat'  => $pt['lat'] ?? ( $pt['latitude'] ?? null ),
				'lon'  => $pt['lon'] ?? ( $pt['longitude'] ?? null ),
				'ele'  => $pt['altitude'] ?? '',
				'time' => $pt['timestamp'] ?? '',
			],
			$route
		);

		return [
			'name'      => '', // HAE only sends the activity type — let the resolver build "{Type} in {place}".
			'type'      => $type,
			'gmt'       => $gmt,
			'status'    => 'draft',
			'start'     => $start,
			'points'    => $points,
			'gpx'       => \NOP\IndieWeb\nop_indieweb_build_gpx( $gpx_points ),
			'meta'      => $meta,
			'source_id' => 'hae:' . $gmt . ':' . $type,
			'service'   => 'health-auto-export',
		];
	}

	private function activity_slug( string $name ): string {
		$n = strtolower( $name );
		foreach ( [
			'run' => 'run', 'cycl' => 'ride', 'bike' => 'ride', 'walk' => 'walk',
			'hik' => 'hike', 'swim' => 'swim', 'row' => 'rowing', 'yoga' => 'yoga',
			'pilates' => 'pilates', 'strength' => 'strength',
		] as $needle => $slug ) {
			if ( str_contains( $n, $needle ) ) {
				return $slug;
			}
		}
		return 'workout';
	}

	private function qty( $field ): float {
		return is_array( $field ) ? (float) ( $field['qty'] ?? 0 ) : (float) $field;
	}

	private function to_metres( $field ): float {
		if ( ! is_array( $field ) ) {
			return (float) $field;
		}
		$qty   = (float) ( $field['qty'] ?? 0 );
		$units = strtolower( (string) ( $field['units'] ?? 'm' ) );
		return match ( $units ) {
			'km'    => $qty * 1000,
			'mi'    => $qty * 1609.344,
			'ft'    => $qty * 0.3048,
			default => $qty,
		};
	}

	private function to_ms( $field ): float {
		if ( ! is_array( $field ) ) {
			return (float) $field;
		}
		$qty   = (float) ( $field['qty'] ?? 0 );
		$units = strtolower( (string) ( $field['units'] ?? 'kmph' ) );
		return match ( $units ) {
			'kmph', 'km/h' => $qty / 3.6,
			'mph'          => $qty * 0.44704,
			default        => $qty,
		};
	}

	private function already_imported( string $source_id ): bool {
		$found = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- webhook dedup, low frequency
			'meta_query'     => [ [ 'key' => 'nop_indieweb_exercise_source_id', 'value' => $source_id ] ],
		] );
		return ! empty( $found );
	}
}
