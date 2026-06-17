<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Preview;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches a target page and extracts a lightweight "what am I acting on?"
 * preview for the /post composer — reply, like, bookmark and repost all act on
 * a URL, and this lets the author see the title / author / a snippet of the
 * thing before they post, rather than acting blind on a bare hostname.
 *
 * Mirrors the cascade + SSRF-safe fetch of the RSVP Event_Parser, but reads a
 * generic h-entry / Article rather than an h-event:
 *
 *   1. microformats2 h-entry   — name, author (h-card name), summary/content
 *   2. JSON-LD Article/BlogPosting/WebPage — headline, author, description
 *   3. Open Graph / Twitter meta — og:title, og:site_name, og:description
 *   4. <title> + meta description only
 *   5. empty shape with source => null, so the UI can stay quiet on a miss.
 *
 * Best-effort and side-effect-free: a failed fetch returns the empty shape and
 * never throws.
 */
class Link_Parser {

	private const TIMEOUT   = 6;
	private const MAX_BYTES = 2097152; // 2 MB.
	private const TITLE_CAP = 200;
	private const TEXT_CAP  = 280;

	/**
	 * Fetches $url and returns the parsed preview.
	 *
	 * @return array{source:?string,url:string,title:string,author:string,excerpt:string,image:string}
	 */
	public function fetch( string $url ): array {
		$empty = [
			'source'  => null,
			'url'     => esc_url_raw( $url ),
			'title'   => '',
			'author'  => '',
			'excerpt' => '',
			'image'   => '',
		];

		$url = trim( $url );
		if ( '' === $url || ! \NOP\IndieWeb\nop_indieweb_is_safe_url( $url ) ) {
			return $empty;
		}

		$response = \NOP\IndieWeb\nop_indieweb_strict_remote_get( $url, [
			'timeout'             => self::TIMEOUT,
			'limit_response_size' => self::MAX_BYTES,
			'user-agent'          => 'NOP IndieWeb/' . NOP_INDIEWEB_VERSION . ' (link-preview; +' . home_url( '/' ) . ')',
		] );
		if ( is_wp_error( $response ) ) {
			return $empty;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return $empty;
		}

		// Only parse HTML — skip PDFs, images, feeds, etc.
		$ctype = (string) wp_remote_retrieve_header( $response, 'content-type' );
		if ( '' !== $ctype && false === stripos( $ctype, 'html' ) ) {
			return $empty;
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === trim( $body ) ) {
			return $empty;
		}

		return $this->parse_html( $body, $url );
	}

	/**
	 * Parses preview details from an HTML string. Public so it can be unit-tested
	 * without a network round-trip.
	 *
	 * @return array{source:?string,url:string,title:string,author:string,excerpt:string,image:string}
	 */
	public function parse_html( string $html, string $source_url ): array {
		$base = [
			'source'  => null,
			'url'     => esc_url_raw( $source_url ),
			'title'   => '',
			'author'  => '',
			'excerpt' => '',
			'image'   => '',
		];

		$dom = new \DOMDocument();
		@$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NONET );
		$xpath = new \DOMXPath( $dom );

		// 1. microformats2 h-entry.
		$entry = $this->from_mf2( $html, $xpath );
		if ( '' !== $entry['title'] || '' !== $entry['excerpt'] ) {
			return $this->finish( $base, $entry, 'mf2', $xpath, $source_url );
		}

		// 2. JSON-LD Article / BlogPosting / WebPage.
		$entry = $this->from_json_ld( $xpath );
		if ( '' !== $entry['title'] ) {
			return $this->finish( $base, $entry, 'jsonld', $xpath, $source_url );
		}

		// 3. Open Graph.
		$entry = $this->from_open_graph( $xpath );
		if ( '' !== $entry['title'] ) {
			return $this->finish( $base, $entry, 'opengraph', $xpath, $source_url );
		}

		// 4. <title> + meta description only.
		$title = $this->clean_title( $this->node_text( $xpath, '//title' ) );
		if ( '' !== $title ) {
			return $this->finish( $base, [
				'title'   => $title,
				'author'  => '',
				'excerpt' => $this->clean_text( $this->meta_content( $xpath, [ 'description' ] ) ),
				'image'   => '',
			], 'title', $xpath, $source_url );
		}

