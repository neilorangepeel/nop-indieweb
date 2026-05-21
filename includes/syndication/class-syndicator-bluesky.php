<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Syndication;

class Syndicator_Bluesky extends Syndicator_Base {

	public function slug(): string  { return 'bluesky'; }
	public function label(): string { return 'Bluesky'; }

	private function handle(): string {
		return (string) \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.bluesky.handle', '' );
	}

	private function app_password(): string {
		return (string) \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.bluesky.app_password', '' );
	}

	private function pds(): string {
		return 'https://bsky.social';
	}

	protected function is_configured(): bool {
		return $this->handle() && $this->app_password();
	}

	protected function owns_url( string $url ): bool {
		return str_contains( $url, 'bsky.app' );
	}

	public function test_connection(): array {
		if ( ! $this->is_configured() ) {
			return [ 'ok' => false, 'message' => __( 'Not configured.', 'nop-indieweb' ) ];
		}

		$response = wp_remote_post(
			$this->pds() . '/xrpc/com.atproto.server.createSession',
			[
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [
					'identifier' => $this->handle(),
					'password'   => $this->app_password(),
				] ),
				'timeout' => 10,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'message' => $response->get_error_message() ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code && isset( $body['handle'] ) ) {
			return [ 'ok' => true, 'message' => 'Connected as @' . $body['handle'] ];
		}

		return [
			'ok'      => false,
			'message' => 'Error ' . $code . ': ' . ( $body['message'] ?? __( 'Unknown error', 'nop-indieweb' ) ),
		];
	}

	protected function do_syndicate( int $post_id ): ?string {
		$session = $this->create_session();
		if ( ! $session ) {
			return null;
		}

		$permalink = (string) get_permalink( $post_id );
		$video     = $this->collect_inline_video( $post_id );
		$images    = $video ? [] : $this->collect_inline_images( $post_id, 4 );
		$body_text = $this->build_full_text( $post_id );

		if ( $video || $images ) {
			// Media path: use the bare site host (e.g. neilorangepeel.com) as the
			// facet label so readers see who/where the post came from while
			// keeping the full URL out of the visible text budget. Falls back to
			// the full permalink if the host can't be parsed. Video and image
			// embeds are mutually exclusive on Bluesky — video wins when both
			// are present in the post.
			$host   = (string) wp_parse_url( (string) home_url(), PHP_URL_HOST );
			$label  = '' !== $host ? $host : $permalink;
			$text   = $this->compose_status( $body_text, 300, $label, mb_strlen( $label ) );
			$facet  = $this->build_label_facet( $text, $label, $permalink );
			$embed  = $video
				? $this->build_video_embed( $video, $session )
				: $this->build_image_embed( $images, $session );
			$facets = $facet ? [ $facet ] : [];
		} else {
			// No media: full text + inline URL, with optional link-card preview
			// for titled posts (Articles/Bookmarks/Replies).
			$text   = $this->compose_status( $body_text, 300, $permalink, mb_strlen( $permalink ) );
			$facets = $this->build_link_facets( $text, $permalink );
			$embed  = $this->build_link_card( $post_id, $permalink, $session );
		}

		$record = [
			'$type'     => 'app.bsky.feed.post',
			'text'      => $text,
			'createdAt' => gmdate( 'c' ),
		];

		if ( $facets ) {
			$record['facets'] = $facets;
		}

		if ( $embed ) {
			$record['embed'] = $embed;
		}

		$response = wp_remote_post(
			$this->pds() . '/xrpc/com.atproto.repo.createRecord',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $session['accessJwt'],
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( [
					'repo'       => $session['did'],
					'collection' => 'app.bsky.feed.post',
					'record'     => $record,
				] ),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$code = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response );
			\NOP\IndieWeb\nop_indieweb_log( "Bluesky syndication failed for post {$post_id}", [ 'code' => $code ] );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$uri  = $body['uri'] ?? null;

		if ( ! $uri ) {
			\NOP\IndieWeb\nop_indieweb_log( "Bluesky syndication: no URI in response for post {$post_id}", $body );
			return null;
		}

		$parts = explode( '/', $uri );
		$rkey  = end( $parts );
		return "https://bsky.app/profile/{$session['did']}/post/{$rkey}";
	}

