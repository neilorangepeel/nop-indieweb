<?php
declare( strict_types=1 );

use NOP\IndieWeb\Football\Match_Events;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/football/class-match-events.php';

final class MatchEventsTest extends TestCase {

	/** A /fixtures/events response in API-Football's v3 shape: Wrexham (home, 1) v Middlesbrough (away, 2). */
	private function response(): array {
		$e = fn( $min, $extra, $team, $type, $detail, $player, $assist = null ) => [
			'time'   => [ 'elapsed' => $min, 'extra' => $extra ],
			'team'   => [ 'id' => $team, 'name' => 1 === $team ? 'Wrexham' : 'Middlesbrough' ],
			'player' => [ 'id' => 0, 'name' => $player ],
			'assist' => [ 'id' => null, 'name' => $assist ],
			'type'   => $type,
			'detail' => $detail,
		];
		return [
			$e( 71, null, 2, 'Goal', 'Normal Goal', 'H. Hackney' ),
			$e( 23, null, 1, 'Card', 'Yellow Card', 'J. Greaves' ),
			$e( 33, null, 2, 'Goal', 'Normal Goal', 'T. Conway', 'H. Hackney' ),
			$e( 45, 2, 1, 'Card', 'Second Yellow card', 'J. Greaves' ),
			$e( 58, null, 1, 'Goal', 'Penalty', 'S. Okafor' ),
			$e( 67, null, 2, 'Var', 'Penalty cancelled', 'T. Conway' ),
			$e( 78, null, 2, 'subst', 'Substitution 1', 'T. Conway', 'M. Barlow' ),
			$e( 88, null, 1, 'Goal', 'Missed Penalty', 'S. Okafor' ),
			$e( 90, 3, 1, 'Goal', 'Own Goal', 'P. Dyer' ),
			[ 'type' => 'Unknown' ],
			'junk',
		];
	}

	public function test_normalises_and_orders_by_minute(): void {
		$events = Match_Events::from_api( $this->response(), 1 );
		$this->assertSame( [ '23', '33', '45+2', '58', '67', '71', '78', '88', '90+3' ], array_column( $events, 'minute' ) );
		$this->assertSame( [ 'yellow', 'goal', 'second-yellow', 'penalty', 'var', 'goal', 'sub', 'missed-penalty', 'own-goal' ], array_column( $events, 'type' ) );
		$this->assertSame( 'away', $events[1]['side'] );
		$this->assertSame( 'H. Hackney', $events[1]['assist'] );
		$this->assertSame( 'M. Barlow', $events[6]['assist'] );
		$this->assertSame( 'Penalty cancelled', $events[4]['detail'] );
	}

	public function test_running_score_counts_own_goals_for_the_other_side(): void {
		$events = Match_Events::with_score( Match_Events::from_api( $this->response(), 1 ) );
		$scores = array_values( array_filter( array_map( fn( $e ) => $e['score'] ?? null, $events ) ) );
		$this->assertSame( [ '0–1', '1–1', '1–2', '1–3' ], $scores );
		$this->assertArrayNotHasKey( 'score', $events[7] ); // a missed penalty is not a goal
	}

	public function test_scorers_per_side(): void {
		$scorers = Match_Events::scorers( Match_Events::from_api( $this->response(), 1 ) );
		$this->assertSame( 'S. Okafor 58 pen', $scorers['home'] );
		$this->assertSame( 'T. Conway 33, H. Hackney 71, P. Dyer 90+3 og', $scorers['away'] );
	}

	public function test_same_player_twice_shares_a_name(): void {
		$events = [
			[ 'minute' => '12', 'type' => 'goal', 'side' => 'home', 'player' => 'Conway', 'assist' => '', 'detail' => '' ],
			[ 'minute' => '80', 'type' => 'penalty', 'side' => 'home', 'player' => 'Conway', 'assist' => '', 'detail' => '' ],
		];
		$this->assertSame( 'Conway 12, 80 pen', Match_Events::scorers( $events )['home'] );
	}

	public function test_sort_key(): void {
		$order = [ '', '1', '45', '45+3', 'HT', '46', '90', '90+5', '105', 'FT' ];
		$keys  = array_map( [ Match_Events::class, 'sort_key' ], $order );
		$sorted = $keys;
		sort( $sorted );
		$this->assertSame( $sorted, $keys );
	}
}
