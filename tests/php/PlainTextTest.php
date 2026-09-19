<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

use function NOP\IndieWeb\nop_indieweb_html_to_text;

/**
 * Syndicated cards and status text are plain-text fields on every platform —
 * Bluesky renders an external card's title/description verbatim, so anything
 * still entity-encoded reaches the reader as literal "I&#8217;ve".
 */
final class PlainTextTest extends TestCase {

	/** get_the_excerpt() texturizes, so this is what every titled post's card carried. */
	public function test_decodes_texturized_excerpt(): void {
		$this->assertSame(
			"I\u{2019}ve been trying to work out why\u{2026}",
			nop_indieweb_html_to_text( 'I&#8217;ve been trying to work out why&hellip;' )
		);
	}

	public function test_decodes_named_and_numeric_entities(): void {
		$this->assertSame( 'B&Q, Belfast', nop_indieweb_html_to_text( 'B&amp;Q, Belfast' ) );
		$this->assertSame( "Bluesky's here", nop_indieweb_html_to_text( 'Bluesky&#039;s here' ) );
		$this->assertSame( '"quoted" & dashed', nop_indieweb_html_to_text( '&quot;quoted&quot; &amp; dashed' ) );
	}

	public function test_strips_tags_and_maps_breaks_to_newlines(): void {
		$this->assertSame( "one\ntwo", nop_indieweb_html_to_text( '<p>one<br />two</p>' ) );
	}

	public function test_empty_and_plain_text_pass_through(): void {
		$this->assertSame( '', nop_indieweb_html_to_text( '' ) );
		$this->assertSame( 'already plain', nop_indieweb_html_to_text( '  already plain  ' ) );
	}
}
