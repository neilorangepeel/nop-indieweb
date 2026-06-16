<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Rsvp;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches an event page and extracts its details for an RSVP post.
 *
 * Follows a fallback cascade so the RSVP sidebar can pre-fill the event name,
 * start/end, location and a note from whatever structured data the page exposes:
 *
 *   1. microformats2 h-event  — the mf2 PHP library if one is loaded (Mf2\parse),
 *                               otherwise the in-house XPath parser used elsewhere
 *                               in the plugin (Cite_Extractor / MF2_Parser idiom).
 *   2. JSON-LD schema.org/Event
 *   3. Open Graph meta tags
 *   4. <title> tag only
 *   5. empty fields with source => null, so the UI can fall back gracefully.
 *
 * Best-effort and side-effect-free: a failed fetch returns the empty shape with
 * source => null and never throws. Reuses the SSRF-safe fetch helper
 * (nop_indieweb_strict_remote_get) like the rest of the plugin.
 */
class Event_Parser {

	private const TIMEOUT   = 6;
	private const MAX_BYTES = 2097152; // 2 MB.
	private const TEXT_CAP  = 300;

	/**
	 * Fetches $url and returns the parsed event.
	 *
	 * @return array{source:?string,url:string,name:string,start:string,end:string,location:string,note:string}
	 */
	public function fetch( string $url ): array {
		$empty = [
			'source'   => null,
			'url'      => esc_url_raw( $url ),
			'name'     => '',
			'start'    => '',
			'end'      => '',
			'location' => '',
			'note'     => '',
		];

		$url = trim( $url );
		if ( '' === $url || ! \NOP\IndieWeb\nop_indieweb_is_safe_url( $url ) ) {
			return $empty;
		}

		$response = \NOP\IndieWeb\nop_indieweb_strict_remote_get( $url, [
			'timeout'             => self::TIMEOUT,
			'limit_response_size' => self::MAX_BYTES,
			'user-agent'          => 'NOP IndieWeb/' . NOP_INDIEWEB_VERSION . ' (rsvp-event; +' . home_url( '/' ) . ')',
		] );
		if ( is_wp_error( $response ) ) {
			return $empty;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return $empty;
		}

		// Only parse HTML — skip PDFs, images, feeds, calendar files, etc.
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
	 * Parses event details from an HTML string. Public so it can be unit-tested
	 * without a network round-trip.
	 *
	 * @return array{source:?string,url:string,name:string,start:string,end:string,location:string,note:string}
	 */
	public function parse_html( string $html, string $source_url ): array {
		$base = [
			'source'   => null,
			'url'      => esc_url_raw( $source_url ),
			'name'     => '',
			'start'    => '',
			'end'      => '',
			'location' => '',
			'note'     => '',
		];

		$dom = new \DOMDocument();
		// LIBXML_NONET blocks network access for embedded references;
		// @ suppresses warnings from malformed third-party HTML.
		@$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NONET );
		$xpath = new \DOMXPath( $dom );

		// 1. microformats2 h-event.
		$event = $this->from_mf2( $html, $xpath );
		if ( '' !== $event['name'] || '' !== $event['start'] ) {
			return array_merge( $base, $event, [ 'source' => 'mf2' ] );
		}

		// 2. JSON-LD schema.org/Event. Pass the source URL so a multi-event blob
		// (e.g. a venue's month-view or a "related events" section) can be
		// disambiguated by matching the requested URL rather than blindly taking
		// the first node — otherwise pasting a listing page returns whatever
		// event happens to come first.
		$event = $this->from_json_ld( $xpath, $source_url );
		if ( '' !== $event['name'] || '' !== $event['start'] ) {
			return array_merge( $base, $event, [ 'source' => 'jsonld' ] );
		}

		// 3. Open Graph.
		$event = $this->from_open_graph( $xpath );
		if ( '' !== $event['name'] ) {
			return array_merge( $base, $event, [ 'source' => 'opengraph' ] );
		}

		// 4. <title> only.
		$title = $this->clean_text( $this->node_text( $xpath, '//title' ) );
		if ( '' !== $title ) {
			return array_merge( $base, [ 'name' => $title, 'source' => 'title' ] );
		}

		// 5. Nothing found.
		return $base;
	}

	// ── 1. microformats2 ─────────────────────────────────────────────────────

