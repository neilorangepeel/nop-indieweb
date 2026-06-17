<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Services;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Micropub quote posts — an IndieWeb quotation: a passage with attribution
 * and an optional source link.
 * Spec: https://indieweb.org/quotation
 *
 * Content model (passage-primary — the passage can't be scraped from a URL, so it's
 * typed): `content` is the quoted passage → a core wp:quote block; `quote-cite` is the
 * attribution → an inline <cite> (linked when a source URL is given); `quotation-of`
 * is an OPTIONAL source link; `quote-comment` is the author's own note below the quote.
 * Works with no URL at all (a book, a conversation, a film).
 */
class Quote extends Url_Response_Service {

	public function get_name(): string { return 'Quote'; }
	public function get_slug(): string { return 'quote'; }

	protected function url_property(): string { return 'quotation-of'; }
	protected function url_meta_key(): string { return 'nop_indieweb_quote_of'; }
	protected function use_cite_card(): bool { return false; }

	public function can_handle( array $payload ): bool {
		$props = $payload['properties'] ?? [];
		if ( ! empty( $props['rsvp'][0] ) ) {
			return false;
		}
		// A composer quote carries post-kind=quote (it may have no URL at all); a generic
		// IndieWeb client may instead just send quotation-of. Match either.
		$kind = sanitize_key( $props['post-kind'][0] ?? '' );
		return 'quote' === $kind || ! empty( $props['quotation-of'][0] );
	}

	public function get_kind( array $parsed = [] ): string {
		return 'quote';
	}

	public function parse( array $payload ): array {
		$parsed            = parent::parse( $payload );
		$props             = $payload['properties'] ?? [];
		$parsed['cite']    = sanitize_text_field( $props['quote-cite'][0] ?? '' );
		$parsed['comment'] = sanitize_textarea_field( $props['quote-comment'][0] ?? '' );
		return $parsed;
	}

	/**
	 * wp:quote(passage + inline <cite>) → optional comment paragraph. The base supplies
	 * the status/date/category/tags args; we override post_content and the title.
	 */
	public function map_to_post( array $parsed ): array {
		$args    = parent::map_to_post( $parsed );
		$content = (string) ( $parsed['content'] ?? '' );
		$cite    = (string) ( $parsed['cite'] ?? '' );
		$comment = (string) ( $parsed['comment'] ?? '' );
		$url     = (string) ( $parsed['url'] ?? '' );

		// Citation: the attribution, linked to the source when one's given. With no
		// author but a URL, fall back to the source host as the link label.
		$label    = '' !== $cite ? $cite : ( '' !== $url ? $this->domain_from_url( $url ) : '' );
		$cite_tag = '';
		if ( '' !== $label ) {
			$inner    = '' !== $url
				? '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>'
				: esc_html( $label );
			$cite_tag = '<cite>' . $inner . '</cite>';
		}

		$blockquote = $content
			? "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><!-- wp:paragraph -->\n<p>"
				. wp_kses_post( $content )
				. "</p>\n<!-- /wp:paragraph -->" . $cite_tag . "</blockquote>\n<!-- /wp:quote -->"
			: '';

		$parts = array_filter( [
			$blockquote,
			$comment
				? "<!-- wp:paragraph -->\n<p>" . wp_kses_post( $comment ) . "</p>\n<!-- /wp:paragraph -->"
				: '',
		] );

		$args['post_content'] = implode( "\n\n", $parts );

		// Title: the attribution, else a short snippet of the passage (not the URL domain,
		// since a quote often has no URL).
		if ( '' !== $cite ) {
			$args['post_title'] = $cite;
		} elseif ( '' !== $content ) {
			$args['post_title'] = wp_trim_words( wp_strip_all_tags( $content ), 10, '…' );
		}

		return $args;
	}

	public function get_meta( array $parsed ): array {
		$meta = [];
		if ( ! empty( $parsed['url'] ) ) {
			$meta[ $this->url_meta_key() ] = $parsed['url'];   // u-quotation-of + outgoing webmention
		}
		if ( ! empty( $parsed['cite'] ) ) {
			$meta['nop_indieweb_cite_author_name'] = $parsed['cite'];
		}
		return $meta;
	}

	protected function get_dedup_key( array $parsed ): ?string {
		return $parsed['url'] ?: null;
	}
}
