<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Webmention;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal microformats2 parser scoped to webmention processing.
 *
 * Handles the mf2 subset that Bridgy and similar services produce:
 * interaction type (like, repost, reply, bookmark, mention), author h-card,
 * reply content, published date, and canonical URL of the interaction.
 *
 * Uses the XPath class-contains idiom rather than a full mf2 algorithm so
 * we avoid a Composer dependency for ~30 lines of DOM work.
 */
class MF2_Parser {

	public function parse( string $html, string $source_url ): array {
		$dom = new \DOMDocument();
		// LIBXML_NONET blocks the parser from issuing network requests for any
		// embedded references; @ suppresses warnings from malformed third-party HTML.
		@$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NONET );
		$xpath = new \DOMXPath( $dom );

		$root         = $this->find_root( $xpath );
		$type         = $this->detect_type( $root, $xpath );
		$author       = $this->extract_author( $root, $xpath );
		$content      = $this->extract_content( $root, $xpath, $type );
		$published    = $this->extract_published( $root, $xpath );
		$original_url = $this->extract_url( $root, $xpath );

		return [
			'type'         => $type,
			'author_name'  => $author['name'],
			'author_url'   => $author['url'],
			'author_photo' => $author['photo'],
			'content'      => $content,
			'published'    => $published,
			'original_url' => $original_url,
			'platform'     => $this->detect_platform( $source_url ),
		];
	}

	// ── Helpers ────────────────────────────────────────────────────────────────

	private function find_root( \DOMXPath $xpath ): ?\DOMElement {
		$nodes = $xpath->query(
			'//*[' . $this->cls( 'h-entry' ) . ' or ' . $this->cls( 'h-cite' ) . ']'
		);
		return $nodes->length > 0 ? $nodes->item( 0 ) : null;
	}

	private function detect_type( ?\DOMElement $root, \DOMXPath $xpath ): string {
		if ( ! $root ) {
			return 'mention';
		}
		foreach ( [
			'u-like-of'     => 'like',
			'u-repost-of'   => 'repost',
			'u-in-reply-to' => 'reply',
			'u-bookmark-of' => 'bookmark',
		] as $class => $type ) {
			if ( $xpath->query( './/*[' . $this->cls( $class ) . ']', $root )->length > 0 ) {
				return $type;
			}
		}
		return 'mention';
	}

	private function extract_author( ?\DOMElement $root, \DOMXPath $xpath ): array {
		$blank = [ 'name' => '', 'url' => '', 'photo' => '' ];
		if ( ! $root ) {
			return $blank;
		}

		$card = $xpath->query( './/*[' . $this->cls( 'h-card' ) . ']', $root )->item( 0 );
		if ( ! $card ) {
			return $blank;
		}

		$name  = $this->text( $xpath, './/*[' . $this->cls( 'p-name' ) . ']', $card );
		$url   = $this->attr( $xpath, './/*[' . $this->cls( 'u-url' ) . ']/@href', $card );
		$photo = $this->attr( $xpath, './/*[' . $this->cls( 'u-photo' ) . ']/@src', $card );

		return [
			'name'  => sanitize_text_field( $name ),
			'url'   => esc_url_raw( $url ),
			'photo' => esc_url_raw( $photo ),
		];
	}

	private function extract_content( ?\DOMElement $root, \DOMXPath $xpath, string $type ): string {
		if ( $type !== 'reply' ) {
			return '';
		}

		if ( $root ) {
			$text = $this->text(
				$xpath,
				'.//*[' . $this->cls( 'e-content' ) . ' or ' . $this->cls( 'p-summary' ) . ']',
				$root
			);
			if ( $text !== '' ) {
				return sanitize_textarea_field( trim( $text ) );
			}
		}

		// Fall back to meta description for sources without mf2 markup.
		foreach ( [
			'//meta[@name="description"]/@content',
			'//meta[@property="og:description"]/@content',
		] as $query ) {
			$nodes = $xpath->query( $query );
			if ( $nodes && $nodes->length > 0 ) {
				$text = trim( (string) $nodes->item( 0 )->nodeValue );
				if ( $text !== '' ) {
					return sanitize_textarea_field( wp_trim_words( $text, 55 ) );
				}
			}
		}

		return '';
	}

	private function extract_published( ?\DOMElement $root, \DOMXPath $xpath ): string {
		if ( ! $root ) {
			return '';
		}
		return sanitize_text_field(
			$this->attr( $xpath, './/*[' . $this->cls( 'dt-published' ) . ']/@datetime', $root )
		);
	}

	private function extract_url( ?\DOMElement $root, \DOMXPath $xpath ): string {
		if ( ! $root ) {
			return '';
		}
		return esc_url_raw(
			$this->attr( $xpath, './/*[' . $this->cls( 'u-url' ) . ']/@href', $root )
		);
	}

	private function detect_platform( string $source_url ): string {
		if ( str_contains( $source_url, 'brid.gy' ) || str_contains( $source_url, 'bridgy' ) ) {
			if ( str_contains( $source_url, '/mastodon/' ) ) return 'mastodon';
			if ( str_contains( $source_url, '/bluesky/' ) )  return 'bluesky';
		}
		return 'unknown';
	}

	// ── XPath utilities ────────────────────────────────────────────────────────

	/** Builds the XPath class-contains predicate for an mf2 class. */
	private function cls( string $class ): string {
		return 'contains(concat(" ",normalize-space(@class)," ")," ' . $class . ' ")';
	}

	private function text( \DOMXPath $xpath, string $query, \DOMElement $context ): string {
		$nodes = $xpath->query( $query, $context );
		return $nodes->length > 0 ? trim( $nodes->item( 0 )->textContent ) : '';
	}

	private function attr( \DOMXPath $xpath, string $query, \DOMElement $context ): string {
		$nodes = $xpath->query( $query, $context );
		return $nodes->length > 0 ? trim( $nodes->item( 0 )->nodeValue ) : '';
	}
}
