<?php
// CLI-only — refuse to run if reached over HTTP.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'This file may only be executed via WP-CLI.' );
}
// End-to-end test of the async syndication queue + retry + status journal.
// Run: studio wp eval-file wp-content/plugins/nop-indieweb/bin/test-syndication-retry.php

use NOP\IndieWeb\Syndication\Syndication_Manager;

$fail = 0;
$check = function ( string $label, bool $ok ) use ( &$fail ) {
	echo ( $ok ? '  PASS  ' : '✗ FAIL  ' ) . $label . "\n";
	if ( ! $ok ) { $fail++; }
};

// ── Setup: enable Mastodon with an unreachable instance ──────────────────────
$snapshot = get_option( 'nop_indieweb_settings', [] );
$test_opts = $snapshot;
$test_opts['syndicators']['mastodon']['enabled']      = true;
$test_opts['syndicators']['mastodon']['instance']     = 'https://mastodon-unreachable.invalid';
$test_opts['syndicators']['mastodon']['access_token'] = 'fake-token-for-testing';
update_option( 'nop_indieweb_settings', $test_opts, false );

$post_id = wp_insert_post( [
	'post_status'  => 'publish',
	'post_title'   => '',
	'post_content' => '<!-- wp:paragraph --><p>Retry queue test post</p><!-- /wp:paragraph -->',
	'meta_input'   => [ 'nop_indieweb_service' => 'test' ], // block the wp_after_insert_post auto-path
] );

echo "── Queue ──\n";
$manager = new Syndication_Manager();
$manager->register();
$manager->syndicate( $post_id );

$next = wp_next_scheduled( 'nop_indieweb_syndicate_target', [ $post_id, 'mastodon', 1 ] );
$check( 'cron event queued for (post, mastodon, attempt 1)', false !== $next );

$status = get_post_meta( $post_id, 'nop_indieweb_syndication_status', true );
$check( 'status journal written', is_array( $status ) && isset( $status['mastodon'] ) );
$check( 'state is pending', ( $status['mastodon']['state'] ?? '' ) === 'pending' );
$check( 'attempts is 0 before first run', ( $status['mastodon']['attempts'] ?? -1 ) === 0 );

$bluesky_queued = wp_next_scheduled( 'nop_indieweb_syndicate_target', [ $post_id, 'bluesky', 1 ] );
$check( 'disabled platform (bluesky) NOT queued', false === $bluesky_queued );

echo "── Attempt 1 fails → retry scheduled ──\n";
// Run the worker directly (simulating cron firing).
$manager->run_target( $post_id, 'mastodon', 1 );

$status = get_post_meta( $post_id, 'nop_indieweb_syndication_status', true );
$check( 'still pending after failed attempt 1', ( $status['mastodon']['state'] ?? '' ) === 'pending' );
$check( 'attempts incremented to 1', ( $status['mastodon']['attempts'] ?? -1 ) === 1 );
$check( 'error message captured', '' !== ( $status['mastodon']['error'] ?? '' ) );
echo '        error: ' . ( $status['mastodon']['error'] ?? '(none)' ) . "\n";

$retry = wp_next_scheduled( 'nop_indieweb_syndicate_target', [ $post_id, 'mastodon', 2 ] );
$check( 'retry (attempt 2) scheduled', false !== $retry );
$delay = $retry - time();
$check( 'retry delay ≈ 5 minutes (' . $delay . 's)', $delay > 290 && $delay <= 310 );

echo "── Final attempt fails → permanent failure ──\n";
$manager->run_target( $post_id, 'mastodon', 4 );

$status = get_post_meta( $post_id, 'nop_indieweb_syndication_status', true );
$check( 'state is failed after max attempts', ( $status['mastodon']['state'] ?? '' ) === 'failed' );
$check( 'attempts recorded as 4', ( $status['mastodon']['attempts'] ?? -1 ) === 4 );
$check( 'no attempt 5 scheduled', false === wp_next_scheduled( 'nop_indieweb_syndicate_target', [ $post_id, 'mastodon', 5 ] ) );

echo "── REST retry endpoint ──\n";
wp_set_current_user( 1 );
$request = new WP_REST_Request( 'POST', '/nop-indieweb/v1/syndication/retry' );
$request->set_body_params( [ 'post_id' => $post_id, 'target' => 'mastodon' ] );
$response = rest_do_request( $request );
$check( 'retry endpoint returns 202', 202 === $response->get_status() );

$status = get_post_meta( $post_id, 'nop_indieweb_syndication_status', true );
$check( 'retry resets state to pending', ( $status['mastodon']['state'] ?? '' ) === 'pending' );
$check( 'retry resets attempts to 0', ( $status['mastodon']['attempts'] ?? -1 ) === 0 );

$request2 = new WP_REST_Request( 'POST', '/nop-indieweb/v1/syndication/retry' );
$request2->set_body_params( [ 'post_id' => $post_id, 'target' => 'nonexistent' ] );
$response2 = rest_do_request( $request2 );
$check( 'unknown target returns 404', 404 === $response2->get_status() );

echo "── Skipped platform leaves no trace ──\n";
$manager->run_target( $post_id, 'bluesky', 1 );
$status = get_post_meta( $post_id, 'nop_indieweb_syndication_status', true );
$check( 'bluesky (disabled) has no journal entry', ! isset( $status['bluesky'] ) );

echo "── Successful syndication path (mocked via pre_http_request) ──\n";
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( str_contains( $url, '/api/v1/statuses' ) ) {
		return [
			'headers'  => [],
			'body'     => wp_json_encode( [ 'url' => 'https://mastodon-unreachable.invalid/@test/12345' ] ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
		];
	}
	return $pre;
}, 10, 3 );

$manager->run_target( $post_id, 'mastodon', 1 );
$status = get_post_meta( $post_id, 'nop_indieweb_syndication_status', true );
$urls   = get_post_meta( $post_id, 'nop_indieweb_syndication', true );
$check( 'state is sent', ( $status['mastodon']['state'] ?? '' ) === 'sent' );
$check( 'syndicated URL stored in status', str_contains( $status['mastodon']['url'] ?? '', '/@test/12345' ) );
$check( 'URL appended to nop_indieweb_syndication', is_array( $urls ) && str_contains( $urls[0] ?? '', '/@test/12345' ) );

echo "── Dedup: second run returns existing URL, no duplicate ──\n";
$manager->run_target( $post_id, 'mastodon', 1 );
$urls = get_post_meta( $post_id, 'nop_indieweb_syndication', true );
$check( 'no duplicate URL after re-run', is_array( $urls ) && 1 === count( $urls ) );

// ── Teardown ──────────────────────────────────────────────────────────────────
wp_delete_post( $post_id, true );
update_option( 'nop_indieweb_settings', $snapshot, false );
// Clear any leftover cron events for this post.
wp_unschedule_hook( 'nop_indieweb_syndicate_target' );
$restored = get_option( 'nop_indieweb_settings' );
echo "\nsettings restored: " . ( ( $restored['syndicators']['mastodon']['enabled'] ?? true ) === false ? 'yes' : 'NO — CHECK MANUALLY' ) . "\n";
echo $fail ? "\n✗ {$fail} FAILURES\n" : "\n✓ ALL PASS\n";
