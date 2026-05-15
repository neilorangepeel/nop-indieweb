<?php
// CLI-only — refuse to run if reached over HTTP.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'This file may only be executed via WP-CLI.' );
}
/**
 * Performance benchmark for the strict-redirect helper.
 *
 * Times wp_safe_remote_get vs nop_indieweb_strict_remote_get against the
 * actual public endpoints the plugin uses. Each helper is called N times
 * sequentially and the wall-clock time is reported.
 *
 * Run via:
 *   wp eval-file wp-content/plugins/nop-indieweb/bin/benchmark-strict-fetch.php
 *
 * Endpoints exercised (chosen because they don't redirect, which isolates
 * the helper's own CPU + per-call overhead from network redirect-following
 * cost):
 *
 *   - https://public.api.bsky.app/xrpc/...resolveHandle  (no redirects)
 *   - https://public.api.bsky.app/xrpc/...getAuthorFeed  (no redirects)
 *
 * For a redirect-aware test, the helper would need to be pointed at a
 * server that issues a 3xx — out of scope for a smoke test (would require
 * a control server). Per-redirect cost is analysed theoretically in the
 * audit notes.
 */

$iterations = 5;
$endpoints  = [
	'bluesky_resolve_handle' => 'https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle?handle=bsky.app',
	'bluesky_author_feed'    => 'https://public.api.bsky.app/xrpc/app.bsky.feed.getAuthorFeed?actor=did:plc:z72i7hdynmk6r22z27h6tvur&limit=5',
];

WP_CLI::line( "Benchmarking {$iterations} sequential calls per endpoint per helper.\n" );

foreach ( $endpoints as $label => $url ) {
	WP_CLI::line( "── {$label} ──" );

	// Warm the connection / DNS cache with a throwaway call so the first
	// measured iteration of each helper isn't skewed by cold-cache costs.
	wp_remote_get( $url, [ 'timeout' => 10 ] );

	$baseline_times = [];
	$strict_times   = [];

	for ( $i = 0; $i < $iterations; $i++ ) {
		$t0 = microtime( true );
		$response = wp_safe_remote_get( $url, [
			'timeout'             => 15,
			'redirection'         => 3,
			'limit_response_size' => 4 * 1024 * 1024,
		] );
		$baseline_times[] = ( microtime( true ) - $t0 ) * 1000;
		if ( is_wp_error( $response ) ) {
			WP_CLI::warning( "baseline error: " . $response->get_error_message() );
		}
	}

	for ( $i = 0; $i < $iterations; $i++ ) {
		$t0 = microtime( true );
		$response = \NOP\IndieWeb\nop_indieweb_strict_remote_get( $url, [
			'timeout'             => 15,
			'limit_response_size' => 4 * 1024 * 1024,
		] );
		$strict_times[] = ( microtime( true ) - $t0 ) * 1000;
		if ( is_wp_error( $response ) ) {
			WP_CLI::warning( "strict error: " . $response->get_error_message() );
		}
	}

	$baseline_median = median( $baseline_times );
	$strict_median   = median( $strict_times );
	$delta_ms        = $strict_median - $baseline_median;
	$delta_pct       = $baseline_median > 0 ? ( $delta_ms / $baseline_median ) * 100 : 0;

	printf(
		"  wp_safe_remote_get        : median %.1f ms (min %.1f, max %.1f)\n",
		$baseline_median, min( $baseline_times ), max( $baseline_times )
	);
	printf(
		"  nop_strict_remote_get     : median %.1f ms (min %.1f, max %.1f)\n",
		$strict_median, min( $strict_times ), max( $strict_times )
	);
	printf(
		"  overhead                  : %+.1f ms (%+.1f%%)\n\n",
		$delta_ms, $delta_pct
	);
}

WP_CLI::success( 'Benchmark complete.' );
WP_CLI::line( 'Notes:' );
WP_CLI::line( '  • Zero-redirect endpoints: overhead = helper CPU + one extra is_wp_error check.' );
WP_CLI::line( '  • Each redirect hop adds 1 extra DNS resolve + TLS handshake (~50-150 ms).' );
WP_CLI::line( '  • All four protected call sites are zero-redirect in normal operation.' );

function median( array $values ): float {
	sort( $values );
	$n = count( $values );
	if ( 0 === $n ) {
		return 0.0;
	}
	$mid = (int) ( $n / 2 );
	return 0 === $n % 2 ? ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2 : $values[ $mid ];
}
