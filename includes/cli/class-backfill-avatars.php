<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Cli;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use NOP\IndieWeb\Webmention\Avatar_Cache;
use WP_CLI;

/**
 * `wp nop-indieweb backfill-avatars`
 *
 * Saves every webmention author's photo on this site and points the comment at
 * the local copy, so visitors' browsers never contact the author's host.
 * Photos that cannot be fetched are cleared (the fallback icon shows instead).
 * Idempotent: already-local photos are skipped.
 */
class Backfill_Avatars {

	/**
	 * Cache remote webmention avatars locally.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything.
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		global $wpdb;
		$dry_run = isset( $assoc_args['dry-run'] );

		$rows = $wpdb->get_results( "SELECT meta_id, meta_value FROM {$wpdb->commentmeta} WHERE meta_key = 'webmention_author_photo' AND meta_value <> ''" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off maintenance command.
		$done = $skipped = $failed = 0;

		foreach ( $rows as $row ) {
			if ( Avatar_Cache::is_local( $row->meta_value ) ) {
				$skipped++;
				continue;
			}
			if ( $dry_run ) {
				$done++;
				continue;
			}
			$local = Avatar_Cache::cache( $row->meta_value );
			$local ? $done++ : $failed++;
			$wpdb->update( $wpdb->commentmeta, [ 'meta_value' => $local ], [ 'meta_id' => $row->meta_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		WP_CLI::success( sprintf( '%d cached, %d already local, %d could not be fetched (cleared).', $done, $skipped, $failed ) );
	}
}
