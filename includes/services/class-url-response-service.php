<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Services;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base for services that respond to a single URL — Bookmark, Like, Repost, Reply, RSVP.
 *
 * Subclasses declare which Micropub property carries the URL ('bookmark-of',
 * 'like-of', etc.) and which post meta key stores it. Everything else
 * (parsing, post args, dedup, meta) is shared.
 */
abstract class Url_Response_Service extends Service_Base {

	abstract protected function url_property(): string;
	abstract protected function url_meta_key(): string;

	public function can_handle( array $payload ): bool {
		return ! empty( $payload['properties'][ $this->url_property() ][0] );
	}

	public function parse( array $payload ): array {
		$props = $payload['properties'] ?? [];

		return [
			'url'       => esc_url_raw( $props[ $this->url_property() ][0] ?? '' ),
			'content'   => sanitize_textarea_field( $props['content'][0] ?? '' ),
			'published' => sanitize_text_field( $props['published'][0] ?? '' ),
		];
	}

	public function map_to_post( array $parsed ): array {
		$settings                      = $this->get_settings();
		[ $post_date, $post_date_gmt ] = $this->parse_post_date( $parsed['published'] );

		$blocks = $parsed['content']
			? "<!-- wp:paragraph -->\n<p>" . wp_kses_post( $parsed['content'] ) . "</p>\n<!-- /wp:paragraph -->"
			: '';

		$category_ids = $this->category_ids_from_setting( $settings['post_category'] ?? '' );

		$args = [
			'post_title'   => $this->domain_from_url( $parsed['url'] ),
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
		return [ $this->url_meta_key() => $parsed['url'] ];
	}

	protected function get_dedup_meta_key(): string {
		return $this->url_meta_key();
	}
}
