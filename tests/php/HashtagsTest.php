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

	public function test_only_a_subjects_names_file_a_post(): void {
		$content = "<p>FT – #BORO 2-2 #Cardiff #UTB</p>\n<p>#WebDesign and #OpenSource, #Belfast #nofilter</p>";
		$this->assertSame( [ 'Boro', 'Web' ], Hashtags::subjects_in( $content, Hashtags::SUBJECTS ) );
	}

	public function test_names_match_without_case_spaces_or_punctuation(): void {
		$this->assertSame( [ 'Sport', 'Ding', 'TV & film' ], Hashtags::subjects_in( '#worldcup2026 #DingTheDachshund #peepshow', Hashtags::SUBJECTS ) );
	}

	public function test_the_subject_itself_is_a_name(): void {
		$this->assertSame( [ 'Cats' ], Hashtags::subjects_in( '#Cats', [ 'Cats' => [ 'cat' ] ] ) );
	}

	public function test_no_name_belongs_to_two_subjects(): void {
		$seen = [];
		foreach ( Hashtags::SUBJECTS as $subject => $names ) {
			foreach ( array_unique( array_map( [ Hashtags::class, 'key' ], [ $subject, ...$names ] ) ) as $key ) {
				$this->assertArrayNotHasKey( $key, $seen, "$key is in $subject and " . ( $seen[ $key ] ?? '' ) );
				$seen[ $key ] = $subject;
			}
		}
	}
}
