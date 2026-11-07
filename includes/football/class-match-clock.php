<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Football;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The pure parts of a match: the clock, and what a take's text says.
 * No WordPress, so it is unit-tested in tests/php/MatchClockTest.php.
 */
class Match_Clock {

	/**
	 * The match minute a moment falls in, from the seconds since kick-off.
	 *
	 * A guess from the clock: about 3 minutes of first-half stoppage, a 15-minute
	 * break and 5 of second-half stoppage. A take that starts with a minute
	 * ("67'") overrides it. '' means before kick-off.
	 */
	public static function minute( int $seconds_since_kickoff ): string {
		if ( $seconds_since_kickoff < 0 ) {
			return '';
		}
		$e = intdiv( $seconds_since_kickoff, 60 );
		if ( $e < 45 ) {
			return (string) ( $e + 1 );
		}
		if ( $e < 48 ) {
			return '45+' . ( $e - 44 );
		}
		if ( $e < 63 ) {
			return 'HT';
		}
		$s = $e - 63;
		if ( $s < 45 ) {
			return (string) ( 46 + $s );
		}
		if ( $s < 50 ) {
			return '90+' . ( $s - 44 );
		}
		return 'FT';
	}

	/** A minute typed at the start of a take: "67'", "45+2'", "HT", "FT". Null when there is none. */
	public static function typed_minute( string $text ): ?string {
		$text = self::plain( $text );
		if ( preg_match( '/^(\d{1,3})(?:\s*\+\s*(\d{1,2}))?\s*[\'’′]/u', $text, $m ) ) {
			return $m[1] . ( isset( $m[2] ) ? '+' . $m[2] : '' );
		}
		if ( preg_match( '/^(HT|FT)\b/i', $text, $m ) ) {
			return strtoupper( $m[1] );
		}
		return null;
	}

	/**
	 * A score at the start of a take, after any minute: "1-0 Conway!" or "67' 2–1".
	 * Home team first, as on a scoreboard. Returned with an en dash; null when there is none.
	 */
	public static function typed_score( string $text ): ?string {
		$text = self::plain( $text );
		$text = (string) preg_replace( '/^(?:\d{1,3}(?:\s*\+\s*\d{1,2})?\s*[\'’′]|HT\b|FT\b)\s*/iu', '', $text );
		if ( preg_match( '/^(\d{1,2})\s*[-–—]\s*(\d{1,2})(?![\d\-–—])/u', $text, $m ) ) {
			return $m[1] . '–' . $m[2];
		}
		return null;
	}

	/** Names a scoreboard shortens its own way. Anything else loses a club suffix (see short_name()). */
	const SHORT_NAMES = [
		'Middlesbrough'           => 'Boro',
		'Queens Park Rangers'     => 'QPR',
		'West Bromwich Albion'    => 'West Brom',
		'Manchester United'       => 'Man Utd',
		'Manchester City'         => 'Man City',
		'Tottenham Hotspur'       => 'Spurs',
		'Wolverhampton Wanderers' => 'Wolves',
		'Nottingham Forest'       => 'Forest',
		'Brighton & Hove Albion'  => 'Brighton',
		'Brighton'                => 'Brighton',
		'Preston North End'       => 'Preston',
		'Sheffield Wednesday'     => 'Sheff Wed',
		'Sheffield Utd'           => 'Sheff Utd',
		'Sheffield United'        => 'Sheff Utd',
		'Crystal Palace'          => 'Palace',
		'Milton Keynes Dons'      => 'MK Dons',
		'MK Dons'                 => 'MK Dons',
		'Newcastle'               => 'Newcastle',
		'Wycombe'                 => 'Wycombe',
	];

	/**
	 * A team's short name for the scoreboard: "Middlesbrough" → "Boro", "Hull City" → "Hull", "Blackburn Rovers" →
	 * "Blackburn", "AFC Bournemouth" → "Bournemouth". A listed name first, else a leading AFC/FC and a trailing club
	 * word dropped when a name is left. (Match_Day stores a hand-set short name per match, which wins over this.)
	 *
	 * @param array<string,string> $names Overrides, as SHORT_NAMES.
	 */
	public static function short_name( string $name, array $names = self::SHORT_NAMES ): string {
		$name = trim( $name );
		if ( isset( $names[ $name ] ) ) {
			return $names[ $name ];
		}
		$short = (string) preg_replace( [ '/^A?FC\s+/u', '/\s+(?:City|Town|United|Utd|Rovers|Athletic|County|Wanderers|Albion|Argyle|Rangers|Wednesday|A?FC)$/u' ], '', $name );
		return '' !== $short ? $short : $name;
	}

	/**
	 * A take's HTML without the minute and score typed at its start ("71' 1-2 HACKNEY." → "HACKNEY."), for the match
	 * page, where the minute column and the events already say them. Only the start of the first paragraph is touched.
	 */
	public static function strip_typed_lead( string $html ): string {
		$minute = '(?:\d{1,3}(?:\s*\+\s*\d{1,2})?\s*(?:[\'’′]|&#8217;|&#039;)|HT\b|FT\b)';
		$score  = '\d{1,2}\s*[-–—]\s*\d{1,2}(?![\d\-–—])';
		return (string) preg_replace( '~^(\s*(?:<!--[^>]*-->\s*)?(?:<p[^>]*>)?)\s*(?:' . $minute . '[.:,]?\s*)?(?:' . $score . '[.:,]?\s*)?~iu', '$1', $html, 1 );
	}

	private static function plain( string $text ): string {
		return trim( html_entity_decode( strip_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