	private function build_link_card( int $post_id, string $url, array $session ): ?array {
		$post        = get_post( $post_id );
		$title       = $post->post_title ?: '';
		$description = get_the_excerpt( $post );

		if ( ! $title ) {
			return null;
		}

		$external = [
			'uri'         => $url,
			'title'       => $title,
			'description' => $description ?: '',
		];

		// Upload featured image as thumb blob.
		$thumb = $this->upload_thumb( $post_id, $session );
		if ( $thumb ) {
			$external['thumb'] = $thumb;
		}

		return [
			'$type'    => 'app.bsky.embed.external',
			'external' => $external,
		];
	}

	private function upload_thumb( int $post_id, array $session ): ?array {
		$map_url = (string) get_post_meta( $post_id, 'nop_indieweb_map_url', true );
		if ( '' !== $map_url ) {
			return $this->upload_image_blob( [
				'url'           => $map_url,
				'alt'           => '',
				'attachment_id' => 0,
				'width'         => 1240,
				'height'        => 620,
			], $session );
		}

		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( ! $thumbnail_id ) {
			return null;
		}
		$src = wp_get_attachment_image_src( $thumbnail_id, 'medium' );
		if ( ! $src ) {
			return null;
		}
		return $this->upload_image_blob( [
			'url'           => (string) $src[0],
			'alt'           => '',
			'attachment_id' => $thumbnail_id,
			'width'         => (int) ( $src[1] ?? 0 ),
			'height'        => (int) ( $src[2] ?? 0 ),
		], $session );
	}

	/**
	 * Builds an app.bsky.embed.images structure from inline images. Each entry
	 * carries alt text and an aspect-ratio hint so Bluesky's feed doesn't crop
	 * to a square. Skips individual images that fail to upload — does not fail
	 * the whole post.
	 */
	private function build_image_embed( array $images, array $session ): ?array {
		$items = [];
		foreach ( $images as $image ) {
			$blob = $this->upload_image_blob( $image, $session );
			if ( ! $blob ) {
				continue;
			}
			$entry = [
				'alt'   => (string) ( $image['alt'] ?? '' ),
				'image' => $blob,
			];
			$width  = (int) ( $image['width'] ?? 0 );
			$height = (int) ( $image['height'] ?? 0 );
			if ( $width > 0 && $height > 0 ) {
				$entry['aspectRatio'] = [ 'width' => $width, 'height' => $height ];
			}
			$items[] = $entry;
		}
		if ( ! $items ) {
			return null;
		}
		return [
			'$type'  => 'app.bsky.embed.images',
			'images' => $items,
		];
	}

	/**
	 * Uploads a single image as a Bluesky blob. Bluesky's 1MB blob limit kicks
	 * in often — when the 'large' URL is over budget, try the 'medium' size for
	 * known attachments before giving up.
	 */
	private function upload_image_blob( array $image, array $session ): ?array {
		$candidates = [ (string) $image['url'] ];

		$id = (int) ( $image['attachment_id'] ?? 0 );
		if ( $id > 0 ) {
			$medium = wp_get_attachment_image_src( $id, 'medium' );
			if ( $medium && (string) $medium[0] !== (string) $image['url'] ) {
				$candidates[] = (string) $medium[0];
			}
			$thumb = wp_get_attachment_image_src( $id, 'thumbnail' );
			if ( $thumb && ! in_array( (string) $thumb[0], $candidates, true ) ) {
				$candidates[] = (string) $thumb[0];
			}
		}

		foreach ( $candidates as $url ) {
			$fetched = $this->fetch_image( $url );
			if ( ! $fetched ) {
				continue;
			}
			if ( strlen( $fetched['data'] ) > 1_000_000 ) {
				\NOP\IndieWeb\nop_indieweb_log( 'Bluesky: image over 1MB, trying smaller', [
					'url'   => $url,
					'bytes' => strlen( $fetched['data'] ),
				] );
				continue;
			}
			$blob = $this->upload_blob( $fetched['data'], $fetched['mime'], $session );
			if ( $blob ) {
				return $blob;
			}
		}
		return null;
	}

