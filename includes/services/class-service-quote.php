<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Services;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Micropub quotation-of posts — an IndieWeb quote: a passage from a
 * source, with attribution and optional commentary.
 * Spec: https://indieweb.org/quotation
 *
 * Content model: the `content` property is the quoted passage (rendered as a
 * blockquote); the source URL rides on `quotation-of` and surfaces via the
 * cite-card; an optional `quote-comment` property carries the author's own note,
 * rendered under the quote. With no comment the output is exactly
 * blockquote + cite-card — identical to the editor-panel quote layout.
 */
class Quote extends Url_Response_Service {

	public function get_name(): string { return 'Quote'; }
	public function get_slug(): string { return 'quote'; }

	protected function url_property(): string { return 'quotation-of'; }
	protected function url_meta_key(): string { return 'nop_indieweb_quote_of'; }
	protected function use_cite_card(): bool { return true; }

	public function can_handle( array $payload ): bool {
		$props = $payload['properties'] ?? [];
		// A quotation-of with an rsvp is an RSVP, not a quote — defer (matches the family).
		return ! empty( $props['quotation-of'][0] ) && empty( $props['rsvp'][0] );
	}

	public function get_kind( array $parsed = [] ): string {
		return 'quote';
	}

	public function parse( array $payload ): array {
		$parsed            = parent::parse( $payload );
		$props             = $payload['properties'] ?? [];
		$parsed['comment'] = sanitize_textarea_field( $props['quote-comment'][0] ?? '' );
		return $parsed;
	}

	/**
	 * blockquote(passage) → cite-card → optional paragraph(comment). The base's
	 * title/status/date/category args are reused; only post_content differs.
	 */
	public function map_to_post( array $parsed ): array {
		$args    = parent::map_to_post( $parsed );
		$content = $parsed['content'] ?? '';
		$comment = $parsed['comment'] ?? '';

		$parts = array_filter( [
			$content
				? "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><!-- wp:paragraph -->\n<p>"
					. wp_kses_post( $content )
					. "</p>\n<!-- /wp:paragraph --></blockquote>\n<!-- /wp:quote -->"
				: '',
			'<!-- wp:nop-indieweb/cite-card /-->',
			$comment
				? "<!-- wp:paragraph -->\n<p>" . wp_kses_post( $comment ) . "</p>\n<!-- /wp:paragraph -->"
				: '',
		] );

		$args['post_content'] = implode( "\n\n", $parts );

		return $args;
	}

	protected function get_dedup_key( array $parsed ): ?string {
		return $parsed['url'] ?: null;
	}
}
