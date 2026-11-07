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

	/** "Middlesbrough FC" → "Middlesbrough"; "Wrexham AFC" → "Wrexham". */
	public static function team_name( string $name ): string {
		return trim( (string) preg_replace( '/\s+A?FC$/', '', trim( $name ) ) );
	}

	/** The season a date belongs to, as openfootball names it: 2026-11-07 → "2026-27". */
	public static function season( string $ymd ): string {
		$y = (int) substr( $ymd, 0, 4 );
		$m = (int) substr( $ymd, 5, 2 );
		$start = $m >= 7 ? $y : $y - 1;
		return sprintf( '%d-%02d', $start, ( $start + 1 ) % 100 );
	}

	private static function plain( string $text ): string {
		return trim( html_entity_decode( strip_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
