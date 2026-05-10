<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Services;

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
	abstract public function get_post_format( array $parsed ): string;

	/**
	 * Returns a string that uniquely identifies this payload for duplicate detection.
	 * Return null to skip the check (default — opt-in per service).
	 */
	protected function get_dedup_key( array $parsed ): ?string {
		return null;
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
			$existing = $this->find_by_dedup_key( $dedup_key );
			if ( $existing ) {
				\NOP\IndieWeb\nop_indieweb_log( "Duplicate detected ({$dedup_key}) — returning existing post {$existing}" );
				return $existing;
			}
		}

		$post_args = $this->map_to_post( $parsed );
		$post_args = apply_filters( 'nop_indieweb_before_post_insert', $post_args, $parsed, $this );

		$post_id = wp_insert_post( $post_args, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		foreach ( $this->get_meta( $parsed ) as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		update_post_meta( $post_id, 'nop_indieweb_service', $this->get_slug() );

		$format = $this->get_post_format( $parsed );
		if ( $format && 'standard' !== $format ) {
			set_post_format( $post_id, $format );
		}

		set_transient( 'nop_indieweb_last_payload', $payload, DAY_IN_SECONDS );
		set_transient( 'nop_indieweb_last_post_id', $post_id, DAY_IN_SECONDS );

		\NOP\IndieWeb\nop_indieweb_log( "Post created via {$this->get_slug()}", [ 'post_id' => $post_id ] );

		$this->after_insert( $post_id, $parsed );

		return $post_id;
	}

	/**
	 * Sideloads remote photo URLs into the WordPress media library.
	 *
	 * Returns attachment IDs for successfully imported images.
	 * Sets the first photo as the post's featured image if none is set.
	 */
	protected function sideload_photos( array $photo_urls, int $post_id ): array {
		if ( ! $photo_urls ) {
			return [];
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_ids = [];
		$set_featured   = ! has_post_thumbnail( $post_id );

		foreach ( $photo_urls as $url ) {
			$id = media_sideload_image( $url, $post_id, '', 'id' );
			if ( is_wp_error( $id ) ) {
				\NOP\IndieWeb\nop_indieweb_log( "Photo sideload failed: {$url}", $id->get_error_message() );
				continue;
			}
			$attachment_ids[] = (int) $id;
			if ( $set_featured ) {
				set_post_thumbnail( $post_id, $id );
				$set_featured = false;
			}
		}

		return $attachment_ids;
	}

	/**
	 * Looks up an existing post by the dedup key stored in nop_indieweb_checkin_url meta.
	 */
	private function find_by_dedup_key( string $key ): ?int {
		$posts = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [ [
				'key'   => 'nop_indieweb_checkin_url',
				'value' => $key,
			] ],
		] );
		return ! empty( $posts ) ? (int) $posts[0] : null;
	}

	protected function get_settings(): array {
		$all = \NOP\IndieWeb\nop_indieweb_get_option( 'services', [] );
		return $all[ $this->get_slug() ] ?? [];
	}

	public function is_enabled(): bool {
		return (bool) ( $this->get_settings()['enabled'] ?? true );
	}
}
