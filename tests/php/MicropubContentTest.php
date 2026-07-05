<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Tests;

use PHPUnit\Framework\TestCase;

use function NOP\IndieWeb\nop_indieweb_micropub_content_parts;

/**
 * Unit tests for the Micropub content normaliser behind the /post Markdown
 * feature: a plain string or a { value, html } object → a [plain, html] pair,
 * with html sanitised to an inline subset and plain always safe for socials.
 */
final class MicropubContentTest extends TestCase {

	public function test_plain_string_has_no_html(): void {
		$parts = nop_indieweb_micropub_content_parts( 'hello world' );
		$this->assertSame( 'hello world', $parts['plain'] );
		$this->assertSame( '', $parts['html'] );
	}

	public function test_html_object_keeps_inline_and_strips_disallowed(): void {
		$parts = nop_indieweb_micropub_content_parts( [
			'html'  => 'a <strong>bold</strong> and <em>it</em> <script>x</script>',
			'value' => 'a bold and it x',
		] );
		$this->assertStringContainsString( '<strong>bold</strong>', $parts['html'] );
		$this->assertStringContainsString( '<em>it</em>', $parts['html'] );
		$this->assertStringNotContainsString( '<script', $parts['html'] );
		// The plain fallback is what socials and titles use — never any markup.
		$this->assertSame( 'a bold and it x', $parts['plain'] );
	}

	public function test_html_only_derives_plain_from_stripped_html(): void {
		$parts = nop_indieweb_micropub_content_parts( [ 'html' => '<em>hi</em> there' ] );
		$this->assertSame( 'hi there', $parts['plain'] );
		$this->assertStringContainsString( '<em>hi</em>', $parts['html'] );
	}

	public function test_empty_content_is_empty(): void {
		$parts = nop_indieweb_micropub_content_parts( '' );
		$this->assertSame( '', $parts['plain'] );
		$this->assertSame( '', $parts['html'] );
	}
}
