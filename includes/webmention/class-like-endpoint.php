<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Webmention;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
			'count'     => $this->like_count( $post_id ),
			'liked'     => $this->visitor_has_liked( $post_id ),
			'responses' => $this->response_count( $post_id ),
		] );
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = (int) $request->get_param( 'post_id' );

		if ( ! $this->post_is_valid( $post_id ) ) {
			return new \WP_Error( 'invalid_post', 'Post not found.', [ 'status' => 404 ] );
		}

		// Cheap per-IP burst limit. The IP-hash dedup below prevents duplicate
		// likes on the same post, but doesn't prevent an attacker rotating
		// across many posts to flood the comments table.
		if ( ! $this->throttle_ok() ) {
			return new \WP_Error( 'rate_limited', 'Too many likes from this IP — try again shortly.', [ 'status' => 429 ] );
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

		if ( ! $comment_id ) {
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

	/**
	 * Likes shown on the pills: site and webmention likes, plus likes imported
	 * from the platform the post first appeared on (which carry no identities).
	 */
	public function like_count( int $post_id ): int {
		$primed = $this->primed();
		if ( isset( $primed['counts'][ $post_id ] ) || ( $primed && isset( $primed['ids'][ $post_id ] ) ) ) {
			return (int) get_post_meta( $post_id, 'nop_indieweb_imported_likes', true ) + (int) ( $primed['counts'][ $post_id ] ?? 0 );
		}
		return (int) get_post_meta( $post_id, 'nop_indieweb_imported_likes', true ) + (int) get_comments( [
			'post_id'    => $post_id,
			'type'       => 'webmention',
			'status'     => 'approve',
			'count'      => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- low-frequency meta/taxonomy lookup (import, admin, or per-post render cache), not a hot path
			'meta_query' => [ [ 'key' => 'webmention_type', 'value' => 'like' ] ],
		] );
	}

	/**
	 * Every approved response — comments and webmentions of any kind — which is
	 * what the replies area counts. Polled to offer "new responses".
	 */
	public function response_count( int $post_id ): int {
		return (int) get_comments( [
			'post_id'  => $post_id,
			'type__in' => [ 'comment', 'webmention' ],
			'status'   => 'approve',
			'count'    => true,
		] );
	}

	/**
	 * Seeds the shared `nop-indieweb/likes` Interactivity store with this post's
	 * count and the visitor's liked state, so every like control for the post —
	 * a tile's heart, the post footer's pill — renders and updates as one.
	 */
	public function seed_state( int $post_id ): void {
		static $seeded = [];
		if ( isset( $seeded[ $post_id ] ) ) {
			return;
		}
		$seeded[ $post_id ] = true;

		// Derived state for the server's directive pass — the same getters
		// assets/js/likes.js defines, so the markup is complete before JS loads.
		$post = static function (): array {
			$state = wp_interactivity_state( 'nop-indieweb/likes' );
			$key   = (string) ( wp_interactivity_get_context()['key'] ?? '' );
			return $state['posts'][ $key ] ?? [ 'count' => 0, 'liked' => false ];
		};

		wp_interactivity_state( 'nop-indieweb/likes', [
			'liked'      => static fn(): bool => (bool) $post()['liked'],
			'count'      => static fn(): int => (int) $post()['count'],
			'label'      => static fn(): string => $post()['liked'] ? __( 'Liked', 'nop-indieweb' ) : __( 'Like', 'nop-indieweb' ),
			'countLabel' => static function () use ( $post ): string {
				$count = (int) $post()['count'];
				/* translators: %d: number of likes */
				return sprintf( _n( '%d like', '%d likes', $count, 'nop-indieweb' ), $count );
			},
			'endpoint' => rest_url( 'nop-indieweb/v1/like' ),
			'i18n'     => [
				'like'   => __( 'Like', 'nop-indieweb' ),
				'liked'  => __( 'Liked', 'nop-indieweb' ),
				/* translators: %d: number of likes (one) */
				'one'    => __( '%d like', 'nop-indieweb' ),
				/* translators: %d: number of likes */
				'other'  => __( '%d likes', 'nop-indieweb' ),
				'failed' => __( 'Could not save like. Please try again.', 'nop-indieweb' ),
			],
			'posts'    => [
				'p' . $post_id => [
					'count' => $this->like_count( $post_id ),
					'liked' => $this->visitor_has_liked( $post_id ),
				],
			],
		] );
	}

	public function visitor_has_liked( int $post_id ): bool {
		$primed = $this->primed();
		if ( $primed && isset( $primed['ids'][ $post_id ] ) ) {
			return isset( $primed['liked'][ $post_id ] );
		}
		return ! empty( get_comments( [
			'post_id'    => $post_id,
			'type'       => 'webmention',
			'status'     => 'approve',
			'number'     => 1,
			'fields'     => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- low-frequency meta/taxonomy lookup (import, admin, or per-post render cache), not a hot path
			'meta_query' => [
				[ 'key' => 'webmention_type',    'value' => 'like' ],
				[ 'key' => 'webmention_ip_hash', 'value' => $this->ip_hash() ],
			],
		] ) );
	}

	// ── Private ───────────────────────────────────────────────────────────────

	/**
	 * The like counts, and which of them this visitor has made, for every post in the loop's query, in two grouped queries
	 * instead of two per post (a wall of 25 tiles asked 50 times). Made the first time a count is wanted inside a loop; a
	 * post outside it (a single entry, the REST route) is asked for singly as before.
	 *
	 * @return array{ids:array<int,true>,counts:array<int,int>,liked:array<int,true>}|array{} Empty outside a loop.
	 */
	private function primed(): array {
		static $primed = null;
		global $wp_query, $wpdb;
		if ( null !== $primed ) {
			return $primed;
		}
		$ids = ( in_the_loop() && $wp_query instanceof \WP_Query ) ? array_values( array_filter( array_map( 'intval', wp_list_pluck( $wp_query->posts, 'ID' ) ) ) ) : [];
		if ( count( $ids ) < 2 ) {
			return []; // One post: the ordinary query is as cheap (and this is not cached, so a later loop can still prime).
		}
		$in     = implode( ',', $ids );
		$counts = [];
		$liked  = [];
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one grouped read for the page; $in is integers only
		foreach ( (array) $wpdb->get_results(
			"SELECT c.comment_post_ID AS id, COUNT(*) AS n FROM {$wpdb->comments} c
			 INNER JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = 'webmention_type' AND m.meta_value = 'like'
			 WHERE c.comment_type = 'webmention' AND c.comment_approved = '1' AND c.comment_post_ID IN ({$in}) GROUP BY c.comment_post_ID"
		) as $row ) {
			$counts[ (int) $row->id ] = (int) $row->n;
		}
		foreach ( (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT c.comment_post_ID FROM {$wpdb->comments} c
			 INNER JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = 'webmention_type' AND m.meta_value = 'like'
			 INNER JOIN {$wpdb->commentmeta} h ON h.comment_id = c.comment_ID AND h.meta_key = 'webmention_ip_hash' AND h.meta_value = %s
			 WHERE c.comment_type = 'webmention' AND c.comment_approved = '1' AND c.comment_post_ID IN ({$in})",
			$this->ip_hash()
		) ) as $id ) {
			$liked[ (int) $id ] = true;
		}
		// phpcs:enable
		$primed = [ 'ids' => array_fill_keys( $ids, true ), 'counts' => $counts, 'liked' => $liked ];
		return $primed;
	}

	private function post_is_valid( int $post_id ): bool {
		$post = get_post( $post_id );
		return $post instanceof \WP_Post && 'publish' === $post->post_status;
	}

	private function ip_hash(): string {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		// Strip IPv6-mapped IPv4 prefix so ::ffff:127.0.0.1 and 127.0.0.1 hash identically.
		if ( str_starts_with( $ip, '::ffff:' ) ) {
			$ip = substr( $ip, 7 );
		}
		return hash( 'sha256', $ip . wp_salt( 'nonce' ) );
	}

	/**
	 * Per-IP burst limit: caps new like POSTs at 30/min by default.
	 * Returns true if the request may proceed.
	 */
	private function throttle_ok(): bool {
		$max    = (int) apply_filters( 'nop_indieweb_like_rate_limit', 30 );
		$window = (int) apply_filters( 'nop_indieweb_like_rate_window', MINUTE_IN_SECONDS );
		if ( $max <= 0 ) {
			return true;
		}
		$key   = 'nop_like_rl_' . $this->ip_hash();
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return false;
		}
		set_transient( $key, $count + 1, $window );
		return true;
	}
}
