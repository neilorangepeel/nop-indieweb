<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Syndication;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Tumblr API v2 layer shared by the syndicator (outbound) and the importer
 * (inbound). Owns OAuth2 token refresh, NPF post creation, and the blog feed
 * fetch. Tumblr access tokens expire after ~42 minutes, so access_token()
 * transparently refreshes from the stored refresh token before each call.
 */
class Tumblr_Client {

	private const API_BASE = 'https://api.tumblr.com/v2';

	/** Returns a valid bearer token, refreshing it first if it's about to expire. */
	public function access_token(): string|\WP_Error {
		$token   = (string) \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.tumblr.access_token', '' );
		$expires = (int) \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.tumblr.token_expires_at', 0 );

		if ( '' !== $token && $expires > time() + 60 ) {
			return $token;
		}
		return $this->refresh_token();
	}

	private function refresh_token(): string|\WP_Error {
		$refresh = (string) \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.tumblr.refresh_token', '' );
		$key     = (string) \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.tumblr.consumer_key', '' );
		$secret  = (string) \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.tumblr.consumer_secret', '' );

		if ( '' === $refresh || '' === $key || '' === $secret ) {
			return new \WP_Error( 'nop_tumblr_unconnected', __( 'Tumblr is not connected.', 'nop-indieweb' ) );
		}

		$response = wp_remote_post( self::API_BASE . '/oauth2/token', [
			'timeout' => 15,
			'body'    => [
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
				'client_id'     => $key,
				'client_secret' => $secret,
			],
		] );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'nop_tumblr_refresh_failed', $response->get_error_message() );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			return new \WP_Error( 'nop_tumblr_refresh_failed', __( 'Tumblr did not return a new access token — reconnect.', 'nop-indieweb' ) );
		}

		\NOP\IndieWeb\nop_indieweb_update_option( 'syndicators.tumblr.access_token', (string) $data['access_token'] );
		\NOP\IndieWeb\nop_indieweb_update_option( 'syndicators.tumblr.token_expires_at', time() + (int) ( $data['expires_in'] ?? 2520 ) );
		// Tumblr may rotate the refresh token — keep the newest one.
		if ( ! empty( $data['refresh_token'] ) ) {
			\NOP\IndieWeb\nop_indieweb_update_option( 'syndicators.tumblr.refresh_token', (string) $data['refresh_token'] );
		}

		return (string) $data['access_token'];
	}

	public function blog_id(): string {
		return (string) \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.tumblr.blog_identifier', '' );
	}

	/**
	 * Creates an NPF post, then resolves its canonical permalink.
	 *
	 * $images is the same list passed to build_npf. When it's non-empty the post
	 * is sent as multipart/form-data — Tumblr won't fetch external image URLs at
	 * creation time, so each image's bytes must travel in the request keyed by
	 * the identifier its content block references. Media-free posts use a plain
	 * JSON body.
	 *
	 * Returns [ 'id' => string, 'url' => string ] or a WP_Error. The WP_Error
	 * carries the HTTP status in its data so the caller can skip retrying a
	 * permanent 4xx.
	 */
	public function create_post( array $npf, array $images = [] ): array|\WP_Error {
		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$blog = $this->blog_id();
		if ( '' === $blog ) {
			return new \WP_Error( 'nop_tumblr_unconnected', __( 'No Tumblr blog configured.', 'nop-indieweb' ) );
		}

		$endpoint = self::API_BASE . "/blog/{$blog}/posts";
		$response = $images
			? $this->post_multipart( $endpoint, $token, $npf, $images )
			: wp_remote_post( $endpoint, [
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $npf ),
				'timeout' => 30,
			] );

		if ( is_wp_error( $response ) ) {
			\NOP\IndieWeb\nop_indieweb_log( 'Tumblr syndication failed', [ 'error' => $response->get_error_message() ] );
			return new \WP_Error( 'nop_syndication_failed', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		// The create response carries `id` (an int); `id_string` only appears when
		// reading posts back. Accept either so a 201 isn't misread as a failure —
		// a false failure would re-queue and post a duplicate.
		$new_id = (string) ( $data['response']['id_string'] ?? $data['response']['id'] ?? '' );

		if ( ! in_array( $code, [ 200, 201 ], true ) || '' === $new_id ) {
			// errors[].detail is the actionable reason (e.g. an invalid-NPF code);
			// meta.msg is only the generic status text. Prefer the detail.
			$detail = $data['errors'][0]['detail'] ?? '';
			$msg    = '' !== $detail ? $detail : ( $data['meta']['msg'] ?? __( 'unknown error', 'nop-indieweb' ) );
			\NOP\IndieWeb\nop_indieweb_log( 'Tumblr syndication rejected', [ 'code' => $code, 'msg' => $msg ] );
			return new \WP_Error(
				'nop_syndication_failed',
				sprintf(
					/* translators: 1: HTTP status code, 2: error detail from the Tumblr API */
					__( 'HTTP %1$d: %2$s', 'nop-indieweb' ),
					$code,
					$msg
				),
				[ 'status' => (int) $code ]
			);
		}

		return [ 'id' => $new_id, 'url' => $this->resolve_post_url( $blog, $new_id, $token ) ];
	}

	/**
	 * Posts the NPF as multipart/form-data: a `json` part for the body plus one
	 * binary part per image, each named with the identifier its block references
	 * (matching build_npf's media_identifier()). Mirrors the shape Tumblr's
	 * client libraries use for NPF media uploads.
	 */
	private function post_multipart( string $endpoint, string $token, array $npf, array $images ): array|\WP_Error {
		$boundary = 'nopboundary' . wp_generate_password( 24, false );
		$eol      = "\r\n";

		$body  = '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="json"' . $eol;
		$body .= 'Content-Type: application/json' . $eol . $eol;
		$body .= (string) wp_json_encode( $npf ) . $eol;

		foreach ( $images as $i => $img ) {
			$src = (string) ( $img['url'] ?? '' );
			if ( '' === $src ) {
				continue;
			}
			$bin = $this->fetch_binary( $src );
			if ( is_wp_error( $bin ) ) {
				return $bin;
			}
			// Mirror build_npf: caller-resolved MIME (local attachment) wins over the
			// URL-extension guess, so the part's Content-Type matches the block's type.
			$mime       = (string) ( $img['mime'] ?? '' );
			$mime       = '' !== $mime ? $mime : self::mime_from_url( $src );
			$identifier = self::media_identifier( $i );
			$filename   = $identifier . '.' . self::ext_from_mime( $mime );

			$body .= '--' . $boundary . $eol;
			$body .= 'Content-Disposition: form-data; name="' . $identifier . '"; filename="' . $filename . '"' . $eol;
			$body .= 'Content-Type: ' . $mime . $eol . $eol;
			$body .= $bin . $eol;
		}

		$body .= '--' . $boundary . '--' . $eol;

		return wp_remote_post( $endpoint, [
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
			],
			'body'    => $body,
			'timeout' => 45,
		] );
	}

	/** Hard cap on media bytes fetched for a Tumblr upload (50 MB). */
	private const MEDIA_FETCH_CAP_BYTES = 50 * 1024 * 1024;

	/**
	 * Downloads the bytes at a media URL for upload, or a WP_Error.
	 *
	 * For imported posts the photo URL is parsed out of attacker-influenceable
	 * post_content, so this uses the SSRF-hardened fetch (re-validates every
	 * redirect hop against private/reserved IPs) and caps the body — matching the
	 * threat model the other syndicators' fetch_media() already enforces.
	 */
	private function fetch_binary( string $url ): string|\WP_Error {
		$response = \NOP\IndieWeb\nop_indieweb_strict_remote_get( $url, [
			'timeout'             => 30,
			'limit_response_size' => self::MEDIA_FETCH_CAP_BYTES,
		] );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'nop_tumblr_media_fetch', $response->get_error_message() );
		}
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'nop_tumblr_media_fetch', __( 'Could not fetch image to upload to Tumblr.', 'nop-indieweb' ) );
		}
		return (string) wp_remote_retrieve_body( $response );
	}

	/** Stable per-image identifier shared by build_npf and the uploader. */
	private static function media_identifier( int $i ): string {
		return 'media-' . $i;
	}

	private static function ext_from_mime( string $mime ): string {
		return [
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
		][ $mime ] ?? 'jpg';
	}

	/**
	 * Fetches the public permalink of a freshly-created post. Falls back to the
	 * predictable hostname/post/{id} form if the lookup fails.
	 */
	private function resolve_post_url( string $blog, string $id, string $token ): string {
		$response = wp_remote_get( self::API_BASE . "/blog/{$blog}/posts/{$id}", [
			'headers' => [ 'Authorization' => 'Bearer ' . $token ],
			'timeout' => 15,
		] );
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			$url  = $data['response']['posts'][0]['post_url'] ?? '';
			if ( $url ) {
				return (string) $url;
			}
		}
		$host = str_contains( $blog, '.' ) ? $blog : "{$blog}.tumblr.com";
		return "https://{$host}/post/{$id}";
	}

	/** Fetches the configured blog's own posts as NPF, for the importer. */
	public function blog_posts( int $limit = 20 ): array|\WP_Error {
		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$blog = $this->blog_id();
		if ( '' === $blog ) {
			return new \WP_Error( 'nop_tumblr_unconnected', __( 'No Tumblr blog configured.', 'nop-indieweb' ) );
		}

		$response = wp_remote_get(
			self::API_BASE . "/blog/{$blog}/posts?" . http_build_query( [ 'npf' => 'true', 'limit' => $limit ] ),
			[
				'headers' => [ 'Authorization' => 'Bearer ' . $token ],
				'timeout' => 20,
			]
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'nop_tumblr_fetch_failed', $response->get_error_message() );
		}
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'nop_tumblr_fetch_failed', __( 'Tumblr feed request failed.', 'nop-indieweb' ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data['response']['posts'] ?? null ) ? $data['response']['posts'] : [];
	}

	/**
	 * Confirms the token works and caches the connected blog's name + URL.
	 * Returns [ 'ok' => bool, 'message' => string ].
	 */
	public function verify(): array {
		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return [ 'ok' => false, 'message' => $token->get_error_message() ];
		}

		$response = wp_remote_get( self::API_BASE . '/user/info', [
			'headers' => [ 'Authorization' => 'Bearer ' . $token ],
			'timeout' => 10,
		] );
		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'message' => $response->get_error_message() ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$name = $data['response']['user']['name'] ?? '';

		if ( 200 !== $code || '' === $name ) {
			return [ 'ok' => false, 'message' => 'Error ' . $code . ': ' . ( $data['meta']['msg'] ?? __( 'Unknown error', 'nop-indieweb' ) ) ];
		}

		// Cache the configured blog's URL for status display, if it's in the list.
		$blog_id = $this->blog_id();
		foreach ( (array) ( $data['response']['user']['blogs'] ?? [] ) as $blog ) {
			$matches = isset( $blog['name'], $blog['url'] )
				&& ( $blog['name'] === $blog_id || str_contains( (string) $blog['url'], (string) $blog_id ) );
			if ( $matches ) {
				\NOP\IndieWeb\nop_indieweb_update_option( 'syndicators.tumblr.profile_url', esc_url_raw( (string) $blog['url'] ) );
				break;
			}
		}
		\NOP\IndieWeb\nop_indieweb_update_option( 'syndicators.tumblr.user_name', sanitize_text_field( $name ) );

		return [ 'ok' => true, 'message' => 'Connected as ' . $name ];
	}

	/**
	 * Pure NPF assembler — no WordPress calls, so it's unit-testable. Maps a
	 * post's text/images/kind to Tumblr content blocks plus tags + source_url.
	 *
	 * $ctx keys: permalink, tags (string[]), cite, target_url, target_title.
	 */
	public static function build_npf( string $text, array $images, string $kind, array $ctx ): array {
		$content = [];

		// Image blocks reference the binary by `identifier`, not by URL: Tumblr does
		// not fetch external image URLs at post-creation time, so the bytes must
		// travel in the same multipart request keyed by this identifier (see
		// create_post). The index keeps build_npf and the uploader in agreement —
		// both walk the same images array. Emitted for EVERY kind, including quote:
		// a quote can carry a photo, and skipping the blocks left the uploaded bytes
		// orphaned (referenced by no block) and the image dropped.
		foreach ( $images as $i => $img ) {
			$url = (string) ( $img['url'] ?? '' );
			if ( '' === $url ) {
				continue;
			}
			// Prefer the caller-resolved true MIME (from the local attachment); fall
			// back to the URL extension for remote-only images.
			$mime  = (string) ( $img['mime'] ?? '' );
			$mime  = '' !== $mime ? $mime : self::mime_from_url( $url );
			$block = [
				'type'  => 'image',
				'media' => [ [ 'type' => $mime, 'identifier' => self::media_identifier( $i ) ] ],
			];
			if ( ! empty( $img['alt'] ) ) {
				$block['alt_text'] = (string) $img['alt'];
			}
			$content[] = $block;
		}

		if ( 'quote' === $kind && '' !== $text ) {
			$content[] = [ 'type' => 'text', 'subtype' => 'quote', 'text' => $text ];
			if ( ! empty( $ctx['cite'] ) ) {
				$content[] = [ 'type' => 'text', 'text' => '— ' . $ctx['cite'] ];
			}
		} else {
			if ( '' !== $text ) {
				$content[] = [ 'type' => 'text', 'text' => $text ];
			}

			if ( in_array( $kind, [ 'reply', 'bookmark', 'repost' ], true ) && ! empty( $ctx['target_url'] ) ) {
				$link = [ 'type' => 'link', 'url' => (string) $ctx['target_url'] ];
				if ( ! empty( $ctx['target_title'] ) ) {
					$link['title'] = (string) $ctx['target_title'];
				}
				$content[] = $link;
			}
		}

		// A Tumblr post needs at least one block — fall back to the permalink.
		if ( ! $content ) {
			$content[] = [ 'type' => 'text', 'text' => (string) ( $ctx['permalink'] ?? '' ) ];
		}

		$npf = [ 'content' => $content, 'state' => 'published' ];
		if ( ! empty( $ctx['tags'] ) ) {
			$npf['tags'] = implode( ',', array_map( 'strval', (array) $ctx['tags'] ) );
		}
		if ( ! empty( $ctx['permalink'] ) ) {
			$npf['source_url'] = (string) $ctx['permalink'];
		}
		return $npf;
	}

	private static function mime_from_url( string $url ): string {
		$ext = strtolower( pathinfo( (string) parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		return [
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
		][ $ext ] ?? 'image/jpeg';
	}
}
