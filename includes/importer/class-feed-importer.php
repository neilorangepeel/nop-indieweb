<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Importer;

use NOP\IndieWeb\Services\Note;
use NOP\IndieWeb\Services\Letterboxd;

/**
 * Imports posts from Mastodon, Bluesky, Pixelfed, and Letterboxd into WordPress.
 *
 * Runs hourly via WP-Cron and can also be triggered manually from the settings
 * page. Reuses service classes to parse, deduplicate, and store posts with the
 * same metadata and format logic as inbound Micropub posts.
 *
 * Deduplication:
 *   - Service::handle() checks nop_indieweb_source_url (source URL of the post).
 *   - Before that, was_syndicated_from_wordpress() checks nop_indieweb_syndication
 *     to skip posts that originated on WordPress and were then syndicated out.
 *
 * Cursor tracking:
 *   - Mastodon/Pixelfed: stores the highest status ID seen per-platform inside
 *     the plugin settings (syndicators.{platform}.import_last_id).
 *   - Bluesky/Letterboxd: always fetches the latest batch; deduplication via
 *     nop_indieweb_source_url skips already-imported posts.
 *
 * Profile URLs:
 *   - Mastodon/Pixelfed return the canonical profile URL from verify_credentials.
 *     Stored as syndicators.{platform}.profile_url inside the plugin settings so
 *     it's surfaced as a rel="me" link without a separate options row.
 */
class Feed_Importer {

	private Note       $note;
	private Letterboxd $letterboxd;

	public function __construct( Note $note, Letterboxd $letterboxd ) {
		$this->note       = $note;
		$this->letterboxd = $letterboxd;
	}

	public function register(): void {
		add_action( 'nop_indieweb_import_feeds', [ $this, 'run' ] );
		add_action( 'admin_init', [ $this, 'handle_sync_now' ] );

		if ( ! wp_next_scheduled( 'nop_indieweb_import_feeds' ) ) {
			wp_schedule_event( time(), 'hourly', 'nop_indieweb_import_feeds' );
		}
	}

	public function run(): void {
		$this->import_mastodon_api( 'mastodon' );
		$this->import_mastodon_api( 'pixelfed' );
		$this->import_bluesky();
		$this->import_letterboxd();
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

		if ( in_array( $platform, [ 'mastodon', 'pixelfed' ], true ) ) {
			$this->import_mastodon_api( $platform );
		} elseif ( 'bluesky' === $platform ) {
			$this->import_bluesky();
		} elseif ( 'letterboxd' === $platform ) {
			$this->import_letterboxd();
		} else {
			$this->run();
		}

		wp_safe_redirect( add_query_arg(
			'nop_synced', $platform,
			admin_url( 'options-general.php?page=nop-indieweb-settings' )
		) );
		exit;
	}

	// ── Mastodon-API platforms (Mastodon + Pixelfed) ───────────────────────────

	/**
	 * Imports from any platform that speaks the Mastodon API — currently Mastodon
	 * and Pixelfed. Both use the same verify_credentials + statuses endpoints.
	 */
	private function import_mastodon_api( string $platform ): void {
		$settings = \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators', [] )[ $platform ] ?? [];
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

		// Cache the canonical profile URL so Plugin::get_me_urls() can emit it as rel="me".
		if ( ! empty( $me['url'] ) ) {
			\NOP\IndieWeb\nop_indieweb_update_option( "syndicators.{$platform}.profile_url", esc_url_raw( $me['url'] ) );
		}

		$last_id = (string) \NOP\IndieWeb\nop_indieweb_get_option( "syndicators.{$platform}.import_last_id", '' );
		$params  = array_filter( [
			'limit'           => 40,
			'since_id'        => $last_id,
			'exclude_reblogs' => 'true',
		] );

		$statuses_url = "{$instance}/api/v1/accounts/{$me['id']}/statuses?" . http_build_query( $params );
		$statuses     = $this->api_get( $statuses_url, $token );

		// Token may lack read:statuses scope; fall back to unauthenticated for public accounts.
		if ( null === $statuses && 'mastodon' === $platform ) {
			$response = wp_remote_get( $statuses_url, [ 'timeout' => 15 ] );
			if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
				$data     = json_decode( wp_remote_retrieve_body( $response ), true );
				$statuses = is_array( $data ) ? $data : null;
			}
		}

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
			\NOP\IndieWeb\nop_indieweb_update_option( "syndicators.{$platform}.import_last_id", $status['id'] );
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

