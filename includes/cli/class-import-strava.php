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
 * `wp nop-indieweb import-strava`
 *
 * Imports activities from a Strava bulk export ("export_<id>" folder) as
 * exercise-kind posts. Reads activities.csv for the metadata and the matching
 * GPX / GPX.GZ track for the route; FIT tracks are skipped (convert separately).
 *
 * Each post stores the exercise metrics as meta, saves the GPX to
 * uploads/exercise-routes/ as the canonical artifact, and renders + caches a
 * route map. Idempotent: an activity already imported (its Strava ID is in
 * nop_indieweb_exercise_source_id) is skipped unless --force.
 */
class Import_Strava {

	private const STRAVA_TYPE_MAP = [
		'Run'             => 'run',
		'Ride'            => 'ride',
		'Virtual Ride'    => 'ride',
		'Walk'            => 'walk',
		'Hike'            => 'hike',
		'Swim'            => 'swim',
		'Rowing'          => 'rowing',
		'Yoga'            => 'yoga',
		'Weight Training' => 'strength',
		'Workout'         => 'workout',
	];

	/**
	 * Import Strava activities as exercise posts.
	 *
	 * ## OPTIONS
	 *
	 * --dir=<path>
	 * : Path to the unpacked Strava export folder (contains activities.csv).
	 *
	 * [--limit=<n>]
	 * : Import at most this many activities. Re-run to continue.
	 *
	 * [--status=<status>]
	 * : Post status for imported posts. Default: draft.
	 *
	 * [--dry-run]
	 * : Report what would be imported without writing anything.
	 *
	 * [--force]
	 * : Re-import activities even if their Strava ID already exists.
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$dir     = rtrim( (string) ( $assoc_args['dir'] ?? '' ), '/' );
		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;
		$status  = (string) ( $assoc_args['status'] ?? 'draft' );
		$dry_run = isset( $assoc_args['dry-run'] );
		$force   = isset( $assoc_args['force'] );

		$csv = $dir . '/activities.csv';
		if ( ! $dir || ! file_exists( $csv ) ) {
			WP_CLI::error( "activities.csv not found in --dir ({$csv})." );
		}

		$api_key = (string) \NOP\IndieWeb\nop_indieweb_get_option( 'maps.geoapify_api_key', '' );

		$fh  = fopen( $csv, 'r' );
		$hdr = fgetcsv( $fh );
		$col = [];
		foreach ( $hdr as $i => $name ) {
			$col[ trim( (string) $name ) ] = $i; // duplicate headers → last (canonical SI) wins.
		}

		$imported = 0;
		$skipped  = 0;
		$no_route = 0;

		while ( ( $row = fgetcsv( $fh ) ) !== false ) {
			$get = fn( string $name ) => isset( $col[ $name ] ) ? trim( (string) ( $row[ $col[ $name ] ] ?? '' ) ) : '';

			$file = $get( 'Filename' );
			if ( ! preg_match( '/\.gpx(\.gz)?$/', $file ) ) {
				++$no_route; // FIT or no track — out of scope for this pass.
				continue;
			}

			$strava_id = $get( 'Activity ID' );
			if ( ! $force && $this->already_imported( $strava_id ) ) {
				++$skipped;
				continue;
			}

			$path = $dir . '/' . $file;
			if ( ! file_exists( $path ) ) {
				++$no_route;
				continue;
			}
			$raw = file_get_contents( $path );
			if ( str_ends_with( $file, '.gz' ) ) {
				$raw = gzdecode( $raw );
			}
			$parsed = \NOP\IndieWeb\nop_indieweb_parse_gpx( (string) $raw );
			if ( count( $parsed['points'] ) < 2 ) {
				++$no_route;
				continue;
			}

			$type  = self::STRAVA_TYPE_MAP[ $get( 'Activity Type' ) ] ?? 'workout';
			$name  = $get( 'Activity Name' ) ?: ucfirst( $type );
			$gmt   = $parsed['start_time'] ? gmdate( 'Y-m-d H:i:s', strtotime( $parsed['start_time'] ) ) : gmdate( 'Y-m-d H:i:s', strtotime( $get( 'Activity Date' ) ) );

			if ( $dry_run ) {
				WP_CLI::log( sprintf( '[dry-run] %s — %s (%s, %d pts)', $gmt, $name, $type, count( $parsed['points'] ) ) );
				++$imported;
				if ( $limit && $imported >= $limit ) {
					break;
				}
				continue;
			}

			$post_id = wp_insert_post( [
				'post_title'    => $name,
				'post_content'  => '',
				'post_status'   => $status,
				'post_type'     => 'post',
				'post_date_gmt' => $gmt,
				'post_date'     => get_date_from_gmt( $gmt ),
			], true );

			if ( is_wp_error( $post_id ) ) {
				WP_CLI::warning( "insert failed for {$strava_id}: " . $post_id->get_error_message() );
				continue;
			}

			wp_set_object_terms( $post_id, 'exercise', Kind_Taxonomy::TAXONOMY );

			$start = $parsed['start'] ?? [ 0, 0 ];
			$meta  = [
				'nop_indieweb_service'             => 'strava',
				'nop_indieweb_exercise_type'       => $type,
				'nop_indieweb_exercise_source_id'  => $strava_id,
				'nop_indieweb_exercise_source_url' => $strava_id ? "https://www.strava.com/activities/{$strava_id}" : '',
				'nop_indieweb_exercise_start_lat'  => (string) $start[0],
				'nop_indieweb_exercise_start_lng'  => (string) $start[1],
			];
			$metrics = [
				'nop_indieweb_exercise_distance_m'       => (float) $get( 'Distance' ),
				'nop_indieweb_exercise_duration_s'       => (int) ( $get( 'Moving Time' ) ?: $get( 'Elapsed Time' ) ),
				'nop_indieweb_exercise_elevation_gain_m' => (float) $get( 'Elevation Gain' ),
				'nop_indieweb_exercise_elevation_loss_m' => (float) $get( 'Elevation Loss' ),
				'nop_indieweb_exercise_avg_heart_rate'   => (int) (float) $get( 'Average Heart Rate' ),
				'nop_indieweb_exercise_max_heart_rate'   => (int) (float) $get( 'Max Heart Rate' ),
				'nop_indieweb_exercise_calories'         => (int) (float) $get( 'Calories' ),
			];
			foreach ( $metrics as $k => $v ) {
				if ( $v ) {
					$meta[ $k ] = $v;
				}
			}
			foreach ( $meta as $k => $v ) {
				update_post_meta( $post_id, $k, $v );
			}

			$this->store_gpx( $post_id, (string) $raw );

			if ( $api_key ) {
				\NOP\IndieWeb\nop_indieweb_render_route_map( $post_id, $parsed['points'], $api_key, [ 'color' => 'e03232' ] );
			}

			WP_CLI::log( sprintf( '#%d  %s — %s (%s)', $post_id, $gmt, $name, $type ) );
			++$imported;
			if ( $limit && $imported >= $limit ) {
				break;
			}
		}
		fclose( $fh );

		WP_CLI::success( sprintf(
			'%s %d activities (skipped %d already imported, %d non-GPX/empty).',
			$dry_run ? 'Would import' : 'Imported',
			$imported,
			$skipped,
			$no_route
		) );
	}

	private function already_imported( string $strava_id ): bool {
		if ( '' === $strava_id ) {
			return false;
		}
		$found = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-off CLI import dedup
			'meta_query'     => [ [ 'key' => 'nop_indieweb_exercise_source_id', 'value' => $strava_id ] ],
		] );
		return ! empty( $found );
	}

	private function store_gpx( int $post_id, string $gpx ): void {
		$dir = wp_upload_dir()['basedir'] . '/exercise-routes';
		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}
		file_put_contents( $dir . "/exercise-route-{$post_id}.gpx", $gpx );
		update_post_meta(
			$post_id,
			'nop_indieweb_exercise_gpx_url',
			wp_upload_dir()['baseurl'] . "/exercise-routes/exercise-route-{$post_id}.gpx"
		);
	}
}