	/**
	 * Extracts an h-event. Prefers a real mf2 library when one is autoloaded
	 * (php-mf2 exposes the Mf2\parse() function); otherwise falls back to the
	 * XPath class-contains idiom the plugin already uses in MF2_Parser.
	 *
	 * @return array{name:string,start:string,end:string,location:string,note:string}
	 */
	private function from_mf2( string $html, \DOMXPath $xpath ): array {
		$blank = [ 'name' => '', 'start' => '', 'end' => '', 'location' => '', 'note' => '' ];

		if ( function_exists( 'Mf2\\parse' ) ) {
			$parsed = $this->from_mf2_library( $html );
			if ( null !== $parsed ) {
				return $parsed;
			}
		}

		$root = $this->find_event( $xpath );
		if ( ! $root instanceof \DOMElement ) {
			return $blank;
		}

		return [
			'name'     => $this->clean_text( $this->prop_text( $xpath, 'p-name', $root ) ),
			'start'    => $this->normalize_datetime( $this->prop_dt( $xpath, 'dt-start', $root ) ),
			'end'      => $this->normalize_datetime( $this->prop_dt( $xpath, 'dt-end', $root ) ),
			'location' => $this->compose_location( $this->prop_text( $xpath, 'p-location', $root ) ),
			'note'     => $this->clean_note( $this->prop_text( $xpath, 'e-content', $root ) ?: $this->prop_text( $xpath, 'p-summary', $root ) ),
		];
	}

	/**
	 * Reads the first h-event from a php-mf2 parse tree.
	 *
	 * @return array{name:string,start:string,end:string,location:string,note:string}|null
	 */
	private function from_mf2_library( string $html ): ?array {
		try {
			$data = \Mf2\parse( $html );
		} catch ( \Throwable $e ) {
			return null;
		}
		$event = $this->find_mf2_event_item( $data['items'] ?? [] );
		if ( null === $event ) {
			return null;
		}

		$props    = $event['properties'] ?? [];
		$location = $props['location'][0] ?? '';
		// location can itself be an h-card/h-adr object — reduce it to its name
		// or fall back to its plain value (h-card's value field is the linkified
		// name when both u-url and p-name are present on the same element).
		if ( is_array( $location ) ) {
			$location = $location['properties']['name'][0] ?? ( $location['value'] ?? '' );
		}
		$content = $props['content'][0] ?? ( $props['summary'][0] ?? '' );
		if ( is_array( $content ) ) {
			$content = $content['value'] ?? '';
		}

		return [
			'name'     => $this->clean_text( (string) ( $props['name'][0] ?? '' ) ),
			'start'    => $this->normalize_datetime( (string) ( $props['start'][0] ?? '' ) ),
			'end'      => $this->normalize_datetime( (string) ( $props['end'][0] ?? '' ) ),
			'location' => $this->compose_location( (string) $location ),
			'note'     => $this->clean_note( (string) $content ),
		];
	}

	/** Depth-first search for the first h-event item in an mf2 item tree. */
	private function find_mf2_event_item( array $items ): ?array {
		foreach ( $items as $item ) {
			if ( in_array( 'h-event', (array) ( $item['type'] ?? [] ), true ) ) {
				return $item;
			}
			$nested = $this->find_mf2_event_item( $item['children'] ?? [] );
			if ( null !== $nested ) {
				return $nested;
			}
		}
		return null;
	}

	private function find_event( \DOMXPath $xpath ): ?\DOMElement {
		$nodes = $xpath->query( '//*[' . $this->cls( 'h-event' ) . ']' );
		$node  = $nodes && $nodes->length > 0 ? $nodes->item( 0 ) : null;
		return $node instanceof \DOMElement ? $node : null;
	}

	/** Text of the first descendant carrying $class. */
	private function prop_text( \DOMXPath $xpath, string $class, \DOMElement $root ): string {
		$nodes = $xpath->query( './/*[' . $this->cls( $class ) . ']', $root );
		return $nodes && $nodes->length > 0 ? trim( $nodes->item( 0 )->textContent ) : '';
	}

