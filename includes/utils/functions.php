<?php
declare( strict_types=1 );

namespace NOP\IndieWeb;

// Returns the full URL to the Micropub REST endpoint.
function nop_indieweb_endpoint_url(): string {
	return rest_url( 'nop-indieweb/v1/micropub' );
}

// Retrieves a single value from the plugin's settings array.
// Supports dot notation for nested keys: 'syndicators.mastodon.enabled'.
function nop_indieweb_get_option( string $key, mixed $default = null ): mixed {
	$options = get_option( 'nop_indieweb_settings', [] );

	if ( ! str_contains( $key, '.' ) ) {
		return $options[ $key ] ?? $default;
	}

	$current = $options;
	foreach ( explode( '.', $key ) as $segment ) {
		if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
			return $default;
		}
		$current = $current[ $segment ];
	}
	return $current;
}

// Saves a single value into the plugin's settings array.
// Supports dot notation for nested keys: 'syndicators.mastodon.profile_url'.
//
// The settings option is stored with autoload=false because it carries
// third-party credentials (Bluesky app password, Mastodon/Pixelfed access
// tokens). Autoloading would put plaintext secrets in memory on every
// front-end request and in WP's object cache.
function nop_indieweb_update_option( string $key, mixed $value ): bool {
	$options = get_option( 'nop_indieweb_settings', [] );

	if ( ! str_contains( $key, '.' ) ) {
		$options[ $key ] = $value;
		return update_option( 'nop_indieweb_settings', $options, false );
	}

	$segments = explode( '.', $key );
	$cursor   = &$options;
	foreach ( array_slice( $segments, 0, -1 ) as $segment ) {
		if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) {
			$cursor[ $segment ] = [];
		}
		$cursor = &$cursor[ $segment ];
	}
	$cursor[ end( $segments ) ] = $value;

	return update_option( 'nop_indieweb_settings', $options, false );
}

/**
 * Recursively redacts known secret keys from an array before logging.
 *
 * Defensive belt-and-braces: even if a future call site passes credentials
 * into the logger by accident, these keys will be replaced with [redacted].
 */
function nop_indieweb_redact_for_log( mixed $value ): mixed {
	static $sensitive = [
		'access_token',
		'refresh_token',
		'authorization',
		'app_password',
		'password',
		'secret_token',
		'client_secret',
		'code_verifier',
		'token',
		'bearer',
		'api_key',
		'apikey',
	];

	if ( is_array( $value ) ) {
		$out = [];
		foreach ( $value as $k => $v ) {
			if ( is_string( $k ) && in_array( strtolower( $k ), $sensitive, true ) ) {
				$out[ $k ] = '[redacted]';
				continue;
			}
			$out[ $k ] = nop_indieweb_redact_for_log( $v );
		}
		return $out;
	}

	return $value;
}

// Writes to the error log only when debug mode is enabled in settings.
// Context arrays are run through the redactor so secrets cannot leak.
function nop_indieweb_log( string $message, mixed $context = null ): void {
	if ( ! nop_indieweb_get_option( 'debug_mode', false ) ) {
		return;
	}
	$entry = '[NOP IndieWeb] ' . $message;
	if ( null !== $context ) {
		$entry .= ' ' . wp_json_encode( nop_indieweb_redact_for_log( $context ) );
	}
	error_log( $entry );
}

/**
 * One-time migration: flip the existing settings option to autoload=false.
 * Idempotent and cheap — runs on plugins_loaded behind a version flag.
 */
function nop_indieweb_maybe_disable_settings_autoload(): void {
	if ( get_option( 'nop_indieweb_settings_autoload_off', false ) ) {
		return;
	}

	global $wpdb;
	$wpdb->update(
		$wpdb->options,
		[ 'autoload' => 'no' ],
		[ 'option_name' => 'nop_indieweb_settings' ],
		[ '%s' ],
		[ '%s' ]
	);

	update_option( 'nop_indieweb_settings_autoload_off', 1, false );
}
