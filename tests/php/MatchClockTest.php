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

	public function test_strip_typed_lead(): void {
		$this->assertSame( '<p>HACKNEY. From 25 yards.</p>', Match_Clock::strip_typed_lead( "<p>71' 1-2 HACKNEY. From 25 yards.</p>" ) );
		$this->assertSame( '<p>Conway! Get in.</p>', Match_Clock::strip_typed_lead( '<p>0-1 Conway! Get in.</p>' ) );
		$this->assertSame( '<p>Brilliant then not.</p>', Match_Clock::strip_typed_lead( '<p>HT. Brilliant then not.</p>' ) );
		$this->assertSame( '<p class="x">Asleep.</p>', Match_Clock::strip_typed_lead( '<p class="x">58&#8217; 1-1. Asleep.</p>' ) );
		$this->assertSame( "\n<p>Nine minutes left.</p>", Match_Clock::strip_typed_lead( "\n<p>Nine minutes left.</p>" ) );
		$this->assertSame( "<!-- wp:paragraph -->\n<p>Conway!</p>", Match_Clock::strip_typed_lead( "<!-- wp:paragraph -->\n<p>0-1 Conway!</p>" ) );
		$this->assertSame( '<p>4-4-2 again.</p>', Match_Clock::strip_typed_lead( '<p>4-4-2 again.</p>' ) );
		$this->assertSame( '<p>Then 1-0 later.</p><p>67\' not here</p>', Match_Clock::strip_typed_lead( '<p>Then 1-0 later.</p><p>67\' not here</p>' ) );
	}

	public function test_short_name(): void {
		$this->assertSame( 'Boro', Match_Clock::short_name( 'Middlesbrough' ) );
		$this->assertSame( 'Hull', Match_Clock::short_name( 'Hull City' ) );
		$this->assertSame( 'Blackburn', Match_Clock::short_name( 'Blackburn Rovers' ) );
		$this->assertSame( 'Wrexham', Match_Clock::short_name( 'Wrexham' ) );
		$this->assertSame( 'QPR', Match_Clock::short_name( 'Queens Park Rangers' ) );
		$this->assertSame( 'Man Utd', Match_Clock::short_name( 'Manchester United' ) );
		$this->assertSame( 'Derby', Match_Clock::short_name( 'Derby County' ) );
		$this->assertSame( 'United', Match_Clock::short_name( 'United' ) );
		$this->assertSame( 'Bournemouth', Match_Clock::short_name( 'AFC Bournemouth' ) );
		$this->assertSame( 'Palace', Match_Clock::short_name( 'Crystal Palace' ) );
		$this->assertSame( 'Peterborough', Match_Clock::short_name( 'Peterborough United' ) );
		$this->assertSame( 'Smoggies', Match_Clock::short_name( 'Middlesbrough', [ 'Middlesbrough' => 'Smoggies' ] ) );
	}
}
