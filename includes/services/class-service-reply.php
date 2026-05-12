<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Services;

/**
 * Handles Micropub in-reply-to posts.
 * Spec: https://indieweb.org/reply
 *
 * Must be registered AFTER RSVP — both detect in-reply-to and RSVP
 * is the more specific case.
 */
class Reply extends Service_Base {

	public function get_name(): string { return 'Reply'; }
	public function get_slug(): string { return 'reply'; }

	public function can_handle( array $payload ): bool {
		$props = $payload['properties'] ?? [];
		return ! empty( $props['in-reply-to'][0] ) && empty( $props['rsvp'][0] );
	}

	public function parse( array $payload ): array {
		$props = $payload['properties'] ?? [];

		return [
			'in_reply_to' => esc_url_raw( $props['in-reply-to'][0] ?? '' ),
			'content'     => sanitize_textarea_field( $props['content'][0] ?? '' ),
			'published'   => sanitize_text_field( $props['published'][0] ?? '' ),
		];
	}

	public function map_to_post( array $parsed ): array {
		$settings                    = $this->get_settings();
		[ $post_date, $post_date_gmt ] = $this->parse_post_date( $parsed['published'] );

		$domain = $this->domain_from_url( $parsed['in_reply_to'] );

		$blocks = $parsed['content']
			? "<!-- wp:paragraph -->\n<p>" . wp_kses_post( $parsed['content'] ) . "</p>\n<!-- /wp:paragraph -->"
			: '';

		$category_ids = $this->category_ids_from_setting( $settings['post_category'] ?? '' );

		$args = [
			'post_title'   => $domain,
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

	public function get_kind(): string {
		return 'reply';
	}

	public function get_meta( array $parsed ): array {
		return [
			'nop_indieweb_in_reply_to' => $parsed['in_reply_to'],
		];
	}

}
