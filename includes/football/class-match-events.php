<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Football;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Match events from API-Football (/fixtures/events), in the shape stored on a
 * match post, and what the page reads from them. No WordPress, so it is
 * unit-tested in tests/php/MatchEventsTest.php.
 *
 * A stored event: minute ("67", "90+3"), type (goal, penalty, own-goal,
 * missed-penalty, yellow, second-yellow, red, sub, var), side (home or away:
 * the team the event belongs to), player, assist (a sub's player coming on),
 * detail (the API's own wording, e.g. "Goal cancelled").
 */
class Match_Events {

	/**
	 * @param array<mixed> $response The API's `response` list.
	 * @param int          $home_id  The home team's API id.
	 * @return array<int,array{minute:string,type:string,side:string,player:string,assist:string,detail:string}>
	 */
	public static function from_api( array $response, int $home_id ): array {
		$events = [];
		foreach ( $response as $e ) {
			if ( ! is_array( $e ) ) {
				continue;
			}
			$detail = (string) ( $e['detail'] ?? '' );
			$type   = self::type( (string) ( $e['type'] ?? '' ), $detail );
			if ( '' === $type ) {
				continue;
			}
			$elapsed  = (int) ( $e['time']['elapsed'] ?? 0 );
			$extra    = (int) ( $e['time']['extra'] ?? 0 );
			$events[] = [
				'minute' => $elapsed . ( $extra ? '+' . $extra : '' ),
				'type'   => $type,
				'side'   => (int) ( $e['team']['id'] ?? 0 ) === $home_id ? 'home' : 'away',
				'player' => (string) ( $e['player']['name'] ?? '' ),
				'assist' => (string) ( $e['assist']['name'] ?? '' ),
				'detail' => $detail,
			];
		}
		usort( $events, fn( $a, $b ) => self::sort_key( $a['minute'] ) <=> self::sort_key( $b['minute'] ) );
		return $events;
	}

	private static function type( string $type, string $detail ): string {
		$d = strtolower( $detail );
		switch ( strtolower( $type ) ) {
			case 'goal':
				return match ( true ) {
					str_contains( $d, 'missed' )  => 'missed-penalty',
					str_contains( $d, 'own' )     => 'own-goal',
					str_contains( $d, 'penalty' ) => 'penalty',
					default                       => 'goal',
				};
			case 'card':
				return match ( true ) {
					str_contains( $d, 'second' ) => 'second-yellow',
					str_contains( $d, 'red' )    => 'red',
					default                      => 'yellow',
				};
			case 'subst':
				return 'sub';
			case 'var':
				return 'var';
		}
		return '';
	}

	/** Orders minutes: before kick-off, 1…45, 45+n, HT, 46…90, 90+n, extra time, FT. */
	public static function sort_key( string $minute ): float {
		if ( '' === $minute ) {
			return -1.0;
		}
		if ( 'HT' === $minute ) {
			return 45.99;
		}
		if ( 'FT' === $minute ) {
			return 999.0;
		}
		$parts = explode( '+', $minute );
		return (float) (int) $parts[0] + ( isset( $parts[1] ) ? (int) $parts[1] / 100 : 0 );
	}

	/** Whether an event put the ball in the net (an own goal counts for the other side). */
	public static function is_goal( array $event ): bool {
		return in_array( $event['type'] ?? '', [ 'goal', 'penalty', 'own-goal' ], true );
	}

	/** The side a goal counts for. */
	public static function scoring_side( array $event ): string {
		$side = (string) ( $event['side'] ?? 'home' );
		return 'own-goal' === ( $event['type'] ?? '' ) ? ( 'home' === $side ? 'away' : 'home' ) : $side;
	}

	/**
	 * The events with the running score on each goal ("0–1").
	 *
	 * @param array<int,array<string,string>> $events Stored events, in minute order.
	 * @return array<int,array<string,string>>
	 */
	public static function with_score( array $events ): array {
		$home = 0;
		$away = 0;
		foreach ( $events as $i => $event ) {
			if ( self::is_goal( $event ) ) {
				'home' === self::scoring_side( $event ) ? ++$home : ++$away;
				$events[ $i ]['score'] = $home . '–' . $away;
			}
		}
		return $events;
	}

	/**
	 * The scorers under each team, as a scoreboard shows them: "Conway 34′, 71′ (pen)".
	 *
	 * @param array<int,array<string,string>> $events Stored events, in minute order.
	 * @return array{home:string,away:string}
	 */
	public static function scorers( array $events ): array {
		$names = [ 'home' => [], 'away' => [] ];
		foreach ( $events as $event ) {
			if ( ! self::is_goal( $event ) ) {
				continue;
			}
			$mark = [ 'penalty' => ' (pen)', 'own-goal' => ' (og)' ][ $event['type'] ] ?? '';
			$side = self::scoring_side( $event );
			$name = '' !== $event['player'] ? $event['player'] : '?';
			$names[ $side ][ $name ][] = $event['minute'] . '′' . $mark;
		}
		$line = fn( array $players ) => implode( ' · ', array_map( fn( $name, $minutes ) => $name . ' ' . implode( ', ', $minutes ), array_keys( $players ), $players ) );
		return [ 'home' => $line( $names['home'] ), 'away' => $line( $names['away'] ) ];
	}
}
