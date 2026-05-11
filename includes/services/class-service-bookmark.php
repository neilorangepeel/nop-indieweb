<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Services;

/**
 * Handles Micropub bookmark-of posts.
 * Spec: https://indieweb.org/bookmark
 */
class Bookmark extends Service_Base {

	public function get_name(): string { return 'Bookmark'; }
	public function get_slug(): string { return 'bookmark'; }

	public function can_handle( array $payload ): bool {
		return ! empty( $payload['properties']['bookmark-of'][0] );
	}

	public function parse( array $payload ): array {
		$props = $payload['properties'] ?? [];

		return [
			'bookmark_of' => esc_url_raw( $props['bookmark-of'][0] ?? '' ),
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

		$category_names = array_filter( array_map( 'trim', explode( ',', $settings['post_category'] ?? 'Bookmarks' ) ) );
		$category_ids   = array_values( array_filter( array_map( [ $this, 'ensure_category' ], $category_names ) ) );

		$args = [
			'post_title'   => 'Bookmarked · ' . $this->domain_from_url( $parsed['bookmark_of'] ),
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
			'nop_indieweb_post_kind'   => 'bookmark',
			'nop_indieweb_bookmark_of' => $parsed['bookmark_of'],
		];
	}

	public function get_post_format( array $parsed ): string {
		return 'status';
	}

	protected function get_dedup_key( array $parsed ): ?string {
		return $parsed['bookmark_of'] ?: null;
	}

	protected function get_dedup_meta_key(): string {
		return 'nop_indieweb_bookmark_of';
	}
}
