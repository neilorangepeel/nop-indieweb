<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Importer;

use NOP\IndieWeb\Services\Note;

/**
 * Imports posts from Mastodon and Bluesky into WordPress.
 *
 * Runs hourly via WP-Cron and can also be triggered manually from the settings
 * page. Reuses the Note/Entries service to parse, deduplicate, and store posts
 * with the same metadata and format logic as inbound Micropub posts.
 *
 * Deduplication:
 *   - Note::handle() checks nop_indieweb_source_url (source URL of the post).
 *   - Before that, was_syndicated_from_wordpress() checks nop_indieweb_syndication
 *     to skip posts that originated on WordPress and were then syndicated out.
 *
 * Cursor tracking:
 *   - Mastodon: stores the highest status ID seen in nop_indieweb_mastodon_import_last_id.
 *   - Bluesky:  stores the cursor string in nop_indieweb_bluesky_import_cursor.
 */
class Feed_Importer {

	private Note $note;

	public function __construct( Note $note ) {
		$this->note = $note;
	}

	public function register(): void {
		add_action( 'nop_indieweb_import_feeds', [ $this, 'run' ] );
		add_action( 'admin_init', [ $this, 'handle_sync_now' ] );

		if ( ! wp_next_scheduled( 'nop_indieweb_import_feeds' ) ) {
			wp_schedule_event( time(), 'hourly', 'nop_indieweb_import_feeds' );
		}
	}

	public function run(): void {
		$this->import_mastodon();
		$this->import_bluesky();
	}

	// ── Manual sync ────────────────────────────────────────────────────────────

