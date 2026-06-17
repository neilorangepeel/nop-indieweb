<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Services;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use NOP\IndieWeb\Kind\Kind_Taxonomy;
use WP_Error;

/**
 * Base class for all IndieWeb services (Swarm, Mastodon, Bluesky, etc.).
 *
 * To add a new service:
 *   1. Create a class in includes/services/ that extends Service_Base.
 *   2. Implement all abstract methods.
 *   3. Register it via the `nop_indieweb_register_services` filter in class-plugin.php.
 *
 * Lifecycle for every incoming Micropub POST:
 *   can_handle() → parse() → [dedup check] → map_to_post() → get_meta() → handle() → after_insert()
 */
abstract class Service_Base {

	abstract public function get_name(): string;
	abstract public function get_slug(): string;
	abstract public function can_handle( array $payload ): bool;
	abstract public function parse( array $payload ): array;
	abstract public function map_to_post( array $parsed ): array;
	abstract public function get_meta( array $parsed ): array;
	/**
	 * Returns the nop_kind taxonomy slug for posts created by this service.
	 * Override in subclasses. An empty string means no kind term is assigned.
	 */
	public function get_kind( array $parsed = [] ): string {
		return '';
	}

	/**
	 * Returns a string that uniquely identifies this payload for duplicate detection.
	 * Return null to skip the check (default — opt-in per service).
	 */
	protected function get_dedup_key( array $parsed ): ?string {
		return null;
	}

	/**
	 * The post meta key used to store and query the dedup value.
	 * Override in subclasses that use a different meta field.
	 */
	protected function get_dedup_meta_key(): string {
		return 'nop_indieweb_checkin_url';
	}

	/**
	 * Called after the post, meta, and format have all been saved.
	 * Override in a service for post-creation work (photo sideloading, etc.).
	 */
	protected function after_insert( int $post_id, array $parsed ): void {}

	/**
	 * Public entry point for the background cron dispatcher in Plugin.
	 * Calls after_insert() with the parsed data retrieved from the transient.
	 */
	public function run_after_insert( int $post_id, array $parsed ): void {
		$this->after_insert( $post_id, $parsed );
	}

