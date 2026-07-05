<?php
declare( strict_types=1 );

namespace NOP\IndieWeb;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-gated diagnostic logging (only runs when debug_mode is enabled)
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

	// WASM PHP (WordPress Studio / Playground) tunnels every outbound HTTP
	// call through the host process. Its DNS shim returns a sentinel address
	// in 172.x for every hostname, which would always trip the private-range
	// check below even though no real private-network access is possible
	// from inside the WASM sandbox. Skip the pre-check there and let
	// wp_safe_remote_get rely on the host runtime's own egress boundary.
	// Real PHP hosts don't match this string, so production behaviour is
	// unchanged.
	if ( str_contains( php_uname(), 'Emscripten' ) ) {
		return true;
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
	// Intermediate hops don't need streaming or huge bodies — we just need the
	// status line and Location header. Force a 1 MB cap unless the caller set
	// something smaller, and strip any stream/filename options.
	$hop_args = $args + [ 'redirection' => 0 ];
	$hop_args['limit_response_size'] = min( (int) ( $args['limit_response_size'] ?? PHP_INT_MAX ), 1024 * 1024 );
	unset( $hop_args['stream'], $hop_args['filename'] );

	// strict_ip_check=false: this helper has always leaned on wp_safe_remote_get's
	// own per-hop host/IP validation rather than the extra is_safe_url pre-resolve.
	$result = nop_indieweb_walk_safe_redirects( $url, $hop_args, $max_hops, false );
	return is_wp_error( $result ) ? $result : $result['url'];
}

/**
 * Shared redirect-walker behind nop_indieweb_resolve_url_safely() and
 * nop_indieweb_strict_remote_get().
 *
 * Walks the redirect chain one hop at a time with redirection=0, re-validating
 * scheme and (optionally) the resolved IP on every hop, detecting loops, and
 * stripping Authorization on cross-host redirects — the logic both callers must
 * keep identical. The streaming download path (Service_Base::safe_download_to_tmp)
 * keeps its own loop because it truncates each hop's body to a tmp file and so
 * doesn't fit this buffered-response shape.
 *
 * @param string $url             Starting URL.
 * @param array  $hop_args        Per-hop wp_safe_remote_get args (must include redirection=0).
 *                                Copied locally before dropping Authorization across hosts.
 * @param int    $max_hops        Maximum redirect chain length.
 * @param bool   $strict_ip_check When true, also run nop_indieweb_is_safe_url()
 *                                before each hop (blocks 169.254/16 etc. that
 *                                wp_safe_remote_get misses).
 * @return array{url:string,response:array}|\WP_Error  Final URL + response, or error.
 */