		$handle = (string) ( $settings['handle'] ?? '' );
		if ( ! $handle ) {
			return;
		}

		// Resolve handle to DID using the public AppView — no credentials needed.
		$resolve = wp_remote_get(
			'https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle?' . http_build_query( [ 'handle' => $handle ] ),
			[ 'timeout' => 15 ]
		);

		if ( is_wp_error( $resolve ) || 200 !== wp_remote_retrieve_response_code( $resolve ) ) {
			return;
		}

		$identity = json_decode( wp_remote_retrieve_body( $resolve ), true );
		$did      = $identity['did'] ?? '';
		if ( ! $did ) {
			return;
		}

		$response = wp_remote_get(
			'https://public.api.bsky.app/xrpc/app.bsky.feed.getAuthorFeed?' . http_build_query( [ 'actor' => $did, 'limit' => 25 ] ),
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

	// ── Letterboxd ──────────────────────────────────────────────────────────────

	private function import_letterboxd(): void {
		$settings = \NOP\IndieWeb\nop_indieweb_get_option( 'services', [] )['letterboxd'] ?? [];
		if ( empty( $settings['import_enabled'] ) ) {
			return;
		}

		$username = trim( (string) ( $settings['username'] ?? '' ) );
		if ( ! $username ) {
			return;
		}

		$response = wp_remote_get(
			"https://letterboxd.com/{$username}/rss/",
			[ 'timeout' => 15, 'user-agent' => 'nop-indieweb/1.0 (WordPress)' ]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return;
		}

		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( wp_remote_retrieve_body( $response ) );
		libxml_clear_errors();

		if ( ! $xml ) {
			return;
		}

		// Discover the letterboxd: namespace URI from the document (it's https://letterboxd.com).
		$ns      = $xml->getDocNamespaces( true );
		$lbxd_ns = $ns['letterboxd'] ?? 'https://letterboxd.com';

		$items = iterator_to_array( $xml->channel->item, false );

		// Process oldest-first so the most recent entry wins on any dedup edge case.
		foreach ( array_reverse( $items ) as $item ) {
			// Use link element as the canonical URL; guid is not always a URL.
			$url = (string) $item->link;
			if ( ! $url || $this->was_syndicated_from_wordpress( $url ) ) {
				continue;
			}

			$lbxd        = $item->children( $lbxd_ns );
			$description = (string) $item->description;

			$this->letterboxd->handle( [
				'type'       => [ 'h-cite' ],
				'properties' => [
					'film_title'  => [ (string) ( $lbxd->filmTitle    ?? '' ) ],
					'film_year'   => [ (string) ( $lbxd->filmYear     ?? '' ) ],
					'rating'      => [ (string) ( $lbxd->memberRating ?? '' ) ],
					'watch_date'  => [ (string) ( $lbxd->watchedDate  ?? '' ) ],
					'rewatch'     => [ strtolower( (string) ( $lbxd->rewatch ?? '' ) ) === 'yes' ? '1' : '0' ],
					'content'     => [ $this->extract_letterboxd_review( $description ) ],
					'url'         => [ $url ],
					'poster'      => [ $this->extract_letterboxd_poster( $description ) ],
				],
			] );
		}
	}

	/**
	 * Extracts the film poster URL from the first <img> in the Letterboxd
	 * description CDATA. Letterboxd has no media:thumbnail in their feed.
	 */
	private function extract_letterboxd_poster( string $html ): string {
		if ( preg_match( '/<img\b[^>]+\bsrc=["\']([^"\']+)["\']/', $html, $m ) ) {
			return esc_url_raw( $m[1] );
		}
		return '';
	}

	/**
	 * Strips the poster image, "Watched on..." lines, and star-rating-only lines
	 * from a Letterboxd RSS description, leaving just the user's review text.
	 */
	private function extract_letterboxd_review( string $html ): string {
		$html  = preg_replace( '/<img\b[^>]*>/i', '', $html ) ?? $html;
		$text  = wp_strip_all_tags( $html );
		$lines = array_filter(
			array_map( 'trim', explode( "\n", $text ) ),
			static fn( string $line ): bool =>
				$line !== ''
				&& ! str_starts_with( $line, 'Watched' )
				&& ! preg_match( '/^[★½\s]+$/', $line )
		);
		return trim( implode( "\n", $lines ) );
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
