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

		$api_key = (string) \NOP\IndieWeb\nop_indieweb_get_option( 'weather.pirate_weather_api_key', '' );
		if ( '' === $api_key ) {
			return false;
		}

		$url = sprintf(
			'%s/%s/%s,%s,%d?units=si&exclude=minutely,hourly,daily,alerts',
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
			\NOP\IndieWeb\nop_indieweb_log( 'weather: fetch failed', [
				'post_id' => $post_id,
				'error'   => $response->get_error_message(),
			] );
			return false;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			\NOP\IndieWeb\nop_indieweb_log( 'weather: non-200 response', [
				'post_id' => $post_id,
				'code'    => wp_remote_retrieve_response_code( $response ),
			] );
			return false;
		}

		$body = (array) json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$current = is_array( $body['currently'] ?? null ) ? $body['currently'] : null;
		if ( ! $current ) {
			return false;
		}

		// Pirate Weather returns temperature in Celsius when units=si.
		$temp_c = isset( $current['temperature'] ) ? (float) $current['temperature'] : null;
		$icon   = isset( $current['icon'] )    ? sanitize_key( (string) $current['icon'] )       : '';
		$summary = isset( $current['summary'] ) ? sanitize_text_field( (string) $current['summary'] ) : '';

		if ( null === $temp_c && '' === $icon && '' === $summary ) {
			return false;
		}

		if ( null !== $temp_c ) {
			$temp_f = ( $temp_c * 9 / 5 ) + 32;
			update_post_meta( $post_id, 'nop_indieweb_weather_temp_c', (string) round( $temp_c, 1 ) );
			update_post_meta( $post_id, 'nop_indieweb_weather_temp_f', (string) round( $temp_f, 1 ) );
		}
		if ( '' !== $icon ) {
			update_post_meta( $post_id, 'nop_indieweb_weather_icon', $icon );
		}
		if ( '' !== $summary ) {
			update_post_meta( $post_id, 'nop_indieweb_weather_summary', $summary );
		}
		update_post_meta( $post_id, 'nop_indieweb_weather_provider',  'pirate-weather' );
		update_post_meta( $post_id, 'nop_indieweb_weather_fetched_at', gmdate( 'c' ) );

		return true;
	}
}
