<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Cli;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use NOP\IndieWeb\Football\Match_Day;

/**
 * Start a match by hand, or look up a fixture.
 *
 *   wp nop-indieweb match fixture --date=2026-11-07
 *   wp nop-indieweb match start                    # today's fixture
 *   wp nop-indieweb match start --date=2026-11-04  # a past one, for testing
 */
class Match_CLI {

	/**
	 * Print the team's fixture on a day.
	 *
	 * [--date=<date>]
	 * : Y-m-d, UK time. Default today.
	 */
	public function fixture( array $args, array $assoc ): void {
		$fixture = Match_Day::fixture_on( $this->time( $assoc ) );
		if ( ! $fixture ) {
			\WP_CLI::error( 'No fixture that day.' );
		}
		\WP_CLI::line( sprintf( '%s v %s, %s, kick-off %s, score %s', $fixture['home'], $fixture['away'], $fixture['competition'], wp_date( 'D j M Y H:i', $fixture['kickoff'] ), '' !== $fixture['score'] ? $fixture['score'] : 'not known' ) );
	}

	/**
	 * Create the match post for a day's fixture (or return the one that exists).
	 *
	 * [--date=<date>]
	 * : Y-m-d, UK time. Default today.
	 */
	public function start( array $args, array $assoc ): void {
		$fixture = Match_Day::fixture_on( $this->time( $assoc ) );
		if ( ! $fixture ) {
			\WP_CLI::error( 'No fixture that day.' );
		}
		$id = Match_Day::create( $fixture );
		if ( ! $id ) {
			\WP_CLI::error( 'Could not create the match post.' );
		}
		\WP_CLI::success( sprintf( 'Match post %d: %s', $id, get_permalink( $id ) ) );
	}

	private function time( array $assoc ): int {
		if ( empty( $assoc['date'] ) ) {
			return time();
		}
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', (string) $assoc['date'] . ' 12:00', new \DateTimeZone( 'Europe/London' ) );
		if ( ! $day ) {
			\WP_CLI::error( 'Use --date=YYYY-MM-DD.' );
		}
		return $day->getTimestamp();
	}
}