function nop_indieweb_walk_safe_redirects( string $url, array $hop_args, int $max_hops, bool $strict_ip_check ): array|\WP_Error {
	$visited = [];

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
		// notably 169.254/16 where cloud-metadata services live. The overlap with
		// wp_safe_remote_get's own check below is intentional belt-and-braces.
		if ( $strict_ip_check && ! nop_indieweb_is_safe_url( $url ) ) {
			return new \WP_Error( 'nop_blocked_ip', 'URL resolves to a private or reserved IP range.' );
		}

		// wp_safe_remote_get validates the URL's resolved IP isn't private/loopback.
		$response = wp_safe_remote_get( $url, $hop_args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 300 || $code >= 400 ) {
			return [ 'url' => $url, 'response' => $response ];
		}

		$location = wp_remote_retrieve_header( $response, 'location' );
		if ( ! $location ) {
			return [ 'url' => $url, 'response' => $response ];
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
	$hop_args = $args + [ 'redirection' => 0 ];

	// strict_ip_check=true: also pre-resolve and reject private/reserved ranges
	// (169.254/16 etc.) that wp_safe_remote_get's own check misses.
	$result = nop_indieweb_walk_safe_redirects( $url, $hop_args, $max_hops, true );
	return is_wp_error( $result ) ? $result : $result['response'];
}

/**
 * Returns a local URL for the check-in map image, fetching it from Geoapify
 * and saving it as a WP attachment on the first call. Subsequent calls return
 * the cached URL from post meta — zero external requests at page-render time.
 *
 * The image is fetched at 2× the display dimensions so it is retina-ready
 * when served at the 1× width/height set in the img tag.
 */
function nop_indieweb_get_or_cache_map_image( int $post_id, float $lat, float $lng, int $width, int $height, string $api_key ): string {
	return nop_indieweb_cache_static_map( $post_id, $lat, $lng, $width, $height, $api_key, 'nop_indieweb_map_url', 18, 'checkin-maps', 'checkin-map' );
}

/**
 * Fetches and disk-caches a Geoapify static map image for an exercise workout
 * start location. Stores the result URL in nop_indieweb_exercise_map_url meta.
 *
 * Mirrors nop_indieweb_get_or_cache_map_image() but uses a separate cache
 * directory (exercise-maps/) and a different meta key so exercise and checkin
 * maps never collide.
 */
function nop_indieweb_get_or_cache_exercise_map_image( int $post_id, float $lat, float $lng, int $width, int $height, string $api_key ): string {
	return nop_indieweb_cache_static_map( $post_id, $lat, $lng, $width, $height, $api_key, 'nop_indieweb_exercise_map_url', 15, 'exercise-maps', 'exercise-map' );
}

/**
 * Shared implementation behind the check-in and exercise map cachers: fetch a
 * Geoapify static map at 2× the display size, store it under uploads/<subdir>/
 * as <prefix>-<post_id>.png and record the local URL in <meta_key>. Returns the
 * cached URL on later calls and '' on any failure.
 */
function nop_indieweb_cache_static_map( int $post_id, float $lat, float $lng, int $width, int $height, string $api_key, string $meta_key, int $zoom, string $subdir, string $file_prefix ): string {
	$cached = (string) get_post_meta( $post_id, $meta_key, true );
	if ( $cached ) {
		return $cached;
	}

	$marker_color = apply_filters( 'nop_indieweb_map_marker_color', 'e03232' );

	$api_url = sprintf(
		'https://maps.geoapify.com/v1/staticmap?style=osm-carto&zoom=%d&center=lonlat:%s,%s&marker=lonlat:%s,%s;type:awesome;color:%%23%s;size:small&width=%d&height=%d&apiKey=%s',
		$zoom,
		rawurlencode( (string) $lng ), rawurlencode( (string) $lat ),
		rawurlencode( (string) $lng ), rawurlencode( (string) $lat ),
		rawurlencode( $marker_color ),
		$width * 2, $height * 2,
		rawurlencode( $api_key )
	);

	// 4 MB ceiling on the response. A 1240×620 PNG (the upper bound we ever ask
	// Geoapify for) is well under 1 MB in practice — the cap is just a guard
	// against an unbounded write to disk if the upstream misbehaves.
	$response = wp_safe_remote_get( $api_url, [
		'timeout'             => 8,
		'limit_response_size' => 4 * 1024 * 1024,
	] );
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return '';
	}

	$content_type = wp_remote_retrieve_header( $response, 'content-type' );
	if ( ! str_starts_with( (string) $content_type, 'image/' ) ) {
		return '';
	}

	$upload_dir = wp_upload_dir();
	$maps_dir   = $upload_dir['basedir'] . '/' . $subdir;
	if ( ! wp_mkdir_p( $maps_dir ) ) {
		return '';
	}

	$file = $maps_dir . "/{$file_prefix}-{$post_id}.png";
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- direct write to a plugin-owned cache dir; WP_Filesystem adds no value for this binary image write
	if ( false === file_put_contents( $file, wp_remote_retrieve_body( $response ) ) ) {
		return '';
	}

	$local_url = $upload_dir['baseurl'] . "/{$subdir}/{$file_prefix}-{$post_id}.png";
	update_post_meta( $post_id, $meta_key, $local_url );

	return $local_url;
}

function nop_indieweb_ordinal( int $n ): string {
	$abs = abs( $n );
	$mod = $abs % 100;
	if ( $mod >= 11 && $mod <= 13 ) {
		return $n . 'th';
	}
	return match ( $abs % 10 ) {
		1       => $n . 'st',
		2       => $n . 'nd',
		3       => $n . 'rd',
		default => $n . 'th',
	};
}

/**
 * Counts the number of checkins at a Foursquare venue that predate $post_date,
 * then returns $prior_count + 1 as the visit ordinal for the given post.
 * Pass the current post's ID in $exclude_id to avoid counting it if it was
 * already inserted before this call runs.
 */
function nop_indieweb_compute_venue_visit_number( string $venue_id, string $post_date, int $exclude_id = 0 ): int {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- direct query against a custom plugin table / one-off maintenance query; no core API or persistent object cache applies
	$prior = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT p.ID)
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
		 WHERE m.meta_key IN ('nop_indieweb_venue_uid', 'nop_indieweb_venue_fsq_id')
		 AND m.meta_value = %s
		 AND p.post_type = 'post'
		 AND p.post_status IN ('publish', 'draft', 'private')
		 AND p.post_date < %s
		 AND p.ID != %d",
		$venue_id,
		$post_date,
		$exclude_id
	) );
	return $prior + 1;
}

/**
 * The site owner's own profile URLs, gathered from the identity settings and
 * each configured syndicator. Used to advertise rel=me links and to recognise
 * the owner's own silo accounts when back-feeding interactions.
 *
 * @return string[]
 */
