<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Venue;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the per-venue "Nth visit" meta accurate as checkin posts are deleted,
 * trashed, or restored by renumbering the remaining visits chronologically.
 */
class Venue_Visit_Counter {

	public function register(): void {
		add_action( 'before_delete_post', [ $this, 'renumber_venue_visits_on_delete' ] );
		add_action( 'trashed_post', [ $this, 'renumber_venue_visits_on_status_change' ] );
		add_action( 'untrashed_post', [ $this, 'renumber_venue_visits_on_status_change' ] );
	}

	public function renumber_venue_visits_on_delete( int $post_id ): void {
		if ( 'post' !== get_post_type( $post_id ) ) {
			return;
		}
		$venue_id = $this->get_checkin_venue_id( $post_id );
		if ( $venue_id ) {
			$this->renumber_checkins_for_venue( $venue_id, $post_id );
		}
	}

	public function renumber_venue_visits_on_status_change( int $post_id ): void {
		if ( 'post' !== get_post_type( $post_id ) ) {
			return;
		}
		$venue_id = $this->get_checkin_venue_id( $post_id );
		if ( $venue_id ) {
			$this->renumber_checkins_for_venue( $venue_id );
		}
	}

	private function get_checkin_venue_id( int $post_id ): string {
		return (string) ( get_post_meta( $post_id, 'nop_indieweb_venue_uid', true )
			?: get_post_meta( $post_id, 'nop_indieweb_venue_fsq_id', true ) );
	}

	private function renumber_checkins_for_venue( string $venue_id, int $exclude_id = 0 ): void {
		global $wpdb;
		// $exclude_id is an int; cast and inline directly. Do NOT $wpdb->prepare()
		// it here — the whole query is prepared below, and pre-preparing a fragment
		// then interpolating it leads to a double-prepare that mangles placeholders.
		$exclude = $exclude_id ? 'AND p.ID != ' . (int) $exclude_id : '';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- direct query against a custom plugin table / one-off maintenance query; no core API or persistent object cache applies
		$post_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT p.ID
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			 WHERE m.meta_key IN ('nop_indieweb_venue_uid', 'nop_indieweb_venue_fsq_id')
			 AND m.meta_value = %s
			 AND p.post_type = 'post'
			 AND p.post_status IN ('publish', 'draft', 'private')
			 {$exclude}
			 ORDER BY p.post_date ASC",
			$venue_id
		) );
		// phpcs:enable
		foreach ( $post_ids as $i => $post_id ) {
			update_post_meta( (int) $post_id, 'nop_indieweb_venue_visit_number', $i + 1 );
		}
	}
}
