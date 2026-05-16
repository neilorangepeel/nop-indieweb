<?php
// CLI-only — refuse to run if reached over HTTP.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'This file may only be executed via WP-CLI.' );
}
/**
 * Backfill post_author for plugin-created posts that have post_author = 0.
 *
 * Pre-fix, Service_Base::handle() inserted posts without stamping the token's
 * owning user. WordPress's wp_insert_post() leaves post_author = 0 in that
 * case, which means the mf2 author h-card emitter has no user to render,
 * `get_author_posts_url()` returns the site root, and editor permission
 * checks silently fall back to the global admin policy.
 *
 * This script finds every post with `nop_indieweb_service` meta and
 * `post_author = 0`, then sets the author to the user returned by the
 * `nop_indieweb_default_author_id` filter (default user 1).
 *
 * Run via WP-CLI:
 *   studio wp eval-file wp-content/plugins/nop-indieweb/bin/backfill-post-authors.php
 *
 * Idempotent — re-running on already-stamped posts does nothing.
 */

global $wpdb;

$default_author = (int) apply_filters( 'nop_indieweb_default_author_id', 1 );

if ( ! get_userdata( $default_author ) ) {
	WP_CLI::error( "Default author user {$default_author} does not exist. Set the nop_indieweb_default_author_id filter to a valid user." );
	return;
}

$ids = $wpdb->get_col(
	"SELECT DISTINCT p.ID
	   FROM {$wpdb->posts} p
	   INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
	  WHERE m.meta_key = 'nop_indieweb_service'
	    AND p.post_author = 0"
);

if ( ! $ids ) {
	WP_CLI::success( 'No authorless IndieWeb posts found — nothing to backfill.' );
	return;
}

$updated = 0;
foreach ( $ids as $id ) {
	$post_id = (int) $id;
	$result  = $wpdb->update(
		$wpdb->posts,
		[ 'post_author' => $default_author ],
		[ 'ID' => $post_id ],
		[ '%d' ],
		[ '%d' ]
	);
	if ( false === $result ) {
		WP_CLI::warning( "  fail {$post_id}: db update returned false" );
		continue;
	}
	clean_post_cache( $post_id );
	WP_CLI::line( "  set post_author={$default_author} on post {$post_id}" );
	$updated++;
}

WP_CLI::success( "Done. {$updated} of " . count( $ids ) . " posts updated." );
