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

		$content_parts = \NOP\IndieWeb\nop_indieweb_micropub_content_parts( $props['content'][0] ?? '' );

		return [
			'url'          => esc_url_raw( $props[ $this->url_property() ][0] ?? '' ),
			'content'      => $content_parts['plain'],
			'content_html' => $content_parts['html'],
			'published' => sanitize_text_field( $props['published'][0] ?? '' ),
			// Topical tags (Micropub `category`) — composer-driven for the kinds that
			// opt in (bookmark, quote). Stored as WP tags in map_to_post().
			'tags'      => array_values( array_filter( array_map(
				'sanitize_text_field',
				(array) ( $props['category'] ?? [] )
			) ) ),
		];
	}

	protected function use_cite_card(): bool {
		return false;
	}

	public function map_to_post( array $parsed ): array {
		$settings                      = $this->get_settings();
		[ $post_date, $post_date_gmt ] = $this->parse_post_date( $parsed['published'] );

		$content_html = trim( (string) ( $parsed['content_html'] ?? '' ) );
		$note_inner   = '' !== $content_html
			? $content_html
			: ( $parsed['content'] ? wp_kses_post( $parsed['content'] ) : '' );
		$parts = array_filter( [
			$this->use_cite_card() ? '<!-- wp:nop-indieweb/cite-card /-->' : '',
			'' !== $note_inner
				? "<!-- wp:paragraph -->\n<p>" . $note_inner . "</p>\n<!-- /wp:paragraph -->"
				: '',
		] );
		$blocks = implode( "\n\n", $parts );

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
		if ( ! empty( $parsed['tags'] ) ) {
			$args['tags_input'] = $parsed['tags'];
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
