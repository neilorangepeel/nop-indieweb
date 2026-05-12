<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Services;

/**
 * Handles Micropub repost-of posts.
 * Spec: https://indieweb.org/repost
 */
class Repost extends Service_Base {

	public function get_name(): string { return 'Repost'; }
	public function get_slug(): string { return 'repost'; }

	public function can_handle( array $payload ): bool {
		return ! empty( $payload['properties']['repost-of'][0] );
	}

	public function parse( array $payload ): array {
		$props = $payload['properties'] ?? [];

		return [
			'repost_of' => esc_url_raw( $props['repost-of'][0] ?? '' ),
			'content'   => sanitize_textarea_field( $props['content'][0] ?? '' ),
			'published' => sanitize_text_field( $props['published'][0] ?? '' ),
		];
	}

	public function map_to_post( array $parsed ): array {
		$settings                    = $this->get_settings();
		[ $post_date, $post_date_gmt ] = $this->parse_post_date( $parsed['published'] );

		$blocks = $parsed['content']
			? "<!-- wp:paragraph -->\n<p>" . wp_kses_post( $parsed['content'] ) . "</p>\n<!-- /wp:paragraph -->"
			: '';

		$category_ids = $this->category_ids_from_setting( $settings['post_category'] ?? '' );

		$args = [
			'post_title'   => $this->domain_from_url( $parsed['repost_of'] ),
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
		return 'repost';
	}

	public function get_meta( array $parsed ): array {
		return [
			'nop_indieweb_repost_of' => $parsed['repost_of'],
		];
	}

	protected function get_dedup_key( array $parsed ): ?string {
		return $parsed['repost_of'] ?: null;
	}

	protected function get_dedup_meta_key(): string {
		return 'nop_indieweb_repost_of';
	}
}
