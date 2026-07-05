<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Syndication;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Syndicator_Bluesky extends Syndicator_Base {

	/** Bluesky rejects image blobs larger than 1 MB. */
	private const BLOB_CAP_BYTES = 1_000_000;

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

	protected function do_syndicate( int $post_id ): string|\WP_Error {
		$session = $this->create_session();
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$permalink = (string) get_permalink( $post_id );
		$video  = $this->collect_inline_video( $post_id );
		$images = $video ? [] : $this->collect_inline_images( $post_id, 4 );

		// Swarm check-in photos are stored in nop_indieweb_photos meta at creation
		// time, but the photo blocks aren't injected into post_content until the
		// background after_insert cron runs — which happens after syndication fires.
		// Fall back to the raw CDN URLs so images aren't silently dropped.
		if ( ! $video && ! $images ) {
			$cdn_photos = get_post_meta( $post_id, 'nop_indieweb_photos', true );
			if ( is_array( $cdn_photos ) && $cdn_photos ) {
				$cdn_alts = get_post_meta( $post_id, 'nop_indieweb_photo_alts', true );
				$cdn_alts = is_array( $cdn_alts ) ? $cdn_alts : [];
				foreach ( $cdn_photos as $i => $url ) {
					if ( '' === (string) $url || count( $images ) >= 4 ) {
						continue;
					}
					// Resolve the attachment id from the URL so upload_image_blob() can
					// fall back to a 'medium'/'thumbnail' size when the full image blows
					// past Bluesky's 1MB blob cap — without it a >1MB photo is dropped.
					$images[] = [
						'url'           => (string) $url,
						'alt'           => (string) ( $cdn_alts[ $i ] ?? '' ),
						'attachment_id' => attachment_url_to_postid( (string) $url ),
						'width'         => 0,
						'height'        => 0,
					];
				}
			}
		}

		$body_text = $this->build_full_text( $post_id );

		// Quiet link back: use the bare site host (e.g. neilorangepeel.com) as a
		// tappable facet label so readers see where the post came from while the
		// full URL stays out of the visible text budget. Falls back to the full
		// permalink if the host can't be parsed. Applied to every post regardless
		// of embed type so the link treatment is consistent.
		$host   = (string) wp_parse_url( (string) home_url(), PHP_URL_HOST );
		$label  = '' !== $host ? $host : $permalink;
		$text   = $this->compose_status( $body_text, 300, $label, mb_strlen( $label ) );
		$facet  = $this->build_label_facet( $text, $label, $permalink );
		// Bluesky doesn't auto-link hashtags the way Mastodon does — each #tag
		// the author typed needs an explicit facet to become searchable.
		$facets = array_merge(
			$facet ? [ $facet ] : [],
			$this->build_hashtag_facets( $text )
		);

		// A reply to a Bluesky post is threaded natively via record.reply (below),
		// so it appears in the conversation. Null unless the target resolves to a
		// Bluesky post — non-Bluesky replies keep their link card.
		$reply_ref = $this->build_reply_ref( $post_id );

		// Embeds are mutually exclusive on Bluesky. Video wins over images; a
		// natively-threaded reply shows its parent inline, so it needs no card; with
		// no media a link-card preview is built for titled posts (and for
		// check-ins, which carry a map image as the card thumbnail).
		if ( $video ) {
			$embed = $this->build_video_embed( $video, $session );
			// If Bluesky couldn't process the clip (wrong codec, too large, or the
			// video service timed out), still link the self-hosted story rather than
			// posting an empty text stub.
			if ( null === $embed ) {
				$embed = $this->build_link_card( $post_id, $permalink, $session );
			}
		} elseif ( $images ) {
			$embed = $this->build_image_embed( $images, $session );
		} elseif ( $reply_ref ) {
			$embed = null;
		} else {
			$embed = $this->build_link_card( $post_id, $permalink, $session );
		}

		$record = [
			'$type'     => 'app.bsky.feed.post',
			'text'      => $text,
			'createdAt' => $this->post_created_at( $post_id ),
			'langs'     => [ $this->post_lang() ],
		];

		if ( $facets ) {
			$record['facets'] = $facets;
		}

		if ( $embed ) {
			$record['embed'] = $embed;
		}

		if ( $reply_ref ) {
			$record['reply'] = $reply_ref;
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

		if ( is_wp_error( $response ) ) {
			\NOP\IndieWeb\nop_indieweb_log( "Bluesky syndication failed for post {$post_id}", [ 'code' => $response->get_error_message() ] );
			return new \WP_Error( 'nop_syndication_failed', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			\NOP\IndieWeb\nop_indieweb_log( "Bluesky syndication failed for post {$post_id}", [ 'code' => $code ] );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			return new \WP_Error( 'nop_syndication_failed', sprintf(
				/* translators: 1: HTTP status code, 2: error detail from the Bluesky API */
				__( 'HTTP %1$d: %2$s', 'nop-indieweb' ),
				$code,
				$body['message'] ?? __( 'unknown error', 'nop-indieweb' )
			) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$uri  = $body['uri'] ?? null;

		if ( ! $uri ) {
			\NOP\IndieWeb\nop_indieweb_log( "Bluesky syndication: no URI in response for post {$post_id}", $body );
			return new \WP_Error( 'nop_syndication_failed', __( 'Bluesky accepted the post but returned no URI.', 'nop-indieweb' ) );
		}

		$parts = explode( '/', $uri );
		$rkey  = end( $parts );
		return "https://bsky.app/profile/{$session['did']}/post/{$rkey}";
	}

	private function build_link_card( int $post_id, string $url, array $session ): ?array {
		// Response kinds (bookmark/like/repost/reply/quote) card the *target* with
		// the cited source's own title/excerpt/image, so the preview represents
		// what was linked rather than our permalink.
		$target = $this->response_target_url( $post_id );
		if ( '' !== $target ) {
			return $this->build_cite_card( $post_id, $target, $session );
		}

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

	/**
	 * Builds the external card for a response kind from the cited target: the
	 * source's own title, excerpt, and image (captured at save time by
	 * Cite_Extractor), pointing at the target URL. Always returns a card — the
	 * title falls back to the target host, the thumb to the post's own image
	 * chain — so a bookmark/like/repost never lands as a bare link.
	 */
	private function build_cite_card( int $post_id, string $url, array $session ): array {
		$title = (string) get_post_meta( $post_id, 'nop_indieweb_cite_title', true );
		if ( '' === $title ) {
			$title = (string) wp_parse_url( $url, PHP_URL_HOST );
		}

		$external = [
			'uri'         => $url,
			'title'       => $title,
			'description' => (string) get_post_meta( $post_id, 'nop_indieweb_cite_excerpt', true ),
		];

		$image = (string) get_post_meta( $post_id, 'nop_indieweb_cite_image', true );
		$thumb = '' !== $image
			? $this->upload_image_blob( [ 'url' => $image, 'alt' => '', 'attachment_id' => 0, 'width' => 0, 'height' => 0 ], $session )
			: null;
		// Fall back to the post's own thumbnail chain (featured image → site icon)
		// when the source has no usable image, or it blew past the 1 MB blob cap.
		$thumb = $thumb ?: $this->upload_thumb( $post_id, $session );
		if ( $thumb ) {
			$external['thumb'] = $thumb;
		}

		return [
			'$type'    => 'app.bsky.embed.external',
			'external' => $external,
		];
	}

	/**
	 * Builds the record.reply strong-ref pair for a reply-kind post whose target
	 * is a Bluesky post, so the syndicated copy threads under the original instead
	 * of posting standalone. Returns null (→ standalone + link card) when the post
	 * isn't a reply, the target isn't a Bluesky post, or resolution fails.
	 *
	 * root is the thread's origin: if the parent is itself a reply, we inherit its
	 * record.reply.root; otherwise the parent IS the root (a top-level post).
	 */
	private function build_reply_ref( int $post_id ): ?array {
		if ( 'reply' !== (string) get_post_meta( $post_id, 'nop_indieweb_post_kind', true ) ) {
			return null;
		}
		$target = $this->response_target_url( $post_id );
		if ( '' === $target || ! $this->owns_url( $target ) ) {
			return null;
		}
		$at_uri = $this->resolve_at_uri( $target );
		if ( null === $at_uri ) {
			return null;
		}

		$thread = $this->get_public_json( 'app.bsky.feed.getPostThread', [
			'uri'          => $at_uri,
			'depth'        => 0,
			'parentHeight' => 0,
		] );

		$node = is_array( $thread['thread'] ?? null ) ? $thread['thread'] : [];
		$post = is_array( $node['post'] ?? null ) ? $node['post'] : [];
		if ( empty( $post['uri'] ) || empty( $post['cid'] ) ) {
			return null;
		}

		$parent = [ 'uri' => (string) $post['uri'], 'cid' => (string) $post['cid'] ];

		// If the parent is itself a reply, thread under its root; else it is the root.
		$record   = is_array( $post['record'] ?? null ) ? $post['record'] : [];
		$reply    = is_array( $record['reply'] ?? null ) ? $record['reply'] : [];
		$root_ref = is_array( $reply['root'] ?? null ) ? $reply['root'] : [];
		$root     = ( ! empty( $root_ref['uri'] ) && ! empty( $root_ref['cid'] ) )
			? [ 'uri' => (string) $root_ref['uri'], 'cid' => (string) $root_ref['cid'] ]
			: $parent;

		return [ 'root' => $root, 'parent' => $parent ];
	}

	/**
	 * Resolves a bsky.app post URL to its at:// URI. The profile segment may be a
	 * DID (used as-is) or a handle (resolved via com.atproto.identity.resolveHandle).
	 */
	private function resolve_at_uri( string $bsky_url ): ?string {
		if ( ! preg_match( '~/profile/([^/]+)/post/([^/?#]+)~', $bsky_url, $m ) ) {
			return null;
		}
		$authority = $m[1];
		$rkey      = $m[2];
		$did       = str_starts_with( $authority, 'did:' )
			? $authority
			: (string) ( $this->get_public_json( 'com.atproto.identity.resolveHandle', [ 'handle' => $authority ] )['did'] ?? '' );
		return '' !== $did ? "at://{$did}/app.bsky.feed.post/{$rkey}" : null;
	}

	/** GETs a public AppView XRPC method and returns the decoded array (or []). */
	private function get_public_json( string $method, array $params ): array {
		$response = wp_remote_get(
			'https://public.api.bsky.app/xrpc/' . $method . '?' . http_build_query( $params ),
			[ 'timeout' => 15 ]
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : [];
	}

	private function upload_thumb( int $post_id, array $session ): ?array {
		$map_url = (string) get_post_meta( $post_id, 'nop_indieweb_map_url', true );

		// Syndication runs on an async cron event (see Syndication_Manager) that
		// can fire before Service_Swarm::after_insert() has finished caching the
		// map. Rather than trust that ordering, generate-or-fetch the map here
		// when it's missing but the post carries venue coordinates and a Geoapify
		// key is configured. The helper is idempotent and self-caching, so this
		// also back-fills any historical checkin whose map landed late.
		if ( '' === $map_url ) {
			$lat = (float) get_post_meta( $post_id, 'nop_indieweb_venue_lat', true );
			$lng = (float) get_post_meta( $post_id, 'nop_indieweb_venue_lng', true );
			$key = trim( (string) \NOP\IndieWeb\nop_indieweb_get_option( 'maps.geoapify_api_key', '' ) );
			if ( ( $lat || $lng ) && '' !== $key ) {
				$map_url = \NOP\IndieWeb\nop_indieweb_get_or_cache_map_image( $post_id, $lat, $lng, 620, 310, $key );
			}
		}

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
		if ( $thumbnail_id ) {
			$src = wp_get_attachment_image_src( $thumbnail_id, 'medium' );
			if ( $src ) {
				return $this->upload_image_blob( [
					'url'           => (string) $src[0],
					'alt'           => '',
					'attachment_id' => $thumbnail_id,
					'width'         => (int) ( $src[1] ?? 0 ),
					'height'        => (int) ( $src[2] ?? 0 ),
				], $session );
			}
		}

		// Fall back to the site icon (the author's portrait) so link cards always
		// carry an identity image — mirrors the Open Graph image fallback chain.
		$icon_id = (int) get_option( 'site_icon' );
		if ( ! $icon_id ) {
			return null;
		}
		$src = wp_get_attachment_image_src( $icon_id, 'large' );
		if ( ! $src ) {
			return null;
		}
		return $this->upload_image_blob( [
			'url'           => (string) $src[0],
			'alt'           => '',
			'attachment_id' => $icon_id,
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
			if ( strlen( $fetched['data'] ) > self::BLOB_CAP_BYTES ) {
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
	 * Resolves a video file into a Bluesky blob ref for an embed.
	 *
	 * MP4s go through Bluesky's dedicated video service (transcoded *before* the
	 * post is created, so it plays immediately and the 100 MB / 3 min service
	 * limits apply). If that path fails — or the clip isn't MP4 — it falls back
	 * to a raw com.atproto.repo.uploadBlob, which still works for small clips
	 * within the PDS blob cap. Returns null when neither succeeds; the caller
	 * then degrades to a link card.
	 */
	private function upload_video_blob( array $video, array $session ): ?array {
		$fetched = $this->fetch_upload_video( $video );
		if ( ! $fetched ) {
			return null;
		}

		if ( 'video/mp4' === $fetched['mime'] ) {
			$blob = $this->upload_video_via_service( $fetched['data'], $session, (int) ( $video['attachment_id'] ?? 0 ) );
			if ( $blob ) {
				return $blob;
			}
		}

		return $this->upload_blob( $fetched['data'], $fetched['mime'], $session );
	}

	/**
	 * Uploads an MP4 to Bluesky's video service and returns the processed blob.
	 *
	 * Flow (per docs.bsky.app/docs/tutorials/video): mint a service-auth token
	 * scoped to the video service, POST the bytes to video.bsky.app, then poll
	 * getJobStatus until the optimised blob is ready. Safe to block — syndication
	 * runs on the cron worker, not a web request. Returns null on any failure.
	 */
	private function upload_video_via_service( string $data, array $session, int $attachment_id ): ?array {
		$token = $this->get_video_service_auth( $session );
		if ( ! $token ) {
			return null;
		}

		// A name unique per DID dodges the service's "already_exists" on a retry.
		$name = 'nop-' . $attachment_id . '-' . substr( md5( $data ), 0, 12 ) . '.mp4';

		$upload = wp_remote_post(
			'https://video.bsky.app/xrpc/app.bsky.video.uploadVideo?' . http_build_query( [
				'did'  => $session['did'],
				'name' => $name,
			] ),
			[
				'headers' => [
					'Authorization'  => 'Bearer ' . $token,
					'Content-Type'   => 'video/mp4',
					'Content-Length' => (string) strlen( $data ),
				],
				'body'    => $data,
				'timeout' => 120,
			]
		);
		if ( is_wp_error( $upload ) ) {
			\NOP\IndieWeb\nop_indieweb_log( 'Bluesky video uploadVideo failed', [ 'error' => $upload->get_error_message() ] );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $upload ), true );
		// uploadVideo returns a jobStatus at the top level; a 409 already_exists
		// still carries a jobId we can poll. The job may already hold the blob.
		$job = is_array( $body ) ? ( $body['jobStatus'] ?? $body ) : [];
		if ( ! empty( $job['blob'] ) ) {
			return $job['blob'];
		}
		$job_id = $job['jobId'] ?? null;
		if ( ! $job_id ) {
			\NOP\IndieWeb\nop_indieweb_log( 'Bluesky video uploadVideo: no jobId', [
				'code' => wp_remote_retrieve_response_code( $upload ),
				'body' => $body,
			] );
			return null;
		}

		return $this->poll_video_job( (string) $job_id );
	}

	/**
	 * Mints a service-auth token scoped to the Bluesky video service, signed with
	 * the current app-password session. getServiceAuth is an XRPC query (GET).
	 */
	private function get_video_service_auth( array $session ): ?string {
		$response = wp_remote_get(
			$this->pds() . '/xrpc/com.atproto.server.getServiceAuth?' . http_build_query( [
				'aud' => 'did:web:video.bsky.app',
				'lxm' => 'com.atproto.repo.uploadBlob',
				'exp' => time() + 30 * MINUTE_IN_SECONDS,
			] ),
			[
				'headers' => [ 'Authorization' => 'Bearer ' . $session['accessJwt'] ],
				'timeout' => 15,
			]
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			\NOP\IndieWeb\nop_indieweb_log( 'Bluesky getServiceAuth failed', [
				'code' => is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response ),
			] );
			return null;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return ! empty( $body['token'] ) ? (string) $body['token'] : null;
	}

	/**
	 * Polls app.bsky.video.getJobStatus until the processed blob is ready, the
	 * job fails, or a ~90 s budget is hit. getJobStatus is a public endpoint
	 * (no auth). Returns the blob ref, or null.
	 */
	private function poll_video_job( string $job_id ): ?array {
		$url      = 'https://video.bsky.app/xrpc/app.bsky.video.getJobStatus?' . http_build_query( [ 'jobId' => $job_id ] );
		$deadline = time() + 90;

		while ( time() < $deadline ) {
			sleep( 3 );
			$response = wp_remote_get( $url, [ 'timeout' => 15 ] );
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$job  = is_array( $body ) ? ( $body['jobStatus'] ?? [] ) : [];
			if ( ! empty( $job['blob'] ) ) {
				return $job['blob'];
			}
			if ( 'JOB_STATE_FAILED' === ( $job['state'] ?? '' ) ) {
				\NOP\IndieWeb\nop_indieweb_log( 'Bluesky video job failed', $job );
				return null;
			}
		}
		\NOP\IndieWeb\nop_indieweb_log( 'Bluesky video job timed out', [ 'jobId' => $job_id ] );
		return null;
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

	/**
	 * Builds tag facets for every #hashtag the author typed, so they become
	 * real searchable tags on Bluesky (which, unlike Mastodon, does not
	 * auto-link hashtags). Byte offsets — required by the AT Protocol facet
	 * spec — are read directly from PREG_OFFSET_CAPTURE, which reports byte
	 * positions even under the /u modifier, so multibyte text (emoji, accents)
	 * is handled correctly. Purely numeric tags are skipped (Bluesky rejects
	 * them); the tag value sent omits the leading '#'.
	 */
	private function build_hashtag_facets( string $text ): array {
		if ( ! preg_match_all(
			'/(?:^|\s)([#＃][\p{L}\p{N}][\p{L}\p{N}_-]*)/u',
			$text,
			$matches,
			PREG_OFFSET_CAPTURE
		) ) {
			return [];
		}

		$facets = [];
		foreach ( $matches[1] as $match ) {
			$hashtag    = $match[0];          // e.g. "#belfast"
			$byte_start = $match[1];          // byte offset of the leading #
			$tag        = (string) mb_substr( $hashtag, 1 ); // strip # or ＃

			if ( '' === $tag || ctype_digit( $tag ) || mb_strlen( $tag ) > 64 ) {
				continue;
			}

			$facets[] = [
				'index'    => [
					'byteStart' => $byte_start,
					'byteEnd'   => $byte_start + strlen( $hashtag ),
				],
				'features' => [
					[ '$type' => 'app.bsky.richtext.facet#tag', 'tag' => $tag ],
				],
			];
		}
		return $facets;
	}

	/**
	 * The post's publish time as an ISO 8601 UTC string, so the Bluesky record's
	 * createdAt matches the canonical post date (rather than the syndication
	 * moment, which would drift for backdated or imported posts).
	 */
	private function post_created_at( int $post_id ): string {
		$gmt = (string) get_post_field( 'post_date_gmt', $post_id );
		if ( '' === $gmt || '0000-00-00 00:00:00' === $gmt ) {
			return gmdate( 'c' );
		}
		$ts = strtotime( $gmt . ' GMT' );
		return $ts ? gmdate( 'c', $ts ) : gmdate( 'c' );
	}

	/**
	 * The site's language as a short BCP-47 subtag (e.g. "en" from "en_GB"),
	 * for the record's langs field. Portable across sites rather than hardcoded.
	 */
	private function post_lang(): string {
		$lang = substr( (string) get_locale(), 0, 2 );
		return '' !== $lang ? $lang : 'en';
	}

	private function create_session(): array|\WP_Error {
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

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'nop_syndication_failed', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			return new \WP_Error( 'nop_syndication_failed', sprintf(
				/* translators: 1: HTTP status code, 2: error detail from the Bluesky API */
				__( 'Bluesky sign-in failed — HTTP %1$d: %2$s', 'nop-indieweb' ),
				$code,
				$body['message'] ?? __( 'unknown error', 'nop-indieweb' )
			) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['accessJwt'] ) ) {
			return new \WP_Error( 'nop_syndication_failed', __( 'Bluesky returned an unreadable session.', 'nop-indieweb' ) );
		}
		return $data;
	}
}
