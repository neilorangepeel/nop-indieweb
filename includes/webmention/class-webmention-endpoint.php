<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Webmention;

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
 * Webmentions from brid.gy are auto-approved; all others are held for
 * moderation (comment_approved = 0) so the site owner can review them first.
 */
class Webmention_Endpoint {

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );
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

	public function handle( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$source = $request->get_param( 'source' );
		$target = $request->get_param( 'target' );

		if ( $source === $target ) {
			return new \WP_Error( 'invalid_webmention', 'Source and target must differ.', [ 'status' => 400 ] );
		}

		if ( ! $this->is_local_url( $target ) ) {
			return new \WP_Error( 'invalid_target', 'Target must be a URL on this site.', [ 'status' => 400 ] );
		}

		$post_id = url_to_postid( $target );
		if ( ! $post_id ) {
			return new \WP_Error( 'target_not_found', 'Target URL does not resolve to a post.', [ 'status' => 400 ] );
		}

		// Fetch the source.
		$response = wp_safe_remote_get( $source, [
			'timeout'    => 15,
			'user-agent' => 'NOP IndieWeb/' . NOP_INDIEWEB_VERSION . ' (webmention; +https://neilorangepeel.com)',
		] );

		$http_code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );

		// Handle source deletion — spec requires us to delete the stored webmention.
		if ( 410 === $http_code ) {
			$existing_id = $this->find_existing( $source, $post_id );
			if ( $existing_id ) {
				wp_delete_comment( $existing_id, true );
				return new \WP_REST_Response( [ 'status' => 'deleted' ], 200 );
			}
			return new \WP_Error( 'source_gone', 'Source is gone.', [ 'status' => 400 ] );
		}

		if ( is_wp_error( $response ) || $http_code < 200 || $http_code >= 300 ) {
			return new \WP_Error( 'source_fetch_failed', 'Could not fetch source URL.', [ 'status' => 400 ] );
		}

		$body = wp_remote_retrieve_body( $response );

		if ( ! $this->source_links_to_target( $body, $target ) ) {
			return new \WP_Error( 'no_link', 'Source does not link to target.', [ 'status' => 400 ] );
		}

		// Deduplicate: update the existing comment rather than creating a duplicate.
		$existing_id = $this->find_existing( $source, $post_id );

		$parser = new MF2_Parser();
		$parsed = $parser->parse( $body, $source );

		if ( $existing_id ) {
			$this->update_webmention( $existing_id, $parsed );
			return new \WP_REST_Response( [ 'status' => 'updated' ], 200 );
		}

		$comment_id = $this->insert_webmention( $post_id, $source, $target, $parsed );
		if ( is_wp_error( $comment_id ) ) {
			return new \WP_Error( 'store_failed', 'Failed to store webmention.', [ 'status' => 500 ] );
		}

		return new \WP_REST_Response( [ 'status' => 'created' ], 201 );
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
		@$dom->loadHTML( $html, LIBXML_NOERROR );
		$xpath = new \DOMXPath( $dom );
		foreach ( $xpath->query( '//a[@href]' ) as $link ) {
			if ( rtrim( $link->getAttribute( 'href' ), '/' ) === $target_norm ) {
				return true;
			}
		}
		return false;
	}

	// ── Storage ────────────────────────────────────────────────────────────────

	private function find_existing( string $source, int $post_id ): int {
		$comments = get_comments( [
			'post_id'    => $post_id,
			'type'       => 'webmention',
			'meta_key'   => 'webmention_source',
			'meta_value' => $source,
			'number'     => 1,
			'fields'     => 'ids',
		] );
		return isset( $comments[0] ) ? (int) $comments[0] : 0;
	}

	private function insert_webmention( int $post_id, string $source, string $target, array $parsed ): int|\WP_Error {
		// Auto-approve Bridgy; hold everything else for manual review.
		$approved = str_contains( $source, 'brid.gy' ) ? 1 : 0;

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
