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
function nop_indieweb_update_option( string $key, mixed $value ): bool {
	$options         = get_option( 'nop_indieweb_settings', [] );
	$options[ $key ] = $value;
	return update_option( 'nop_indieweb_settings', $options );
}

// Writes to the error log only when debug mode is enabled in settings.
function nop_indieweb_log( string $message, mixed $context = null ): void {
	if ( ! nop_indieweb_get_option( 'debug_mode', false ) ) {
		return;
	}
	$entry = '[NOP IndieWeb] ' . $message;
	if ( null !== $context ) {
		$entry .= ' ' . wp_json_encode( $context );
	}
	error_log( $entry );
}
