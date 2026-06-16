<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Tests;

use NOP\IndieWeb\Rsvp\Event_Parser;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the RSVP event-detail parser's structured-data cascade
 * (microformats2 → JSON-LD → Open Graph → <title>). Exercises parse_html()
 * directly so no network round-trip is involved.
 */
final class EventParserTest extends TestCase {

	private Event_Parser $parser;

	protected function setUp(): void {
		$this->parser = new Event_Parser();
	}

	private function parse( string $html, string $url = 'https://example.com/event' ): array {
		return $this->parser->parse_html( $html, $url );
	}

	public function test_blank_html_returns_empty_shape(): void {
		$result = $this->parse( '' );

		$this->assertNull( $result['source'] );
		$this->assertSame( '', $result['name'] );
		$this->assertSame( '', $result['start'] );
		$this->assertSame( 'https://example.com/event', $result['url'] );
	}

	public function test_parses_microformats_h_event(): void {
		$html = <<<'HTML'
<div class="h-event">
	<h1 class="p-name">IndieWebCamp Brighton</h1>
	<time class="dt-start" datetime="2026-09-26T09:00:00">26 Sep</time>
	<time class="dt-end" datetime="2026-09-27T17:00:00">27 Sep</time>
	<span class="p-location">68 Middle Street, Brighton</span>
	<div class="e-content">A weekend of building the indie web.</div>
</div>
HTML;
		$result = $this->parse( $html );

		$this->assertSame( 'mf2', $result['source'] );
		$this->assertSame( 'IndieWebCamp Brighton', $result['name'] );
		$this->assertSame( '2026-09-26T09:00', $result['start'] );
		$this->assertSame( '2026-09-27T17:00', $result['end'] );
		$this->assertSame( '68 Middle Street, Brighton', $result['location'] );
		$this->assertStringContainsString( 'building the indie web', $result['note'] );
	}

	public function test_parses_json_ld_event(): void {
		$html = <<<'HTML'
<script type="application/ld+json">
{
	"@context": "https://schema.org",
	"@type": "Event",
	"name": "Steel Magnolias",
	"startDate": "2026-06-13T19:30",
	"endDate": "2026-06-13T22:00",
	"location": { "@type": "Place", "name": "Grand Opera House", "address": "Great Victoria Street, Belfast" },
	"image": "https://example.com/poster.jpg",
	"description": "A revival of the classic."
}
</script>
HTML;
		$result = $this->parse( $html );

		$this->assertSame( 'jsonld', $result['source'] );
		$this->assertSame( 'Steel Magnolias', $result['name'] );
		$this->assertSame( '2026-06-13T19:30', $result['start'] );
		$this->assertSame( 'Grand Opera House, Great Victoria Street, Belfast', $result['location'] );
		$this->assertSame( 'https://example.com/poster.jpg', $result['image'] );
	}

	public function test_json_ld_date_only_stays_date_only(): void {
		// A date with no time must NOT be coerced to a midnight datetime.
		$html = <<<'HTML'
<script type="application/ld+json">
{ "@context": "https://schema.org", "@type": "TheaterEvent", "name": "Matinee", "startDate": "2026-06-13" }
</script>
HTML;
		$result = $this->parse( $html );

		$this->assertSame( 'jsonld', $result['source'] );
		$this->assertSame( '2026-06-13', $result['start'] );
	}

	public function test_json_ld_placeholder_location_is_dropped(): void {
		$html = <<<'HTML'
<script type="application/ld+json">
{ "@context": "https://schema.org", "@type": "Event", "name": "Online Talk", "startDate": "2026-07-01", "location": { "@type": "Place", "name": "None" } }
</script>
HTML;
		$result = $this->parse( $html );

		$this->assertSame( 'Online Talk', $result['name'] );
		$this->assertSame( '', $result['location'] );
	}

	public function test_multi_event_listing_without_url_match_falls_through(): void {
		// Two events, neither matching the requested URL → JSON-LD declines, so the
		// parser falls back to the <title> rather than guessing an unrelated event.
		$html = <<<'HTML'
<title>What's On — The Venue</title>
<script type="application/ld+json">
[
	{ "@type": "Event", "name": "Gig One", "startDate": "2026-05-01", "url": "https://example.com/gig-one" },
	{ "@type": "Event", "name": "Gig Two", "startDate": "2026-05-02", "url": "https://example.com/gig-two" }
]
</script>
HTML;
		$result = $this->parse( $html, 'https://example.com/whats-on' );

		$this->assertSame( 'title', $result['source'] );
		$this->assertSame( "What's On — The Venue", $result['name'] );
	}

	public function test_multi_event_listing_picks_url_matched_event(): void {
		$html = <<<'HTML'
<script type="application/ld+json">
[
	{ "@type": "Event", "name": "Gig One", "startDate": "2026-05-01", "url": "https://example.com/gig-one" },
	{ "@type": "Event", "name": "Gig Two", "startDate": "2026-05-02", "url": "https://example.com/gig-two" }
]
</script>
HTML;
		$result = $this->parse( $html, 'https://example.com/gig-two/' );

		$this->assertSame( 'jsonld', $result['source'] );
		$this->assertSame( 'Gig Two', $result['name'] );
	}

	public function test_falls_back_to_open_graph(): void {
		$html = <<<'HTML'
<meta property="og:title" content="Late Night Jazz">
<meta property="og:image" content="https://example.com/jazz.jpg">
<meta property="og:description" content="Smoky tunes till late.">
HTML;
		$result = $this->parse( $html );

		$this->assertSame( 'opengraph', $result['source'] );
		$this->assertSame( 'Late Night Jazz', $result['name'] );
		$this->assertSame( 'https://example.com/jazz.jpg', $result['image'] );
		$this->assertStringContainsString( 'Smoky tunes', $result['note'] );
	}

	public function test_falls_back_to_title_tag(): void {
		$result = $this->parse( '<title>Plain Page Title</title>' );

		$this->assertSame( 'title', $result['source'] );
		$this->assertSame( 'Plain Page Title', $result['name'] );
	}

	public function test_backfills_image_from_og_when_layer_has_none(): void {
		// An mf2 h-event with no u-photo should still get an image from og:image.
		$html = <<<'HTML'
<meta property="og:image" content="https://example.com/from-og.jpg">
<div class="h-event">
	<h1 class="p-name">No-Photo Event</h1>
	<time class="dt-start" datetime="2026-08-01T18:00">1 Aug</time>
</div>
HTML;
		$result = $this->parse( $html );

		$this->assertSame( 'mf2', $result['source'] );
		$this->assertSame( 'https://example.com/from-og.jpg', $result['image'] );
	}

	public function test_rejects_data_uri_images(): void {
		$html = <<<'HTML'
<meta property="og:title" content="Sketchy Image Event">
<meta property="og:image" content="data:image/png;base64,iVBORw0KGgo=">
HTML;
		$result = $this->parse( $html );

		$this->assertSame( 'opengraph', $result['source'] );
		$this->assertSame( '', $result['image'] );
	}
}
