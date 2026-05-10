<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Post_Meta;

use WP_Block;

/**
 * Registers the nop-indieweb/post-meta Block Bindings source.
 *
 * Requires WordPress 6.5+ (Block Bindings API).
 *
 * Usage — bind any supported core block attribute to an IndieWeb meta field:
 *
 *   "metadata": {
 *     "bindings": {
 *       "content": {
 *         "source": "nop-indieweb/post-meta",
 *         "args": { "key": "nop_indieweb_venue_name" }
 *       }
 *     }
 *   }
 *
 * Currently read-only: get_value_callback reads meta and displays it in the editor.
 * set_value_callback (inline editing) is not yet in the allowed property list as of
 * WP 7.0 — add it here once it lands in a stable release.
 *
 * Bindable string keys:
 *   nop_indieweb_venue_name, nop_indieweb_venue_url, nop_indieweb_venue_uid,
 *   nop_indieweb_venue_lat, nop_indieweb_venue_lng, nop_indieweb_venue_altitude,
 *   nop_indieweb_venue_accuracy, nop_indieweb_venue_address,
 *   nop_indieweb_venue_locality, nop_indieweb_venue_region,
 *   nop_indieweb_venue_country, nop_indieweb_venue_postcode,
 *   nop_indieweb_checkin_url, nop_indieweb_service
 *
 * Array keys (returns item count as a string):
 *   nop_indieweb_venue_categories, nop_indieweb_syndication,
 *   nop_indieweb_photos, nop_indieweb_photo_ids
 */
class Block_Bindings {

	public function register(): void {
		add_action( 'init', [ $this, 'register_source' ] );
	}

	public function register_source(): void {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}

		register_block_bindings_source( 'nop-indieweb/post-meta', [
			'label'              => 'IndieWeb Post Meta',
			'get_value_callback' => [ $this, 'get_value' ],
			'uses_context'       => [ 'postId', 'postType' ],
		] );
	}

	public function get_value( array $source_args, WP_Block $block ): ?string {
		$key     = sanitize_key( $source_args['key'] ?? '' );
		$post_id = $block->context['postId'] ?? get_the_ID();

		if ( ! $key || ! $post_id ) {
			return null;
		}

		$value = get_post_meta( $post_id, $key, true );

		if ( is_array( $value ) ) {
			return (string) count( $value );
		}

		return $value ? (string) $value : null;
	}
}
