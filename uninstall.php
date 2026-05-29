<?php
declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ── Options ───────────────────────────────────────────────────────────────────

delete_option( 'nop_indieweb_settings' );
delete_option( 'nop_indieweb_db_version' );
delete_option( 'nop_indieweb_settings_autoload_off' );

// Legacy standalone options written by early versions of the plugin.
delete_option( 'nop_indieweb_mastodon_profile_url' );
delete_option( 'nop_indieweb_pixelfed_profile_url' );

// ── Post meta ────────────────────────────────────────────────────────────────

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'nop\_indieweb\_%'" );

// ── Venue category taxonomy ──────────────────────────────────────────────────

$venue_terms = get_terms( [
	'taxonomy'   => 'nop_venue_category',
	'hide_empty' => false,
	'fields'     => 'ids',
] );
if ( is_array( $venue_terms ) ) {
	foreach ( $venue_terms as $term_id ) {
		wp_delete_term( (int) $term_id, 'nop_venue_category' );
	}
}

// ── Webmention comments ───────────────────────────────────────────────────────

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$comment_ids = $wpdb->get_col(
	"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_type = 'webmention'"
);

if ( $comment_ids ) {
	// $ids is a comma-joined list of intval-cast integers — safe to interpolate;
	// $wpdb->prepare() can't bind a variable-length IN() list cleanly. One-off
	// data cleanup at uninstall, so DirectQuery/NoCaching don't apply.
	$ids = implode( ',', array_map( 'intval', $comment_ids ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN ({$ids})" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_ID IN ({$ids})" );
}

// ── Cached map images ────────────────────────────────────────────────────────

$uploads  = wp_upload_dir();
$maps_dir = ( $uploads['basedir'] ?? '' ) . '/checkin-maps';
if ( $maps_dir && is_dir( $maps_dir ) ) {
	foreach ( (array) glob( $maps_dir . '/checkin-map-*.png' ) as $file ) {
		wp_delete_file( $file );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- removing our own now-empty cache dir at uninstall; WP_Filesystem is not bootstrapped in the uninstall context
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- removing our own now-empty cache dir at uninstall; WP_Filesystem is not bootstrapped in the uninstall context
	@rmdir( $maps_dir );
}

// ── Custom table ─────────────────────────────────────────────────────────────

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}nop_indieweb_tokens" );

// ── Scheduled events ─────────────────────────────────────────────────────────

wp_clear_scheduled_hook( 'nop_indieweb_import_feeds' );
wp_clear_scheduled_hook( 'nop_indieweb_send_webmentions' );