		return $base;
	}

	/**
	 * Merges the winning layer over the base, stamps the source, and back-fills
	 * the author from og:site_name and the image from og:image when the winning
	 * layer didn't carry them.
	 *
	 * @param array<string,mixed> $base
	 * @param array<string,mixed> $entry
	 */
	private function finish( array $base, array $entry, string $source, \DOMXPath $xpath, string $page_url ): array {
		$merged = array_merge( $base, $entry, [ 'source' => $source ] );

		if ( '' === ( $merged['author'] ?? '' ) ) {
			$site = $this->meta_content( $xpath, [ 'og:site_name' ] );
			if ( '' !== $site ) {
				$merged['author'] = $this->clean_title( $site );
			}
		}
		if ( '' === ( $merged['image'] ?? '' ) ) {
			$og_image = $this->meta_content( $xpath, [ 'og:image', 'og:image:secure_url', 'twitter:image' ] );
			if ( '' !== $og_image ) {
				$merged['image'] = $this->clean_image_url( $og_image, $page_url );
			}
		}
		return $merged;
	}

	// ── 1. microformats2 ───────────────────────────────────────────────────────

	/**
	 * @return array{title:string,author:string,excerpt:string,image:string}
	 */
	private function from_mf2( string $html, \DOMXPath $xpath ): array {
		$blank = [ 'title' => '', 'author' => '', 'excerpt' => '', 'image' => '' ];

		if ( function_exists( 'Mf2\\parse' ) ) {
			$parsed = $this->from_mf2_library( $html );
			if ( null !== $parsed ) {
				return $parsed;
			}
		}

		$root = $this->find_entry( $xpath );
		if ( ! $root instanceof \DOMElement ) {
			return $blank;
		}

		$summary = $this->prop_text( $xpath, 'p-summary', $root );
		if ( '' === $summary ) {
			$summary = $this->prop_text( $xpath, 'e-content', $root );
		}

		return [
			'title'   => $this->clean_title( $this->prop_text( $xpath, 'p-name', $root ) ),
			'author'  => $this->clean_title( $this->prop_text( $xpath, 'p-author', $root ) ),
			'excerpt' => $this->clean_text( $summary ),
			'image'   => '',
		];
	}

	/**
	 * @return array{title:string,author:string,excerpt:string,image:string}|null
	 */
	private function from_mf2_library( string $html ): ?array {
		try {
			$data = \Mf2\parse( $html );
		} catch ( \Throwable $e ) {
			return null;
		}
		$entry = $this->find_mf2_entry_item( $data['items'] ?? [] );
		if ( null === $entry ) {
			return null;
		}

		$props  = $entry['properties'] ?? [];
		$author = $props['author'][0] ?? '';
		if ( is_array( $author ) ) {
			$author = $author['properties']['name'][0] ?? ( $author['value'] ?? '' );
		}
		$content = $props['summary'][0] ?? ( $props['content'][0] ?? '' );
		if ( is_array( $content ) ) {
			$content = $content['value'] ?? '';
		}
		$photo = $props['photo'][0] ?? '';
		if ( is_array( $photo ) ) {
			$photo = $photo['value'] ?? '';
		}

		return [
			'title'   => $this->clean_title( (string) ( $props['name'][0] ?? '' ) ),
			'author'  => $this->clean_title( (string) $author ),
			'excerpt' => $this->clean_text( (string) $content ),
			'image'   => $this->clean_image_url( (string) $photo, '' ),
		];
	}

	/** Depth-first search for the first h-entry item in an mf2 item tree. */
	private function find_mf2_entry_item( array $items ): ?array {
		foreach ( $items as $item ) {
			if ( in_array( 'h-entry', (array) ( $item['type'] ?? [] ), true ) ) {
				return $item;
			}
			$nested = $this->find_mf2_entry_item( $item['children'] ?? [] );
			if ( null !== $nested ) {
				return $nested;
			}
		}
		return null;
	}

	private function find_entry( \DOMXPath $xpath ): ?\DOMElement {
		$nodes = $xpath->query( '//*[' . $this->cls( 'h-entry' ) . ']' );
		$node  = $nodes && $nodes->length > 0 ? $nodes->item( 0 ) : null;
		return $node instanceof \DOMElement ? $node : null;
	}

	/** Text of the first descendant carrying $class. */
	private function prop_text( \DOMXPath $xpath, string $class, \DOMElement $root ): string {
		$nodes = $xpath->query( './/*[' . $this->cls( $class ) . ']', $root );
		return $nodes && $nodes->length > 0 ? trim( $nodes->item( 0 )->textContent ) : '';
	}

	// ── 2. JSON-LD ─────────────────────────────────────────────────────────────

	/**
	 * @return array{title:string,author:string,excerpt:string,image:string}
	 */
	private function from_json_ld( \DOMXPath $xpath ): array {
		$blank = [ 'title' => '', 'author' => '', 'excerpt' => '', 'image' => '' ];

		$scripts = $xpath->query( '//script[@type="application/ld+json"]' );
		if ( ! $scripts ) {
			return $blank;
		}

		foreach ( $scripts as $script ) {
			$decoded = json_decode( trim( (string) $script->textContent ), true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			$node = $this->find_article_node( $decoded );
			if ( null !== $node ) {
				return $this->map_json_ld( $node );
			}
		}
		return $blank;
	}

	/** Finds the first Article/BlogPosting/WebPage-ish node in a JSON-LD blob. */
	private function find_article_node( array $data ): ?array {
		$candidates = isset( $data['@graph'] ) && is_array( $data['@graph'] ) ? $data['@graph'] : null;
		if ( null === $candidates ) {
			$candidates = isset( $data[0] ) ? $data : [ $data ];
		}
		foreach ( $candidates as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			foreach ( (array) ( $node['@type'] ?? [] ) as $type ) {
				if ( is_string( $type ) && preg_match( '/article|posting|webpage|blog/i', $type ) ) {
					return $node;
				}
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $node
	 * @return array{title:string,author:string,excerpt:string,image:string}
	 */
	private function map_json_ld( array $node ): array {
		$author = $node['author'] ?? '';
		if ( is_array( $author ) ) {
			// author can be a Person node or a list of them.
			$author = $author['name'] ?? ( $author[0]['name'] ?? ( $author[0] ?? '' ) );
		}
		return [
			'title'   => $this->clean_title( (string) ( $node['headline'] ?? $node['name'] ?? '' ) ),
			'author'  => $this->clean_title( is_string( $author ) ? $author : '' ),
			'excerpt' => $this->clean_text( (string) ( $node['description'] ?? '' ) ),
			'image'   => $this->jsonld_image( $node['image'] ?? '' ),
		];
	}

	/** @param mixed $value */
	private function jsonld_image( $value ): string {
		if ( is_string( $value ) ) {
			return $this->clean_image_url( $value, '' );
		}
		if ( ! is_array( $value ) ) {
			return '';
		}
		if ( isset( $value['url'] ) && is_string( $value['url'] ) ) {
			return $this->clean_image_url( $value['url'], '' );
		}
		foreach ( $value as $item ) {
			$url = $this->jsonld_image( $item );
			if ( '' !== $url ) {
				return $url;
			}
		}
		return '';
	}

	// ── 3. Open Graph ──────────────────────────────────────────────────────────

	/**
	 * @return array{title:string,author:string,excerpt:string,image:string}
	 */
	private function from_open_graph( \DOMXPath $xpath ): array {
		return [
			'title'   => $this->clean_title( $this->meta_content( $xpath, [ 'og:title', 'twitter:title' ] ) ),
			'author'  => $this->clean_title( $this->meta_content( $xpath, [ 'article:author', 'author' ] ) ),
			'excerpt' => $this->clean_text( $this->meta_content( $xpath, [ 'og:description', 'twitter:description', 'description' ] ) ),
			'image'   => $this->clean_image_url( $this->meta_content( $xpath, [ 'og:image', 'og:image:secure_url', 'twitter:image' ] ), '' ),
		];
	}

	// ── Shared helpers ─────────────────────────────────────────────────────────

	private function cls( string $class ): string {
		return 'contains(concat(" ",normalize-space(@class)," ")," ' . $class . ' ")';
	}

	private function node_text( \DOMXPath $xpath, string $query ): string {
		$nodes = $xpath->query( $query );
		return $nodes && $nodes->length > 0 ? trim( $nodes->item( 0 )->textContent ) : '';
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

	private function clean_title( string $text ): string {
		return mb_substr( sanitize_text_field( $text ), 0, self::TITLE_CAP );
	}

	private function clean_text( string $text ): string {
		$text = sanitize_textarea_field( $text );
		if ( '' === $text ) {
			return '';
		}
		$text = preg_replace( '/\s+/', ' ', $text ) ?? $text;
		return mb_substr( trim( $text ), 0, self::TEXT_CAP );
	}

	/**
	 * Returns an absolute http(s) image URL or empty string. Resolves
	 * protocol-relative and root-relative refs against the page URL.
	 */
	private function clean_image_url( string $value, string $page_url ): string {
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > 2048 ) {
			return '';
		}
		if ( str_starts_with( $value, '//' ) ) {
			$value = 'https:' . $value;
		} elseif ( str_starts_with( $value, '/' ) && '' !== $page_url ) {
			$parts = wp_parse_url( $page_url );
			if ( ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) ) {
				$value = $parts['scheme'] . '://' . $parts['host'] . $value;
			}
		}
		$value = esc_url_raw( $value );
		if ( '' === $value ) {
			return '';
		}
		$scheme = strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) );
		return in_array( $scheme, [ 'http', 'https' ], true ) ? $value : '';
	}
}
