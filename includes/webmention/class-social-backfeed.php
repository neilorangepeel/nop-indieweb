<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Webmention;

/**
 * Polls Mastodon and Bluesky APIs for interactions on syndicated posts and
 * stores them as webmention-type comments so they appear in the webmentions
 * block alongside site-native and inbound webmention interactions.
 *
 * Runs hourly via WP-Cron. Deduplicates using a per-comment `webmention_platform_id`
 * meta value that stores a unique identifier for each interaction on its platform.
 */
class Social_Backfeed {

	private const CRON_HOOK = 'nop_indieweb_social_backfeed';

	public function register(): void {
		add_action( self::CRON_HOOK, [ $this, 'run' ] );
		add_action( 'init', [ $this, 'maybe_schedule' ] );
	}

	public function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
		}
	}

	public function run(): void {
		$post_ids = $this->get_syndicated_post_ids();
		foreach ( $post_ids as $post_id ) {
			$urls = get_post_meta( $post_id, 'nop_indieweb_syndication', true );
			if ( ! is_array( $urls ) ) {
				continue;
			}
			$seen = $this->get_known_platform_ids( $post_id );
			foreach ( $urls as $url ) {
				if ( $this->is_pixelfed_url( $url ) ) {
					$this->backfeed_pixelfed( $post_id, $url, $seen );
				} elseif ( $this->is_mastodon_url( $url ) ) {
					$this->backfeed_mastodon( $post_id, $url, $seen );
				} elseif ( $this->is_bluesky_url( $url ) ) {
					$this->backfeed_bluesky( $post_id, $url, $seen );
				}
			}
		}
	}

	// -------------------------------------------------------------------------
	// Mastodon
	// -------------------------------------------------------------------------

	private function backfeed_mastodon( int $post_id, string $post_url, array &$seen, string $platform = 'mastodon' ): void {
		if ( ! preg_match( '#^(https?://[^/]+)/@[^/]+/(\d+)$#', $post_url, $m ) ) {
			return;
		}

		$instance  = $m[1];
		$status_id = $m[2];
		$token     = $this->mastodon_access_token();
		$base      = "{$instance}/api/v1/statuses/{$status_id}";

		// Likes (favourites).
		$favourers = $this->fetch_json( "{$base}/favourited_by", $token );
		if ( is_array( $favourers ) ) {
			foreach ( $favourers as $account ) {
				$pid = "{$platform}_like_" . ( $account['id'] ?? '' );
				if ( isset( $seen[ $pid ] ) || empty( $account['id'] ) ) {
					continue;
				}
				$this->store_interaction( $post_id, [
					'platform'      => $platform,
					'type'          => 'like',
					'platform_id'   => $pid,
					'source'        => $post_url,
					'author'        => $account['display_name'] ?: ( $account['username'] ?? '' ),
					'author_url'    => $account['url'] ?? '',
					'author_photo'  => $account['avatar_static'] ?? ( $account['avatar'] ?? '' ),
					'author_handle' => $this->mastodon_handle( $account, $instance ),
					'content'       => '',
					'date'          => '',
				], $seen );
			}
		}

		// Boosts (reblogs).
		$boosters = $this->fetch_json( "{$base}/reblogged_by", $token );
		if ( is_array( $boosters ) ) {
			foreach ( $boosters as $account ) {
				$pid = "{$platform}_repost_" . ( $account['id'] ?? '' );
				if ( isset( $seen[ $pid ] ) || empty( $account['id'] ) ) {
					continue;
				}
				$this->store_interaction( $post_id, [
					'platform'      => $platform,
					'type'          => 'repost',
					'platform_id'   => $pid,
					'source'        => $post_url,
					'author'        => $account['display_name'] ?: ( $account['username'] ?? '' ),
					'author_url'    => $account['url'] ?? '',
					'author_photo'  => $account['avatar_static'] ?? ( $account['avatar'] ?? '' ),
					'author_handle' => $this->mastodon_handle( $account, $instance ),
					'content'       => '',
					'date'          => '',
				], $seen );
			}
		}

		// Replies (thread context — descendants only).
		$context = $this->fetch_json( "{$base}/context", $token );
		if ( $context && is_array( $context['descendants'] ?? null ) ) {
			foreach ( $context['descendants'] as $status ) {
				$pid = "{$platform}_reply_" . ( $status['id'] ?? '' );
				if ( isset( $seen[ $pid ] ) || empty( $status['id'] ) ) {
					continue;
				}
				$account = $status['account'] ?? [];
				$this->store_interaction( $post_id, [
					'platform'      => $platform,
					'type'          => 'reply',
					'platform_id'   => $pid,
					'source'        => $status['url'] ?? ( $status['uri'] ?? $post_url ),
					'author'        => ( $account['display_name'] ?? '' ) ?: ( $account['username'] ?? '' ),
					'author_url'    => $account['url'] ?? '',
					'author_photo'  => $account['avatar_static'] ?? ( $account['avatar'] ?? '' ),
					'author_handle' => $this->mastodon_handle( $account, $instance ),
					'content'       => wp_strip_all_tags( $status['content'] ?? '' ),
					'date'          => $status['created_at'] ?? '',
				], $seen );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Pixelfed (Mastodon-compatible API)
	// -------------------------------------------------------------------------

	private function backfeed_pixelfed( int $post_id, string $post_url, array &$seen ): void {
		// Pixelfed URLs: /p/username/id → normalise to /@username/id for the API.
		$normalised = preg_replace( '#/p/([^/]+)/(\d+)$#', '/@$1/$2', $post_url );
		if ( ! $normalised || $normalised === $post_url ) {
			return;
		}
		$this->backfeed_mastodon( $post_id, $normalised, $seen, 'pixelfed' );
	}

	private function mastodon_access_token(): string {
		return (string) \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.mastodon.access_token', '' );
	}

	private function mastodon_handle( array $account, string $instance ): string {
		$acct = $account['acct'] ?? '';
		if ( ! $acct ) {
			return '';
		}
		// `acct` is bare username for local accounts; username@domain for remote.
		$handle = str_contains( $acct, '@' ) ? "@{$acct}" : "@{$acct}@" . (string) parse_url( $instance, PHP_URL_HOST );
		return $handle;
	}

	// -------------------------------------------------------------------------
	// Bluesky
	// -------------------------------------------------------------------------

	private function backfeed_bluesky( int $post_id, string $post_url, array &$seen ): void {
		// URL: https://bsky.app/profile/{did_or_handle}/post/{rkey}
		if ( ! preg_match( '~/profile/([^/]+)/post/([^/?#\s]+)~', $post_url, $m ) ) {
			return;
		}

		$did    = $m[1];
		$rkey   = $m[2];
		$at_uri = "at://{$did}/app.bsky.feed.post/{$rkey}";
		$api    = 'https://public.api.bsky.app/xrpc';
		$enc    = urlencode( $at_uri );

		// Likes.
		$likes = $this->fetch_json( "{$api}/app.bsky.feed.getLikes?uri={$enc}" );
		if ( $likes && is_array( $likes['likes'] ?? null ) ) {
			foreach ( $likes['likes'] as $like ) {
				$actor = $like['actor'] ?? [];
				$pid   = 'bluesky_like_' . ( $actor['did'] ?? '' );
				if ( isset( $seen[ $pid ] ) || empty( $actor['did'] ) ) {
					continue;
				}
				$handle = $actor['handle'] ?? '';
				$this->store_interaction( $post_id, [
					'platform'      => 'bluesky',
					'type'          => 'like',
					'platform_id'   => $pid,
					'source'        => $post_url,
					'author'        => ( $actor['displayName'] ?? '' ) ?: $handle,
					'author_url'    => $handle ? "https://bsky.app/profile/{$handle}" : '',
					'author_photo'  => $actor['avatar'] ?? '',
					'author_handle' => $handle ? "@{$handle}" : '',
					'content'       => '',
					'date'          => $like['createdAt'] ?? '',
				], $seen );
			}
		}

		// Reposts.
		$reposts = $this->fetch_json( "{$api}/app.bsky.feed.getRepostedBy?uri={$enc}" );
		if ( $reposts && is_array( $reposts['repostedBy'] ?? null ) ) {
			foreach ( $reposts['repostedBy'] as $actor ) {
				$pid = 'bluesky_repost_' . ( $actor['did'] ?? '' );
				if ( isset( $seen[ $pid ] ) || empty( $actor['did'] ) ) {
					continue;
				}
				$handle = $actor['handle'] ?? '';
				$this->store_interaction( $post_id, [
					'platform'      => 'bluesky',
					'type'          => 'repost',
					'platform_id'   => $pid,
					'source'        => $post_url,
					'author'        => ( $actor['displayName'] ?? '' ) ?: $handle,
					'author_url'    => $handle ? "https://bsky.app/profile/{$handle}" : '',
					'author_photo'  => $actor['avatar'] ?? '',
					'author_handle' => $handle ? "@{$handle}" : '',
					'content'       => '',
					'date'          => '',
				], $seen );
			}
		}

		// Replies (thread).
		$thread = $this->fetch_json( "{$api}/app.bsky.feed.getPostThread?uri={$enc}" );
		if ( $thread && is_array( $thread['thread']['replies'] ?? null ) ) {
			$this->process_bluesky_replies( $post_id, $thread['thread']['replies'], $seen );
		}
	}

	private function process_bluesky_replies( int $post_id, array $replies, array &$seen ): void {
		foreach ( $replies as $reply ) {
			$post = $reply['post'] ?? [];
			$uri  = $post['uri'] ?? '';
			if ( ! $uri ) {
				continue;
			}

			$pid = 'bluesky_reply_' . $uri;
			if ( ! isset( $seen[ $pid ] ) ) {
				$parts      = explode( '/', $uri );
				$reply_rkey = end( $parts );
				$reply_did  = $parts[2] ?? '';
				$author     = $post['author'] ?? [];
				$handle     = $author['handle'] ?? '';
				$record     = $post['record'] ?? [];

				$this->store_interaction( $post_id, [
					'platform'      => 'bluesky',
					'type'          => 'reply',
					'platform_id'   => $pid,
					'source'        => "https://bsky.app/profile/{$reply_did}/post/{$reply_rkey}",
					'author'        => ( $author['displayName'] ?? '' ) ?: $handle,
					'author_url'    => $handle ? "https://bsky.app/profile/{$handle}" : '',
					'author_photo'  => $author['avatar'] ?? '',
					'author_handle' => $handle ? "@{$handle}" : '',
					'content'       => $record['text'] ?? '',
					'date'          => $record['createdAt'] ?? '',
				], $seen );
			}

			if ( ! empty( $reply['replies'] ) ) {
				$this->process_bluesky_replies( $post_id, $reply['replies'], $seen );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Storage
	// -------------------------------------------------------------------------

	/**
	 * @param array{platform:string,type:string,platform_id:string,source:string,author:string,author_url:string,content:string,date:string} $data
	 * @param array<string,mixed> $seen
	 */
	private function store_interaction( int $post_id, array $data, array &$seen ): void {
		$date_gmt = $data['date'] ? gmdate( 'Y-m-d H:i:s', (int) strtotime( $data['date'] ) ) : current_time( 'mysql', true );
		$date     = get_date_from_gmt( $date_gmt );

		$comment_id = wp_insert_comment( [
			'comment_post_ID'      => $post_id,
			'comment_type'         => 'webmention',
			'comment_approved'     => 1,
			'comment_author'       => $data['author'] ?: $data['platform'],
			'comment_author_url'   => $data['author_url'],
			'comment_author_email' => '',
			'comment_author_IP'    => '',
			'comment_agent'        => '',
			'comment_content'      => $data['content'],
			'comment_date'         => $date,
			'comment_date_gmt'     => $date_gmt,
		] );

		if ( $comment_id ) {
			add_comment_meta( $comment_id, 'webmention_type',          $data['type'],                     true );
			add_comment_meta( $comment_id, 'webmention_platform',      $data['platform'],                 true );
			add_comment_meta( $comment_id, 'webmention_platform_id',   $data['platform_id'],              true );
			add_comment_meta( $comment_id, 'webmention_source',        $data['source'],                   true );
			add_comment_meta( $comment_id, 'webmention_author_photo',  $data['author_photo'] ?? '',       true );
			add_comment_meta( $comment_id, 'webmention_author_handle', $data['author_handle'] ?? '',      true );
			$seen[ $data['platform_id'] ] = true;
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function get_syndicated_post_ids(): array {
		$query = new \WP_Query( [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'fields'         => 'ids',
			'meta_key'       => 'nop_indieweb_syndication',
			'no_found_rows'  => true,
		] );
		return $query->posts;
	}

	private function get_known_platform_ids( int $post_id ): array {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT cm.meta_value
			 FROM {$wpdb->commentmeta} cm
			 INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
			 WHERE c.comment_post_ID = %d
			   AND c.comment_type = 'webmention'
			   AND cm.meta_key = 'webmention_platform_id'",
			$post_id
		) );
		return array_flip( $rows ?: [] );
	}

	private function is_pixelfed_url( string $url ): bool {
		return (bool) preg_match( '#/p/[^/]+/\d+$#', $url );
	}

	private function is_mastodon_url( string $url ): bool {
		return (bool) preg_match( '#/@[^/]+/\d+$#', $url );
	}

	private function is_bluesky_url( string $url ): bool {
		return str_starts_with( $url, 'https://bsky.app/profile/' );
	}

	private function fetch_json( string $url, string $token = '' ): ?array {
		$headers = [ 'Accept' => 'application/json' ];
		if ( $token ) {
			$headers['Authorization'] = "Bearer {$token}";
		}

		// Strict re-validation on every redirect hop — see api_get() in the
		// feed importer for the rationale. A misconfigured or compromised
		// Mastodon instance cannot redirect us into the local network.
		$response = \NOP\IndieWeb\nop_indieweb_strict_remote_get( $url, [
			'timeout'             => 15,
			'limit_response_size' => 4 * 1024 * 1024,
			'headers'             => $headers,
		] );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : null;
	}
}
