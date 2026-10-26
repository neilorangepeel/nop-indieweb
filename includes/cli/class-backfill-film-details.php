<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Cli;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use NOP\IndieWeb\Lookup\Film_Details;
use WP_CLI;

/**
 * `wp nop-indieweb backfill-film-details`
 *
 * Fills a watched film's director, genres, runtime and country from TMDB on
 * watches that have none yet. Two TMDB requests per film at most.
 */
class Backfill_Film_Details {

	/**
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Refetch watches that already have details.
	 *
	 * [--limit=<n>]
	 * : Stop after this many watches. Re-run to continue.
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$force = isset( $assoc_args['force'] );
		$limit = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;
		$query = [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- a CLI backfill, not a hot path.
			'tax_query'      => [ [ 'taxonomy' => 'nop_kind', 'field' => 'slug', 'terms' => 'watch' ] ],
		];
		if ( ! $force ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a CLI backfill, not a hot path.
			$query['meta_query'] = [ [ 'key' => 'nop_indieweb_film_director', 'compare' => 'NOT EXISTS' ] ];
		}
		$ids   = get_posts( $query );
		$done  = 0;
		$skips = 0;
		foreach ( $ids as $post_id ) {
			if ( $limit && $done >= $limit ) {
				break;
			}
			$why = Film_Details::fill( (int) $post_id );
			if ( '' === $why ) {
				$done++;
				WP_CLI::log( "  ✓ #{$post_id} " . get_post_meta( $post_id, 'nop_indieweb_film_title', true ) );
			} else {
				$skips++;
				WP_CLI::log( "  – #{$post_id} {$why}" );
				if ( 'no TMDB key' === $why ) {
					WP_CLI::error( 'No TMDB API key configured (Settings → Lookups).' );
				}
			}
		}
		WP_CLI::success( sprintf( '%d filled · %d skipped · %d found', $done, $skips, count( $ids ) ) );
	}
}
