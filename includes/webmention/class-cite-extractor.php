<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Webmention;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches a remote URL and extracts a "cite" of it — title, author, excerpt,
 * representative image, site name and canonical URL — so a like / bookmark /
 * repost / reply post can carry real context instead of a bare link.
 *
 * Each field follows a fallback chain: microformats2 (h-entry / h-card) →
 * OpenGraph → plain HTML (<title> / <meta name="description"> / rel=canonical).
 * Best-effort: returns [] when the page can't be fetched or isn't HTML.
 *
 * Reuses the SSRF-safe fetch helper (nop_indieweb_strict_remote_get) and the
 * same XPath class-contains idiom as MF2_Parser.
 */
class Cite_Extractor {

	private const TIMEOUT       = 6;
	private const MAX_BYTES     = 2097152; // 2 MB.
	private const EXCERPT_WORDS = 55;
	private const TEXT_CAP      = 300;

	/** Fetches $url and returns the extracted cite, or [] on failure. */
	public function extract_from_url( string $url ): array {
		$response = \NOP\IndieWeb\nop_indieweb_strict_remote_get( $url, [
			'timeout'             => self::TIMEOUT,
			'limit_response_size' => self::MAX_BYTES,
			'user-agent'          => 'NOP IndieWeb/' . NOP_INDIEWEB_VERSION . ' (cite; +' . home_url( '/' ) . ')',
		] );
		if ( is_wp_error( $response ) ) {
			return [];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return [];
		}

		// Only parse HTML — skip PDFs, images, feeds, etc.
		$ctype = (string) wp_remote_retrieve_header( $response, 'content-type' );
		if ( '' !== $ctype && false === stripos( $ctype, 'html' ) ) {
			return [];
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === trim( $body ) ) {
			return [];
		}

		return $this->extract_from_html( $body, $url );
	}

	/** Parses HTML and returns the cite fields. Public so it can be unit-tested. */
	public function extract_from_html( string $html, string $source_url ): array {
		$dom = new \DOMDocument();
		// LIBXML_NONET blocks network access for embedded references;
		// @ suppresses warnings from malformed third-party HTML.
		@$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NONET );
		$xpath = new \DOMXPath( $dom );

		$entry  = $this->find_entry( $xpath );
		$author = $this->extract_author( $entry, $xpath );

