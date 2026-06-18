<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Webmention;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Webmention receiver — accepts, verifies, and stores inbound webmentions.
 *
 * Spec: https://www.w3.org/TR/webmention/
 *
 * Flow:
 *   1. Validate source and target params.
 *   2. Confirm target lives on this site and resolves to a post.
 *   3. Fetch source. If 410 Gone and we already hold a webmention from that
 *      source, delete it (supports withdrawals from Bridgy and others).
 *   4. Verify source links to target (required by spec).
 *   5. Deduplicate — one webmention per source/post pair.
 *   6. Parse mf2 from source to extract type, author, and content.
 *   7. Store as a WordPress comment with type "webmention".
 *
 * Storage schema (comment meta):
 *   webmention_source       — source URL (Bridgy URL or direct sender)
 *   webmention_target       — target URL (our post permalink)
 *   webmention_type         — like | repost | reply | bookmark | mention
 *   webmention_platform     — mastodon | bluesky | unknown
 *   webmention_author_photo — avatar URL from the source mf2
 *   webmention_original_url — canonical post URL on the originating platform
 *
 * Approval strategy is configurable via Settings → IndieWeb → Webmentions:
 * bridgy_only (default), auto_all, or manual_all.
 */
class Webmention_Endpoint {

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );
		add_action( 'nop_indieweb_process_webmention', [ $this, 'process_queued' ] );
	}

	public function register_route(): void {
		register_rest_route(
			'nop-indieweb/v1',
			'/webmention',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'source' => [
						'required'          => true,
						'type'              => 'string',
						'format'            => 'uri',
						'sanitize_callback' => 'esc_url_raw',
					],
					'target' => [
						'required'          => true,
						'type'              => 'string',
						'format'            => 'uri',
						'sanitize_callback' => 'esc_url_raw',
					],
				],
			]
		);
	}

	/** Maximum source-page body we'll buffer + parse. 2 MB is plenty for HTML mf2. */
	private const MAX_SOURCE_BYTES = 2 * 1024 * 1024;

	public function handle( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$receive_enabled = \NOP\IndieWeb\nop_indieweb_get_option( 'webmentions', [] )['receive_enabled'] ?? true;
		if ( ! $receive_enabled ) {
			return new \WP_Error( 'webmentions_disabled', 'Webmentions are not accepted.', [ 'status' => 403 ] );
		}

		// Cheap per-IP throttle. Prevents a misbehaving sender (or attacker)
		// from forcing the worker pool to repeatedly fetch arbitrary URLs.
		if ( ! $this->throttle_ok() ) {
			return new \WP_Error( 'rate_limited', 'Too many webmentions from this IP — try again shortly.', [ 'status' => 429 ] );
		}

		$source = $request->get_param( 'source' );
		$target = $request->get_param( 'target' );

		if ( $source === $target ) {
			return new \WP_Error( 'invalid_webmention', 'Source and target must differ.', [ 'status' => 400 ] );
		}

		// Reject anything that isn't an http(s) URL — wp_safe_remote_get also
		// rejects file://, gopher://, etc., but failing early is cleaner.
		foreach ( [ 'source' => $source, 'target' => $target ] as $name => $url ) {
			$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
			if ( 'https' !== $scheme && 'http' !== $scheme ) {
				return new \WP_Error( 'invalid_url', "{$name} must be an http(s) URL.", [ 'status' => 400 ] );
			}
		}

		if ( ! $this->is_local_url( $target ) ) {
			return new \WP_Error( 'invalid_target', 'Target must be a URL on this site.', [ 'status' => 400 ] );
		}

		$post_id = url_to_postid( $target );
		if ( ! $post_id ) {
			return new \WP_Error( 'target_not_found', 'Target URL does not resolve to a post.', [ 'status' => 400 ] );
		}

		// Queue the heavy work (source fetch, verification, mf2 parsing, storage)
		// as a background cron job and return 202 Accepted immediately.
		//
		// The spec allows asynchronous processing; doing it synchronously ties up a
		// PHP worker for up to 15 s per webmention and becomes untenable under burst
		// traffic (e.g. Bridgy relay after a post goes viral).
		//
		// The transient key doubles as an idempotency lock: if the same source/target
		// pair arrives again before the job runs, we return 202 without re-queuing.
		$job_key = 'nop_wm_pending_' . md5( $source . $target );
		if ( ! get_transient( $job_key ) ) {
			set_transient( $job_key, [ 'source' => $source, 'target' => $target, 'post_id' => $post_id ], HOUR_IN_SECONDS );
			wp_schedule_single_event( time(), 'nop_indieweb_process_webmention', [ $job_key ] );
		}

		return new \WP_REST_Response( null, 202 );
	}

	/**
	 * WP-Cron callback: fetch, verify, parse, and store a queued webmention.
	 * Registered via Plugin::boot() → Webmention_Endpoint::register().
	 */
	public function process_queued( string $job_key ): void {
		$job = get_transient( $job_key );
		delete_transient( $job_key );

		if ( ! is_array( $job ) ) {
			return;
		}

		$source  = (string) ( $job['source']  ?? '' );
		$target  = (string) ( $job['target']  ?? '' );
		$post_id = (int)    ( $job['post_id'] ?? 0  );

		if ( ! $source || ! $target || ! $post_id ) {
			return;
		}

		// Fetch the source through the plugin's strict SSRF guard, which re-validates
		// EVERY redirect hop against private/reserved ranges — wp_safe_remote_get only
		// checks the first URL, so a 30x to 169.254/127.x would otherwise slip through.
		// limit_response_size caps the body so a sender cannot stream gigabytes at us.
		$response = \NOP\IndieWeb\nop_indieweb_strict_remote_get( $source, [
			'timeout'             => 15,
			'limit_response_size' => self::MAX_SOURCE_BYTES,
			'user-agent'          => 'NOP IndieWeb/' . NOP_INDIEWEB_VERSION . ' (webmention; +' . home_url( '/' ) . ')',
		], 3 );

		$http_code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );

		// Handle source deletion — spec requires us to delete the stored webmention.
		if ( 410 === $http_code ) {
			$existing_id = $this->find_existing( $source, $post_id );
			if ( $existing_id ) {
				wp_delete_comment( $existing_id, true );
			}
			return;
		}

		if ( is_wp_error( $response ) || $http_code < 200 || $http_code >= 300 ) {
			\NOP\IndieWeb\nop_indieweb_log( "Webmention: source fetch failed for {$source}", $http_code );
			return;
		}

		$body = wp_remote_retrieve_body( $response );

		if ( ! $this->source_links_to_target( $body, $target ) ) {
			\NOP\IndieWeb\nop_indieweb_log( "Webmention: source does not link to target", compact( 'source', 'target' ) );
			return;
		}

		$existing_id = $this->find_existing( $source, $post_id );

		$parser = new MF2_Parser();
		$parsed = $parser->parse( $body, $source );

		if ( $existing_id ) {
			$this->update_webmention( $existing_id, $parsed );
			return;
		}

		$this->insert_webmention( $post_id, $source, $target, $parsed );
	}

	// ── Validation ─────────────────────────────────────────────────────────────

	private function is_local_url( string $url ): bool {
		$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$target_host = wp_parse_url( $url, PHP_URL_HOST );
		return $site_host && $site_host === $target_host;
	}

	private function source_links_to_target( string $html, string $target ): bool {
		$target_norm = rtrim( $target, '/' );
		if ( ! str_contains( $html, $target_norm ) ) {
			return false;
		}
		$dom = new \DOMDocument();
		// LIBXML_NONET blocks any network fetch the parser might attempt; the @
		// suppresses parse warnings from malformed third-party HTML.
		@$dom->loadHTML( $html, LIBXML_NOERROR | LIBXML_NONET );
		$xpath = new \DOMXPath( $dom );
		foreach ( $xpath->query( '//a[@href]' ) as $link ) {
			if ( rtrim( $link->getAttribute( 'href' ), '/' ) === $target_norm ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Simple per-IP rate-limit using a short-lived transient counter.
	 *
	 * Allows 20 requests per IP per 5 minutes by default. Bridgy and similar
	 * relays send burst traffic, so the limit is generous; the goal is to
	 * stop a single attacker from running the endpoint into the ground.
	 */
	private function throttle_ok(): bool {
		$max    = (int) apply_filters( 'nop_indieweb_webmention_rate_limit', 20 );
		$window = (int) apply_filters( 'nop_indieweb_webmention_rate_window', 5 * MINUTE_IN_SECONDS );
		if ( $max <= 0 ) {
			return true;
		}

		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		if ( ! $ip ) {
			return true;
		}
		if ( str_starts_with( $ip, '::ffff:' ) ) {
			$ip = substr( $ip, 7 );
		}
		$key   = 'nop_wm_rl_' . hash( 'sha256', $ip . wp_salt( 'nonce' ) );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return false;
		}
		set_transient( $key, $count + 1, $window );
		return true;
	}

	// ── Storage ────────────────────────────────────────────────────────────────

	private function find_existing( string $source, int $post_id ): int {
		$comments = get_comments( [
			'post_id'    => $post_id,
			'type'       => 'webmention',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- low-frequency meta/taxonomy lookup (import, admin, or per-post render cache), not a hot path
			'meta_key'   => 'webmention_source',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- low-frequency meta/taxonomy lookup (import, admin, or per-post render cache), not a hot path
			'meta_value' => $source,
			'number'     => 1,
			'fields'     => 'ids',
		] );
		return isset( $comments[0] ) ? (int) $comments[0] : 0;
	}

	private function insert_webmention( int $post_id, string $source, string $target, array $parsed ): int|\WP_Error {
		$approval = \NOP\IndieWeb\nop_indieweb_get_option( 'webmentions', [] )['approval'] ?? 'bridgy_only';
		$approved = match( $approval ) {
			'auto_all'   => 1,
			'manual_all' => 0,
			default      => str_contains( $source, 'brid.gy' ) ? 1 : 0,
		};

		$comment_id = wp_insert_comment( wp_slash( [
			'comment_post_ID'      => $post_id,
			'comment_type'         => 'webmention',
			'comment_author'       => $parsed['author_name'] ?: 'Anonymous',
			'comment_author_url'   => $parsed['author_url'],
			'comment_author_email' => '',
			'comment_content'      => $parsed['content'],
			'comment_date'         => $this->parse_date_local( $parsed['published'] ),
			'comment_date_gmt'     => $this->parse_date_gmt( $parsed['published'] ),
			'comment_approved'     => $approved,
		] ) );

		if ( ! $comment_id || is_wp_error( $comment_id ) ) {
			return $comment_id ?: new \WP_Error( 'insert_failed', 'wp_insert_comment returned falsy.' );
		}

		$this->save_meta( $comment_id, $source, $target, $parsed );

		return $comment_id;
	}

	private function update_webmention( int $comment_id, array $parsed ): void {
		wp_update_comment( wp_slash( [
			'comment_ID'      => $comment_id,
			'comment_author'  => $parsed['author_name'] ?: 'Anonymous',
			'comment_content' => $parsed['content'],
		] ) );

		update_comment_meta( $comment_id, 'webmention_type',         $parsed['type'] );
		update_comment_meta( $comment_id, 'webmention_author_photo', $parsed['author_photo'] );
		update_comment_meta( $comment_id, 'webmention_original_url', $parsed['original_url'] );
		update_comment_meta( $comment_id, 'webmention_platform',     $parsed['platform'] );
	}

	private function save_meta( int $comment_id, string $source, string $target, array $parsed ): void {
		add_comment_meta( $comment_id, 'webmention_source',       $source,                 true );
		add_comment_meta( $comment_id, 'webmention_target',       $target,                 true );
		add_comment_meta( $comment_id, 'webmention_type',         $parsed['type'],         true );
		add_comment_meta( $comment_id, 'webmention_platform',     $parsed['platform'],     true );
		add_comment_meta( $comment_id, 'webmention_author_photo', $parsed['author_photo'], true );
		add_comment_meta( $comment_id, 'webmention_original_url', $parsed['original_url'], true );
	}

	// ── Date helpers ───────────────────────────────────────────────────────────

	private function parse_date_local( string $published ): string {
		if ( $published ) {
			$ts = strtotime( $published );
			if ( $ts ) {
				return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ) );
			}
		}
		return current_time( 'mysql' );
	}

	private function parse_date_gmt( string $published ): string {
		if ( $published ) {
			$ts = strtotime( $published );
			if ( $ts ) {
				return gmdate( 'Y-m-d H:i:s', $ts );
			}
		}
		return current_time( 'mysql', true );
	}
}
