<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Cli;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use NOP\IndieWeb\Kind\Exercise_Type_Taxonomy;
use NOP\IndieWeb\Kind\Kind_Taxonomy;
use WP_CLI;

/**
 * `wp nop-indieweb backfill-exercise-types`
 *
 * Walks every exercise post that has nop_indieweb_exercise_type meta and
 * assigns the matching nop_exercise_type taxonomy term. Safe to re-run —
 * already-tagged posts are skipped unless --force is passed.
 */
class Backfill_Exercise_Types {

	/**
	 * Backfill exercise type taxonomy terms on existing exercise posts.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything.
	 *
	 * [--force]
	 * : Re-tag every post, even those that already have an exercise type term.
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );
		$force   = isset( $assoc_args['force'] );

		$post_ids = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- low-frequency backfill command, not a hot path
			'tax_query'      => [ [
				'taxonomy' => Kind_Taxonomy::TAXONOMY,
				'field'    => 'slug',
				'terms'    => 'exercise',
			] ],
		] );

		$total = count( $post_ids );
		WP_CLI::log( sprintf( 'Found %d exercise post(s).', $total ) );
		if ( 0 === $total ) {
			return;
		}

		$progress = \WP_CLI\Utils\make_progress_bar( $dry_run ? 'Inspecting' : 'Tagging', $total );
		$updated  = 0;
		$skipped  = 0;
		$no_meta  = 0;

		foreach ( $post_ids as $post_id ) {
			$progress->tick();

			if ( ! $force ) {
				$existing = wp_get_object_terms( $post_id, Exercise_Type_Taxonomy::TAXONOMY );
				if ( ! is_wp_error( $existing ) && $existing ) {
					$skipped++;
					continue;
				}
			}

			$type = sanitize_key( (string) get_post_meta( $post_id, 'nop_indieweb_exercise_type', true ) );
			if ( '' === $type ) {
				$no_meta++;
				continue;
			}

			if ( ! $dry_run ) {
				if ( ! term_exists( $type, Exercise_Type_Taxonomy::TAXONOMY ) ) {
					wp_insert_term( ucfirst( $type ), Exercise_Type_Taxonomy::TAXONOMY, [ 'slug' => $type ] );
				}
				wp_set_object_terms( $post_id, $type, Exercise_Type_Taxonomy::TAXONOMY );
			}
			$updated++;
		}

		$progress->finish();

		WP_CLI::success( sprintf(
			'%s%d tagged · %d already tagged · %d without type meta',
			$dry_run ? '[DRY RUN] ' : '',
			$updated,
			$skipped,
			$no_meta
		) );
	}
}
