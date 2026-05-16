<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Post_Meta;

use WP_Block;

/**
 * Registers the nop-indieweb/post-meta Block Bindings source and injects
 * microformat classes onto bound core blocks at render time.
 *
 * Requires WordPress 6.5+ (Block Bindings API).
 *
 * Usage — two equivalent forms:
 *
 *   Full meta key:
 *     "source": "nop-indieweb/post-meta",
 *     "args": { "key": "nop_indieweb_venue_locality" }
 *
 *   Venue field shorthand (prepends nop_indieweb_venue_):
 *     "source": "nop-indieweb/post-meta",
 *     "args": { "field": "locality" }
 *
 *   Derived field (computed, not stored in meta):
 *     "args": { "field": "locality_country" }  → "Belfast, United Kingdom"
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
 *   nop_indieweb_syndication, nop_indieweb_photos, nop_indieweb_photo_ids
 */
class Block_Bindings {

	/**
	 * Meta key → microformat class injected onto the bound block's wrapper element.
	 * Only applies to content bindings (not url/alt/etc).
	 */
	private const MF2_CLASSES = [
		'nop_indieweb_venue_address'  => 'p-street-address',
		'nop_indieweb_venue_locality' => 'p-locality',
		'nop_indieweb_venue_country'  => 'p-country-name',
	];

	public function register(): void {
		add_action( 'init', [ $this, 'register_source' ] );
		add_filter( 'render_block', [ $this, 'inject_mf2_classes' ], 10, 2 );
	}

	public function register_source(): void {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}

		register_block_bindings_source( 'nop-indieweb/post-meta', [
			'label'              => 'IndieWeb Post Meta',
			'get_value_callback' => [ $this, 'get_value' ],
			// Block context (postId, postType) is declared in block.json usesContext —
			// register_block_bindings_source() does not accept uses_context.
		] );
	}

	/**
	 * Preview values shown in the template editor when no real post is available.
	 * Keyed by field shorthand; also covers the resolved meta key for direct-key usage.
	 */
	private const PREVIEW_VALUES = [
		'address'                         => '46 Great Victoria Street',
		'locality'                        => 'Belfast',
		'country'                         => 'United Kingdom',
		'locality_country'                => 'Belfast, United Kingdom',
		'nop_indieweb_venue_address'      => '46 Great Victoria Street',
		'nop_indieweb_venue_locality'     => 'Belfast',
		'nop_indieweb_venue_country'      => 'United Kingdom',
	];

	public function get_value( array $source_args, WP_Block $block ): ?string {
		$post_id = $block->context['postId'] ?? get_the_ID();

		$field = sanitize_key( $source_args['field'] ?? '' );

		// Venue field shorthand: field="locality" → nop_indieweb_venue_locality
		if ( $field && ! isset( $source_args['key'] ) ) {
			$key = 'nop_indieweb_venue_' . $field;
		} else {
			$key = sanitize_key( $source_args['key'] ?? '' );
		}

		// Try to resolve the real value first.
		if ( $post_id ) {
			// Derived field: locality_country → "Belfast, United Kingdom"
			if ( 'locality_country' === $field ) {
				$locality = get_post_meta( $post_id, 'nop_indieweb_venue_locality', true );
				$country  = get_post_meta( $post_id, 'nop_indieweb_venue_country',  true );
				$parts    = array_filter( [ $locality, $country ] );
				if ( $parts ) {
					return implode( ', ', $parts );
				}
			} elseif ( $key ) {
				$value = get_post_meta( $post_id, $key, true );
				if ( $value ) {
					return is_array( $value ) ? (string) count( $value ) : (string) $value;
				}
			}
		}

		// No value found. In the editor (REST request with context=edit), return
		// a representative preview string so the block shows meaningful placeholder
		// content rather than the binding source label "IndieWeb Post Meta".
		$is_editor = defined( 'REST_REQUEST' ) && REST_REQUEST
			&& isset( $_GET['context'] ) && 'edit' === $_GET['context']; // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $post_id || $is_editor ) {
			return self::PREVIEW_VALUES[ $field ] ?? self::PREVIEW_VALUES[ $key ] ?? null;
		}

		return null;
	}

	/**
	 * Injects microformat classes onto the rendered wrapper of any core block
	 * whose content is bound to a venue meta field via nop-indieweb/post-meta
	 * or core/post-meta. Runs frontend-only (render_block fires only on output).
	 */
	public function inject_mf2_classes( string $html, array $block ): string {
		$binding = $block['attrs']['metadata']['bindings']['content'] ?? null;
		if ( ! $binding ) {
			return $html;
		}

		$source = $binding['source'] ?? '';
		if ( ! in_array( $source, [ 'nop-indieweb/post-meta', 'core/post-meta' ], true ) ) {
			return $html;
		}

		// Resolve the meta key from either form: { key } or { field }.
		$args  = $binding['args'] ?? [];
		$field = sanitize_key( $args['field'] ?? '' );
		$key   = $field && ! isset( $args['key'] )
			? 'nop_indieweb_venue_' . $field
			: sanitize_key( $args['key'] ?? '' );

		if ( ! isset( self::MF2_CLASSES[ $key ] ) ) {
			return $html;
		}

		$class = self::MF2_CLASSES[ $key ];

		// Prepend the mf2 class to the existing class attribute on the first tag.
		$replaced = preg_replace( '/(<\w[^>]+\sclass=")/', '$1' . $class . ' ', $html, 1, $count );
		if ( $count ) {
			return $replaced;
		}

		// Fallback: no class attribute on the wrapper — add one to the opening tag.
		return preg_replace( '/(<\w+)(\s|>)/', '$1 class="' . $class . '"$2', $html, 1 ) ?? $html;
	}
}
