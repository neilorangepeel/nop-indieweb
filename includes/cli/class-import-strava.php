<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Cli;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
			$start = $parsed['start'] ?? [ 0, 0 ];
			$name  = \NOP\IndieWeb\nop_indieweb_exercise_title( $get( 'Activity Name' ), $type, (float) $start[0], (float) $start[1] );
			$gmt   = $parsed['start_time'] ? gmdate( 'Y-m-d H:i:s', strtotime( $parsed['start_time'] ) ) : gmdate( 'Y-m-d H:i:s', strtotime( $get( 'Activity Date' ) ) );

			if ( $dry_run ) {
				WP_CLI::log( sprintf( '[dry-run] %s — %s (%s, %d pts)', $gmt, $name, $type, count( $parsed['points'] ) ) );
				++$imported;
				if ( $limit && $imported >= $limit ) {
					break;
				}
				continue;
			}

			$meta    = [];
			$gear    = $get( 'Activity Gear' );
			if ( '' !== $gear ) {
				$meta['nop_indieweb_exercise_gear'] = sanitize_text_field( $gear );
			}
			$metrics = [
				'nop_indieweb_exercise_distance_m'       => (float) $get( 'Distance' ),
				'nop_indieweb_exercise_duration_s'       => (int) ( $get( 'Moving Time' ) ?: $get( 'Elapsed Time' ) ),
				'nop_indieweb_exercise_elevation_gain_m' => (float) $get( 'Elevation Gain' ),
				'nop_indieweb_exercise_elevation_loss_m' => (float) $get( 'Elevation Loss' ),
				'nop_indieweb_exercise_elevation_high_m' => (float) $get( 'Elevation High' ),
				'nop_indieweb_exercise_elevation_low_m'  => (float) $get( 'Elevation Low' ),
				'nop_indieweb_exercise_max_speed_ms'     => (float) $get( 'Max Speed' ),
				'nop_indieweb_exercise_max_grade'        => (float) $get( 'Max Grade' ),
				'nop_indieweb_exercise_avg_heart_rate'   => (int) (float) $get( 'Average Heart Rate' ),
				'nop_indieweb_exercise_max_heart_rate'   => (int) (float) $get( 'Max Heart Rate' ),
				'nop_indieweb_exercise_calories'         => (int) (float) $get( 'Calories' ),
			];
			foreach ( $metrics as $k => $v ) {
				if ( $v ) {
					$meta[ $k ] = $v;
				}
			}

			$post_id = \NOP\IndieWeb\nop_indieweb_save_exercise_post( [
				'name'       => $name,
				'type'       => $type,
				'gmt'        => $gmt,
				'content'    => $this->description_blocks( $get( 'Activity Description' ) ),
				'status'     => $status,
				'start'      => $start,
				'points'     => $parsed['points'],
				'gpx'        => (string) $raw,
				'meta'       => $meta,
				'source_id'  => $strava_id,
				'source_url' => $strava_id ? "https://www.strava.com/activities/{$strava_id}" : '',
				'service'    => 'strava',
			], $api_key );

			if ( is_wp_error( $post_id ) ) {
				WP_CLI::warning( "insert failed for {$strava_id}: " . $post_id->get_error_message() );
				continue;
			}

			$this->attach_photos( $post_id, $get( 'Media' ), $dir );

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

	private function description_blocks( string $desc ): string {
		$desc = trim( $desc );
		if ( '' === $desc ) {
			return '';
		}
		$out = '';
		foreach ( preg_split( '/\R+/', $desc ) as $para ) {
			$para = trim( $para );
			if ( '' !== $para ) {
				$out .= "<!-- wp:paragraph -->\n<p>" . esc_html( $para ) . "</p>\n<!-- /wp:paragraph -->\n\n";
			}
		}
		return $out;
	}

	private function attach_photos( int $post_id, string $media, string $dir ): void {
		$media = trim( $media );
		if ( '' === $media ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$ids = [];
		foreach ( preg_split( '/[,;|]/', $media ) as $rel ) {
			$rel = trim( $rel );
			$src = $dir . '/' . $rel;
			if ( '' === $rel || ! file_exists( $src ) ) {
				continue;
			}
			$upload = wp_upload_bits( basename( $src ), null, file_get_contents( $src ) );
			if ( ! empty( $upload['error'] ) ) {
				continue;
			}
			$attach_id = wp_insert_attachment( [
				'post_mime_type' => wp_check_filetype( $upload['file'] )['type'] ?: 'image/jpeg',
				'post_title'     => get_the_title( $post_id ),
				'post_status'    => 'inherit',
				'post_parent'    => $post_id,
			], $upload['file'], $post_id );
			if ( ! $attach_id || is_wp_error( $attach_id ) ) {
				continue;
			}
			wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $upload['file'] ) );
			$ids[] = $attach_id;
		}
		if ( ! $ids ) {
			return;
		}

		set_post_thumbnail( $post_id, $ids[0] );

		$blocks = '';
		foreach ( $ids as $aid ) {
			$blocks .= sprintf(
				"\n<!-- wp:image {\"id\":%d,\"sizeSlug\":\"large\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"%s\" alt=\"\" class=\"wp-image-%d\"/></figure>\n<!-- /wp:image -->\n",
				$aid,
				esc_url( (string) wp_get_attachment_image_url( $aid, 'large' ) ),
				$aid
			);
		}
		$post = get_post( $post_id );
		wp_update_post( [ 'ID' => $post_id, 'post_content' => $post->post_content . $blocks ] );
	}

}
