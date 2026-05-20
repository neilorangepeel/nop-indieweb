<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Venue;

/**
 * Reverse-geocodes a lat/lng pair via the Geoapify Geocoding API.
 *
 * Used as a fallback when Foursquare's Places API returns no address data
 * (common for corporate, industrial, or sparsely-listed venues). Returns the
 * same field shape as Foursquare_Enricher::fetch_venue_details() so callers
 * can treat both sources uniformly.
 *
 * Results cached per rounded coordinate pair for 30 days.
 * Failure mode: always returns []; never throws; never blocks the caller.
 */
class Geoapify_Geocoder {

	private const ENDPOINT  = 'https://api.geoapify.com/v1/geocode/reverse';
	private const TIMEOUT   = 6;
	private const MAX_BYTES = 32 * 1024;
	private const CACHE_TTL = 30 * DAY_IN_SECONDS;

	/**
	 * Returns address fields for a lat/lng pair.
	 *
	 * Keys returned: address, locality, region, country, postcode.
	 * All values are sanitized strings; empty string when not available.
	 */
	public static function reverse_geocode( float $lat, float $lng ): array {
		if ( 0.0 === $lat && 0.0 === $lng ) {
			return [];
		}

		$api_key = (string) \NOP\IndieWeb\nop_indieweb_get_option( 'maps.geoapify_api_key', '' );
		if ( '' === $api_key ) {
			return [];
		}

		// Round to 4 d.p. (~11 m precision) so nearby GPS readings share a cache entry.
		$cache_key = 'nop_geo_reverse_' . md5( round( $lat, 4 ) . ',' . round( $lng, 4 ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$url      = add_query_arg( [ 'lat' => $lat, 'lon' => $lng, 'lang' => 'en', 'apiKey' => $api_key ], self::ENDPOINT );
		$response = \NOP\IndieWeb\nop_indieweb_strict_remote_get( $url, [
			'timeout'             => self::TIMEOUT,
			'limit_response_size' => self::MAX_BYTES,
		] );

		if ( is_wp_error( $response ) ) {
			\NOP\IndieWeb\nop_indieweb_log( 'geoapify: reverse geocode failed', [
				'lat'   => $lat,
				'lng'   => $lng,
				'error' => $response->get_error_message(),
			] );
			return [];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			\NOP\IndieWeb\nop_indieweb_log( 'geoapify: reverse geocode non-200', [
				'lat'  => $lat,
				'lng'  => $lng,
				'code' => $code,
			] );
			return [];
		}

		$body     = (array) json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$features = is_array( $body['features'] ?? null ) ? $body['features'] : [];
		$props    = is_array( $features[0]['properties'] ?? null ) ? $features[0]['properties'] : [];

		if ( ! $props ) {
			set_transient( $cache_key, [], self::CACHE_TTL );
			return [];
		}

		// Geoapify uses city > town > village > county in descending specificity.
		$locality = (string) ( $props['city'] ?? $props['town'] ?? $props['village'] ?? $props['county'] ?? '' );

		$result = [
			'address'  => sanitize_text_field( (string) ( $props['street']       ?? '' ) ),
			'locality' => sanitize_text_field( $locality ),
			'region'   => sanitize_text_field( (string) ( $props['state']        ?? '' ) ),
			'country'  => sanitize_text_field( strtoupper( (string) ( $props['country_code'] ?? '' ) ) ),
			'postcode' => sanitize_text_field( (string) ( $props['postcode']     ?? '' ) ),
		];

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}
}
