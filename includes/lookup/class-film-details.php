<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Lookup;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A watched film's details from TMDB: director, genres, runtime and country of
 * origin, stored on the post (nop_indieweb_film_director / _genres / _runtime /
 * _country) so templates bind to them like any other field.
 *
 * Fetched once, after a watch is saved without them (the film's TMDB ID when the
 * picker set one, else a search by title and year); never on render. The
 * backfill-film-details command fills older watches. Without a TMDB key
 * (Settings → Lookups) nothing happens.
 */
final class Film_Details {

	private const API_BASE = 'https://api.themoviedb.org/3';

	public function register(): void {
		add_action( 'wp_after_insert_post', [ $this, 'on_save' ], 20, 2 );
	}

	public function on_save( int $post_id, \WP_Post $post ): void {
		if ( 'publish' !== $post->post_status || wp_is_post_revision( $post_id ) || ! has_term( 'watch', 'nop_kind', $post_id ) ) {
			return;
		}
		if ( '' !== (string) get_post_meta( $post_id, 'nop_indieweb_film_director', true ) ) {
			return;
		}
		self::fill( $post_id );
	}

	/**
	 * Fetch and store a watch's details.
	 *
	 * @return string '' when stored, else why not.
	 */
	public static function fill( int $post_id ): string {
		$key = (string) \NOP\IndieWeb\nop_indieweb_get_option( 'lookups.tmdb_api_key', '' );
		if ( '' === $key ) {
			return 'no TMDB key';
		}
		$id = (string) get_post_meta( $post_id, 'nop_indieweb_film_tmdb_id', true );
		if ( '' === $id ) {
			$title = (string) get_post_meta( $post_id, 'nop_indieweb_film_title', true );
			if ( '' === $title ) {
				return 'no film title';
			}
			$found = self::get( '/search/movie', [ 'query' => $title, 'primary_release_year' => (string) get_post_meta( $post_id, 'nop_indieweb_film_year', true ) ], $key );
			$id    = isset( $found['results'][0]['id'] ) ? (string) $found['results'][0]['id'] : '';
			if ( '' === $id ) {
				return 'not found on TMDB';
			}
			update_post_meta( $post_id, 'nop_indieweb_film_tmdb_id', $id );
		}
		$film = self::get( '/movie/' . rawurlencode( $id ), [ 'append_to_response' => 'credits' ], $key );
		if ( ! $film ) {
			return 'TMDB request failed';
		}
		$directors = array_column( array_filter( (array) ( $film['credits']['crew'] ?? [] ), fn( $c ) => 'Director' === ( $c['job'] ?? '' ) ), 'name' );
		$details   = [
			'nop_indieweb_film_director' => implode( ', ', array_slice( $directors, 0, 2 ) ),
			'nop_indieweb_film_genres'   => implode( ', ', array_slice( array_column( (array) ( $film['genres'] ?? [] ), 'name' ), 0, 3 ) ),
			'nop_indieweb_film_runtime'  => (string) (int) ( $film['runtime'] ?? 0 ),
			'nop_indieweb_film_country'  => (string) ( $film['production_countries'][0]['name'] ?? '' ),
		];
		foreach ( $details as $meta_key => $value ) {
			update_post_meta( $post_id, $meta_key, sanitize_text_field( $value ) );
		}
		return '';
	}

	/** @return array<string,mixed> The decoded response, or [] on failure. */
	private static function get( string $path, array $args, string $key ): array {
		$response = wp_safe_remote_get( add_query_arg( array_merge( $args, [ 'api_key' => $key ] ), self::API_BASE . $path ), [ 'timeout' => 8, 'limit_response_size' => 1024 * 1024 ] );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) ? $body : [];
	}
}