		return [
			'title'        => $this->extract_title( $entry, $xpath ),
			'excerpt'      => $this->extract_excerpt( $entry, $xpath ),
			'image'        => $this->extract_image( $entry, $xpath ),
			'author_name'  => $author['name'],
			'author_url'   => $author['url'],
			'author_photo' => $author['photo'],
			'site_name'    => $this->extract_site_name( $xpath, $source_url ),
			'url'          => $this->extract_canonical( $xpath, $source_url ),
		];
	}

	// ── Field extractors ─────────────────────────────────────────────────────

	private function extract_title( ?\DOMElement $entry, \DOMXPath $xpath ): string {
		if ( $entry ) {
			$name = $this->entry_text( $xpath, 'p-name', $entry );
			if ( '' !== $name ) {
				return $this->clean_text( $name );
			}
		}
		$title = $this->meta_content( $xpath, [ 'og:title' ] );
		if ( '' === $title ) {
			$title = $this->node_text( $xpath, '//title' );
		}
		return $this->clean_text( $title );
	}

	private function extract_excerpt( ?\DOMElement $entry, \DOMXPath $xpath ): string {
		if ( $entry ) {
			$summary = $this->entry_text( $xpath, 'p-summary', $entry );
			if ( '' !== $summary ) {
				return $this->clean_excerpt( $summary );
			}
		}
		return $this->clean_excerpt( $this->meta_content( $xpath, [ 'og:description', 'description' ] ) );
	}

	private function extract_image( ?\DOMElement $entry, \DOMXPath $xpath ): string {
		if ( $entry ) {
			$src = $this->entry_attr( $xpath, 'u-photo', 'src', $entry );
			if ( '' !== $src ) {
				return esc_url_raw( $src );
			}
		}
		return esc_url_raw( $this->meta_content( $xpath, [ 'og:image', 'og:image:url' ] ) );
	}

	private function extract_author( ?\DOMElement $entry, \DOMXPath $xpath ): array {
		$blank = [ 'name' => '', 'url' => '', 'photo' => '' ];
		if ( ! $entry ) {
			return $blank;
		}
		$card = $xpath->query( './/*[' . $this->cls( 'h-card' ) . ']', $entry )->item( 0 );
		if ( ! $card instanceof \DOMElement ) {
			return $blank;
		}
		return [
			'name'  => sanitize_text_field( $this->text( $xpath, './/*[' . $this->cls( 'p-name' ) . ']', $card ) ),
			'url'   => esc_url_raw( $this->attr( $xpath, './/*[' . $this->cls( 'u-url' ) . ']/@href', $card ) ),
			'photo' => esc_url_raw( $this->attr( $xpath, './/*[' . $this->cls( 'u-photo' ) . ']/@src', $card ) ),
		];
	}

	private function extract_site_name( \DOMXPath $xpath, string $source_url ): string {
		$name = $this->meta_content( $xpath, [ 'og:site_name' ] );
		if ( '' !== $name ) {
			return $this->clean_text( $name );
		}
		return (string) wp_parse_url( $source_url, PHP_URL_HOST );
	}

	private function extract_canonical( \DOMXPath $xpath, string $source_url ): string {
		$nodes = $xpath->query( '//link[@rel="canonical"]/@href' );
		if ( $nodes && $nodes->length > 0 ) {
			$canonical = esc_url_raw( trim( (string) $nodes->item( 0 )->nodeValue ) );
			if ( '' !== $canonical ) {
				return $canonical;
			}
		}
		$og = esc_url_raw( $this->meta_content( $xpath, [ 'og:url' ] ) );
		return '' !== $og ? $og : esc_url_raw( $source_url );
	}

	// ── XPath utilities ──────────────────────────────────────────────────────

	private function find_entry( \DOMXPath $xpath ): ?\DOMElement {
		$nodes = $xpath->query( '//*[' . $this->cls( 'h-entry' ) . ' or ' . $this->cls( 'h-cite' ) . ']' );
		$node  = $nodes && $nodes->length > 0 ? $nodes->item( 0 ) : null;
		return $node instanceof \DOMElement ? $node : null;
	}

	/** Text of the first element with $class inside the entry, excluding the author h-card. */
	private function entry_text( \DOMXPath $xpath, string $class, \DOMElement $entry ): string {
		$query = './/*[' . $this->cls( $class ) . '][not( ancestor::*[' . $this->cls( 'h-card' ) . '] )]';
		$nodes = $xpath->query( $query, $entry );
		return $nodes && $nodes->length > 0 ? trim( $nodes->item( 0 )->textContent ) : '';
	}

	private function entry_attr( \DOMXPath $xpath, string $class, string $attr, \DOMElement $entry ): string {
		$query = './/*[' . $this->cls( $class ) . '][not( ancestor::*[' . $this->cls( 'h-card' ) . '] )]/@' . $attr;
		$nodes = $xpath->query( $query, $entry );
		return $nodes && $nodes->length > 0 ? trim( (string) $nodes->item( 0 )->nodeValue ) : '';
	}

	/** First non-empty <meta property|name="…"> content from the candidate list. */
	private function meta_content( \DOMXPath $xpath, array $keys ): string {
		foreach ( $keys as $key ) {
			$nodes = $xpath->query(
				'//meta[@property="' . $key . '"]/@content | //meta[@name="' . $key . '"]/@content'
			);
			if ( $nodes && $nodes->length > 0 ) {
				$value = trim( (string) $nodes->item( 0 )->nodeValue );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}
		return '';
	}

	private function node_text( \DOMXPath $xpath, string $query ): string {
		$nodes = $xpath->query( $query );
		return $nodes && $nodes->length > 0 ? trim( $nodes->item( 0 )->textContent ) : '';
	}

	private function cls( string $class ): string {
		return 'contains(concat(" ",normalize-space(@class)," ")," ' . $class . ' ")';
	}

	private function text( \DOMXPath $xpath, string $query, \DOMElement $context ): string {
		$nodes = $xpath->query( $query, $context );
		return $nodes && $nodes->length > 0 ? trim( $nodes->item( 0 )->textContent ) : '';
	}

	private function attr( \DOMXPath $xpath, string $query, \DOMElement $context ): string {
		$nodes = $xpath->query( $query, $context );
		return $nodes && $nodes->length > 0 ? trim( (string) $nodes->item( 0 )->nodeValue ) : '';
	}

	// ── Cleaning ───────────────────────────────────────────────────────────────

	private function clean_text( string $text ): string {
		$text = sanitize_text_field( $text );
		return mb_substr( $text, 0, self::TEXT_CAP );
	}

	private function clean_excerpt( string $text ): string {
		$text = sanitize_textarea_field( $text );
		return '' === $text ? '' : wp_trim_words( $text, self::EXCERPT_WORDS, '…' );
	}
}
