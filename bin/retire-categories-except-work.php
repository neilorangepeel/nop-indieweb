<?php
// CLI-only — refuse to run if reached over HTTP.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'This file may only be executed via WP-CLI.' );
}
/**
 * Retire every category except Work (STRUCTURE.md in the Orange Peel theme, 2026-11-05).
 *
 *   wp eval-file wp-content/plugins/nop-indieweb/bin/retire-categories-except-work.php          # dry run: counts only
 *   wp eval-file wp-content/plugins/nop-indieweb/bin/retire-categories-except-work.php apply    # make the changes
 *
 * Removes every category but Work from posts, deletes those terms (all but the site's default
 * category, which WordPress needs to exist; it is left empty), and clears the old per-service
 * `post_category` settings. Old /category/<slug>/ addresses redirect (Logbook::redirect()).
 * Idempotent: a second run finds nothing to do.
 */

$apply   = in_array( 'apply', (array) ( $args ?? [] ), true );
$keep    = [ 'work' ];
$default = (int) get_option( 'default_category' );

WP_CLI::line( $apply ? 'APPLYING changes.' : 'DRY RUN: nothing is changed. Add "apply" to make the changes.' );

$retire = [];
foreach ( get_terms( [ 'taxonomy' => 'category', 'hide_empty' => false ] ) as $term ) {
	if ( ! in_array( $term->slug, $keep, true ) ) {
		$retire[ $term->term_id ] = $term;
	}
}

$posts_touched = 0;
foreach ( $retire as $term ) {
	$ids = get_objects_in_term( $term->term_id, 'category' );
	$ids = is_wp_error( $ids ) ? [] : $ids;
	WP_CLI::line( sprintf( '  %-28s %5d posts%s', html_entity_decode( $term->name ), count( $ids ), $term->term_id === $default ? '  (default: emptied, kept)' : '' ) );
	foreach ( $ids as $post_id ) {
		$posts_touched++;
		if ( $apply ) {
			wp_remove_object_terms( (int) $post_id, $term->term_id, 'category' );
		}
	}
	if ( $apply && $term->term_id !== $default ) {
		wp_delete_term( $term->term_id, 'category' );
	}
}

// Old per-service default categories (the setting no longer exists).
$option  = get_option( 'nop_indieweb_settings', [] );
$cleared = 0;
foreach ( [ 'services', 'syndicators' ] as $group ) {
	foreach ( (array) ( $option[ $group ] ?? [] ) as $key => $settings ) {
		if ( is_array( $settings ) && array_key_exists( 'post_category', $settings ) ) {
			$cleared++;
			unset( $option[ $group ][ $key ]['post_category'] );
		}
	}
}
if ( $apply && $cleared ) {
	update_option( 'nop_indieweb_settings', $option );
}

$work = get_term_by( 'slug', 'work', 'category' );
WP_CLI::line( '' );
WP_CLI::line( sprintf( 'Categories retired: %d. Post-category links removed: %d. Old service settings cleared: %d.', count( $retire ) - ( isset( $retire[ $default ] ) ? 1 : 0 ), $posts_touched, $cleared ) );
WP_CLI::line( sprintf( 'Work keeps its %d posts.', $work ? (int) $work->count : 0 ) );
$apply ? WP_CLI::success( 'Done.' ) : WP_CLI::success( 'Dry run finished.' );
