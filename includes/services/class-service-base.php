<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Services;

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
	 * Full create lifecycle. Override only if a service needs a different sequence.
	 */
	public function handle( array $payload ): int|WP_Error {
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

		$this->after_insert( $post_id, $parsed );

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

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$cap_bytes      = (int) apply_filters( 'nop_indieweb_photo_size_cap', 25 * 1024 * 1024 );
		$attachment_ids = [];
		$set_featured   = ! has_post_thumbnail( $post_id );

		foreach ( $photos as $photo ) {
			$url = $this->select_photo_url( $photo, $cap_bytes );
			if ( '' === $url ) {
				continue;
			}

			$id = media_sideload_image( $url, $post_id, '', 'id' );
			if ( is_wp_error( $id ) ) {
				\NOP\IndieWeb\nop_indieweb_log( "Photo sideload failed: {$url}", $id->get_error_message() );
				continue;
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
			'meta_query'     => [ [
				'key'   => $meta_key,
				'value' => $key,
			] ],
		] );
		return ! empty( $posts ) ? (int) $posts[0] : null;
	}

	/**
	 * Builds WordPress image/gallery block markup from sideloaded attachment IDs or fallback URLs.
	 * Pass $ids when photos have been sideloaded; pass $urls as a fallback for remote references.
	 */
	protected function build_photo_blocks( array $ids, array $urls ): string {
		if ( $ids ) {
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
			if ( 1 === count( $urls ) ) {
				return sprintf(
					"<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"%s\" alt=\"\"/></figure>\n<!-- /wp:image -->",
					esc_url( $urls[0] )
				);
			}
			$inner = '';
			foreach ( $urls as $url ) {
				$inner .= sprintf(
					"\n<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"%s\" alt=\"\"/></figure>\n<!-- /wp:image -->",
					esc_url( $url )
				);
			}
			return "<!-- wp:gallery {\"columns\":2,\"linkTo\":\"none\"} -->\n<figure class=\"wp-block-gallery has-nested-images columns-2 is-cropped\">{$inner}\n</figure>\n<!-- /wp:gallery -->";
		}

		return '';
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

		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$cap_bytes = (int) apply_filters( 'nop_indieweb_video_size_cap', 100 * 1024 * 1024 );
		$ids       = [];

		foreach ( $videos as $video ) {
			if ( ! is_array( $video ) ) {
				$video = [ 'primary' => (string) $video ];
			}
			$url  = (string) ( $video['primary'] ?? '' );
			$size = (int) ( $video['size'] ?? 0 );
			$alt  = (string) ( $video['alt'] ?? '' );

			if ( '' === $url ) {
				continue;
			}
			if ( $size > 0 && $size > $cap_bytes ) {
				\NOP\IndieWeb\nop_indieweb_log( 'Video blob over cap, skipping sideload', [
					'size' => $size,
					'cap'  => $cap_bytes,
					'url'  => $url,
				] );
				continue;
			}

			$tmp = download_url( $url, 600 );
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
				@unlink( $tmp );
				continue;
			}

			if ( '' !== $alt ) {
				update_post_meta( (int) $id, '_wp_attachment_image_alt', $alt );
			}
			$ids[] = (int) $id;
		}

		return $ids;
	}

	/**
	 * Builds core/video block markup for a list of sideloaded video attachment IDs.
	 */
	protected function build_video_blocks( array $ids ): string {
		$blocks = [];
		foreach ( $ids as $id ) {
			$src = wp_get_attachment_url( $id );
			if ( ! $src ) {
				continue;
			}
			$blocks[] = sprintf(
				"<!-- wp:video {\"id\":%d} -->\n<figure class=\"wp-block-video\"><video controls src=\"%s\"></video></figure>\n<!-- /wp:video -->",
				$id,
				esc_url( $src )
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
}
