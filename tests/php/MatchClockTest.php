<?php
declare( strict_types=1 );

use NOP\IndieWeb\Football\Match_Clock;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/football/class-match-clock.php';

final class MatchClockTest extends TestCase {

	public function test_minute_from_the_clock(): void {
		$cases = [
			-60  => '',
			0    => '1',
			59   => '1',
			60   => '2',
			2640 => '45',      // 44 minutes in
			2700 => '45+1',
			2820 => '45+3',
			2880 => 'HT',
			3720 => 'HT',
			3780 => '46',
			6420 => '90',
			6480 => '90+1',
			6720 => '90+5',
			6780 => 'FT',
		];
		foreach ( $cases as $seconds => $minute ) {
			$this->assertSame( $minute, Match_Clock::minute( $seconds ), "at {$seconds}s" );
		}
	}

	public function test_typed_minute(): void {
		$this->assertSame( '67', Match_Clock::typed_minute( "67' Nothing in that." ) );
		$this->assertSame( '45+2', Match_Clock::typed_minute( '<p>45+2’ One up.</p>' ) );
		$this->assertSame( '45+2', Match_Clock::typed_minute( '45 + 2&#8217; One up.' ) );
		$this->assertSame( 'HT', Match_Clock::typed_minute( 'ht: brilliant then not' ) );
		$this->assertSame( 'FT', Match_Clock::typed_minute( 'FT 2-1 get in' ) );
		$this->assertNull( Match_Clock::typed_minute( 'Nine minutes left' ) );
		$this->assertNull( Match_Clock::typed_minute( '67 minutes and nothing' ) );
		$this->assertNull( Match_Clock::typed_minute( 'Half-time thoughts' ) );
	}

	public function test_typed_score(): void {
		$this->assertSame( '1–0', Match_Clock::typed_score( '1-0 Conway!' ) );
		$this->assertSame( '2–1', Match_Clock::typed_score( "71' 2–1 Hackney from 25 yards" ) );
		$this->assertSame( '2–2', Match_Clock::typed_score( 'FT 2-2' ) );
		$this->assertSame( '0–1', Match_Clock::typed_score( '<p>0 - 1, asleep at the back post</p>' ) );
		$this->assertNull( Match_Clock::typed_score( '4-4-2 again today' ) );
		$this->assertNull( Match_Clock::typed_score( 'Conway makes it 1-0' ) );
	}
}
