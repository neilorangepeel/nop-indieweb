<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Importer;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

	/** Lazily-built set of all outbound syndication URLs (url => true). */
	private ?array $syndicated_urls = null;

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
		$this->import_tumblr();
		$this->import_letterboxd();
	}

	// ── Manual sync ────────────────────────────────────────────────────────────

	public function handle_sync_now(): void {
		if ( ! isset( $_GET['nop_indieweb_sync'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'nop-indieweb' ), '', [ 'response' => 403 ] );
		}

		$platform = sanitize_key( $_GET['nop_indieweb_sync'] );
		check_admin_referer( "nop_indieweb_sync_{$platform}" );

		if ( in_array( $platform, [ 'mastodon', 'pixelfed' ], true ) ) {
			$this->import_mastodon_api( $platform );
		} elseif ( 'bluesky' === $platform ) {
			$this->import_bluesky();
		} elseif ( 'tumblr' === $platform ) {
			$this->import_tumblr();
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

		// Cache the canonical profile URL so Link_Discovery::get_me_urls() can emit it as rel="me".
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
			$response = \NOP\IndieWeb\nop_indieweb_strict_remote_get( $statuses_url, [
				'timeout'             => 15,
				'limit_response_size' => 4 * 1024 * 1024,
			] );
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

			// NOP: needs review — handle() can return a WP_Error, but we ignore it and
			// advance the cursor below regardless, so a permanently-failing item is
			// skipped forever. Honouring the error instead risks an infinite retry
			// loop on a poison item; the right policy (retry budget? dead-letter?) is
			// a product decision. Same pattern at the Bluesky/Letterboxd handle calls.
			$this->note->handle( $this->mastodon_to_payload( $status, 'pixelfed' === $platform ? 'photo' : '' ) );
			// Only advance the cursor when the status carries an id — a missing id would
			// write an empty since_id and re-import the whole feed on the next run.
			if ( ! empty( $status['id'] ) ) {
				\NOP\IndieWeb\nop_indieweb_update_option( "syndicators.{$platform}.import_last_id", $status['id'] );
			}
		}

		\NOP\IndieWeb\nop_indieweb_update_option( "syndicators.{$platform}.import_last_at", gmdate( 'c' ) );
	}

	private function mastodon_to_payload( array $status, string $kind = '' ): array {
		$html = $status['content'] ?? '';
		$text = wp_strip_all_tags( $html );
		$url  = $status['url'] ?? '';

		// Mastodon's url is its own server-processed file. No usable fallback
		// to pair against — it's the only thing the platform exposes — so the
		// size cap path in sideload_photos won't fire.
		$photos = [];
		$videos = [];
		foreach ( $status['media_attachments'] ?? [] as $att ) {
			$type = (string) ( $att['type'] ?? '' );
			$src  = (string) ( $att['url'] ?? '' );
			if ( '' === $src ) {
				continue;
			}
			$alt  = (string) ( $att['description'] ?? '' );
			$meta = $att['meta']['original'] ?? [];

			if ( 'image' === $type ) {
				$photos[] = [ 'primary' => $src, 'alt' => $alt ];
			} elseif ( in_array( $type, [ 'video', 'gifv' ], true ) ) {
				$videos[] = [
					'primary' => $src,
					'alt'     => $alt,
					'size'    => (int) ( $att['meta']['original']['size'] ?? 0 ),
					'width'   => (int) ( $meta['width']  ?? 0 ),
					'height'  => (int) ( $meta['height'] ?? 0 ),
				];
			}
		}

		$properties = [
			'content'     => [ [ 'html' => $html, 'value' => $text ] ],
			'published'   => [ $status['created_at'] ?? '' ],
			'url'         => [ $url ],
			'syndication' => [ $url ],
			'photo'       => $photos,
			'video'       => $videos,
		];

		if ( '' !== $kind ) {
			$properties['post-kind'] = [ $kind ];
		}

		return [
			'type'       => [ 'h-entry' ],
			'properties' => $properties,
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
		$resolve = \NOP\IndieWeb\nop_indieweb_strict_remote_get(
			'https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle?' . http_build_query( [ 'handle' => $handle ] ),
			[ 'timeout' => 15, 'limit_response_size' => 1 * 1024 * 1024 ]
		);

		if ( is_wp_error( $resolve ) || 200 !== wp_remote_retrieve_response_code( $resolve ) ) {
			return;
		}

		$identity = json_decode( wp_remote_retrieve_body( $resolve ), true );
		$did      = $identity['did'] ?? '';
		if ( ! $did ) {
			return;
		}

		$response = \NOP\IndieWeb\nop_indieweb_strict_remote_get(
			'https://public.api.bsky.app/xrpc/app.bsky.feed.getAuthorFeed?' . http_build_query( [ 'actor' => $did, 'limit' => 25 ] ),
			[ 'timeout' => 15, 'limit_response_size' => 4 * 1024 * 1024 ]
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

		\NOP\IndieWeb\nop_indieweb_update_option( 'syndicators.bluesky.import_last_at', gmdate( 'c' ) );
	}

	private function bluesky_to_payload( array $post, string $url ): array {
		$record = $post['record'] ?? [];
		$text   = $record['text'] ?? '';
		$did    = (string) ( $post['author']['did'] ?? '' );

		return [
			'type'       => [ 'h-entry' ],
			'properties' => [
				'content'     => [ $text ],
				'published'   => [ $record['createdAt'] ?? '' ],
				'url'         => [ $url ],
				'syndication' => [ $url ],
				'photo'       => $this->bluesky_extract_photos( $post, $did ),
				'video'       => $this->bluesky_extract_videos( $post, $did ),
			],
		];
	}

	/**
	 * Extracts video references from a Bluesky post.
	 *
	 * Returns an array of { primary, size, alt, width, height } entries pointing
	 * at the original uploaded blob via getBlob. Handles both standalone video
	 * embeds and the recordWithMedia variant.
	 */
	private function bluesky_extract_videos( array $post, string $did ): array {
		if ( '' === $did ) {
			return [];
		}

		$record_embed = $post['record']['embed'] ?? [];
		if ( 'app.bsky.embed.recordWithMedia' === ( $record_embed['$type'] ?? '' ) ) {
			$record_embed = $record_embed['media'] ?? [];
		}

		if ( 'app.bsky.embed.video' !== ( $record_embed['$type'] ?? '' ) ) {
			return [];
		}

		$video = $record_embed['video'] ?? [];
		$cid   = (string) ( $video['ref']['$link'] ?? '' );
		if ( '' === $cid ) {
			return [];
		}

		return [ [
			'primary' => $this->bluesky_blob_url( $did, $cid ),
			'size'    => (int) ( $video['size'] ?? 0 ),
			'alt'     => (string) ( $record_embed['alt'] ?? '' ),
			'width'   => (int) ( $record_embed['aspectRatio']['width']  ?? 0 ),
			'height'  => (int) ( $record_embed['aspectRatio']['height'] ?? 0 ),
		] ];
	}

	/**
	 * Extracts photo references from a Bluesky post.
	 *
	 * Returns an array of { primary, fallback, size } entries:
	 *   - primary  → getBlob URL for the original uploaded blob (full fidelity)
	 *   - fallback → fullsize CDN URL (used if the original exceeds the size cap)
	 *   - size     → blob byte count from the record, used to pick primary/fallback
	 *
	 * Handles both standalone image embeds and the recordWithMedia variant
	 * (quote-posts with images).
	 */
	private function bluesky_extract_photos( array $post, string $did ): array {
		if ( '' === $did ) {
			return [];
		}

		$record_embed = $post['record']['embed'] ?? [];
		$view_embed   = $post['embed'] ?? [];

		// recordWithMedia nests the actual media one level deeper.
		if ( 'app.bsky.embed.recordWithMedia' === ( $record_embed['$type'] ?? '' ) ) {
			$record_embed = $record_embed['media'] ?? [];
		}
		if ( 'app.bsky.embed.recordWithMedia#view' === ( $view_embed['$type'] ?? '' ) ) {
			$view_embed = $view_embed['media'] ?? [];
		}

		if ( 'app.bsky.embed.images' !== ( $record_embed['$type'] ?? '' ) ) {
			return [];
		}

		$view_images = ( 'app.bsky.embed.images#view' === ( $view_embed['$type'] ?? '' ) )
			? ( $view_embed['images'] ?? [] )
			: [];

		$photos = [];
		foreach ( $record_embed['images'] ?? [] as $i => $rec_img ) {
			$cid = (string) ( $rec_img['image']['ref']['$link'] ?? '' );
			if ( '' === $cid ) {
				continue;
			}
			$photos[] = [
				'primary'  => $this->bluesky_blob_url( $did, $cid ),
				'fallback' => (string) ( $view_images[ $i ]['fullsize'] ?? '' ),
				'size'     => (int) ( $rec_img['image']['size'] ?? 0 ),
				'alt'      => (string) ( $rec_img['alt'] ?? '' ),
			];
		}
		return $photos;
	}

	/**
	 * Builds a getBlob URL for an original uploaded blob. bsky.social serves
	 * blobs for accounts hosted there; for self-hosted PDSs this would need
	 * to resolve via the DID document — out of scope for v1 since the
	 * importer only fetches the configured user's own account.
	 */
	private function bluesky_blob_url( string $did, string $cid ): string {
		return 'https://bsky.social/xrpc/com.atproto.sync.getBlob?'
			. http_build_query( [ 'did' => $did, 'cid' => $cid ] );
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

	// ── Tumblr ────────────────────────────────────────────────────────────────────

	private function import_tumblr(): void {
		$settings = \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators', [] )['tumblr'] ?? [];
		if ( empty( $settings['import_enabled'] ) ) {
			return;
		}

		$client = new \NOP\IndieWeb\Syndication\Tumblr_Client();
		if ( '' === $client->blog_id() ) {
			return;
		}

		$posts = $client->blog_posts( 20 );
		if ( is_wp_error( $posts ) || ! $posts ) {
			return;
		}

		foreach ( array_reverse( $posts ) as $post ) {
			// Skip reblogs — only the blog's own posts are ours to mirror.
			if ( ! empty( $post['parent_post_id'] ) || ! empty( $post['reblogged_root_id'] ) ) {
				continue;
			}
			$url = (string) ( $post['post_url'] ?? '' );
			if ( '' === $url || $this->was_syndicated_from_wordpress( $url ) ) {
				continue;
			}
			$this->note->handle( $this->tumblr_to_payload( $post, $url ) );
		}

		\NOP\IndieWeb\nop_indieweb_update_option( 'syndicators.tumblr.import_last_at', gmdate( 'c' ) );
	}

	/**
	 * Flattens a Tumblr NPF post into a Micropub-shaped payload: text blocks become
	 * the content, image blocks become photos (with alt), and an image-bearing post
	 * is tagged as a photo kind so it lands in the grid.
	 */
	private function tumblr_to_payload( array $post, string $url ): array {
		$text   = [];
		$photos = [];
		foreach ( (array) ( $post['content'] ?? [] ) as $block ) {
			$type = (string) ( $block['type'] ?? '' );
			if ( 'text' === $type && '' !== (string) ( $block['text'] ?? '' ) ) {
				$text[] = (string) $block['text'];
			} elseif ( 'image' === $type ) {
				$src = (string) ( $block['media'][0]['url'] ?? '' );
				if ( '' !== $src ) {
					$photos[] = [ 'primary' => $src, 'alt' => (string) ( $block['alt_text'] ?? '' ) ];
				}
			} elseif ( 'link' === $type && ! empty( $block['url'] ) ) {
				$text[] = (string) $block['url'];
			}
		}

		$published = ! empty( $post['timestamp'] )
			? gmdate( 'c', (int) $post['timestamp'] )
			: (string) ( $post['date'] ?? '' );

		$properties = [
			'content'     => [ implode( "\n\n", $text ) ],
			'published'   => [ $published ],
			'url'         => [ $url ],
			'syndication' => [ $url ],
			'photo'       => $photos,
		];
		if ( $photos ) {
			$properties['post-kind'] = [ 'photo' ];
		}

		return [
			'type'       => [ 'h-entry' ],
			'properties' => $properties,
		];
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

		$response = \NOP\IndieWeb\nop_indieweb_strict_remote_get(
			"https://letterboxd.com/{$username}/rss/",
			[ 'timeout' => 15, 'limit_response_size' => 4 * 1024 * 1024, 'user-agent' => 'nop-indieweb/1.0 (WordPress)' ]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return;
		}

		libxml_use_internal_errors( true );
		// LIBXML_NONET blocks any network DTD/entity resolution; LIBXML_NOENT is
		// deliberately omitted so external entities are not expanded (XXE defence).
		$xml = simplexml_load_string( wp_remote_retrieve_body( $response ), \SimpleXMLElement::class, LIBXML_NONET );
		libxml_clear_errors();

		if ( ! $xml ) {
			return;
		}

		// Discover the letterboxd: namespace URI from the document (it's https://letterboxd.com).
		$ns      = $xml->getDocNamespaces( true );
		$lbxd_ns = $ns['letterboxd'] ?? 'https://letterboxd.com';

		if ( ! isset( $xml->channel->item ) ) {
			return;
		}

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

		\NOP\IndieWeb\nop_indieweb_update_option( 'services.letterboxd.import_last_at', gmdate( 'c' ) );
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
		if ( null === $this->syndicated_urls ) {
			$this->syndicated_urls = $this->load_syndicated_urls();
		}
		return isset( $this->syndicated_urls[ $url ] );
	}

	/**
	 * Loads every outbound syndication URL into a hash set once per run. Replaces
	 * a per-item leading-wildcard LIKE scan (a full postmeta table scan executed
	 * for each imported item) with a single query plus O(1) membership checks.
	 * No new syndication meta is written during an import run, so building this
	 * once at first use is safe.
	 */
	private function load_syndicated_urls(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one read of a single meta key per import run; object cache offers nothing here
		$rows = $wpdb->get_col(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'nop_indieweb_syndication'"
		);
		$set = [];
		foreach ( $rows as $raw ) {
			$value = maybe_unserialize( $raw );
			foreach ( (array) $value as $u ) {
				if ( is_string( $u ) && '' !== $u ) {
					$set[ $u ] = true;
				}
			}
		}
		return $set;
	}

	private function api_get( string $url, string $token ): ?array {
		// Strict re-validation on every redirect hop — wp_safe_remote_get alone
		// validates only the first request, then lets WP core follow Location
		// headers without re-checking. A hostile or compromised Mastodon
		// instance could otherwise redirect us into the local network with the
		// Bearer token attached on the first hop (and cURL would strip the
		// auth on cross-host, but the response body would still come back).
		$response = \NOP\IndieWeb\nop_indieweb_strict_remote_get( $url, [
			'headers'             => [ 'Authorization' => "Bearer {$token}" ],
			'timeout'             => 15,
			'limit_response_size' => 4 * 1024 * 1024,
		] );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : null;
	}
}