	/**
	 * Datetime value of the first descendant carrying $class. Prefers the
	 * datetime/value attribute (the mf2 value-class pattern), then the text.
	 */
	private function prop_dt( \DOMXPath $xpath, string $class, \DOMElement $root ): string {
		$node = $xpath->query( './/*[' . $this->cls( $class ) . ']', $root )->item( 0 );
		if ( ! $node instanceof \DOMElement ) {
			return '';
		}
		foreach ( [ 'datetime', 'value', 'title' ] as $attr ) {
			$value = trim( (string) $node->getAttribute( $attr ) );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return trim( $node->textContent );
	}

	// ── 2. JSON-LD ───────────────────────────────────────────────────────────

	/**
	 * @return array{name:string,start:string,end:string,location:string,note:string}
	 */
	private function from_json_ld( \DOMXPath $xpath, string $source_url ): array {
		$blank = [ 'name' => '', 'start' => '', 'end' => '', 'location' => '', 'note' => '' ];

		$scripts = $xpath->query( '//script[@type="application/ld+json"]' );
		if ( ! $scripts ) {
			return $blank;
		}

		// Collect every Event-typed node across every JSON-LD block on the page
		// before choosing one. Pages in the wild often split related events into
		// separate <script> tags (Duke's Events Calendar puts a month-view
		// @graph in one block and an additional featured Event in another) —
		// looking at each block in isolation makes the wrong call.
		$events = [];
		foreach ( $scripts as $script ) {
			$decoded = json_decode( trim( (string) $script->textContent ), true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			foreach ( $this->collect_event_nodes( $decoded ) as $node ) {
				$events[] = $node;
			}
		}

		$event = $this->pick_event( $events, $source_url );
		return null === $event ? $blank : $this->map_json_ld_event( $event );
	}

	/**
	 * Walks a decoded JSON-LD blob (object, list, or @graph) for Event nodes,
	 * including types that subclass it (TheaterEvent, BusinessEvent, etc.).
	 *
	 * @return array<int,array>
	 */
	private function collect_event_nodes( array $data ): array {
		// A bare list of nodes, or an @graph wrapper. isset($data[0]) distinguishes
		// a numerically-indexed list from a single associative node (array_is_list()
		// would be cleaner but needs PHP 8.1; this targets 8.0).
		$candidates = isset( $data['@graph'] ) && is_array( $data['@graph'] ) ? $data['@graph'] : null;
		if ( null === $candidates ) {
			$candidates = isset( $data[0] ) ? $data : [ $data ];
		}

		$events = [];
		foreach ( $candidates as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$types = (array) ( $node['@type'] ?? [] );
			foreach ( $types as $type ) {
				if ( is_string( $type ) && str_contains( strtolower( $type ), 'event' ) ) {
					$events[] = $node;
					break;
				}
			}
		}
		return $events;
	}

	/**
	 * Picks the right Event from the collected set.
	 *
	 *   none  → null
	 *   one   → that event (single-event page)
	 *   many  → the one whose `url` matches the requested page; otherwise null,
	 *           so a listings/calendar URL falls through to OG/title and the UI
	 *           reports "couldn't find event data" instead of silently filling
	 *           the form with whichever unrelated event appeared first.
	 */
	private function pick_event( array $events, string $source_url ): ?array {
		if ( ! $events ) {
			return null;
		}
		if ( 1 === count( $events ) ) {
			return $events[0];
		}
		$want = $this->canon_url( $source_url );
		foreach ( $events as $node ) {
			$node_url = (string) ( $node['url'] ?? $node['mainEntityOfPage'] ?? '' );
			if ( '' !== $node_url && $this->canon_url( $node_url ) === $want ) {
				return $node;
			}
		}
		return null;
	}

	/** Normalises a URL for "are these the same page?" comparison. */
	private function canon_url( string $url ): string {
		$url = strtolower( trim( $url ) );
		// '~' delimiter — '#' would collide with the literal '#' in the class.
		$url = preg_replace( '~[?#].*$~', '', $url );
		return rtrim( (string) $url, '/' );
	}

	/**
	 * @return array{name:string,start:string,end:string,location:string,note:string}
	 */
	private function map_json_ld_event( array $event ): array {
		return [
			'name'     => $this->clean_text( (string) ( $event['name'] ?? '' ) ),
			'start'    => $this->normalize_datetime( (string) ( $event['startDate'] ?? '' ) ),
			'end'      => $this->normalize_datetime( (string) ( $event['endDate'] ?? '' ) ),
			'location' => $this->compose_location( $event['location'] ?? '' ),
			'note'     => $this->clean_note( (string) ( $event['description'] ?? '' ) ),
		];
	}

	/**
	 * Reduces a schema.org Place / PostalAddress (or a bare string) to a single
	 * human-readable location line.
	 *
	 * Filters two real-world failure modes the wild publishes:
	 *   1. Placeholder strings like "None" / "TBA" / "TBD" that the publisher
	 *      emits as a literal (Duke's Events Calendar fills `Place.name="None"`
	 *      for every event without a venue) — treated as empty.
	 *   2. The same name repeated in `Place.name` AND `address.streetAddress`
	 *      (Luma puts the venue name in streetAddress too) — deduped.
	 *
	 * @param mixed $location
	 */
	private function compose_location( $location ): string {
		if ( is_string( $location ) ) {
			$value = trim( $location );
			return $this->is_placeholder( $value ) ? '' : $this->clean_text( $value );
		}
		if ( ! is_array( $location ) ) {
			return '';
		}

		$name = $this->non_placeholder( (string) ( $location['name'] ?? '' ) );

		$address = $location['address'] ?? '';
		$address_str = '';
		if ( is_string( $address ) ) {
			$address_str = trim( $address );
		} elseif ( is_array( $address ) ) {
			$address_str = $this->non_placeholder( (string) ( $address['name'] ?? '' ) );
			if ( '' === $address_str ) {
				$address_str = $this->non_placeholder( (string) ( $address['streetAddress'] ?? '' ) );
			}
		}

		// Dedup: if the address line is literally the venue name again, drop it.
		if ( '' !== $name && '' !== $address_str
			&& mb_strtolower( $name ) === mb_strtolower( $address_str ) ) {
			$address_str = '';
		}

		$parts = array_filter( [ $name, $address_str ], static fn( $s ) => '' !== $s );
		return $this->clean_text( implode( ', ', $parts ) );
	}

	/** Common venue placeholders publishers emit when no real value is set. */
	private function is_placeholder( string $value ): bool {
		$norm = mb_strtolower( trim( $value ) );
		return in_array( $norm, [ '', 'none', 'tba', 'tbd', 'tbc', 'n/a', 'null', 'undefined' ], true );
	}

	private function non_placeholder( string $value ): string {
		return $this->is_placeholder( $value ) ? '' : trim( $value );
	}

	// ── 3. Open Graph ──────────────────────────────────────────────────────────

	/**
	 * @return array{name:string,start:string,end:string,location:string,note:string}
	 */
	private function from_open_graph( \DOMXPath $xpath ): array {
		return [
			'name'     => $this->clean_text( $this->meta_content( $xpath, [ 'og:title' ] ) ),
			// OG has no standard event-time vocabulary; some sites emit these.
			'start'    => $this->normalize_datetime( $this->meta_content( $xpath, [ 'event:start_time', 'og:event:start_time' ] ) ),
			'end'      => $this->normalize_datetime( $this->meta_content( $xpath, [ 'event:end_time', 'og:event:end_time' ] ) ),
			'location' => '',
			'note'     => $this->clean_note( $this->meta_content( $xpath, [ 'og:description', 'description' ] ) ),
		];
	}

	// ── Shared helpers ───────────────────────────────────────────────────────

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

	/**
	 * Normalises a datetime string to either `Y-m-d\TH:i` (when the source carried
	 * a time) or `Y-m-d` (when it only carried a date). The UI splits each into a
	 * `date` + `time` pair, so a date-only value pre-fills the date and leaves the
	 * time blank rather than inventing midnight. An unparseable value round-trips
	 * sanitised so the field stays editable.
	 *
	 * Why: schema.org and h-event publishers in the wild range from strict ISO
	 * datetimes to date-only ranges (e.g. a theatrical run with `"Sat 13 Jun 2026"`).
	 * Coercing those to midnight reads as the author having said midnight, which is
	 * a lie — Steel Magnolias on Sat 13 Jun isn't a 00:00 performance.
	 */
	private function normalize_datetime( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		// ISO local datetime (e.g. "2026-05-01T09:30" or "2026-05-01 09:30:00+01:00").
		// Keep the date + wall-clock time as-is so the event's local start time isn't
		// silently shifted to UTC; normalise the separator to 'T'.
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/', $value, $m ) ) {
			return $m[1] . 'T' . $m[2];
		}
		// ISO date-only — pass straight through.
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})$/', $value, $m ) ) {
			return $m[1];
		}
		$ts = strtotime( $value );
		if ( false === $ts ) {
			return sanitize_text_field( $value );
		}
		// Only emit a time component when the source actually carried one — a bare
		// "Fri 10 Jul 2026" reports back as a date, not a midnight datetime.
		return preg_match( '/\d{1,2}:\d{2}/', $value )
			? gmdate( 'Y-m-d\TH:i', $ts )
			: gmdate( 'Y-m-d', $ts );
	}

	private function clean_text( string $text ): string {
		return mb_substr( sanitize_text_field( $text ), 0, self::TEXT_CAP );
	}

	private function clean_note( string $text ): string {
		$text = sanitize_textarea_field( $text );
		return '' === $text ? '' : wp_trim_words( $text, 55, '…' );
	}
}
