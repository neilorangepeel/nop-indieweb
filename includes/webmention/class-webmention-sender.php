<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Webmention;

/**
 * Sends outbound webmentions when a post is published or updated.
 *
 * Runs asynchronously via WP-Cron so it never blocks the publish request.
 * Skips imported social posts (they have nop_indieweb_source_url set).
 * Tracks sent targets in nop_indieweb_webmentions_sent post meta so
 * re-publishing or cron retries don't re-notify the same endpoints.
 */
class Webmention_Sender {

	public function register(): void {
		add_action( 'transition_post_status', [ $this, 'maybe_schedule' ], 10, 3 );
		add_action( 'nop_indieweb_send_webmentions', [ $this, 'send_for_post' ] );
	}

	public function maybe_schedule( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' !== $new_status ) {
			return;
		}
		if ( 'post' !== $post->post_type ) {
			return;
		}
		// Imported social posts — never send outbound webmentions for these.
		if ( get_post_meta( $post->ID, 'nop_indieweb_source_url', true ) ) {
			return;
		}
		wp_schedule_single_event( time(), 'nop_indieweb_send_webmentions', [ $post->ID ] );
	}

	public function send_for_post( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		$source = get_permalink( $post_id );
		$home   = home_url();
		$urls   = $this->extract_links( $post->post_content );
		$meta = get_post_meta( $post_id, 'nop_indieweb_webmentions_sent', true );
		$sent = is_array( $meta ) ? $meta : [];

		foreach ( $urls as $target ) {
			if ( in_array( $target, $sent, true ) ) {
				continue;
			}
			// Don't ping our own site.
			if ( str_starts_with( $target, $home ) ) {
				continue;
			}

			$endpoint = $this->discover_endpoint( $target );
			if ( ! $endpoint ) {
				continue;
			}

			$code = $this->send( $source, $target, $endpoint );
			if ( $code >= 200 && $code < 300 ) {
				$sent[] = $target;
			}
		}

		update_post_meta( $post_id, 'nop_indieweb_webmentions_sent', array_unique( $sent ) );
	}

	private function extract_links( string $content ): array {
		preg_match_all( '/href=["\']([^"\']+)["\']/', $content, $matches );
		$urls = array_unique( $matches[1] ?? [] );
		return array_values( array_filter( $urls, static fn( string $u ) =>
			str_starts_with( $u, 'http' ) && false !== filter_var( $u, FILTER_VALIDATE_URL )
		) );
	}

	/**
	 * Discovers the webmention endpoint for a target URL.
	 * Sends HEAD first (faster, no body transfer); falls back to GET
	 * to parse HTML <link rel="webmention"> or <a rel="webmention">.
	 */
	private function discover_endpoint( string $target ): ?string {
		$response = wp_remote_head( $target, [ 'timeout' => 10, 'redirection' => 5 ] );

		if ( ! is_wp_error( $response ) ) {
			$endpoint = $this->endpoint_from_link_header(
				wp_remote_retrieve_header( $response, 'link' ),
				$target
			);
			if ( $endpoint ) {
				return $endpoint;
			}
		}

		$response = wp_remote_get( $target, [ 'timeout' => 10, 'redirection' => 5 ] );
		if ( is_wp_error( $response ) ) {
			return null;
		}

		$endpoint = $this->endpoint_from_link_header(
			wp_remote_retrieve_header( $response, 'link' ),
			$target
		);
		if ( $endpoint ) {
			return $endpoint;
		}

		return $this->endpoint_from_html( wp_remote_retrieve_body( $response ), $target );
	}

	/**
	 * Parses a Link header for a webmention rel.
	 * Handles comma-separated entries, space-separated rel values,
	 * and both quoted (rel="webmention") and unquoted (rel=webmention) forms.
	 * Example: <https://webmention.io/example.com/webmention>; rel="webmention"
	 * Example: </test/1/webmention>; rel=webmention
	 */
	private function endpoint_from_link_header( string $header, string $base ): ?string {
		if ( ! $header ) {
			return null;
		}
		foreach ( explode( ',', $header ) as $part ) {
			if ( ! preg_match( '/<([^>]+)>/', $part, $url_m ) ) {
				continue;
			}
			// Match both rel="value" and rel=value (HTTP spec allows either).
			if ( ! preg_match( '/\brel=["\']?([^"\'\s,;>]+)["\']?/', $part, $rel_m ) ) {
				continue;
			}
			if ( in_array( 'webmention', preg_split( '/\s+/', $rel_m[1] ), true ) ) {
				return $this->resolve_url( $url_m[1], $base );
			}
		}
		return null;
	}

	private function endpoint_from_html( string $html, string $base ): ?string {
		if ( ! preg_match(
			'/<(?:link|a)\b[^>]+\brel=["\'][^"\']*\bwebmention\b[^"\']*["\'][^>]*>/i',
			$html,
			$m
		) ) {
			return null;
		}
		if ( ! preg_match( '/href=["\']([^"\']+)["\']/', $m[0], $href ) ) {
			return null;
		}
		return $this->resolve_url( $href[1], $base );
	}

	private function resolve_url( string $url, string $base ): string {
		$url = trim( $url );
		if ( str_starts_with( $url, 'http' ) ) {
			return $url;
		}
		$parts  = wp_parse_url( $base );
		$scheme = $parts['scheme'] ?? 'https';
		$host   = $parts['host'] ?? '';
		if ( str_starts_with( $url, '//' ) ) {
			return "{$scheme}:{$url}";
		}
		if ( str_starts_with( $url, '/' ) ) {
			return "{$scheme}://{$host}{$url}";
		}
		$path = rtrim( dirname( $parts['path'] ?? '/' ), '/' );
		return "{$scheme}://{$host}{$path}/{$url}";
	}

	private function send( string $source, string $target, string $endpoint ): int {
		$response = wp_remote_post( $endpoint, [
			'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
			'body'    => http_build_query( [ 'source' => $source, 'target' => $target ] ),
			'timeout' => 15,
		] );
		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		\NOP\IndieWeb\nop_indieweb_log( "Webmention → {$endpoint}", compact( 'source', 'target', 'code' ) );
		return $code;
	}
}
