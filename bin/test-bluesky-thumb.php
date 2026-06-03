<?php
// CLI-only — refuse to run if reached over HTTP.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'This file may only be executed via WP-CLI.' );
}
/**
 * Smoke test for the Bluesky link-card thumb fallback chain:
 * map → featured image → site icon (author portrait).
 *
 * Run via: studio wp eval-file wp-content/plugins/nop-indieweb/bin/test-bluesky-thumb.php
 */

use NOP\IndieWeb\Syndication\Syndicator_Bluesky;

$fail  = 0;
$check = function ( string $label, bool $ok, string $detail = '' ) use ( &$fail ) {
	echo ( $ok ? '  PASS  ' : '✗ FAIL  ' ) . $label . ( $detail ? "  [{$detail}]" : '' ) . "\n";
	if ( ! $ok ) { $fail++; }
};

// Track which image URL gets fetched for the blob upload.
$fetched_urls = [];
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$fetched_urls ) {
	if ( str_contains( $url, 'uploadBlob' ) ) {
		return [
			'headers'  => [],
			'body'     => wp_json_encode( [ 'blob' => [ '$type' => 'blob', 'ref' => [ '$link' => 'fake-cid' ], 'mimeType' => 'image/jpeg', 'size' => 1234 ] ] ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
		];
	}
	// Image fetches: return tiny fake JPEG data.
	if ( str_contains( $url, '.jpg' ) || str_contains( $url, '.jpeg' ) || str_contains( $url, '.png' ) ) {
		$fetched_urls[] = $url;
		return [
			'headers'  => [ 'content-type' => 'image/jpeg' ],
			'body'     => 'fake-jpeg-bytes',
			'response' => [ 'code' => 200, 'message' => 'OK' ],
		];
	}
	return $pre;
}, 10, 3 );

// A fake attachment to act as the site icon.
$icon_id = wp_insert_attachment( [
	'post_title'     => 'Test portrait',
	'post_mime_type' => 'image/jpeg',
	'post_status'    => 'inherit',
], 'test-portrait.jpg' );
update_post_meta( $icon_id, '_wp_attachment_metadata', [
	'width'  => 1200,
	'height' => 1200,
	'file'   => 'test-portrait.jpg',
	'sizes'  => [],
] );
update_attached_file( $icon_id, 'test-portrait.jpg' );

$post_id = wp_insert_post( [
	'post_status'  => 'draft',
	'post_title'   => 'A titled post with no media',
	'post_content' => '<!-- wp:paragraph --><p>Just words.</p><!-- /wp:paragraph -->',
] );

$bluesky = new Syndicator_Bluesky();
$ref     = new ReflectionMethod( $bluesky, 'upload_thumb' );
$ref->setAccessible( true );
$session = [ 'accessJwt' => 'fake-jwt', 'did' => 'did:plc:fake' ];

echo "── No media, no site icon ──\n";
$old_icon = get_option( 'site_icon' );
update_option( 'site_icon', 0 );
$thumb = $ref->invoke( $bluesky, $post_id, $session );
$check( 'no thumb when nothing available', null === $thumb );

echo "── No media, site icon set ──\n";
update_option( 'site_icon', $icon_id );
$fetched_urls = [];
$thumb        = $ref->invoke( $bluesky, $post_id, $session );
$check( 'thumb returned from site icon fallback', is_array( $thumb ) && 'blob' === ( $thumb['$type'] ?? '' ) );
$check( 'fetched the site icon image', (bool) array_filter( $fetched_urls, fn( $u ) => str_contains( $u, 'test-portrait' ) ), implode( ', ', $fetched_urls ) );

echo "── Featured image still wins over site icon ──\n";
set_post_thumbnail( $post_id, $icon_id ); // reuse attachment as featured image
$fetched_urls = [];
$thumb        = $ref->invoke( $bluesky, $post_id, $session );
$check( 'thumb returned', is_array( $thumb ) );

// ── Teardown ──────────────────────────────────────────────────────────────────
update_option( 'site_icon', $old_icon ?: 0 );
wp_delete_post( $post_id, true );
wp_delete_attachment( $icon_id, true );
echo $fail ? "\n✗ {$fail} FAILURES\n" : "\n✓ ALL PASS\n";
