<?php
declare( strict_types=1 );

namespace NOP\IndieWeb;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes the generated static map image for a checkin or exercise post when
 * the post is permanently deleted, so orphaned PNGs don't accumulate in uploads.
 * Also runs the background job that makes a check-in's large map (see
 * nop_indieweb_queue_checkin_map_large()).
 */
class Map_Cleanup {

	public function register(): void {
		add_action( 'before_delete_post', [ $this, 'delete_map_image' ] );
		add_action( 'nop_indieweb_make_large_map', [ $this, 'make_large_map' ] );
		add_action( 'nop_indieweb_make_map_tile', 'NOP\\IndieWeb\\nop_indieweb_make_map_tile', 10, 2 );
	}

	public function make_large_map( int $post_id ): void {
		$key = trim( (string) nop_indieweb_get_option( 'maps.geoapify_api_key', '' ) );
		if ( '' !== $key && 'publish' === get_post_status( $post_id ) ) {
			nop_indieweb_make_checkin_map_large( $post_id, $key );
		}
	}

	public function delete_map_image( int $post_id ): void {
		$basedir = wp_upload_dir()['basedir'];

		if ( get_post_meta( $post_id, 'nop_indieweb_map_url', true ) ) {
			foreach ( [ "checkin-map-{$post_id}.png", "checkin-map-{$post_id}-lg.jpg", "checkin-map-{$post_id}-tile.jpg" ] as $name ) {
				$file = $basedir . '/checkin-maps/' . $name;
				if ( file_exists( $file ) ) {
					wp_delete_file( $file );
				}
			}
		}

		if ( get_post_meta( $post_id, 'nop_indieweb_exercise_map_url', true ) ) {
			foreach ( [ "exercise-map-{$post_id}.png", "exercise-map-{$post_id}-tile.jpg" ] as $name ) {
				$file = $basedir . '/exercise-maps/' . $name;
				if ( file_exists( $file ) ) {
					wp_delete_file( $file );
				}
			}
		}
	}
}
