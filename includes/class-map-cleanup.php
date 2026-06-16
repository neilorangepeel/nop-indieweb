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
 */
class Map_Cleanup {

	public function register(): void {
		add_action( 'before_delete_post', [ $this, 'delete_map_image' ] );
	}

	public function delete_map_image( int $post_id ): void {
		$basedir = wp_upload_dir()['basedir'];

		if ( get_post_meta( $post_id, 'nop_indieweb_map_url', true ) ) {
			$file = $basedir . "/checkin-maps/checkin-map-{$post_id}.png";
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}

		if ( get_post_meta( $post_id, 'nop_indieweb_exercise_map_url', true ) ) {
			$file = $basedir . "/exercise-maps/exercise-map-{$post_id}.png";
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}
}
