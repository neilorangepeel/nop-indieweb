<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Services;

/**
 * Handles Micropub RSVP posts.
 * Spec: https://indieweb.org/rsvp
 *
 * Must be registered BEFORE Reply — both use in-reply-to and RSVP
 * is the more specific case (also has an rsvp property).
 */
class RSVP extends Service_Base {

	private const VALID_VALUES = [ 'yes', 'no', 'maybe', 'interested' ];

	public function get_name(): string { return 'RSVP'; }
	public function get_slug(): string { return 'rsvp'; }

	public function can_handle( array $payload ): bool {
		$props = $payload['properties'] ?? [];
		return ! empty( $props['in-reply-to'][0] ) && ! empty( $props['rsvp'][0] );
	}

	public function parse( array $payload ): array {
		$props = $payload['properties'] ?? [];
		$rsvp  = strtolower( sanitize_key( $props['rsvp'][0] ?? '' ) );

		return [
			'in_reply_to' => esc_url_raw( $props['in-reply-to'][0] ?? '' ),
			'rsvp'        => in_array( $rsvp, self::VALID_VALUES, true ) ? $rsvp : 'yes',
			'content'     => sanitize_textarea_field( $props['content'][0] ?? '' ),
			'published'   => sanitize_text_field( $props['published'][0] ?? '' ),
		];
	}

	public function map_to_post( array $parsed ): array {
		$settings      = $this->get_settings();
		$post_date     = '';
		$post_date_gmt = '';

		if ( $parsed['published'] ) {
			$ts = strtotime( $parsed['published'] );
			if ( $ts ) {
				$post_date     = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ) );
				$post_date_gmt = gmdate( 'Y-m-d H:i:s', $ts );
			}
		}

		$blocks = $parsed['content']
			? "<!-- wp:paragraph -->\n<p>" . wp_kses_post( $parsed['content'] ) . "</p>\n<!-- /wp:paragraph -->"
			: '';

		$category_names = array_filter( array_map( 'trim', explode( ',', $settings['post_category'] ?? 'RSVPs' ) ) );
		$category_ids   = array_values( array_filter( array_map( [ $this, 'ensure_category' ], $category_names ) ) );

		$domain = $this->domain_from_url( $parsed['in_reply_to'] );

		$args = [
			'post_title'   => 'RSVP ' . $parsed['rsvp'] . ' · ' . $domain,
			'post_content' => $blocks,
			'post_status'  => $settings['post_status'] ?? 'publish',
			'post_type'    => 'post',
		];

		if ( $category_ids ) {
			$args['post_category'] = $category_ids;
		}
		if ( $post_date ) {
			$args['post_date']     = $post_date;
			$args['post_date_gmt'] = $post_date_gmt;
		}

		return $args;
	}

	public function get_meta( array $parsed ): array {
		return [
			'nop_indieweb_post_kind'   => 'rsvp',
			'nop_indieweb_in_reply_to' => $parsed['in_reply_to'],
			'nop_indieweb_rsvp'        => $parsed['rsvp'],
		];
	}

	public function get_post_format( array $parsed ): string {
		return 'status';
	}
}
