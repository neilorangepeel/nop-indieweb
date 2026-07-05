<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Syndication;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared base for syndicators that speak the Mastodon HTTP API:
 *   POST /api/v1/statuses     — create a post
 *   POST /api/v2/media        — upload media
 *   GET  /api/v1/accounts/verify_credentials — connection test
 *
 * Pixelfed re-uses the same API, so both syndicators share this implementation.
 * Subclasses just declare their slug, label, and character limit.
 */
abstract class Mastodon_Compatible_Syndicator extends Syndicator_Base {

	abstract protected function char_limit(): int;

	protected function instance(): string {
		return rtrim( (string) \NOP\IndieWeb\nop_indieweb_get_option( "syndicators.{$this->slug()}.instance", '' ), '/' );
	}

	protected function access_token(): string {
		return (string) \NOP\IndieWeb\nop_indieweb_get_option( "syndicators.{$this->slug()}.access_token", '' );
	}

	protected function is_configured(): bool {
		return $this->instance() && $this->access_token();
	}

	protected function owns_url( string $url ): bool {
		$instance = $this->instance();
		return $instance && str_starts_with( $url, $instance );
	}

	/**
	 * Editor preview: the composed status text (truncated to the char budget), the
	 * raw budget usage, and a note on the unfurl/thread treatment. URLs cost a flat
	 * 23 against the budget regardless of visible length, mirroring do_syndicate().
	 */
	public function editor_preview( int $post_id ): ?array {
		if ( ! $this->enabled() || ! $this->is_configured() || ! $this->supports_post( $post_id ) ) {
			return null;
		}
		$permalink = (string) get_permalink( $post_id );
		$target    = $this->response_target_url( $post_id );
		$kind      = (string) get_post_meta( $post_id, 'nop_indieweb_post_kind', true );
		$threaded  = 'reply' === $kind && '' !== $target && ! str_contains( $target, 'bsky.app' );
		$suffix    = $threaded ? '' : ( '' !== $target ? $target : $permalink );
		$body      = $this->build_full_text( $post_id );
		$limit     = $this->char_limit();
		return [
			'slug'  => $this->slug(),
			'label' => $this->label(),
			'text'  => $this->compose_status( $body, $limit, $suffix, 23 ),
			'count' => mb_strlen( $body ) + ( '' !== $suffix ? 2 + 23 : 0 ),
			'limit' => $limit,
			'badge' => $threaded
				? __( 'Replies in-thread', 'nop-indieweb' )
				: ( '' !== $target ? __( 'Unfurls the source', 'nop-indieweb' ) : __( 'Link preview', 'nop-indieweb' ) ),
		];
	}

