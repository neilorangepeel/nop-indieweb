<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Webmention;

/**
 * REST endpoint for site-native likes.
 *
 * Stores likes as comment_type = 'webmention' with webmention_type = 'like'
 * and webmention_platform = 'site'. This means the webmentions block facepile
 * displays site likes alongside IndieWeb webmention likes automatically —
 * both sources unified in one count and one display.
 *
 * Rate-limiting: one like per hashed IP address per post, enforced server-side
 * at insert time. No cookies. The IP is hashed with wp_salt() before storage
 * so it cannot be reversed.
 *
 * Routes:
 *   GET  /nop-indieweb/v1/like?post_id=N  → { count, liked }
 *   POST /nop-indieweb/v1/like            → { liked, count } (201) or { liked, already, count } (200)
 */
class Like_Endpoint {

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$args = [
			'post_id' => [
				'required'          => true,
				'type'              => 'integer',
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			],
		];

		register_rest_route( 'nop-indieweb/v1', '/like', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get' ],
				'permission_callback' => '__return_true',
				'args'                => $args,
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create' ],
				'permission_callback' => '__return_true',
				'args'                => $args,
			],
		] );
	}

	public function get( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = (int) $request->get_param( 'post_id' );

		if ( ! $this->post_is_valid( $post_id ) ) {
			return new \WP_Error( 'invalid_post', 'Post not found.', [ 'status' => 404 ] );
		}

		return new \WP_REST_Response( [
			'count' => $this->like_count( $post_id ),
			'liked' => $this->visitor_has_liked( $post_id ),
		] );
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = (int) $request->get_param( 'post_id' );

		if ( ! $this->post_is_valid( $post_id ) ) {
			return new \WP_Error( 'invalid_post', 'Post not found.', [ 'status' => 404 ] );
		}

		if ( $this->visitor_has_liked( $post_id ) ) {
			return new \WP_REST_Response( [
				'liked'   => true,
				'already' => true,
				'count'   => $this->like_count( $post_id ),
			], 200 );
		}

		$comment_id = wp_insert_comment( wp_slash( [
			'comment_post_ID'      => $post_id,
			'comment_type'         => 'webmention',
			'comment_author'       => '',
			'comment_author_email' => '',
			'comment_author_url'   => '',
			'comment_content'      => '',
			'comment_approved'     => 1,
		] ) );

		if ( ! $comment_id || is_wp_error( $comment_id ) ) {
			return new \WP_Error( 'insert_failed', 'Could not save like.', [ 'status' => 500 ] );
		}

		add_comment_meta( $comment_id, 'webmention_type',     'like',            true );
		add_comment_meta( $comment_id, 'webmention_platform', 'site',            true );
		add_comment_meta( $comment_id, 'webmention_ip_hash',  $this->ip_hash(),  true );

		return new \WP_REST_Response( [
			'liked' => true,
			'count' => $this->like_count( $post_id ),
		], 201 );
	}

	// ── Public helpers (used by render.php) ───────────────────────────────────

	public function like_count( int $post_id ): int {
		return (int) get_comments( [
			'post_id'    => $post_id,
			'type'       => 'webmention',
			'status'     => 'approve',
			'count'      => true,
			'meta_query' => [ [ 'key' => 'webmention_type', 'value' => 'like' ] ],
		] );
	}

	public function visitor_has_liked( int $post_id ): bool {
		return ! empty( get_comments( [
			'post_id'    => $post_id,
			'type'       => 'webmention',
			'status'     => 'approve',
			'number'     => 1,
			'fields'     => 'ids',
			'meta_query' => [
				[ 'key' => 'webmention_type',    'value' => 'like' ],
				[ 'key' => 'webmention_ip_hash', 'value' => $this->ip_hash() ],
			],
		] ) );
	}

	// ── Private ───────────────────────────────────────────────────────────────

	private function post_is_valid( int $post_id ): bool {
		$post = get_post( $post_id );
		return $post instanceof \WP_Post && 'publish' === $post->post_status;
	}

	private function ip_hash(): string {
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		// Strip IPv6-mapped IPv4 prefix so ::ffff:127.0.0.1 and 127.0.0.1 hash identically.
		if ( str_starts_with( $ip, '::ffff:' ) ) {
			$ip = substr( $ip, 7 );
		}
		return hash( 'sha256', $ip . wp_salt( 'nonce' ) );
	}
}
