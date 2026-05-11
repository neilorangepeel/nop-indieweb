<?php
declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ── Options ───────────────────────────────────────────────────────────────────

delete_option( 'nop_indieweb_settings' );
delete_option( 'nop_indieweb_db_version' );

// Legacy standalone options written by early versions of the plugin.
delete_option( 'nop_indieweb_mastodon_profile_url' );
delete_option( 'nop_indieweb_pixelfed_profile_url' );

// ── Post meta ────────────────────────────────────────────────────────────────

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'nop\_indieweb\_%'" );

// ── Webmention comments ───────────────────────────────────────────────────────

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$comment_ids = $wpdb->get_col(
	"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_type = 'webmention'"
);

if ( $comment_ids ) {
	$ids = implode( ',', array_map( 'intval', $comment_ids ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN ({$ids})" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_ID IN ({$ids})" );
}

// ── Custom table ─────────────────────────────────────────────────────────────

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}nop_indieweb_tokens" );

// ── Scheduled events ─────────────────────────────────────────────────────────

wp_clear_scheduled_hook( 'nop_indieweb_import_feeds' );
wp_clear_scheduled_hook( 'nop_indieweb_send_webmentions' );
