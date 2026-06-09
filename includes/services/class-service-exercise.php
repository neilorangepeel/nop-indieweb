<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Services;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Micropub payloads for exercise / workout posts.
 *
 * Designed for an iOS Shortcut that reads the latest HealthKit workout and
 * POSTs a Micropub h-entry with exercise-specific properties. Payload shape:
 *
 *   properties.exercise-type[0]          → run | ride | swim | walk | hike | strength | yoga | workout | …
 *   properties.exercise-distance[0]      → distance in metres (integer string)
 *   properties.exercise-duration[0]      → elapsed time in seconds (integer string)
 *   properties.exercise-moving-time[0]   → moving time in seconds (integer string, optional)
 *   properties.exercise-elevation-gain[0] → gain in metres (float string, optional)
 *   properties.exercise-elevation-loss[0] → loss in metres (float string, optional)
 *   properties.exercise-heart-rate-avg[0] → average bpm (integer string, optional)
 *   properties.exercise-heart-rate-max[0] → max bpm (integer string, optional)
 *   properties.exercise-calories[0]      → active kcal (integer string, optional)
 *   properties.exercise-id[0]            → platform-native activity ID (dedup key, optional)
 *   properties.location[0]               → h-geo with latitude / longitude (start coords)
 *   properties.name[0]                   → optional place-label (e.g. "Run in Cregagh Glen")
 *   properties.content[0]                → workout notes (optional)
 *   properties.published[0]              → ISO 8601 workout datetime
 *   properties.syndication[0]            → permalink on source platform (optional)
 */
class Exercise extends Service_Base {

	public function get_name(): string {
		return 'Exercise';
	}

	public function get_slug(): string {
		return 'exercise';
	}

	public function can_handle( array $payload ): bool {
		if ( ! in_array( 'h-entry', $payload['type'] ?? [], true ) ) {
			return false;
		}
		$props = $payload['properties'] ?? [];
		return isset( $props['exercise-type'] ) || isset( $props['exercise-duration'] );
	}

	protected function get_dedup_key( array $parsed ): ?string {
		return $parsed['source_id'] ?: null;
	}

	protected function get_dedup_meta_key(): string {
		return 'nop_indieweb_exercise_source_id';
	}

	public function parse( array $payload ): array {
		$props = $payload['properties'] ?? [];

		// Location: h-geo nested under location[0].properties.
		$geo_props = [];
		$location  = $props['location'][0] ?? null;
		if ( is_array( $location ) && in_array( 'h-geo', $location['type'] ?? [], true ) ) {
			$geo_props = $location['properties'] ?? [];
		}

		// Content can arrive as a plain string or {'html':'…','value':'…'}.
		$content_raw = $props['content'][0] ?? '';
		$content     = is_array( $content_raw )
			? ( $content_raw['value'] ?? '' )
			: (string) $content_raw;

		return [
			'name'             => sanitize_text_field( $props['name'][0] ?? '' ),
			'content'          => sanitize_textarea_field( $content ),
			'published'        => sanitize_text_field( $props['published'][0] ?? '' ),
			'exercise_type'    => sanitize_key( $props['exercise-type'][0] ?? '' ),
			'distance_m'       => (float) ( $props['exercise-distance'][0] ?? 0 ),
			'duration_s'       => (int) ( $props['exercise-duration'][0] ?? 0 ),
			'moving_time_s'    => (int) ( $props['exercise-moving-time'][0] ?? 0 ),
			'elevation_gain_m' => (float) ( $props['exercise-elevation-gain'][0] ?? 0 ),
			'elevation_loss_m' => (float) ( $props['exercise-elevation-loss'][0] ?? 0 ),
			'avg_heart_rate'   => (int) ( $props['exercise-heart-rate-avg'][0] ?? 0 ),
			'max_heart_rate'   => (int) ( $props['exercise-heart-rate-max'][0] ?? 0 ),
			'calories'         => (int) ( $props['exercise-calories'][0] ?? 0 ),
			'start_lat'        => sanitize_text_field( $geo_props['latitude'][0] ?? '' ),
			'start_lng'        => sanitize_text_field( $geo_props['longitude'][0] ?? '' ),
			'source_id'        => sanitize_text_field( $props['exercise-id'][0] ?? '' ),
			'source_url'       => esc_url_raw( $props['syndication'][0] ?? '' ),
			'raw_payload'      => $payload,
		];
	}

