<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Webmention;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

		// When a visitor replies to a webmention via the comment form, ping the
		// original source URL so the author is notified on their platform.
		add_action( 'comment_post', [ $this, 'maybe_ping_webmention_source' ], 10, 2 );
		add_action( 'transition_comment_status', [ $this, 'maybe_ping_on_approval' ], 10, 3 );
		add_action( 'nop_indieweb_send_reply_webmention', [ $this, 'send_reply_webmention' ], 10, 2 );
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

		// Also notify URLs referenced in post-kind meta — these may not appear in
		// post_content (e.g. a like-of target is stored only in meta, not in the body).
		foreach ( [ 'nop_indieweb_in_reply_to', 'nop_indieweb_bookmark_of', 'nop_indieweb_like_of', 'nop_indieweb_repost_of', 'nop_indieweb_quote_of' ] as $meta_key ) {
			$meta_url = (string) get_post_meta( $post_id, $meta_key, true );
			if ( $meta_url ) {
				$urls[] = $meta_url;
			}
		}
		$urls = array_unique( $urls );

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
	 *
	 * GET always follows HEAD when no link header is found: HEAD cannot return
	 * a body, so the only way to check for an HTML <link> declaration is to
	 * fetch the full document. A successful HEAD with no link header is not a
	 * signal that no endpoint exists — it just means it isn't in the headers.
	 */
	private function discover_endpoint( string $target ): ?string {
		// Targets can originate from inbound (untrusted) webmention sources, so
		// route every fetch through the SSRF-hardened path: pre-reject private/
		// reserved IPs and use the safe HTTP variants. HEAD does not follow
		// redirects here (redirection => 0); a 3xx simply falls through to the
		// GET below, which re-validates each hop.
		if ( ! \NOP\IndieWeb\nop_indieweb_is_safe_url( $target ) ) {
			return null;
		}

		$response = wp_safe_remote_head( $target, [ 'timeout' => 10, 'redirection' => 0 ] );

		if ( ! is_wp_error( $response ) ) {
			$endpoint = $this->endpoint_from_link_header(
				wp_remote_retrieve_header( $response, 'link' ),
				$target
			);
			if ( $endpoint ) {
				return $endpoint;
			}
		}

		// Cap the HTML body we parse for <link rel="webmention">. 2 MB is
		// generous for any real article and stops a hostile target from
		// streaming gigabytes.
		$response = \NOP\IndieWeb\nop_indieweb_strict_remote_get( $target, [
			'timeout'             => 10,
			'limit_response_size' => 2 * 1024 * 1024,
		] );
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

	public function maybe_ping_webmention_source( int $comment_id, int|string $approved ): void {
		if ( 1 !== (int) $approved ) {
			return;
		}
		$this->ping_source_for_comment( $comment_id );
	}

	public function maybe_ping_on_approval( string $new_status, string $old_status, \WP_Comment $comment ): void {
		if ( 'approved' !== $new_status || 'approved' === $old_status ) {
			return;
		}
		$this->ping_source_for_comment( (int) $comment->comment_ID );
	}

	private function ping_source_for_comment( int $comment_id ): void {
		$comment = get_comment( $comment_id );
		if ( ! $comment || 'comment' !== $comment->comment_type ) {
			return;
		}
		$parent_id = (int) $comment->comment_parent;
		if ( ! $parent_id ) {
			return;
		}
		$parent = get_comment( $parent_id );
		if ( ! $parent || 'webmention' !== $parent->comment_type ) {
			return;
		}
		$source_url = (string) get_comment_meta( $parent_id, 'webmention_source', true );
		if ( ! $source_url ) {
			return;
		}
		wp_schedule_single_event( time(), 'nop_indieweb_send_reply_webmention', [ (int) $comment->comment_post_ID, $source_url ] );
	}

	public function send_reply_webmention( int $post_id, string $target_url ): void {
		$source   = get_permalink( $post_id );
		$endpoint = $this->discover_endpoint( $target_url );
		if ( $endpoint ) {
			$this->send( $source, $target_url, $endpoint );
		}
	}

	private function send( string $source, string $target, string $endpoint ): int {
		// The endpoint was discovered from the (untrusted) target's markup, so
		// it is attacker-influenceable too — validate it before POSTing and use
		// the safe variant without redirect-following.
		if ( ! \NOP\IndieWeb\nop_indieweb_is_safe_url( $endpoint ) ) {
			return 0;
		}
		$response = wp_safe_remote_post( $endpoint, [
			'headers'             => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
			'body'                => http_build_query( [ 'source' => $source, 'target' => $target ] ),
			'timeout'             => 15,
			'redirection'         => 0,
			'limit_response_size' => 1024 * 1024,
		] );
		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		\NOP\IndieWeb\nop_indieweb_log( "Webmention → {$endpoint}", compact( 'source', 'target', 'code' ) );
		return $code;
	}
}