	/**
	 * Full create lifecycle. Override only if a service needs a different sequence.
	 *
	 * @param int $author_id  When > 0, the inserted post's post_author is set to
	 *                        this user. The Micropub endpoint passes the token's
	 *                        owning user; cron importers omit it and the resolver
	 *                        falls back to the current user or the configured
	 *                        default (filter `nop_indieweb_default_author_id`,
	 *                        defaulting to user 1).
	 */
	public function handle( array $payload, int $author_id = 0 ): int|WP_Error {
		$parsed = $this->parse( $payload );

		// Idempotency — return the existing post if we've seen this payload before.
		// This handles OwnYourSwarm retries on network failure.
		$dedup_key = $this->get_dedup_key( $parsed );
		if ( $dedup_key ) {
			$existing = $this->find_by_dedup_key( $dedup_key, $this->get_dedup_meta_key() );
			if ( $existing ) {
				\NOP\IndieWeb\nop_indieweb_log( "Duplicate detected ({$dedup_key}) — returning existing post {$existing}" );
				return $existing;
			}
		}

		$post_args = $this->map_to_post( $parsed );

		// Stamp the post author so mf2 author h-card emission and capability
		// checks have a real user to work with.
		$post_args['post_author'] = $this->resolve_author_id( $author_id );

		// Include service meta in meta_input so it is committed before
		// wp_after_insert_post fires — the syndication manager reads
		// nop_indieweb_service to decide whether to skip the post.
		$post_args['meta_input'] = array_merge(
			$this->get_meta( $parsed ),
			[ 'nop_indieweb_service' => $this->get_slug() ]
		);

		$post_args = apply_filters( 'nop_indieweb_before_post_insert', $post_args, $parsed, $this );

		$post_id = wp_insert_post( $post_args, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$kind = $this->get_kind( $parsed );
		if ( $kind ) {
			wp_set_object_terms( $post_id, $kind, Kind_Taxonomy::TAXONOMY );
		}

		set_transient( 'nop_indieweb_last_payload', $payload, DAY_IN_SECONDS );
		set_transient( 'nop_indieweb_last_post_id', $post_id, DAY_IN_SECONDS );

		\NOP\IndieWeb\nop_indieweb_log( "Post created via {$this->get_slug()}", [ 'post_id' => $post_id ] );

		// Queue after_insert work (weather, map, photo sideloading) as a background
		// cron job so the Micropub 201 response is sent immediately. OwnYourSwarm has
		// a ~30 s timeout; running heavy I/O synchronously causes retries that create
		// duplicate posts. The parsed data is stashed in a transient so the cron
		// callback can reconstruct the full context without re-parsing the payload.
		set_transient( 'nop_ia_parsed_' . $post_id, $parsed, HOUR_IN_SECONDS );
		wp_schedule_single_event( time(), 'nop_indieweb_run_after_insert', [ $post_id, $this->get_slug() ] );

		return $post_id;
	}

	/**
	 * Sideloads remote photos into the WordPress media library.
	 *
	 * Each entry may be either:
	 *   - a string URL (legacy form, used by Swarm/Mastodon/Letterboxd)
	 *   - an array { primary: string, fallback?: string, size?: int } (Bluesky)
	 *
	 * The array form lets us prefer the original blob and fall back to a
	 * CDN-resized version when the original would blow past the size cap. The
	 * cap is filterable via the `nop_indieweb_photo_size_cap` filter (default
	 * 25 MB per image).
	 *
	 * Returns attachment IDs for successfully imported images.
	 * Sets the first photo as the post's featured image if none is set.
	 */
	protected function sideload_photos( array $photos, int $post_id ): array {
		if ( ! $photos ) {
			return [];
		}

		$this->require_media_includes();

		$cap_bytes      = (int) apply_filters( 'nop_indieweb_photo_size_cap', 25 * 1024 * 1024 );
		$attachment_ids = [];
		$set_featured   = ! has_post_thumbnail( $post_id );

		foreach ( $photos as $photo ) {
			$url = $this->select_photo_url( $photo, $cap_bytes );
			if ( '' === $url ) {
				continue;
			}

			// A URL already in this site's media library (the /post client
			// uploads via REST before the Micropub call) is reused as-is —
			// re-downloading our own URL would duplicate the file.
			$existing = attachment_url_to_postid( $url );
			if ( $existing ) {
				if ( 0 === (int) get_post( $existing )->post_parent ) {
					wp_update_post( [ 'ID' => $existing, 'post_parent' => $post_id ] );
				}
				$id = $existing;
			} else {
				$tmp = $this->safe_download_to_tmp( $url, $cap_bytes );
				// NOP: needs review — when an array-form photo's chosen (primary) blob
				// exceeds the cap it is rejected here even if a smaller `fallback` CDN
				// URL exists; the fallback is only consulted before download, never
				// after a nop_too_large rejection. Consider retrying the fallback.
				if ( is_wp_error( $tmp ) ) {
					\NOP\IndieWeb\nop_indieweb_log( "Photo sideload failed: {$url}", $tmp->get_error_message() );
					continue;
				}

				$file = [
					'name'     => $this->safe_basename_from_url( $url, 'jpg' ),
					'tmp_name' => $tmp,
				];

				$id = media_handle_sideload( $file, $post_id );
				if ( is_wp_error( $id ) ) {
					\NOP\IndieWeb\nop_indieweb_log( "Photo sideload failed: {$url}", $id->get_error_message() );
					wp_delete_file( $tmp );
					continue;
				}
			}
			$attachment_ids[] = (int) $id;

			$alt = is_array( $photo ) ? (string) ( $photo['alt'] ?? '' ) : '';
			if ( '' !== $alt ) {
				update_post_meta( (int) $id, '_wp_attachment_image_alt', $alt );
			}

			if ( $set_featured ) {
				set_post_thumbnail( $post_id, $id );
				$set_featured = false;
			}
		}

		return $attachment_ids;
	}

	private function require_media_includes(): void {
		if ( function_exists( 'media_handle_sideload' ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	/**
	 * Streams a URL to a tmp file with both SSRF protection and a hard byte cap.
	 *
	 * Uses wp_safe_remote_get under the hood, which honours WordPress's
	 * http_request_host_is_external filter — rejects loopback, link-local, and
	 * RFC1918 hosts that an attacker-controlled feed could try to coerce us
	 * into fetching. limit_response_size truncates the body at $cap_bytes.
	 *
	 * @return string|WP_Error  Temporary file path on success.
	 */
	protected function safe_download_to_tmp( string $url, int $cap_bytes, int $timeout = 60 ): string|WP_Error {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = wp_tempnam( $url );
		if ( ! $tmp ) {
			return new WP_Error( 'nop_tmpnam_failed', 'Could not allocate temp file.' );
		}

		// Stream with manual redirect chasing: each hop is one streaming
		// wp_safe_remote_get (which re-validates the URL against private IPs).
		// If the response is a redirect, the streamed body is a small HTML page —
		// we truncate it before following the Location to the next hop. In the
		// common no-redirect case (every regular API/CDN media URL), this is
		// ONE network round-trip with zero overhead vs the previous code.
		$visited = [];
		$max_hops = 3;

		for ( $hop = 0; $hop <= $max_hops; $hop++ ) {
			$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
			if ( 'https' !== $scheme && 'http' !== $scheme ) {
				wp_delete_file( $tmp );
				return new WP_Error( 'nop_invalid_scheme', 'URL must use http(s).' );
			}

			if ( ! \NOP\IndieWeb\nop_indieweb_is_safe_url( $url ) ) {
				wp_delete_file( $tmp );
				return new WP_Error( 'nop_blocked_ip', 'URL resolves to a private or reserved IP range.' );
			}

			$url_key = strtolower( $url );
			if ( isset( $visited[ $url_key ] ) ) {
				wp_delete_file( $tmp );
				return new WP_Error( 'nop_redirect_loop', 'Redirect loop.' );
			}
			$visited[ $url_key ] = true;

			$response = wp_safe_remote_get( $url, [
				'timeout'             => $timeout,
				'redirection'         => 0,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => $cap_bytes,
			] );

			if ( is_wp_error( $response ) ) {
				wp_delete_file( $tmp );
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 300 ) {
				break;
			}
			// Anything that isn't a 3xx redirect is an error (2xx already broke out).
			if ( $code >= 400 || $code < 300 ) {
				wp_delete_file( $tmp );
				return new WP_Error( 'nop_bad_status', "Upstream returned HTTP {$code}." );
			}

			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( ! $location ) {
				wp_delete_file( $tmp );
				return new WP_Error( 'nop_bad_redirect', '3xx without Location header.' );
			}
			$next = \WP_Http::make_absolute_url( $location, $url );
			if ( ! is_string( $next ) || '' === $next ) {
				wp_delete_file( $tmp );
				return new WP_Error( 'nop_bad_redirect', 'Could not resolve redirect target.' );
			}

			// Discard the redirect page body before following.
			if ( file_exists( $tmp ) ) {
				file_put_contents( $tmp, '' );
			}

			$url = $next;

			if ( $hop === $max_hops ) {
				wp_delete_file( $tmp );
				return new WP_Error( 'nop_too_many_redirects', 'Exceeded redirect cap.' );
			}
		}

		$bytes = @filesize( $tmp );
		if ( false === $bytes || $bytes === 0 ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'nop_empty_body', 'Downloaded file was empty.' );
		}
		if ( $bytes > $cap_bytes ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'nop_too_large', "File exceeds cap ({$bytes} > {$cap_bytes})." );
		}

		return $tmp;
	}

	/**
	 * Returns a safe filename for sideloading. Strips any path/query so a
	 * remote-controlled URL cannot influence where the file lands; falls back
	 * to a hashed name when the URL has no usable basename or when the
	 * extension isn't a recognised media type (e.g. API paths like
	 * "com.atproto.sync.getBlob" which look like filenames but aren't).
	 */
	private function safe_basename_from_url( string $url, string $default_ext ): string {
		static $known_ext = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'mp4', 'mov', 'webm', 'mp3', 'pdf' ];

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$name = sanitize_file_name( basename( $path ) );
		$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( '' === $name || '.' === $name || str_starts_with( $name, '.' ) || ! in_array( $ext, $known_ext, true ) ) {
			$name = 'media-' . substr( md5( $url ), 0, 12 ) . '.' . $default_ext;
		}
		return $name;
	}

	/**
	 * Resolves a photo entry to the URL we should actually sideload.
	 * Strings pass through as-is. For array entries, picks primary unless the
	 * advertised size exceeds the cap — in which case fallback is used (if
	 * supplied). No HTTP round-trip for the size check; relies on the source
	 * payload carrying the byte count.
	 */
	private function select_photo_url( mixed $photo, int $cap_bytes ): string {
		if ( is_string( $photo ) ) {
			return $photo;
		}
		if ( ! is_array( $photo ) ) {
			return '';
		}

		$primary  = (string) ( $photo['primary'] ?? '' );
		$fallback = (string) ( $photo['fallback'] ?? '' );
		$size     = (int) ( $photo['size'] ?? 0 );

		if ( '' === $primary ) {
			return $fallback;
		}
		if ( '' !== $fallback && $size > 0 && $size > $cap_bytes ) {
			\NOP\IndieWeb\nop_indieweb_log( 'Photo blob over cap, falling back to CDN', [
				'size' => $size,
				'cap'  => $cap_bytes,
			] );
			return $fallback;
		}
		return $primary;
	}

	private function find_by_dedup_key( string $key, string $meta_key ): ?int {
		$posts = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- low-frequency meta/taxonomy lookup (import, admin, or per-post render cache), not a hot path
			'meta_query'     => [ [
				'key'   => $meta_key,
				'value' => $key,
			] ],
		] );
		return ! empty( $posts ) ? (int) $posts[0] : null;
	}

	/**
	 * Builds WordPress image/gallery block markup from sideloaded attachment IDs or fallback URLs.
	 *
	 * Pass $ids when photos have been sideloaded — WordPress reads alt text from the
	 * attachment meta at render time, so alt="" in the block markup is correct.
	 *
	 * Pass $urls as a fallback for remote CDN references (sideloading disabled). In
	 * that case the img tag is the final rendered HTML, so $default_alt provides a
	 * venue-context string (e.g. "Photo taken at The Crown Bar, Belfast") that is
	 * applied to every image in the set.
	 */
	protected function build_photo_blocks( array $ids, array $urls, string $default_alt = '' ): string {
		if ( $ids ) {
			// alt="" is intentional for sideloaded images: WP reads alt from attachment meta.
			if ( 1 === count( $ids ) ) {
				$src = wp_get_attachment_url( $ids[0] );
				return sprintf(
					"<!-- wp:image {\"id\":%d,\"sizeSlug\":\"large\",\"linkDestination\":\"none\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"%s\" alt=\"\" class=\"wp-image-%d\"/></figure>\n<!-- /wp:image -->",
					$ids[0], esc_url( $src ), $ids[0]
				);
			}
			$inner = '';
			foreach ( $ids as $id ) {
				$src    = wp_get_attachment_url( $id );
				$inner .= sprintf(
					"\n<!-- wp:image {\"id\":%d,\"sizeSlug\":\"large\",\"linkDestination\":\"none\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"%s\" alt=\"\" class=\"wp-image-%d\"/></figure>\n<!-- /wp:image -->",
					$id, esc_url( $src ), $id
				);
			}
			return "<!-- wp:gallery {\"columns\":2,\"linkTo\":\"none\"} -->\n<figure class=\"wp-block-gallery has-nested-images columns-2 is-cropped\">{$inner}\n</figure>\n<!-- /wp:gallery -->";
		}

		if ( $urls ) {
			$escaped_alt = esc_attr( $default_alt );
			if ( 1 === count( $urls ) ) {
				return sprintf(
					"<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"%s\" alt=\"%s\"/></figure>\n<!-- /wp:image -->",
					esc_url( $urls[0] ),
					$escaped_alt
				);
			}
			$inner = '';
			foreach ( $urls as $url ) {
				$inner .= sprintf(
					"\n<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"%s\" alt=\"%s\"/></figure>\n<!-- /wp:image -->",
					esc_url( $url ),
					$escaped_alt
				);
			}
			return "<!-- wp:gallery {\"columns\":2,\"linkTo\":\"none\"} -->\n<figure class=\"wp-block-gallery has-nested-images columns-2 is-cropped\">{$inner}\n</figure>\n<!-- /wp:gallery -->";
		}

		return '';
	}

	public function append_photos( int $post_id, array $urls ): void {
		$settings = $this->get_settings();
		$ids      = [];
		if ( ! empty( $settings['sideload_photos'] ) ) {
			$ids = $this->sideload_photos( $urls, $post_id );
			if ( $ids ) {
				$existing = (array) get_post_meta( $post_id, 'nop_indieweb_photo_ids', true );
				update_post_meta( $post_id, 'nop_indieweb_photo_ids', array_merge( $existing, $ids ) );
			}
		}
		$existing = (array) get_post_meta( $post_id, 'nop_indieweb_photos', true );
		update_post_meta( $post_id, 'nop_indieweb_photos', array_merge( $existing, $urls ) );

		$blocks = $this->build_photo_blocks( $ids, $ids ? [] : $urls );
		if ( ! $blocks ) {
			return;
		}
		$post    = get_post( $post_id );
		$current = rtrim( (string) $post->post_content );
		wp_update_post( [
			'ID'           => $post_id,
			'post_content' => ( $current ? $current . "\n\n" : '' ) . $blocks,
		] );
	}

	/**
	 * Sideloads video blobs into the WordPress media library.
	 *
	 * Each entry: { primary: string, size?: int, alt?: string }. Skipped (with a
	 * log entry) when the advertised size exceeds the
	 * `nop_indieweb_video_size_cap` filter (default 100 MB) — videos are big,
	 * and there's no useful CDN fallback to swap in like there is for photos.
	 *
	 * Returns attachment IDs for successfully imported videos.
	 */
	protected function sideload_videos( array $videos, int $post_id ): array {
		if ( ! $videos ) {
			return [];
		}

		$this->require_media_includes();

		$cap_bytes = (int) apply_filters( 'nop_indieweb_video_size_cap', 100 * 1024 * 1024 );
		$ids       = [];

		foreach ( $videos as $video ) {
			if ( ! is_array( $video ) ) {
				$video = [ 'primary' => (string) $video ];
			}
			$url    = (string) ( $video['primary'] ?? '' );
			$size   = (int) ( $video['size'] ?? 0 );
			$alt    = (string) ( $video['alt'] ?? '' );
			$poster = (string) ( $video['poster'] ?? '' );

			if ( '' === $url ) {
				continue;
			}

			// A URL already in this site's media library (the /post client uploads
			// the clip via REST before the Micropub call) is reused as-is — re-parented
			// to the post rather than re-downloaded, which would duplicate the file.
			$existing = attachment_url_to_postid( $url );
			if ( $existing ) {
				if ( 0 === (int) get_post( $existing )->post_parent ) {
					wp_update_post( [ 'ID' => $existing, 'post_parent' => $post_id ] );
				}
				$id = $existing;
			} else {
				// $size is sender-supplied and can lie — it's only used as an early
				// reject hint. The real cap is enforced by safe_download_to_tmp().
				if ( $size > 0 && $size > $cap_bytes ) {
					\NOP\IndieWeb\nop_indieweb_log( 'Video blob over cap, skipping sideload', [
						'size' => $size,
						'cap'  => $cap_bytes,
						'url'  => $url,
					] );
					continue;
				}

				$tmp = $this->safe_download_to_tmp( $url, $cap_bytes, 120 );
				if ( is_wp_error( $tmp ) ) {
					\NOP\IndieWeb\nop_indieweb_log( 'Video download failed', [
						'url'   => $url,
						'error' => $tmp->get_error_message(),
					] );
					continue;
				}

				$file = [
					'name'     => 'video-' . substr( md5( $url ), 0, 12 ) . '.mp4',
					'tmp_name' => $tmp,
				];

				$id = media_handle_sideload( $file, $post_id );
				if ( is_wp_error( $id ) ) {
					\NOP\IndieWeb\nop_indieweb_log( 'Video sideload failed', [
						'url'   => $url,
						'error' => $id->get_error_message(),
					] );
					wp_delete_file( $tmp );
					continue;
				}
			}

			if ( '' !== $alt ) {
				// WordPress video attachments don't expose an "Alt text" field in
				// the Media Library admin (image-only UI). Mirror the alt into
				// post_excerpt so it lands in the Caption field and is editable
				// in admin, while keeping _wp_attachment_image_alt for REST
				// consumers and consistency with photos.
				update_post_meta( (int) $id, '_wp_attachment_image_alt', $alt );
				wp_update_post( [
					'ID'           => (int) $id,
					'post_excerpt' => $alt,
				] );
			}

			// Poster frame (e.g. a story's captured first frame): sideload/reuse it as
			// an image — this also sets it as the post's featured image (the rail + grid
			// thumbnail) when none is set — and record its URL on the video attachment so
			// build_video_blocks() can emit a poster="" attribute.
			if ( '' !== $poster ) {
				$poster_ids = $this->sideload_photos( [ $poster ], $post_id );
				$poster_id  = $poster_ids[0] ?? 0;
				if ( $poster_id ) {
					$poster_url = (string) wp_get_attachment_url( $poster_id );
					if ( '' !== $poster_url ) {
						update_post_meta( (int) $id, '_nop_video_poster', $poster_url );
					}
				}
			}

			$ids[] = (int) $id;
		}

		return $ids;
	}

	/**
	 * Builds core/video block markup for a list of sideloaded video attachment IDs.
	 * When an attachment has a caption (post_excerpt), render it as a figcaption
	 * so the alt text is visible on the public post too.
	 */
	protected function build_video_blocks( array $ids ): string {
		$blocks = [];
		foreach ( $ids as $id ) {
			$src = wp_get_attachment_url( $id );
			if ( ! $src ) {
				continue;
			}
			$caption  = (string) get_post_field( 'post_excerpt', $id );
			$fig_html = '' !== $caption
				? '<figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption>'
				: '';
			$poster      = (string) get_post_meta( $id, '_nop_video_poster', true );
			$poster_attr = '' !== $poster ? sprintf( ' poster="%s"', esc_url( $poster ) ) : '';
			$blocks[]    = sprintf(
				"<!-- wp:video {\"id\":%d} -->\n<figure class=\"wp-block-video\"><video controls%s src=\"%s\"></video>%s</figure>\n<!-- /wp:video -->",
				$id,
				$poster_attr,
				esc_url( $src ),
				$fig_html
			);
		}
		return implode( "\n\n", $blocks );
	}

	/**
	 * Parses a published timestamp into [ local_date, gmt_date ] strings for wp_insert_post.
	 * Pass $guard_future = true to discard timestamps more than 60 s in the future
	 * (protects against OwnYourSwarm timezone drift scheduling posts as 'future').
	 *
	 * @return array{ 0: string, 1: string }  [ post_date, post_date_gmt ] — both empty on failure.
	 */
	protected function parse_post_date( string $published, bool $guard_future = false ): array {
		if ( ! $published ) {
			return [ '', '' ];
		}
		$ts = strtotime( $published );
		if ( ! $ts ) {
			return [ '', '' ];
		}
		if ( $guard_future && $ts > ( time() + 60 ) ) {
			return [ '', '' ];
		}
		return [
			get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ) ),
			gmdate( 'Y-m-d H:i:s', $ts ),
		];
	}

	/**
	 * Resolves a comma-separated category setting string into an array of category IDs.
	 * Categories that don't exist yet are created.
	 */
	protected function category_ids_from_setting( string $csv, string $default = '' ): array {
		$names = array_filter( array_map( 'trim', explode( ',', $csv ?: $default ) ) );
		return array_values( array_filter( array_map( [ $this, 'ensure_category' ], $names ) ) );
	}

	/**
	 * Resolves a comma-separated tags setting string into an array of tag names.
	 */
	protected function tags_from_setting( string $csv ): array {
		return array_filter( array_map( 'trim', explode( ',', $csv ) ) );
	}

	protected function get_settings(): array {
		$all = \NOP\IndieWeb\nop_indieweb_get_option( 'services', [] );
		return $all[ $this->get_slug() ] ?? [];
	}

	public function is_enabled(): bool {
		return (bool) ( $this->get_settings()['enabled'] ?? true );
	}

	/**
	 * Finds or creates a category by name and returns its term ID.
	 */
	protected function ensure_category( string $name ): int {
		$slug = sanitize_title( $name );
		$term = get_term_by( 'slug', $slug, 'category' );
		if ( $term instanceof \WP_Term ) {
			return $term->term_id;
		}
		$result = wp_insert_term( $name, 'category' );
		return is_wp_error( $result ) ? 0 : (int) $result['term_id'];
	}

	/**
	 * Builds a readable title from a URL: extracts the hostname.
	 * Falls back to the raw URL if parsing fails.
	 */
	protected function domain_from_url( string $url ): string {
		return wp_parse_url( $url, PHP_URL_HOST ) ?: $url;
	}

	/**
	 * Resolves the user ID to stamp on an inserted post.
	 *
	 * Priority:
	 *   1. Explicit $author_id (Micropub endpoint passes the token's user)
	 *   2. get_current_user_id() (admin sync flows have a logged-in user)
	 *   3. Filterable default `nop_indieweb_default_author_id` (user 1)
	 */
	private function resolve_author_id( int $author_id ): int {
		if ( $author_id > 0 ) {
			return $author_id;
		}
		$current = get_current_user_id();
		if ( $current > 0 ) {
			return $current;
		}
		return (int) apply_filters( 'nop_indieweb_default_author_id', 1 );
	}
}
