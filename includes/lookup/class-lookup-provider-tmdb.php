<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Lookup;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lookup_Provider_TMDB extends Lookup_Provider_Base {

	private const API_BASE    = 'https://api.themoviedb.org/3';
	private const THUMB_BASE  = 'https://image.tmdb.org/t/p/w154';
	private const POSTER_BASE = 'https://image.tmdb.org/t/p/w500';

	public function get_slug(): string  { return 'tmdb'; }
	public function get_label(): string { return 'TMDB'; }

	private function api_key(): string {
		return (string) \NOP\IndieWeb\nop_indieweb_get_option( 'lookups.tmdb_api_key', '' );
	}

	public function search( string $query ): array|\WP_Error {
		$key = $this->api_key();
		if ( ! $key ) {
			return new \WP_Error( 'no_api_key', __( 'TMDB API key not configured.', 'nop-indieweb' ) );
		}

		$response = wp_safe_remote_get(
			add_query_arg( [ 'query' => $query, 'api_key' => $key ], self::API_BASE . '/search/movie' ),
			[ 'timeout' => 8, 'limit_response_size' => 1024 * 1024 ]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['results'] ) ) {
			return new \WP_Error( 'bad_response', __( 'Unexpected response from TMDB.', 'nop-indieweb' ) );
		}

		$results = [];
		foreach ( array_slice( $body['results'], 0, 8 ) as $item ) {
			$year   = ! empty( $item['release_date'] ) ? substr( $item['release_date'], 0, 4 ) : null;
			$poster = ! empty( $item['poster_path'] ) ? self::POSTER_BASE . $item['poster_path'] : null;
			$thumb  = ! empty( $item['poster_path'] ) ? self::THUMB_BASE  . $item['poster_path'] : null;

			$results[] = [
				'id'        => (string) $item['id'],
				'title'     => (string) $item['title'],
				'year'      => $year,
				'thumb_url' => $thumb,
				'meta'      => [
					'nop_indieweb_film_title'   => (string) $item['title'],
					'nop_indieweb_film_year'    => (string) ( $year ?? '' ),
					'nop_indieweb_film_poster'  => (string) ( $poster ?? '' ),
					'nop_indieweb_film_tmdb_id' => (string) $item['id'],
				],
			];
		}

		return $results;
	}
}
