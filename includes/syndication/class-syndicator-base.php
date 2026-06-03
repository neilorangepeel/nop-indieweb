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

		if ( 'article' === $kind ) {
			return '' !== $title ? $title : $body;
		}

		return '' !== $body ? $body : $title;
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
		if ( '' === $suffix ) {
			if ( mb_strlen( $text ) > $limit ) {
				$text = mb_substr( $text, 0, $limit - 1 ) . '…';
			}
			return $text;
		}

		$overhead = 2 + $suffix_cost; // "\n\n" + the suffix's budget cost
		$max_text = $limit - $overhead;
		if ( $max_text < 0 ) {
			$max_text = 0;
		}
		if ( mb_strlen( $text ) > $max_text ) {
			$text = mb_substr( $text, 0, max( 0, $max_text - 1 ) ) . '…';
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

	/**
	 * Back-compat helper: full text + plain permalink suffix. URLs cost their
	 * literal length here — Mastodon's 23-char flat rule is handled by callers
	 * that know about it.
	 */
	protected function build_status_text( int $post_id, int $limit ): string {
		$text      = $this->build_full_text( $post_id );
		$permalink = (string) get_permalink( $post_id );
		return $this->compose_status( $text, $limit, $permalink, mb_strlen( $permalink ) );
	}

	protected function fetch_image( string $url ): ?array {
		return $this->fetch_media( $url, [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ], 15 );
	}

	protected function fetch_video( string $url ): ?array {
		return $this->fetch_media( $url, [ 'video/mp4', 'video/webm', 'video/quicktime' ], 60 );
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
	 */
	protected function collect_inline_video( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}
		return \NOP\IndieWeb\nop_indieweb_block_video( (string) $post->post_content );
	}
}
