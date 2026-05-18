<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Venue;

/**
 * Enriches check-ins with venue category data from the Foursquare Places API.
 *
 * OwnYourSwarm does not forward Foursquare's venue categories
 * (aaronpk/ownyourswarm#47 — long-standing wontfix). This class fills the
 * gap by calling Foursquare directly using the venue ID we already have
 * in nop_indieweb_venue_url, then returns category name strings the Swarm
 * service drops into the nop_venue_category taxonomy.
 *
 * Results are cached per-venue for 30 days. Most check-ins are to places
 * you visit repeatedly, so the live API is called only on first visit.
 *
 * Failure mode: always returns an empty array; never throws; never blocks
 * the Swarm ingest from completing.
 */
class Foursquare_Enricher {

	private const ENDPOINT       = 'https://places-api.foursquare.com/places/';
	private const API_VERSION    = '2025-06-17';
	private const TIMEOUT        = 6;
	private const MAX_BYTES      = 64 * 1024;
	private const CACHE_TTL      = 30 * DAY_IN_SECONDS;
	private const CACHE_PREFIX   = 'nop_fsq_categories_';
	// Foursquare returns categories ordered primary-first. Keeping the top
	// three avoids long-tail term sprawl while still covering mixed-use
	// venues (e.g. "Hotel / Bar / Restaurant"). Most venues return 1–2.
	private const MAX_CATEGORIES = 3;

	/**
	 * Returns category names for a Foursquare venue ID, in the order the
	 * Places API returned them (primary first).
	 */
	public static function fetch_categories( string $venue_id ): array {
		$venue_id = self::normalise_venue_id( $venue_id );
		if ( '' === $venue_id ) {
			return [];
		}

		$cache_key = self::CACHE_PREFIX . $venue_id;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$api_key = (string) \NOP\IndieWeb\nop_indieweb_get_option( 'venue.foursquare_api_key', '' );
		if ( '' === $api_key ) {
			return [];
		}

		$url      = self::ENDPOINT . rawurlencode( $venue_id ) . '?fields=categories';
		$response = \NOP\IndieWeb\nop_indieweb_strict_remote_get( $url, [
			'timeout'             => self::TIMEOUT,
			'limit_response_size' => self::MAX_BYTES,
			'headers'             => [
				'Authorization'        => 'Bearer ' . $api_key,
				'Accept'               => 'application/json',
				'X-Places-Api-Version' => self::API_VERSION,
			],
		] );

		if ( is_wp_error( $response ) ) {
			\NOP\IndieWeb\nop_indieweb_log( 'foursquare: fetch failed', [
				'venue_id' => $venue_id,
				'error'    => $response->get_error_message(),
			] );
			return [];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			\NOP\IndieWeb\nop_indieweb_log( 'foursquare: non-200 response', [
				'venue_id' => $venue_id,
				'code'     => $code,
			] );
			return [];
		}

		$body       = (array) json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$categories = is_array( $body['categories'] ?? null ) ? $body['categories'] : [];

		$names = [];
		foreach ( $categories as $cat ) {
			$name = (string) ( $cat['name'] ?? '' );
			if ( '' !== $name ) {
				$names[] = sanitize_text_field( $name );
			}
		}
		$names = array_slice( $names, 0, self::MAX_CATEGORIES );

		// Cache empty results too — venues with no categories shouldn't be
		// re-queried on every visit. The 30-day TTL covers the edge case
		// where Foursquare later adds categories.
		set_transient( $cache_key, $names, self::CACHE_TTL );

		return $names;
	}