	protected function do_syndicate( int $post_id ): string|\WP_Error {
		$instance  = $this->instance();
		$token     = $this->access_token();
		$permalink = (string) get_permalink( $post_id );

		// Response kinds (bookmark/like/repost/reply/quote) unfurl the *target*
		// source — Mastodon builds its preview card from the first URL — so link
		// that instead of our permalink; every other kind links the permalink.
		$target = $this->response_target_url( $post_id );
		$link   = '' !== $target ? $target : $permalink;

		// For a reply this instance can resolve to a status, thread natively
		// (in_reply_to_id) and drop the link suffix — the parent is shown inline,
		// so re-linking it is noise. Otherwise post standalone as before.
		$in_reply_to = $this->resolve_reply_status_id( $post_id, $target, $instance, $token );
		$suffix      = '' !== $in_reply_to ? '' : $link;

		// URLs count as a flat 23 chars on Mastodon-compatible APIs regardless of
		// the actual link length, so budget the suffix at 23 even though the
		// visible text is the full URL.
		$text     = $this->build_full_text( $post_id );
		$status   = $this->compose_status( $text, $this->char_limit(), $suffix, 23 );

		$body = [ 'status' => $status ];
		if ( '' !== $in_reply_to ) {
			$body['in_reply_to_id'] = $in_reply_to;
		}
		$media_ids = $this->upload_post_images( $post_id, $instance, $token );
		if ( $media_ids ) {
			$body['media_ids'] = $media_ids;
		}

		$response = wp_remote_post(
			$instance . '/api/v1/statuses',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			\NOP\IndieWeb\nop_indieweb_log( "{$this->label()} syndication failed for post {$post_id}", [ 'code' => $response->get_error_message() ] );
			return new \WP_Error( 'nop_syndication_failed', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			\NOP\IndieWeb\nop_indieweb_log( "{$this->label()} syndication failed for post {$post_id}", [ 'code' => $code ] );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			return new \WP_Error( 'nop_syndication_failed', sprintf(
				/* translators: 1: HTTP status code, 2: error detail from the platform API */
				__( 'HTTP %1$d: %2$s', 'nop-indieweb' ),
				$code,
				$body['error'] ?? __( 'unknown error', 'nop-indieweb' )
			) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['url'] ) ) {
			\NOP\IndieWeb\nop_indieweb_log( "{$this->label()} syndication: no URL in response for post {$post_id}", $data );
			return new \WP_Error( 'nop_syndication_failed', __( 'The platform accepted the post but returned no URL.', 'nop-indieweb' ) );
		}
		return (string) $data['url'];
	}

	/**
	 * Resolves the fediverse status a reply targets into a status id local to this
	 * instance, for native threading via in_reply_to_id. Returns '' (→ standalone
	 * post) when the post isn't a reply, no target is set, or the instance can't
	 * resolve it to a status.
	 *
	 * Uses /api/v2/search with resolve=true so the instance fetches the remote
	 * status if it hasn't seen it; the returned status id is always local, as
	 * in_reply_to_id requires. Bluesky targets are skipped — they never resolve on
	 * a Mastodon-compatible instance.
	 */
	private function resolve_reply_status_id( int $post_id, string $target, string $instance, string $token ): string {
		if ( '' === $target || '' === $instance || '' === $token ) {
			return '';
		}
		if ( 'reply' !== (string) get_post_meta( $post_id, 'nop_indieweb_post_kind', true ) ) {
			return '';
		}
		if ( str_contains( $target, 'bsky.app' ) ) {
			return '';
		}

		$response = wp_remote_get(
			$instance . '/api/v2/search?' . http_build_query( [
				'q'       => $target,
				'type'    => 'statuses',
				'resolve' => 'true',
				'limit'   => 1,
			] ),
			[
				'headers' => [ 'Authorization' => 'Bearer ' . $token ],
				'timeout' => 20,
			]
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$data     = json_decode( wp_remote_retrieve_body( $response ), true );
		$statuses = ( is_array( $data ) && is_array( $data['statuses'] ?? null ) ) ? $data['statuses'] : [];
		$first    = is_array( $statuses[0] ?? null ) ? $statuses[0] : [];
		return ! empty( $first['id'] ) ? (string) $first['id'] : '';
	}

	/**
	 * Composes the media attached to a status:
	 *   - core/video block in content → upload that one video (Mastodon caps
	 *     status at 1 video OR up to 4 images, can't mix)
	 *   - else inline core/image blocks → upload up to 4
	 *   - else featured image → upload it so titled posts still get a thumbnail
	 */
	private function upload_post_images( int $post_id, string $instance, string $token ): array {
		$video = $this->collect_inline_video( $post_id );
		if ( $video ) {
			$id = $this->upload_video( $instance, $token, $video );
			return $id ? [ $id ] : [];
		}

		$inline = $this->collect_inline_images( $post_id, 4 );

		if ( $inline ) {
			$ids = [];
			foreach ( $inline as $image ) {
				$id = $this->upload_image( $instance, $token, $image['url'], $image['alt'] );
				if ( $id ) {
					$ids[] = $id;
				}
			}
			return $ids;
		}

		// Same race condition as Bluesky: photo blocks aren't in post_content yet
		// when syndication fires, but the URLs + alts are already in meta. Read the
		// aligned alt array so accessibility text isn't lost during the race window.
		$cdn_photos = get_post_meta( $post_id, 'nop_indieweb_photos', true );
		if ( is_array( $cdn_photos ) && $cdn_photos ) {
			$cdn_alts = get_post_meta( $post_id, 'nop_indieweb_photo_alts', true );
			$cdn_alts = is_array( $cdn_alts ) ? $cdn_alts : [];
			$ids      = [];
			foreach ( $cdn_photos as $i => $url ) {
				if ( '' === (string) $url || count( $ids ) >= 4 ) {
					continue;
				}
				$id = $this->upload_image( $instance, $token, (string) $url, (string) ( $cdn_alts[ $i ] ?? '' ) );
				if ( $id ) {
					$ids[] = $id;
				}
			}
			if ( $ids ) {
				return $ids;
			}
		}

		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( ! $thumbnail_id ) {
			return [];
		}
		$src = wp_get_attachment_image_src( $thumbnail_id, 'large' );
		if ( ! $src ) {
			return [];
		}
		$alt = (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true );
		$id  = $this->upload_image( $instance, $token, (string) $src[0], $alt );
		return $id ? [ $id ] : [];
	}

	/**
	 * Uploads a single image to /api/v2/media with optional alt text in the
	 * description field. Returns the media attachment ID, or null on failure.
	 */
	private function upload_image( string $instance, string $token, string $url, string $alt ): ?string {
		return $this->upload_media(
			$instance,
			$token,
			$this->fetch_image( $url ),
			'image',
			$alt
		);
	}

	private function upload_video( string $instance, string $token, array $video ): ?string {
		return $this->upload_media(
			$instance,
			$token,
			$this->fetch_upload_video( $video ),
			'video.mp4',
			(string) ( $video['alt'] ?? '' )
		);
	}

	private function upload_media( string $instance, string $token, ?array $file_data, string $filename, string $alt ): ?string {
		if ( ! $file_data ) {
			return null;
		}

		$boundary = wp_generate_password( 24, false );
		$body     = "--{$boundary}\r\n"
			. "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n"
			. "Content-Type: {$file_data['mime']}\r\n\r\n"
			. $file_data['data'] . "\r\n";

		if ( '' !== $alt ) {
			$body .= "--{$boundary}\r\n"
				. "Content-Disposition: form-data; name=\"description\"\r\n\r\n"
				. $alt . "\r\n";
		}

		$body .= "--{$boundary}--";

		$response = wp_remote_post(
			rtrim( $instance, '/' ) . '/api/v2/media',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => "multipart/form-data; boundary={$boundary}",
				],
				'body'    => $body,
				'timeout' => 120,
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code   = wp_remote_retrieve_response_code( $response );
		$result = json_decode( wp_remote_retrieve_body( $response ), true );

		// 200 = ready immediately, 202 = still processing. A video attached while
		// processing makes the status POST 422 ("files that have not finished
		// processing"), so on 202 poll the media until it's ready before returning.
		if ( in_array( $code, [ 200, 202 ], true ) && isset( $result['id'] ) ) {
			$id = (string) $result['id'];
			if ( 202 === $code ) {
				$this->await_media_ready( $instance, $token, $id );
			}
			return $id;
		}

		return null;
	}

	/** Polls GET /api/v1/media/:id until processing completes (200) or ~60s elapse. */
	private function await_media_ready( string $instance, string $token, string $id ): void {
		for ( $i = 0; $i < 30; $i++ ) {
			sleep( 2 );
			$response = wp_remote_get(
				rtrim( $instance, '/' ) . '/api/v1/media/' . rawurlencode( $id ),
				[ 'headers' => [ 'Authorization' => 'Bearer ' . $token ], 'timeout' => 15 ]
			);
			if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
				return;
			}
		}
	}

	public function test_connection(): array {
		if ( ! $this->is_configured() ) {
			return [ 'ok' => false, 'message' => __( 'Not configured.', 'nop-indieweb' ) ];
		}

		$response = wp_remote_get(
			$this->instance() . '/api/v1/accounts/verify_credentials',
			[
				'headers' => [ 'Authorization' => 'Bearer ' . $this->access_token() ],
				'timeout' => 10,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'message' => $response->get_error_message() ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code && isset( $body['acct'] ) ) {
			if ( ! empty( $body['url'] ) ) {
				\NOP\IndieWeb\nop_indieweb_update_option( "syndicators.{$this->slug()}.profile_url", esc_url_raw( $body['url'] ) );
			}
			// Cache the bare handle so Open_Graph can build the fediverse:creator
			// tag (@handle@instance) without a front-end API call.
			\NOP\IndieWeb\nop_indieweb_update_option( "syndicators.{$this->slug()}.acct", sanitize_text_field( $body['acct'] ) );
			return [ 'ok' => true, 'message' => 'Connected as @' . $body['acct'] ];
		}

		return [
			'ok'      => false,
			'message' => 'Error ' . $code . ': ' . ( $body['error'] ?? __( 'Unknown error', 'nop-indieweb' ) ),
		];
	}
}
