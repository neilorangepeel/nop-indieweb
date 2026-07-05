<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Syndication;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Syndicator_Base {

	abstract public function slug(): string;

	abstract public function label(): string;

	abstract protected function is_configured(): bool;

	/**
	 * Performs the actual platform API calls. Returns the syndicated URL on
	 * success or a WP_Error describing why it failed (so retries can surface
	 * the reason in the editor).
	 */
	abstract protected function do_syndicate( int $post_id ): string|\WP_Error;

	abstract protected function owns_url( string $url ): bool;

	/**
	 * Verifies credentials are valid and the remote service is reachable.
	 * Returns [ 'ok' => bool, 'message' => string ]. Override in subclasses.
	 */
	public function test_connection(): array {
		return [ 'ok' => false, 'message' => __( 'Connection test not supported.', 'nop-indieweb' ) ];
	}

	public function matches_url( string $url ): bool {
		return $this->owns_url( $url );
	}

	public function enabled(): bool {
		return (bool) \NOP\IndieWeb\nop_indieweb_get_option( "syndicators.{$this->slug()}.enabled", false );
	}

	/**
	 * Whether this platform accepts this post at all. Default: every post.
	 * Override for platforms restricted to certain kinds (e.g. Pixelfed → photo).
	 */
	protected function supports_post( int $post_id ): bool {
		return true;
	}

	/**
	 * Attempts syndication. Returns the syndicated URL on success, an empty
	 * string when this platform doesn't apply (disabled, unconfigured, or
	 * unsupported kind), or a WP_Error describing the failure.
	 */
	public function syndicate( int $post_id ): string|\WP_Error {
		if ( ! $this->enabled() || ! $this->is_configured() || ! $this->supports_post( $post_id ) ) {
			return '';
		}

		// Dedup — if this platform already has a syndication URL on this post,
		// return it so retries of an already-sent platform report success.
		foreach ( $this->stored_urls( $post_id ) as $url ) {
			if ( $this->owns_url( $url ) ) {
				return $url;
			}
		}

		$url = $this->do_syndicate( $post_id );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		// Re-read just before writing — another platform's cron event may have
		// appended its own URL while this platform's HTTP calls were in flight.
		$existing   = $this->stored_urls( $post_id );
		$existing[] = $url;
		update_post_meta( $post_id, 'nop_indieweb_syndication', $existing );

		return $url;
	}

	private function stored_urls( int $post_id ): array {
		$existing = get_post_meta( $post_id, 'nop_indieweb_syndication', true );
		return is_array( $existing ) ? $existing : [];
	}

	/**
	 * Composes the post body text used by the syndicator. Kind-aware:
	 *   - checkin → "Checked in at {venue}"
	 *   - article → title first (link card carries the body)
	 *   - everything else → body text first, title only as a fallback when the
	 *     body is empty (e.g. Like/Repost where the auto-title carries the URL)
	 *
	 * Returns text without any permalink — compose_status() appends that and
	 * accounts for the truncation budget.
	 */
	protected function build_full_text( int $post_id ): string {
		$post       = get_post( $post_id );
		$venue_name = get_post_meta( $post_id, 'nop_indieweb_venue_name', true );

		if ( $venue_name ) {
			$body    = $post ? \NOP\IndieWeb\nop_indieweb_block_text( (string) $post->post_content ) : '';
			$checkin = sprintf( '📍 Checked in at %s', $venue_name );
			return '' !== $body ? $checkin . "\n\n" . $body : $checkin;
		}
		if ( ! $post ) {
			return '';
		}

		$kind  = (string) get_post_meta( $post_id, 'nop_indieweb_post_kind', true );
		$title = (string) ( $post->post_title ?? '' );
		$body  = \NOP\IndieWeb\nop_indieweb_block_text( (string) $post->post_content );

		// Response kinds lead with an emoji + verb (mirroring the 📍 check-in) so
		// they read as an action rather than a bare domain title. No URL or target
		// name goes in the text — the response target is carried by the platform
		// card / unfurl (see response_target_url()), which the captured cite title often
		// mislabels anyway (a YouTube link reads "YouTube", not the video name).
		$leads = [
			'bookmark' => '🔖 ' . __( 'Bookmarked', 'nop-indieweb' ),
			'like'     => '⭐ ' . __( 'Liked', 'nop-indieweb' ),
			'repost'   => '🔁 ' . __( 'Reposted', 'nop-indieweb' ),
			'quote'    => '💬 ' . __( 'Quoted', 'nop-indieweb' ),
		];
		if ( isset( $leads[ $kind ] ) ) {
			return '' !== $body ? $leads[ $kind ] . "\n\n" . $body : $leads[ $kind ];
		}

		if ( 'article' === $kind ) {
			return '' !== $title ? $title : $body;
		}

		return '' !== $body ? $body : $title;
	}

	/**
	 * The URL a response post points at — the bookmarked / liked / reposted /
	 * replied-to / quoted target — or '' for kinds that aren't responses. Drives
	 * the platform link card (Bluesky) and preview unfurl (Mastodon) so the card
	 * represents the cited source, not our own permalink.
	 */
	protected function response_target_url( int $post_id ): string {
		static $map = [
			'bookmark' => 'nop_indieweb_bookmark_of',
			'like'     => 'nop_indieweb_like_of',
			'repost'   => 'nop_indieweb_repost_of',
			'reply'    => 'nop_indieweb_in_reply_to',
			'quote'    => 'nop_indieweb_quote_of',
		];
		$kind = (string) get_post_meta( $post_id, 'nop_indieweb_post_kind', true );
		$key  = $map[ $kind ] ?? '';
		return '' !== $key ? (string) get_post_meta( $post_id, $key, true ) : '';
	}

	/**
	 * Composes `<text>\n\n<suffix>`, truncating $text so the whole thing fits in
	 * $limit. $suffix_cost is how many characters the suffix occupies against the
	 * platform's budget — for Mastodon, URLs cost a flat 23 regardless of length;
	 * for Bluesky a facet label costs its visible character count.
	 *
	 * If $suffix is empty, no separator or suffix is appended.
	 */
	protected function compose_status( string $text, int $limit, string $suffix, int $suffix_cost ): string {
		$has_suffix = '' !== $suffix;
		$overhead   = $has_suffix ? 2 + $suffix_cost : 0;

		$max_text = max( 0, $limit - $overhead );
		if ( mb_strlen( $text ) > $max_text ) {
			$text = mb_substr( $text, 0, max( 0, $max_text - 1 ) ) . '…';
		}

		if ( ! $has_suffix ) {
			return $text;
		}
		return ( '' === $text ) ? $suffix : ( $text . "\n\n" . $suffix );
	}

	/**
	 * Returns inline image references from the post content, up to $limit.
	 * Each item: [ url, alt, attachment_id, width, height ].
	 */
	protected function collect_inline_images( int $post_id, int $limit ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return [];
		}
		return \NOP\IndieWeb\nop_indieweb_block_images( (string) $post->post_content, $limit );
	}

	protected function fetch_image( string $url ): ?array {
		return $this->fetch_media( $url, [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ], 15 );
	}

	protected function fetch_video( string $url ): ?array {
		return $this->fetch_media( $url, [ 'video/mp4', 'video/webm', 'video/quicktime' ], 60 );
	}

	/**
	 * Resolves a video for upload, preferring a transcoded, size-capped MP4 of the
	 * LOCAL attachment (so a 4K iOS .mov becomes an MP4 the networks accept) over a
	 * plain URL fetch. Reading the local file also sidesteps fetch_media's 50 MB
	 * remote cap. Falls back to fetch_video() when the clip isn't a local attachment
	 * or ffmpeg is unavailable.
	 *
	 * @param array $video       collect_inline_video() result (carries attachment_id, url).
	 * @param int   $max_bytes   Per-network output ceiling (0 = the transcoder default).
	 * @param int   $max_seconds Per-network duration cap (0 = full length).
	 * @return array{mime:string,data:string}|null
	 */
	protected function fetch_upload_video( array $video, int $max_bytes = 0, int $max_seconds = 0 ): ?array {
		$attachment_id = (int) ( $video['attachment_id'] ?? 0 );
		if ( $attachment_id > 0 ) {
			$mp4 = Video_Transcoder::web_mp4( $attachment_id, $max_bytes, $max_seconds );
			if ( null !== $mp4 && is_readable( $mp4 ) ) {
				return [ 'mime' => 'video/mp4', 'data' => (string) file_get_contents( $mp4 ) ];
			}
		}
		return $this->fetch_video( (string) ( $video['url'] ?? '' ) );
	}

	/** Hard cap on media body fetched for syndication uploads (50 MB). */
	private const MEDIA_FETCH_CAP_BYTES = 50 * 1024 * 1024;

	private function fetch_media( string $url, array $allowed_mimes, int $timeout ): ?array {
		// Cap body size so a malicious/runaway upstream can't blow up PHP memory.
		// The URL is normally a same-host attachment URL, but for imported posts
		// it can be an attacker-influenced src parsed out of post_content — so
		// use the SSRF-hardened path that re-validates every redirect hop.
		$response = \NOP\IndieWeb\nop_indieweb_strict_remote_get( $url, [
			'timeout'             => $timeout,
			'limit_response_size' => self::MEDIA_FETCH_CAP_BYTES,
		] );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$mime = strtok( wp_remote_retrieve_header( $response, 'content-type' ), ';' );
		if ( ! in_array( $mime, $allowed_mimes, true ) ) {
			return null;
		}
		return [
			'mime' => $mime,
			'data' => wp_remote_retrieve_body( $response ),
		];
	}

	/**
	 * Returns the first inline core/video block reference from a post, or null.
	 * Mirrors collect_inline_images() but capped at one (platform limit).
	 *
	 * Falls back to the nop_indieweb_videos URL meta when no block is present:
	 * the core/video block is injected by the background after_insert cron, which
	 * is scheduled at the same time() as syndication — so a freshly-composed video
	 * story can syndicate BEFORE the block exists. Without this fallback the clip
	 * is silently dropped (Mastodon posts text-only, Bluesky degrades to a link
	 * card) with no retry. The meta carries only the URL, so the attachment id is
	 * resolved from it to keep transcoding + alt text working.
	 */
	protected function collect_inline_video( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}
		$video = \NOP\IndieWeb\nop_indieweb_block_video( (string) $post->post_content );
		if ( null !== $video ) {
			return $video;
		}

		$urls = get_post_meta( $post_id, 'nop_indieweb_videos', true );
		$url  = is_array( $urls ) ? (string) ( $urls[0] ?? '' ) : '';
		if ( '' === $url ) {
			return null;
		}

		$id  = attachment_url_to_postid( $url );
		$alt = '';
		if ( $id ) {
			$alt = (string) get_post_field( 'post_excerpt', $id );
			if ( '' === $alt ) {
				$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
			}
		}
		return [
			'url'           => $url,
			'alt'           => $alt,
			'attachment_id' => $id,
			'width'         => 0,
			'height'        => 0,
			'mime'          => 'video/mp4',
		];
	}
}