	public function handle_sync_now(): void {
		if ( ! isset( $_GET['nop_indieweb_sync'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 403 );
		}

		$platform = sanitize_key( $_GET['nop_indieweb_sync'] );
		check_admin_referer( "nop_indieweb_sync_{$platform}" );

		if ( 'mastodon' === $platform ) {
			$this->import_mastodon();
		} elseif ( 'bluesky' === $platform ) {
			$this->import_bluesky();
		} else {
			$this->run();
		}

		wp_safe_redirect( add_query_arg(
			'nop_synced', $platform,
			admin_url( 'options-general.php?page=nop-indieweb-settings' )
		) );
		exit;
	}

	// ── Mastodon ────────────────────────────────────────────────────────────────

	private function import_mastodon(): void {
		$settings = \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators', [] )['mastodon'] ?? [];
		if ( empty( $settings['import_enabled'] ) ) {
			return;
		}

		$instance = rtrim( (string) ( $settings['instance'] ?? '' ), '/' );
		$token    = (string) ( $settings['access_token'] ?? '' );
		if ( ! $instance || ! $token ) {
			return;
		}

		$me = $this->api_get( "{$instance}/api/v1/accounts/verify_credentials", $token );
		if ( ! is_array( $me ) || empty( $me['id'] ) ) {
			return;
		}

		$last_id  = (string) get_option( 'nop_indieweb_mastodon_import_last_id', '' );
		$params   = array_filter( [
			'limit'           => 40,
			'since_id'        => $last_id,
			'exclude_reblogs' => 'true',
		] );

		$statuses = $this->api_get(
			"{$instance}/api/v1/accounts/{$me['id']}/statuses?" . http_build_query( $params ),
			$token
		);

		if ( ! is_array( $statuses ) || empty( $statuses ) ) {
			return;
		}

		// Process oldest-first so the cursor advances correctly on partial failure.
		foreach ( array_reverse( $statuses ) as $status ) {
			if ( ! empty( $status['in_reply_to_id'] ) ) {
				continue; // skip replies
			}

			$url = $status['url'] ?? '';
			if ( ! $url || $this->was_syndicated_from_wordpress( $url ) ) {
				continue;
			}

			$this->note->handle( $this->mastodon_to_payload( $status ) );
			update_option( 'nop_indieweb_mastodon_import_last_id', $status['id'] );
		}
	}

	private function mastodon_to_payload( array $status ): array {
		$html = $status['content'] ?? '';
		$text = wp_strip_all_tags( $html );
		$url  = $status['url'] ?? '';

		$photos = [];
		foreach ( $status['media_attachments'] ?? [] as $att ) {
			if ( 'image' === ( $att['type'] ?? '' ) && ! empty( $att['url'] ) ) {
				$photos[] = $att['url'];
			}
		}

		return [
			'type'       => [ 'h-entry' ],
			'properties' => [
				'content'     => [ [ 'html' => $html, 'value' => $text ] ],
				'published'   => [ $status['created_at'] ?? '' ],
				'url'         => [ $url ],
				'syndication' => [ $url ],
				'photo'       => $photos,
			],
		];
	}

	// ── Bluesky ─────────────────────────────────────────────────────────────────

	private function import_bluesky(): void {
		$settings = \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators', [] )['bluesky'] ?? [];
		if ( empty( $settings['import_enabled'] ) ) {
			return;
		}

		$handle   = (string) ( $settings['handle'] ?? '' );
		$password = (string) ( $settings['app_password'] ?? '' );
		if ( ! $handle || ! $password ) {
			return;
		}

		$session = $this->bluesky_session( $handle, $password );
		if ( ! $session ) {
			return;
		}

		$did    = $session['did'];
		$cursor = (string) get_option( 'nop_indieweb_bluesky_import_cursor', '' );
		$params = array_filter( [
			'actor'  => $did,
			'limit'  => 25,
			'cursor' => $cursor,
		] );

		$response = wp_remote_get(
			'https://public.api.bsky.app/xrpc/app.bsky.feed.getAuthorFeed?' . http_build_query( $params ),
			[ 'timeout' => 15 ]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$feed = $body['feed'] ?? [];

		if ( empty( $feed ) ) {
			return;
		}

		$new_cursor = $body['cursor'] ?? '';

		foreach ( array_reverse( $feed ) as $item ) {
			// Skip reposts.
			if ( ! empty( $item['reason'] ) ) {
				continue;
			}

			$post = $item['post'] ?? [];

			// Skip replies.
			if ( ! empty( $post['record']['reply'] ) ) {
				continue;
			}

			$uri = $post['uri'] ?? '';
			$url = $this->bluesky_uri_to_url( $uri, $did );
			if ( ! $url || $this->was_syndicated_from_wordpress( $url ) ) {
				continue;
			}

			$this->note->handle( $this->bluesky_to_payload( $post, $url ) );
		}

		if ( $new_cursor ) {
			update_option( 'nop_indieweb_bluesky_import_cursor', $new_cursor );
		}
	}

	private function bluesky_to_payload( array $post, string $url ): array {
		$record = $post['record'] ?? [];
		$text   = $record['text'] ?? '';

		// Extract image URLs from the resolved embed view.
		$photos = [];
		$embed  = $post['embed'] ?? [];
		if ( 'app.bsky.embed.images#view' === ( $embed['$type'] ?? '' ) ) {
			foreach ( $embed['images'] ?? [] as $img ) {
				if ( ! empty( $img['fullsize'] ) ) {
					$photos[] = $img['fullsize'];
				}
			}
		}

		return [
			'type'       => [ 'h-entry' ],
			'properties' => [
				'content'     => [ $text ],
				'published'   => [ $record['createdAt'] ?? '' ],
				'url'         => [ $url ],
				'syndication' => [ $url ],
				'photo'       => $photos,
			],
		];
	}

	private function bluesky_uri_to_url( string $uri, string $did ): string {
		// at://did:plc:xxx/app.bsky.feed.post/rkey → https://bsky.app/profile/{did}/post/{rkey}
		if ( ! str_starts_with( $uri, 'at://' ) ) {
			return '';
		}
		$parts = explode( '/', $uri );
		$rkey  = end( $parts );
		return "https://bsky.app/profile/{$did}/post/{$rkey}";
	}

	private function bluesky_session( string $handle, string $password ): ?array {
		$response = wp_remote_post(
			'https://bsky.social/xrpc/com.atproto.server.createSession',
			[
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [ 'identifier' => $handle, 'password' => $password ] ),
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}

	// ── Shared helpers ──────────────────────────────────────────────────────────

	/**
	 * Returns true if the URL appears in any post's nop_indieweb_syndication meta,
	 * meaning it was originally published on WordPress and then syndicated out.
	 * Using a LIKE query on the serialized array is reliable here because
	 * WordPress serialises URLs verbatim and URLs don't contain regex metacharacters
	 * that would cause false positives in the escaped LIKE pattern.
	 */
	private function was_syndicated_from_wordpress( string $url ): bool {
		global $wpdb;
		$like  = '%' . $wpdb->esc_like( $url ) . '%';
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta}
			 WHERE meta_key = 'nop_indieweb_syndication'
			   AND meta_value LIKE %s",
			$like
		) );
		return $count > 0;
	}

	private function api_get( string $url, string $token ): ?array {
		$response = wp_remote_get( $url, [
			'headers' => [ 'Authorization' => "Bearer {$token}" ],
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : null;
	}
}
