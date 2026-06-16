<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Weather;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enriches a post with weather data for a known location + time.
 *
 * Designed for "events with location + time" — checkins now, workouts later.
 * The caller hands over lat/lng + a Unix timestamp; the fetcher resolves the
 * weather from Pirate Weather's time-machine endpoint and stamps the result
 * onto post meta. Provider-pluggable in the future; single provider for now.
 *
 * Failure mode: every error path returns false silently after logging. A
 * weather lookup must never block the calling service from finishing its
 * own insert work.
 */
class Weather_Fetcher {

	// Time-machine subdomain handles past, present, and recent-future
	// timestamps — one path covers both live Swarm webhooks and historical
	// backfill. The api.pirateweather.net/forecast endpoint refuses past
	// timestamps with a 400 "Please Use Timemachine".
	private const ENDPOINT  = 'https://timemachine.pirateweather.net/forecast';
	private const TIMEOUT   = 6;
	private const MAX_BYTES = 256 * 1024;

	public static function enrich_post( int $post_id, float $lat, float $lng, int $timestamp ): bool {
		if ( $post_id <= 0 || $timestamp <= 0 ) {
			return false;
		}

		$data = self::fetch_at( $lat, $lng, $timestamp );
		if ( ! $data ) {
			return false;
		}

		if ( null !== $data['temp_c'] ) {
			$temp_f = ( $data['temp_c'] * 9 / 5 ) + 32;
			update_post_meta( $post_id, 'nop_indieweb_weather_temp_c', (string) round( $data['temp_c'], 1 ) );
			update_post_meta( $post_id, 'nop_indieweb_weather_temp_f', (string) round( $temp_f, 1 ) );
		}
		if ( '' !== $data['icon'] ) {
			update_post_meta( $post_id, 'nop_indieweb_weather_icon', $data['icon'] );
		}
		if ( '' !== $data['summary'] ) {
			update_post_meta( $post_id, 'nop_indieweb_weather_summary', $data['summary'] );
		}
		update_post_meta( $post_id, 'nop_indieweb_weather_provider',  'pirate-weather' );
		update_post_meta( $post_id, 'nop_indieweb_weather_fetched_at', gmdate( 'c' ) );

		return true;
	}

	/**
	 * Current-conditions weather for "right now" at a coordinate, with a short
	 * transient cache. Powers the /post masthead's at-a-glance data grid (the
	 * nop-indieweb/v1/now route is the only caller). Returns the same shape as
	 * fetch_at() — ['temp_c'=>?float, 'icon'=>string, 'summary'=>string] — or []
	 * when unavailable (no key, network error, nothing usable).
	 */
	public static function fetch_current( float $lat, float $lng ): array {
		if ( 0.0 === $lat && 0.0 === $lng ) {
			return [];
		}

		// Round to 3 d.p. (~110 m) so nearby readings share one entry — weather
		// doesn't vary at street scale, and this keeps Pirate Weather calls rare.
		$cache_key = 'nop_weather_now_' . md5( round( $lat, 3 ) . ',' . round( $lng, 3 ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$data = self::fetch_at( $lat, $lng, time() );

		// Only cache SUCCESSES. Caching empty results "briefly" used to pin a bad
		// state — when the API key was wrong, each /now call refreshed the 5-min
		// empty transient instead of retrying, hiding the failure for hours. Worth
		// the handful of extra retries per day: a Pirate Weather free key is 10k
		// requests/day and a single /post user can't approach that.
		if ( $data ) {
			set_transient( $cache_key, $data, 30 * MINUTE_IN_SECONDS );
		}

		return $data;
	}

	/**
	 * Fetches and parses Pirate Weather's `currently` block for a coordinate +
	 * timestamp. Returns ['temp_c'=>?float, 'icon'=>string, 'summary'=>string],
	 * or [] on any failure / when nothing usable came back. Never touches post
	 * meta — the caller decides (enrich_post stamps meta; fetch_current returns
	 * it to the client).
	 */
	private static function fetch_at( float $lat, float $lng, int $timestamp ): array {
		$api_key = (string) \NOP\IndieWeb\nop_indieweb_get_option( 'weather.pirate_weather_api_key', '' );
		if ( '' === $api_key ) {
			return [];
		}

		$url = sprintf(
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- small, bounded exclusion set
			// Keep `daily` — it carries sunrise/sunset (the /post ticker's golden hour).
			'%s/%s/%s,%s,%d?units=si&exclude=minutely,hourly,alerts',
			self::ENDPOINT,
			rawurlencode( $api_key ),
			rawurlencode( (string) $lat ),
			rawurlencode( (string) $lng ),
			$timestamp
		);

		$response = \NOP\IndieWeb\nop_indieweb_strict_remote_get( $url, [
			'timeout'             => self::TIMEOUT,
			'limit_response_size' => self::MAX_BYTES,
		] );

		if ( is_wp_error( $response ) ) {
			\NOP\IndieWeb\nop_indieweb_log( 'weather: fetch failed', [ 'error' => $response->get_error_message() ] );
			return [];
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			\NOP\IndieWeb\nop_indieweb_log( 'weather: non-200 response', [ 'code' => wp_remote_retrieve_response_code( $response ) ] );
			return [];
		}

		$body    = (array) json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$current = is_array( $body['currently'] ?? null ) ? $body['currently'] : null;
		if ( ! $current ) {
			return [];
		}

		// Pirate Weather returns temperature in Celsius when units=si.
		$temp_c  = isset( $current['temperature'] ) ? (float) $current['temperature'] : null;
		$icon    = isset( $current['icon'] )        ? sanitize_key( (string) $current['icon'] )        : '';
		$summary = isset( $current['summary'] )     ? sanitize_text_field( (string) $current['summary'] ) : '';

		if ( null === $temp_c && '' === $icon && '' === $summary ) {
			return [];
		}

		// Sun times (Unix) from the day's `daily` entry — the /post ticker derives the
		// golden hour from sunset. Absent on the time-machine endpoint for some dates;
		// callers that don't need them (enrich_post) simply ignore the extra keys.
		$day     = is_array( $body['daily']['data'][0] ?? null ) ? $body['daily']['data'][0] : [];
		$sunrise = isset( $day['sunriseTime'] ) ? (int) $day['sunriseTime'] : null;
		$sunset  = isset( $day['sunsetTime'] )  ? (int) $day['sunsetTime']  : null;
		// Moon phase (0..1: 0 new, .25 first quarter, .5 full, .75 last quarter) —
		// the /post ticker names it at night. Absent on some time-machine dates.
		$moon    = isset( $day['moonPhase'] ) ? (float) $day['moonPhase'] : null;

		return [ 'temp_c' => $temp_c, 'icon' => $icon, 'summary' => $summary, 'sunrise' => $sunrise, 'sunset' => $sunset, 'moonphase' => $moon ];
	}
}
