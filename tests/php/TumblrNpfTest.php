<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Tests;

use NOP\IndieWeb\Syndication\Tumblr_Client;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure NPF assembler — maps a post's text/images/kind to
 * Tumblr content blocks. No WordPress, network, or DB involved.
 */
final class TumblrNpfTest extends TestCase {

	public function test_note_is_a_single_text_block_with_source_and_tags(): void {
		$npf = Tumblr_Client::build_npf(
			'just a thought',
			[],
			'note',
			[ 'permalink' => 'https://example.com/p/1', 'tags' => [ 'one', 'two' ] ]
		);

		$this->assertSame( 'published', $npf['state'] );
		$this->assertSame( 'https://example.com/p/1', $npf['source_url'] );
		$this->assertSame( 'one,two', $npf['tags'] );
		$this->assertCount( 1, $npf['content'] );
		$this->assertSame( [ 'type' => 'text', 'text' => 'just a thought' ], $npf['content'][0] );
	}

	public function test_photo_emits_image_block_with_alt_then_caption(): void {
		$npf = Tumblr_Client::build_npf(
			'a caption',
			[ [ 'url' => 'https://example.com/x.png', 'alt' => 'a described png' ] ],
			'photo',
			[ 'permalink' => 'https://example.com/p/2' ]
		);

		$image = $npf['content'][0];
		$this->assertSame( 'image', $image['type'] );
		$this->assertSame( 'image/png', $image['media'][0]['type'] );
		// Images reference an uploaded binary by identifier, not an external URL.
		$this->assertSame( 'media-0', $image['media'][0]['identifier'] );
		$this->assertArrayNotHasKey( 'url', $image['media'][0] );
		$this->assertSame( 'a described png', $image['alt_text'] );
		$this->assertSame( [ 'type' => 'text', 'text' => 'a caption' ], $npf['content'][1] );
	}

	public function test_image_without_alt_omits_alt_text_key(): void {
		$npf = Tumblr_Client::build_npf(
			'',
			[ [ 'url' => 'https://example.com/x.jpg', 'alt' => '' ] ],
			'photo',
			[]
		);
		$this->assertArrayNotHasKey( 'alt_text', $npf['content'][0] );
		$this->assertSame( 'image/jpeg', $npf['content'][0]['media'][0]['type'] );
	}

	public function test_quote_uses_quote_subtype_with_attribution(): void {
		$npf = Tumblr_Client::build_npf(
			'the unexamined life is not worth living',
			[],
			'quote',
			[ 'cite' => 'Socrates' ]
		);

		$this->assertSame( 'text', $npf['content'][0]['type'] );
		$this->assertSame( 'quote', $npf['content'][0]['subtype'] );
		$this->assertSame( '— Socrates', $npf['content'][1]['text'] );
	}

	public function test_bookmark_appends_a_link_block_to_the_target(): void {
		$npf = Tumblr_Client::build_npf(
			'worth saving',
			[],
			'bookmark',
			[ 'target_url' => 'https://other.example/article', 'target_title' => 'An Article' ]
		);

		$link = end( $npf['content'] );
		$this->assertSame( 'link', $link['type'] );
		$this->assertSame( 'https://other.example/article', $link['url'] );
		$this->assertSame( 'An Article', $link['title'] );
	}

	public function test_empty_post_falls_back_to_permalink_text_block(): void {
		$npf = Tumblr_Client::build_npf( '', [], 'note', [ 'permalink' => 'https://example.com/p/9' ] );
		$this->assertSame( [ 'type' => 'text', 'text' => 'https://example.com/p/9' ], $npf['content'][0] );
	}
}