	/**
	 * Search FSQ for a venue by name and optional coordinates.
	 *
	 * Returns ['fsq_id' => string, 'categories' => string[]] on success, or []
	 * when no match is found or the API key is not configured. Results are
	 * cached for 30 days — the same TTL as fetch_categories() — so repeated
	 * import runs don't hammer the API for the same venue names.
	 *
	 * When lat/lng are supplied the search uses them as a proximity hint so
	 * "Crown Bar" resolves to the Belfast pub rather than a different city's bar.
	 * Without coordinates FSQ still finds well-known named places reliably.
	 */
	public static function search_venue( string $name, string $lat = '', string $lng = '' ): array {
		$name = trim( $name );
		if ( '' === $name ) {
			return [];
		}

		$api_key = (string) \NOP\IndieWeb\nop_indieweb_get_option( 'venue.foursquare_api_key', '' );
		if ( '' === $api_key ) {
			return [];
		}

		// Round coords to 2 d.p. so nearby GPS variants share the same cache entry.
		$coord_suffix = ( $lat && $lng )
			? '|' . round( (float) $lat, 2 ) . ',' . round( (float) $lng, 2 )
			: '';
		$cache_key = 'nop_fsq_search_' . md5( strtolower( $name ) . $coord_suffix );

		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// The search endpoint does not support the fields selector — all fields
		// are returned by default. lat/lng live at the top level (not in geocodes).
		$params = [ 'query' => $name, 'limit' => 1 ];
		if ( $lat && $lng ) {
			$params['ll'] = "{$lat},{$lng}";
		}

		$url      = add_query_arg( $params, self::ENDPOINT . 'search' );
		$response = \NOP\IndieWeb\nop_indieweb_strict_remote_get( $url, [
			'timeout'             => self::TIMEOUT,
			'limit_response_size' => self::MAX_BYTES,
			'headers'             => [
				'Authorization'        => 'Bearer ' . $api_key,
				'Accept'               => 'application/json',
				'X-Places-Api-Version' => self::API_VERSION,
			],
		] );

		if ( is_wp_error( $response ) ) {
			\NOP\IndieWeb\nop_indieweb_log( 'foursquare: search failed', [
				'venue'  => $name,
				'error'  => $response->get_error_message(),
			] );
			return [];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			\NOP\IndieWeb\nop_indieweb_log( 'foursquare: search non-200', [
				'venue' => $name,
				'code'  => $code,
			] );
			return [];
		}

		$body    = (array) json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$results = is_array( $body['results'] ?? null ) ? $body['results'] : [];
		$first   = $results[0] ?? null;

		if ( ! $first ) {
			set_transient( $cache_key, [], self::CACHE_TTL );
			return [];
		}

		// Search uses fsq_place_id; lat/lng are top-level (not nested in geocodes).
		$fsq_id    = (string) ( $first['fsq_place_id'] ?? '' );
		$raw_cats  = is_array( $first['categories'] ?? null ) ? $first['categories'] : [];
		$cat_names = [];
		foreach ( $raw_cats as $cat ) {
			$cat_name = (string) ( $cat['name'] ?? '' );
			if ( '' !== $cat_name ) {
				$cat_names[] = sanitize_text_field( $cat_name );
			}
		}
		$cat_names = array_slice( $cat_names, 0, self::MAX_CATEGORIES );

		$result = [
			'fsq_id'     => $fsq_id,
			'categories' => $cat_names,
			'lat'        => isset( $first['latitude'] )  ? (string) $first['latitude']  : '',
			'lng'        => isset( $first['longitude'] ) ? (string) $first['longitude'] : '',
		];
		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Extracts the venue ID from a Foursquare venue URL like
	 * https://foursquare.com/v/1621468202284afcdf60c9bc, or passes through
	 * a bare ID. Returns '' for anything that isn't a foursquare.com URL or
	 * a plausible bare ID.
	 */
	public static function extract_venue_id( string $url_or_id ): string {
		$url_or_id = trim( $url_or_id );
		if ( '' === $url_or_id ) {
			return '';
		}

		// Bare ID — 24 hex chars (v2 format) or v3 fsq_id format.
		if ( self::looks_like_venue_id( $url_or_id ) ) {
			return $url_or_id;
		}

		$host = strtolower( (string) wp_parse_url( $url_or_id, PHP_URL_HOST ) );
		if ( 'foursquare.com' !== $host && 'www.foursquare.com' !== $host ) {
			return '';
		}

		$path  = (string) wp_parse_url( $url_or_id, PHP_URL_PATH );
		$parts = $path ? array_values( array_filter( explode( '/', $path ) ) ) : [];
		$last  = $parts ? (string) end( $parts ) : '';

		return self::normalise_venue_id( $last );
	}

	private static function normalise_venue_id( string $id ): string {
		$id = trim( $id );
		return self::looks_like_venue_id( $id ) ? $id : '';
	}

	private static function looks_like_venue_id( string $id ): bool {
		return (bool) preg_match( '~^[A-Za-z0-9_-]{8,}$~', $id );
	}
}
