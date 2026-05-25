<?php
// CLI-only — refuse to run if reached over HTTP.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'This file may only be executed via WP-CLI.' );
}
/**
 * Fix checkin posts where the shout paragraph was duplicated.
 *
 * Root cause: a Micropub UPDATE received after the initial create (e.g.
 * OwnYourSwarm updating the checkin with the same shout text) triggered
 * plain_text_to_block() which prepended a second identical paragraph instead
 * of detecting that the match was a no-op.
 *
 * This script finds all Swarm posts whose post_content contains two
 * consecutive identical <!-- wp:paragraph --> blocks and removes the duplicate.
 *
 * Idempotent: posts already clean are skipped.
 *
 * Run via WP-CLI:
 *   studio wp eval-file wp-content/plugins/nop-indieweb/bin/fix-duplicate-checkin-shout.php
 *
 * Add -- --dry-run to preview without writing.
 */

$args    = WP_CLI::get_runner()->arguments ?? [];
$dry_run = in_array( 'dry-run', $args, true );
if ( $dry_run ) {
	WP_CLI::log( 'DRY RUN — no posts will be modified.' );
}

$posts = get_posts( [
	'post_type'      => 'post',
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'no_found_rows'  => true,
	'meta_query'     => [ [
		'key'   => 'nop_indieweb_service',
		'value' => 'swarm',
	] ],
] );

WP_CLI::log( sprintf( 'Scanning %d Swarm posts…', count( $posts ) ) );

$fixed  = 0;
$clean  = 0;

// Matches two consecutive identical paragraph blocks separated by a blank line.
$pattern = '/(' . preg_quote( '<!-- wp:paragraph -->', '/' ) . '\n<p>(.*?)<\/p>\n' . preg_quote( '<!-- /wp:paragraph -->', '/' ) . ')\n\n\1/s';

foreach ( $posts as $post_id ) {
	$content = get_post_field( 'post_content', $post_id );
	if ( '' === trim( $content ) ) {
		$clean++;
		continue;
	}

	$updated = preg_replace( $pattern, '$1', $content, -1, $count );

	if ( $count === 0 || null === $updated ) {
		$clean++;
		continue;
	}

	$title = get_the_title( $post_id );
	WP_CLI::log( sprintf( '  [%d] %s — removing %d duplicate paragraph(s)', $post_id, $title, $count ) );

	if ( ! $dry_run ) {
		wp_update_post( [
			'ID'           => $post_id,
			'post_content' => $updated,
		] );
	}

	$fixed++;
}

WP_CLI::success( sprintf(
	'%s: %d fixed, %d already clean.',
	$dry_run ? 'Dry run complete' : 'Done',
	$fixed,
	$clean
) );
