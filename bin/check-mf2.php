<?php
/**
 * MF2 regression check.
 *
 * Renders a real check-in post through WordPress and asserts that the
 * expected microformat classes are present on the rendered output. Run
 * after any change to bindings, block rendering, templates, or patterns
 * to catch silent mf2 regressions.
 *
 * Usage:
 *   studio wp eval-file wp-content/plugins/nop-indieweb/bin/check-mf2.php
 *   studio wp eval-file wp-content/plugins/nop-indieweb/bin/check-mf2.php 519
 *
 * Argument is an optional post ID; defaults to the most recent check-in.
 *
 * Exits 0 if all assertions pass, 1 if any fail.
 */

declare( strict_types=1 );

global $argv;
$post_id = 0;
foreach ( $argv ?? [] as $arg ) {
	if ( ctype_digit( (string) $arg ) ) {
		$post_id = (int) $arg;
		break;
	}
}

if ( ! $post_id ) {
	$latest = get_posts( [
		'post_type'      => 'post',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'tax_query'      => [
			[ 'taxonomy' => 'nop_kind', 'field' => 'slug', 'terms' => 'checkin' ],
		],
	] );
	$post_id = $latest[0] ?? 0;
}

if ( ! $post_id ) {
	fwrite( STDERR, "No check-in post found to test against.\n" );
	exit( 1 );
}

$post = get_post( $post_id );
if ( ! $post || 'publish' !== $post->post_status ) {
	fwrite( STDERR, "Post $post_id is not published.\n" );
	exit( 1 );
}

echo "Checking mf2 on post #$post_id ({$post->post_title})\n";
echo str_repeat( '─', 60 ) . "\n";

// Render the single-post template chain to get the full rendered HTML.
// We use the_content + manual post setup rather than a full request because
// running through the front controller is heavy and SG cache may intercept.
$queried_post = $post;
$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
setup_postdata( $post );

// Render the active checkin template by rendering the template file's blocks.
$template_path = NOP_INDIEWEB_DIR . 'templates/single-nop_kind-checkin.html';
if ( ! is_readable( $template_path ) ) {
	fwrite( STDERR, "Template not readable: $template_path\n" );
	exit( 1 );
}
$template_html = (string) file_get_contents( $template_path );
$rendered      = do_blocks( $template_html );

wp_reset_postdata();

// Each assertion: a description and a regex that should match the rendered HTML.
$assertions = [
	'p-adr (full address paragraph)'               => '/class="[^"]*\bp-adr\b/',
	'p-category (venue category term link)'        => '/class="[^"]*\bp-category\b/',
];

$pass = 0;
$fail = 0;
foreach ( $assertions as $label => $pattern ) {
	if ( preg_match( $pattern, $rendered ) ) {
		echo "  ✓ $label\n";
		$pass++;
	} else {
		echo "  ✗ $label\n";
		$fail++;
	}
}

echo str_repeat( '─', 60 ) . "\n";
echo "Passed: $pass / " . count( $assertions ) . "\n";

if ( $fail ) {
	exit( 1 );
}