	/**
	 * Builds an app.bsky.embed.video structure from a single inline video.
	 * Uploads the original WP attachment's bytes as a blob; carries alt text
	 * and aspect ratio so the post renders with the right shape and is
	 * accessible. Returns null if the upload fails (status will post without
	 * the embed in that case).
	 */
	private function build_video_embed( array $video, array $session ): ?array {
		$blob = $this->upload_video_blob( $video, $session );
		if ( ! $blob ) {
			return null;
		}
		$embed = [
			'$type' => 'app.bsky.embed.video',
			'video' => $blob,
		];
		if ( ! empty( $video['alt'] ) ) {
			$embed['alt'] = (string) $video['alt'];
		}
		$width  = (int) ( $video['width']  ?? 0 );
		$height = (int) ( $video['height'] ?? 0 );
		if ( $width > 0 && $height > 0 ) {
			$embed['aspectRatio'] = [ 'width' => $width, 'height' => $height ];
		}
		return $embed;
	}

	/**
	 * Uploads a video file to the PDS as a blob. Bluesky enforces its own
	 * size/duration limits (currently ~100 MB / ~60 s) — we don't pre-check
	 * those here; the upload simply fails and the post goes out without
	 * the video embed if Bluesky rejects it.
	 */
	private function upload_video_blob( array $video, array $session ): ?array {
		$fetched = $this->fetch_video( (string) $video['url'] );
		if ( ! $fetched ) {
			return null;
		}
		return $this->upload_blob( $fetched['data'], $fetched['mime'], $session );
	}

	private function upload_blob( string $data, string $mime, array $session ): ?array {
		$upload = wp_remote_post(
			$this->pds() . '/xrpc/com.atproto.repo.uploadBlob',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $session['accessJwt'],
					'Content-Type'  => $mime,
				],
				'body'    => $data,
				// 120s handles large videos on slow connections; photos finish in a fraction of this.
				'timeout' => 120,
			]
		);
		if ( is_wp_error( $upload ) || 200 !== wp_remote_retrieve_response_code( $upload ) ) {
			return null;
		}
		$body = json_decode( wp_remote_retrieve_body( $upload ), true );
		return $body['blob'] ?? null;
	}

	/**
	 * Builds a single facet that turns a short visible label (e.g. the site
	 * host) into a clickable link, keeping the full permalink out of the
	 * visible text.
	 *
	 * Anchors to the *last* occurrence of the label, since compose_status()
	 * always appends the label as the final bytes of the text. Using strpos
	 * (first occurrence) would mis-target when the body happens to mention
	 * the same string mid-sentence.
	 */
	private function build_label_facet( string $text, string $label, string $url ): ?array {
		if ( '' === $label || '' === $text ) {
			return null;
		}
		$byte_start = strlen( $text ) - strlen( $label );
		if ( $byte_start < 0 || substr( $text, $byte_start ) !== $label ) {
			return null;
		}
		return [
			'index'    => [
				'byteStart' => $byte_start,
				'byteEnd'   => strlen( $text ),
			],
			'features' => [
				[ '$type' => 'app.bsky.richtext.facet#link', 'uri' => $url ],
			],
		];
	}

	private function create_session(): ?array {
		$response = wp_remote_post(
			$this->pds() . '/xrpc/com.atproto.server.createSession',
			[
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [
					'identifier' => $this->handle(),
					'password'   => $this->app_password(),
				] ),
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}

	private function build_link_facets( string $text, string $url ): array {
		$pos = mb_strpos( $text, $url );
		if ( false === $pos ) {
			return [];
		}

		$byte_start = strlen( mb_substr( $text, 0, $pos ) );
		$byte_end   = $byte_start + strlen( $url );

		return [ [
			'index'    => [ 'byteStart' => $byte_start, 'byteEnd' => $byte_end ],
			'features' => [ [ '$type' => 'app.bsky.richtext.facet#link', 'uri' => $url ] ],
		] ];
	}
}
