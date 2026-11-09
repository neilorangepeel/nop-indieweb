<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Tests;

use PHPUnit\Framework\TestCase;

use function NOP\IndieWeb\nop_indieweb_is_production;

if ( ! defined( 'NOP_INDIEWEB_LIVE_HOST' ) ) {
	define( 'NOP_INDIEWEB_LIVE_HOST', 'neilorangepeel.com' );
}

/**
 * The guard that keeps a dev or local copy from syndicating, sending
 * webmentions or pinging the hub: only the live host counts as production.
 */
final class IsProductionTest extends TestCase {

	/**
	 * @dataProvider homes
	 */
	public function test_only_the_live_host_is_production( string $home, bool $expected ): void {
		$GLOBALS['nop_test_home_url'] = $home;
		$this->assertSame( $expected, nop_indieweb_is_production(), $home );
	}

	public static function homes(): array {
		return [
			'live'          => [ 'https://neilorangepeel.com', true ],
			'dev subdomain' => [ 'https://dev.neilorangepeel.com', false ],
			'network root'  => [ 'https://neil.rocks', false ],
			'local studio'  => [ 'http://localhost:8881', false ],
		];
	}
}
