<?php
/**
 * PHPUnit bootstrap for the plugin's pure-logic unit tests.
 *
 * These tests run with NO WordPress install — they exercise side-effect-free
 * parsing logic in isolation. The handful of WordPress functions the code under
 * test calls are stubbed here with behaviour faithful enough for the assertions
 * (whitespace/tag sanitising, word trimming, URL parsing). Anything that would
 * touch the network, the database, or WP globals is out of scope by design; only
 * classes that don't need a bootstrapped WP belong in this suite.
 */
declare( strict_types=1 );

// The class files guard on ABSPATH; define it so requiring them doesn't exit.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/** Strip tags, collapse all whitespace (incl. newlines) to single spaces, trim. */
	function sanitize_text_field( $str ) {
		$str = wp_strip_all_tags( (string) $str );
		$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
		return trim( $str );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/** Like sanitize_text_field but preserves newlines. */
	function sanitize_textarea_field( $str ) {
		$str = wp_strip_all_tags( (string) $str );
		// Collapse spaces/tabs but keep line breaks.
		$str = preg_replace( '/[ \t]+/', ' ', $str );
		return trim( $str );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $str ) {
		$str = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $str );
		return (string) strip_tags( $str );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/** The tests feed already-clean absolute URLs; trim is enough for assertions. */
	function esc_url_raw( $url ) {
		return trim( (string) $url );
	}
}

if ( ! function_exists( 'wp_kses' ) ) {
	/** Minimal stand-in: drop any tag whose name isn't a key in $allowed; keep text. */
	function wp_kses( $string, $allowed ) {
		return preg_replace_callback(
			'/<\/?([a-zA-Z0-9]+)[^>]*>/',
			static function ( $m ) use ( $allowed ) {
				return isset( $allowed[ strtolower( $m[1] ) ] ) ? $m[0] : '';
			},
			(string) $string
		);
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
}

if ( ! function_exists( 'wp_trim_words' ) ) {
	function wp_trim_words( $text, $num_words = 55, $more = null ) {
		if ( null === $more ) {
			$more = ' …';
		}
		$text  = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		$words = $text === '' ? [] : explode( ' ', $text );
		if ( count( $words ) <= $num_words ) {
			return $text;
		}
		return implode( ' ', array_slice( $words, 0, $num_words ) ) . $more;
	}
}

require_once dirname( __DIR__, 2 ) . '/includes/rsvp/class-event-parser.php';
require_once dirname( __DIR__, 2 ) . '/includes/syndication/class-tumblr-client.php';
require_once dirname( __DIR__, 2 ) . '/includes/utils/functions.php';
