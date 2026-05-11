<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Services;

/**
 * Handles Letterboxd diary entries imported via RSS.
 *
 * Creates standard posts (no post format) where the film title is the post
 * title and the review text is the post content. Film-specific data is stored
 * in post meta and available via Block Bindings.
 *
 * No outbound syndication — Letterboxd has no public write API.
 */
class Letterboxd extends Service_Base {

	public function get_name(): string { return 'Letterboxd'; }
	public function get_slug(): string { return 'letterboxd'; }

	public function can_handle( array $payload ): bool {
		return in_array( 'h-cite', $payload['type'] ?? [], true )
			&& ! empty( $payload['properties']['film_title'] );
	}

	protected function get_dedup_key( array $parsed ): ?string {
		return $parsed['source_url'] ?: null;
	}

	protected function get_dedup_meta_key(): string {
		return 'nop_indieweb_source_url';
	}

	public function parse( array $payload ): array {
		$props = $payload['properties'] ?? [];
		return [
			'film_title'  => sanitize_text_field( $props['film_title'][0] ?? '' ),
			'film_year'   => sanitize_text_field( $props['film_year'][0] ?? '' ),
			'rating'      => sanitize_text_field( $props['rating'][0] ?? '' ),
			'watch_date'  => sanitize_text_field( $props['watch_date'][0] ?? '' ),
			'content'     => sanitize_textarea_field( $props['content'][0] ?? '' ),
			'source_url'  => esc_url_raw( $props['url'][0] ?? '' ),
			'poster'      => esc_url_raw( $props['poster'][0] ?? '' ),
			'rewatch'     => '1' === ( $props['rewatch'][0] ?? '0' ),
		];
	}

	public function map_to_post( array $parsed ): array {
		$settings                    = $this->get_settings();
		[ $post_date, $post_date_gmt ] = $this->parse_post_date( $parsed['watch_date'], true );

		$blocks = $parsed['content']
			? "<!-- wp:paragraph -->\n<p>" . wp_kses_post( $parsed['content'] ) . "</p>\n<!-- /wp:paragraph -->"
			: '';

		$category_ids = $this->category_ids_from_setting( $settings['post_category'] ?? '', 'Films' );

		$args = [
			'post_title'   => $parsed['film_title'] ?: 'Watched a film',
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
			'nop_indieweb_post_kind'    => 'watch',
			'nop_indieweb_film_title'   => $parsed['film_title'],
			'nop_indieweb_film_year'    => $parsed['film_year'],
			'nop_indieweb_film_rating'  => $parsed['rating'],
			'nop_indieweb_watch_date'   => $parsed['watch_date'],
			'nop_indieweb_source_url'   => $parsed['source_url'],
			'nop_indieweb_film_poster'  => $parsed['poster'],
			'nop_indieweb_film_rewatch' => $parsed['rewatch'] ? '1' : '0',
		];
	}

	// Standard post — film reviews have titles, so no post format is set.
	public function get_post_format( array $parsed ): string {
		return '';
	}

	protected function after_insert( int $post_id, array $parsed ): void {
		if ( ! $parsed['poster'] ) {
			return;
		}
		$settings = $this->get_settings();
		if ( empty( $settings['sideload_poster'] ) ) {
			return;
		}
		$this->sideload_photos( [ $parsed['poster'] ], $post_id );
	}

}