function nop_indieweb_me_urls(): array {
	$urls = [];

	$custom = nop_indieweb_get_option( 'me_urls', '' );
	foreach ( array_filter( array_map( 'trim', explode( "\n", (string) $custom ) ) ) as $url ) {
		$urls[] = esc_url_raw( $url );
	}

	$mastodon_url = (string) nop_indieweb_get_option( 'syndicators.mastodon.profile_url', '' );
	if ( $mastodon_url ) {
		$urls[] = $mastodon_url;
	}

	$bluesky_handle = (string) nop_indieweb_get_option( 'syndicators.bluesky.handle', '' );
	if ( $bluesky_handle ) {
		$urls[] = 'https://bsky.app/profile/' . $bluesky_handle;
	}

	$pixelfed_url = (string) nop_indieweb_get_option( 'syndicators.pixelfed.profile_url', '' );
	if ( $pixelfed_url ) {
		$urls[] = $pixelfed_url;
	}

	return array_values( array_unique( array_filter( $urls ) ) );
}

/**
 * Normalises a URL for loose comparison: lowercased, scheme stripped, trailing
 * slash removed. Enough to match the same silo resource across the small
 * spelling differences between Bridgy and the internal API poller.
 */
function nop_indieweb_wm_norm_url( string $url ): string {
	$url = strtolower( trim( $url ) );
	$url = (string) preg_replace( '~^https?://~', '', $url );
	return rtrim( $url, '/' );
}

/**
 * True when a back-fed interaction was authored by the site owner's own silo
 * account — i.e. our own syndicated copy boomeranging back as a reply to the
 * post that spawned it. Filterable via `nop_indieweb_wm_drop_self` (default on).
 */
function nop_indieweb_wm_is_self_author( string $author_url ): bool {
	if ( '' === trim( $author_url ) || ! apply_filters( 'nop_indieweb_wm_drop_self', true ) ) {
		return false;
	}
	$needle = nop_indieweb_wm_norm_url( $author_url );
	foreach ( nop_indieweb_me_urls() as $me ) {
		if ( '' !== $me && $needle === nop_indieweb_wm_norm_url( $me ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Extracts the stable per-post identifier from a silo interaction URL: the
 * Bluesky record key (`…/post/{rkey}` or `…feed.post/{rkey}`) or the numeric
 * Mastodon/Pixelfed status id. Returns '' when neither shape matches.
 */
function nop_indieweb_wm_silo_post_id( string $url ): string {
	if ( preg_match( '~(?:/post/|feed\.post/)([^/?#]+)~', $url, $m ) ) {
		return $m[1];
	}
	if ( preg_match( '~/(\d+)(?:[/?#].*)?$~', $url, $m ) ) {
		return $m[1];
	}
	return '';
}

/**
 * A platform-agnostic dedup key for a back-fed interaction, so the same silo
 * event stored via Bridgy and via the internal poller collapses to one row.
 *
 * Replies key on the reply's own record id (identical across Bridgy's handle
 * URLs and the poller's DID URLs); likes/reposts key on the actor's profile
 * URL, which both paths record the same way.
 */
function nop_indieweb_wm_silo_key( string $type, string $silo_url, string $author_url ): string {
	if ( 'reply' === $type ) {
		$id = nop_indieweb_wm_silo_post_id( $silo_url );
		return 'reply:' . ( '' !== $id ? $id : nop_indieweb_wm_norm_url( $silo_url ) );
	}
	return $type . ':' . nop_indieweb_wm_norm_url( $author_url );
}

/**
 * Returns the id of an existing webmention comment on $post_id carrying
 * $silo_key, or 0. Used to cross-dedup Bridgy webmentions against the internal
 * API poller (and vice versa).
 */
function nop_indieweb_wm_find_by_silo_key( int $post_id, string $silo_key ): int {
	if ( 0 === $post_id || '' === $silo_key ) {
		return 0;
	}
	$ids = get_comments( [
		'post_id'    => $post_id,
		'type'       => 'webmention',
		'status'     => 'all',
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- low-frequency back-feed dedup lookup, not a hot path
		'meta_key'   => 'webmention_silo_key',
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- low-frequency back-feed dedup lookup, not a hot path
		'meta_value' => $silo_key,
		'number'     => 1,
		'fields'     => 'ids',
	] );
	return isset( $ids[0] ) ? (int) $ids[0] : 0;
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
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- direct query against a custom plugin table / one-off maintenance query; no core API or persistent object cache applies
	$wpdb->update(
		$wpdb->options,
		[ 'autoload' => 'no' ],
		[ 'option_name' => 'nop_indieweb_settings' ],
		[ '%s' ],
		[ '%s' ]
	);

	update_option( 'nop_indieweb_settings_autoload_off', 1, false );
}
