<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Tests;

use NOP\IndieWeb\Webmention\MF2_Parser;
use PHPUnit\Framework\TestCase;

/**
 * The parser keeps what a reply answers (u-in-reply-to), so the receiver can file a reply under the one it answers.
 * Pure DOM work: no WP, DB or network.
 */
final class MF2ParserInReplyToTest extends TestCase {

	private function parse( string $entry ): array {
		return ( new MF2_Parser() )->parse( '<html><body>' . $entry . '</body></html>', 'https://example.org/reply' );
	}

	public function test_plain_link_form(): void {
		$parsed = $this->parse( '<div class="h-entry"><a class="u-in-reply-to" href="https://site.test/post/#comment-12">re</a><p class="e-content">Yes.</p></div>' );
		$this->assertSame( 'reply', $parsed['type'] );
		$this->assertSame( [ 'https://site.test/post/#comment-12' ], $parsed['in_reply_to'] );
	}

	public function test_citation_form_and_several_targets(): void {
		$parsed = $this->parse(
			'<div class="h-entry">'
			. '<a class="u-in-reply-to" href="https://site.test/post/">post</a>'
			. '<div class="u-in-reply-to h-cite"><a class="u-url" href="https://mastodon.example/@june/42">June</a></div>'
			. '<p class="e-content">Agreed.</p></div>'
		);
		$this->assertSame( [ 'https://site.test/post/', 'https://mastodon.example/@june/42' ], $parsed['in_reply_to'] );
	}

	public function test_no_reply_no_targets(): void {
		$this->assertSame( [], $this->parse( '<div class="h-entry"><a class="u-like-of" href="https://site.test/post/">like</a></div>' )['in_reply_to'] );
		$this->assertSame( [], $this->parse( '<p>No microformats here.</p>' )['in_reply_to'] );
	}
}
