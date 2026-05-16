<?php
// CLI-only — refuse to run if reached over HTTP.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'This file may only be executed via WP-CLI.' );
}
/**
 * Migrate legacy nop_indieweb_venue_categories meta into the nop_venue_category taxonomy.
 *
 * Pre-redesign Swarm checkins stored venue categories as a serialized string
 * array under post meta. The redesign moves them to a real taxonomy. This
 * script reads each post's legacy meta, sets the corresponding terms on the
 * post, and deletes the meta entry once the terms are in place.
 *
 * Run via WP-CLI:
 *   studio wp eval-file wp-content/plugins/nop-indieweb/bin/migrate-venue-categories.php
 *
 * Idempotent: posts whose meta has already been migrated have nothing to do
 * on a second run.
 */

global $wpdb;

$rows = $wpdb->get_results(
	"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'nop_indieweb_venue_categories'"
);

if ( ! $rows ) {
	WP_CLI::success( 'No legacy nop_indieweb_venue_categories meta found — nothing to migrate.' );
	return;
}

$migrated = 0;
$skipped  = 0;

foreach ( $rows as $row ) {
	$post_id = (int) $row->post_id;
	$value   = maybe_unserialize( $row->meta_value );

	if ( ! is_array( $value ) ) {
		WP_CLI::line( "  skip {$post_id}: value is not an array" );
		$skipped++;
		continue;
	}

	$names = array_values( array_filter( array_map( 'trim', array_map( 'strval', $value ) ) ) );
	if ( ! $names ) {
		delete_post_meta( $post_id, 'nop_indieweb_venue_categories' );
		WP_CLI::line( "  cleared {$post_id}: empty array, meta deleted" );
		$skipped++;
		continue;
	}

	$result = wp_set_object_terms( $post_id, $names, 'nop_venue_category', false );
	if ( is_wp_error( $result ) ) {
		WP_CLI::warning( "  fail {$post_id}: " . $result->get_error_message() );
		continue;
	}

	delete_post_meta( $post_id, 'nop_indieweb_venue_categories' );
	WP_CLI::line( "  migrated {$post_id}: " . implode( ', ', $names ) );
	$migrated++;
}

WP_CLI::success( "Done. {$migrated} migrated, {$skipped} skipped." );
