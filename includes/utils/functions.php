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
 * Stricter SSRF check than wp_safe_remote_get's built-in validation.
 *
 * WordPress core's `wp_http_validate_url` only rejects a hardcoded list of
 * private ranges (127.x, 10.x, 172.16-32.x, 192.168.x) and notably misses:
 *   - 169.254.0.0/16 — link-local, where cloud metadata services live
 *     (AWS, GCP, Azure all serve credentials from 169.254.169.254)
 *   - 100.64.0.0/10 — carrier-grade NAT
 *   - IPv6 link-local (fe80::/10) and ULA (fc00::/7)
 *
 * This helper resolves the host's IP and rejects every private + reserved
 * range using PHP's filter_var flags, which cover all of the above.
 *
 * @return bool  True when the URL's resolved IP is on the public internet.
 */
function nop_indieweb_is_safe_url( string $url ): bool {
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	if ( '' === $host ) {
		return false;
	}

	// If the URL already contains a literal IP, validate it directly. Otherwise
	// resolve the hostname. gethostbyname returns the input string unchanged
	// when resolution fails — we treat that as unsafe.
	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		$ip = $host;
	} else {
		$ip = gethostbyname( $host );
		if ( $ip === $host ) {
			return false;
		}
	}

	// FILTER_FLAG_NO_PRIV_RANGE: 10/8, 127/8, 172.16/12, 192.168/16, IPv6 fc00::/7 + fe80::/10 + ::1
	// FILTER_FLAG_NO_RES_RANGE:  0/8, 169.254/16, 192.0.0/24, 192.0.2/24, 198.51.100/24, 203.0.113/24, 224/4, 240/4
	return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
}

/**
 * Resolves a URL through its redirect chain, re-running wp_safe_remote_get's
 * host/IP validation on every hop.
 *
 * Closes a known WordPress-core SSRF gap: `wp_safe_remote_get` validates the
 * initial host against private/loopback IPs, but when the response is a 3xx
 * redirect, WordPress follows the Location header internally without
 * re-validating. A hostile server can return `Location: http://127.0.0.1:...`
 * and reach internal services that should never be reachable.
 *
 * This helper sets redirection=0 on each hop, manually re-issues the request
 * for each Location, and runs the wp_safe_remote_get validation on every
 * URL. Authorization headers are stripped on cross-host redirects (matches
 * cURL / WP core behaviour).
 *
 * @param string $url       Starting URL.
 * @param array  $args      Same shape as wp_safe_remote_get args.
 * @param int    $max_hops  Maximum redirect chain length.
 * @return string|\WP_Error  Final resolved URL on success.
 */
function nop_indieweb_resolve_url_safely( string $url, array $args = [], int $max_hops = 3 ): string|\WP_Error {
	$visited = [];

	// Intermediate hops don't need streaming or huge bodies — we just need the
	// status line and Location header. Force a 1 MB cap unless the caller set
	// something smaller, and strip any stream/filename options.
	$hop_args = $args + [ 'redirection' => 0 ];
	$hop_args['limit_response_size'] = min( (int) ( $args['limit_response_size'] ?? PHP_INT_MAX ), 1024 * 1024 );
	unset( $hop_args['stream'], $hop_args['filename'] );

	for ( $hop = 0; $hop <= $max_hops; $hop++ ) {
		$url_key = strtolower( $url );
		if ( isset( $visited[ $url_key ] ) ) {
			return new \WP_Error( 'nop_redirect_loop', 'Redirect loop detected.' );
		}
		$visited[ $url_key ] = true;

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return new \WP_Error( 'nop_invalid_scheme', 'URL must use http(s).' );
		}

		// wp_safe_remote_get validates the URL's resolved IP isn't private/loopback.
		$response = wp_safe_remote_get( $url, $hop_args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 300 || $code >= 400 ) {
			return $url;
		}

		$location = wp_remote_retrieve_header( $response, 'location' );
		if ( ! $location ) {
			return $url;
		}

		$next = \WP_Http::make_absolute_url( $location, $url );
		if ( ! is_string( $next ) || '' === $next ) {
			return new \WP_Error( 'nop_bad_redirect', 'Could not resolve redirect target.' );
		}

		// Strip Authorization on cross-host redirects — matches WP core / cURL.
		$prev_host = strtolower( (string) wp_parse_url( $url,  PHP_URL_HOST ) );
		$next_host = strtolower( (string) wp_parse_url( $next, PHP_URL_HOST ) );
		if ( $prev_host !== $next_host && isset( $hop_args['headers']['Authorization'] ) ) {
			unset( $hop_args['headers']['Authorization'] );
		}

		$url = $next;
	}

	return new \WP_Error( 'nop_too_many_redirects', 'Exceeded redirect cap.' );
}

/**
 * Drop-in replacement for wp_safe_remote_get that re-validates every redirect
 * hop against private/loopback IPs.
 *
 * Single request per hop: on the no-redirect path (the common case for all
 * call sites in this plugin), this is one wp_safe_remote_get call with zero
 * extra network round-trips. Each redirect adds one more request — same as
 * native redirect-following would.
 *
 * Use this for any outbound HTTP call where the URL ultimately traces back to
 * user-supplied or third-party input.
 *
 * @return array|\WP_Error  Same shape as wp_safe_remote_get.
 */
function nop_indieweb_strict_remote_get( string $url, array $args = [], int $max_hops = 3 ): array|\WP_Error {
	$visited  = [];
	$hop_args = $args + [ 'redirection' => 0 ];

	for ( $hop = 0; $hop <= $max_hops; $hop++ ) {
		$url_key = strtolower( $url );
		if ( isset( $visited[ $url_key ] ) ) {
			return new \WP_Error( 'nop_redirect_loop', 'Redirect loop detected.' );
		}
		$visited[ $url_key ] = true;

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return new \WP_Error( 'nop_invalid_scheme', 'URL must use http(s).' );
		}

		// Block private + reserved IP ranges that wp_safe_remote_get doesn't —
		// notably 169.254/16 where cloud-metadata services live.
		if ( ! nop_indieweb_is_safe_url( $url ) ) {
			return new \WP_Error( 'nop_blocked_ip', 'URL resolves to a private or reserved IP range.' );
		}

		// wp_safe_remote_get also rejects 127.x/10.x/172.16-32/192.168 — overlap
		// is intentional belt-and-braces in case the host's DNS answer changes
		// between our pre-resolve and WP's own resolve.
		$response = wp_safe_remote_get( $url, $hop_args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 300 || $code >= 400 ) {
			return $response;
		}

		$location = wp_remote_retrieve_header( $response, 'location' );
		if ( ! $location ) {
			return $response;
		}

		$next = \WP_Http::make_absolute_url( $location, $url );
		if ( ! is_string( $next ) || '' === $next ) {
			return new \WP_Error( 'nop_bad_redirect', 'Could not resolve redirect target.' );
		}

		// Strip Authorization on cross-host redirects — matches WP core / cURL.
		$prev_host = strtolower( (string) wp_parse_url( $url,  PHP_URL_HOST ) );
		$next_host = strtolower( (string) wp_parse_url( $next, PHP_URL_HOST ) );
		if ( $prev_host !== $next_host && isset( $hop_args['headers']['Authorization'] ) ) {
			unset( $hop_args['headers']['Authorization'] );
		}

		$url = $next;
	}

	return new \WP_Error( 'nop_too_many_redirects', 'Exceeded redirect cap.' );
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
