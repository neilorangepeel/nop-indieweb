<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Tests;

use PHPUnit\Framework\TestCase;

use function NOP\IndieWeb\nop_indieweb_is_safe_url;

/**
 * Unit tests for the SSRF gate that every outbound fetch (webmention discovery,
 * inbound import, link-card enrichment) passes through. Literal-IP URLs are used
 * throughout so the assertions are deterministic and never touch DNS — they
 * exercise the private/reserved-range rejection directly. The classic attack
 * targets (loopback, RFC 1918, and the 169.254.169.254 cloud-metadata address)
 * must all be refused; genuine public addresses must pass.
 */
final class SafeUrlTest extends TestCase {

	/**
	 * @dataProvider blocked_urls
	 */
	public function test_unsafe_urls_are_rejected( string $url ): void {
		$this->assertFalse( nop_indieweb_is_safe_url( $url ), "$url should be unsafe" );
	}

	/**
	 * @dataProvider allowed_urls
	 */
	public function test_public_urls_are_allowed( string $url ): void {
		$this->assertTrue( nop_indieweb_is_safe_url( $url ), "$url should be safe" );
	}

	public static function blocked_urls(): array {
		return [
			'loopback'            => [ 'http://127.0.0.1/' ],
			'loopback range'      => [ 'http://127.0.0.53/' ],
			'private 10/8'        => [ 'http://10.0.0.1/' ],
			'private 172.16/12'   => [ 'http://172.16.0.1/' ],
			'private 192.168/16'  => [ 'http://192.168.1.1/' ],
			'link-local metadata' => [ 'http://169.254.169.254/latest/meta-data/' ],
			'this-network 0/8'    => [ 'http://0.0.0.0/' ],
			'no host'             => [ 'not-a-url' ],
			'empty string'        => [ '' ],
		];
	}

	public static function allowed_urls(): array {
		return [
			'public dns ip'  => [ 'http://8.8.8.8/' ],
			'public cf ip'   => [ 'https://1.1.1.1/' ],
			'public https'   => [ 'https://93.184.216.34/path' ],
		];
	}
}