	public function map_to_post( array $parsed ): array {
		[ $post_date, $post_date_gmt ] = $this->parse_post_date( $parsed['published'], true );

		$title = \NOP\IndieWeb\nop_indieweb_exercise_title(
			$parsed['name'],
			$parsed['exercise_type'],
			(float) $parsed['start_lat'],
			(float) $parsed['start_lng']
		);

		$note   = wp_kses_post( trim( $parsed['content'] ) );
		$blocks = $note
			? "<!-- wp:paragraph -->\n<p>{$note}</p>\n<!-- /wp:paragraph -->"
			: '';

		$args = [
			'post_title'   => $title,
			'post_content' => $blocks,
			'post_status'  => 'publish',
			'post_type'    => 'post',
		];

		if ( $post_date ) {
			$args['post_date']     = $post_date;
			$args['post_date_gmt'] = $post_date_gmt;
		}

		return $args;
	}

	public function get_kind( array $parsed = [] ): string {
		return 'exercise';
	}

	public function get_meta( array $parsed ): array {
		$meta = [
			'nop_indieweb_service'                  => 'exercise',
			'nop_indieweb_exercise_type'            => $parsed['exercise_type'],
			'nop_indieweb_exercise_start_lat'       => $parsed['start_lat'],
			'nop_indieweb_exercise_start_lng'       => $parsed['start_lng'],
			'nop_indieweb_exercise_source_id'       => $parsed['source_id'],
			'nop_indieweb_exercise_source_url'      => $parsed['source_url'],
			'nop_indieweb_raw_payload'              => wp_json_encode( $parsed['raw_payload'] ),
		];

		if ( $parsed['distance_m'] ) {
			$meta['nop_indieweb_exercise_distance_m'] = $parsed['distance_m'];
		}
		if ( $parsed['duration_s'] ) {
			$meta['nop_indieweb_exercise_duration_s'] = $parsed['duration_s'];
		}
		if ( $parsed['moving_time_s'] ) {
			$meta['nop_indieweb_exercise_moving_time_s'] = $parsed['moving_time_s'];
		}
		if ( $parsed['elevation_gain_m'] ) {
			$meta['nop_indieweb_exercise_elevation_gain_m'] = $parsed['elevation_gain_m'];
		}
		if ( $parsed['elevation_loss_m'] ) {
			$meta['nop_indieweb_exercise_elevation_loss_m'] = $parsed['elevation_loss_m'];
		}
		if ( $parsed['avg_heart_rate'] ) {
			$meta['nop_indieweb_exercise_avg_heart_rate'] = $parsed['avg_heart_rate'];
		}
		if ( $parsed['max_heart_rate'] ) {
			$meta['nop_indieweb_exercise_max_heart_rate'] = $parsed['max_heart_rate'];
		}
		if ( $parsed['calories'] ) {
			$meta['nop_indieweb_exercise_calories'] = $parsed['calories'];
		}

		return $meta;
	}

	protected function after_insert( int $post_id, array $parsed ): void {
		$lat = (float) $parsed['start_lat'];
		$lng = (float) $parsed['start_lng'];

		if ( ! $lat && ! $lng ) {
			return;
		}

		// Weather at workout time and place.
		\NOP\IndieWeb\Weather\Weather_Fetcher::enrich_post(
			$post_id,
			$lat,
			$lng,
			(int) get_post_timestamp( $post_id, 'date_gmt' )
		);

		// Static map image for the workout start location.
		$geoapify_key = trim( (string) \NOP\IndieWeb\nop_indieweb_get_option( 'maps.geoapify_api_key', '' ) );
		if ( $geoapify_key ) {
			\NOP\IndieWeb\nop_indieweb_get_or_cache_exercise_map_image( $post_id, $lat, $lng, 620, 310, $geoapify_key );
		}
	}

}
