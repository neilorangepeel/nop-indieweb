<?php
declare( strict_types=1 );

use NOP\IndieWeb\Kind\Hashtags;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/kind/class-hashtags.php';

final class HashtagsTest extends TestCase {

	public function test_finds_hashtags_in_block_markup(): void {
		$content = "<!-- wp:paragraph -->\n<p>FT – #BORO 2-2 #Cardiff</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>#UTB</p>\n<!-- /wp:paragraph -->";
		$this->assertSame( [ 'BORO', 'Cardiff', 'UTB' ], Hashtags::extract( $content ) );
	}

	public function test_once_each_keeping_the_first_spelling(): void {
		$this->assertSame( [ 'Boro' ], Hashtags::extract( '#Boro won, #BORO up, #boro!' ) );
	}

	public function test_skips_numbers_colours_anchors_and_mid_word(): void {
		$this->assertSame( [], Hashtags::extract( 'Issue #1, see https://example.com/#top or a#b' ) );
		$this->assertSame( [], Hashtags::extract( '<p style="color:#ffffff">plain</p>' ) ); // token-ok: test input, not styling
	}

	public function test_letters_beyond_ascii_and_brackets(): void {
		$this->assertSame( [ 'Béal', 'darkroom' ], Hashtags::extract( 'Up the #Béal (#darkroom)' ) );
	}

	public function test_entities_are_read_as_text(): void {
		$this->assertSame( [ 'Belfast' ], Hashtags::extract( 'Tom &amp; Jerry&#8217;s #Belfast' ) );
	}
}
