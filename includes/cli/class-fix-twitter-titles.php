<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Cli;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_CLI;

/**
 * `wp nop-indieweb fix-twitter-titles`
 *
 * Cleans up imported Twitter-archive post titles: strips the leading
 * "D Mon YYYY ·" date prefix, decodes HTML entities, capitalises the first
 * character, and regenerates the slug (post_name) to match. Idempotent —
 * a post already in the clean form is skipped.
 *
 * ## OPTIONS
 *
 * [--limit=<n>]
 * : Process at most N posts (0 = all).
 *
 * [--dry-run]
 * : Show before/after without writing.
 *
 * @when after_wp_load
 */
class Fix_Twitter_Titles {

	public function __invoke( array $args, array $assoc_args ): void {
		global $wpdb;
		$limit   = (int) ( $assoc_args['limit'] ?? 0 );
		$dry_run = isset( $assoc_args['dry-run'] );

		$ids = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => $limit > 0 ? $limit : -1,
			'fields'         => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-off maintenance command, not a hot path
			'meta_key'       => 'nop_indieweb_service',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one-off maintenance command, not a hot path
			'meta_value'     => 'twitter-archive',
		] );

		$total = count( $ids );
		WP_CLI::log( ( $dry_run ? '[DRY RUN] ' : '' ) . "{$total} Twitter-archive posts" );

		$progress = $dry_run ? null : \WP_CLI\Utils\make_progress_bar( 'Retitling', $total );
		$changed  = 0;
		$skipped  = 0;

		foreach ( $ids as $id ) {
			$old_title = (string) get_post_field( 'post_title', $id );
			$new_title = self::clean_title( $old_title );

			// Title already clean → nothing to do. (Skip on title alone so re-runs
			// don't churn slugs.)
			if ( $new_title === $old_title ) {
				$skipped++;
				if ( $progress ) {
					$progress->tick();
				}
				continue;
			}

			$new_slug = wp_unique_post_slug(
				self::slug( $new_title ), $id, (string) get_post_field( 'post_status', $id ), 'post', 0
			);

			if ( $dry_run ) {
				if ( $changed < 15 ) {
					WP_CLI::log( "  {$old_title}\n   → {$new_title}  [/{$new_slug}]" );
				}
				$changed++;
				continue;
			}

			// Direct write: no wp_update_post so we fire no save_post hooks (which
			// would queue per-post cite-enrich / Wayback-archive cron events — 12k of
			// them bloated the autoloaded cron option) and create no revisions.
			$wpdb->update(
				$wpdb->posts,
				[ 'post_title' => $new_title, 'post_name' => $new_slug ],
				[ 'ID' => $id ]
			);
			clean_post_cache( $id );
			$changed++;
			$progress->tick();
		}

		if ( $progress ) {
			$progress->finish();
		}
		WP_CLI::success( ( $dry_run ? '[DRY RUN] ' : '' ) . "{$changed} retitled · {$skipped} already clean" );
	}

	/** Strip "D Mon YYYY ·" prefix, decode entities, capitalise first char. */
	public static function clean_title( string $title ): string {
		$t = preg_replace( '/^\d{1,2}\s+[A-Za-z]{3,}\s+\d{4}\s*·\s*/u', '', $title );
		$t = html_entity_decode( (string) $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Drop a trailing URL fragment the excerpt ran into ("… x.com/Boro/status/18…"),
		// keeping the truncation ellipsis if there was one.
		$t = preg_replace( '~\s+\S*(?:x\.com|t\.co|pic\.twitter\.com|https?://)\S*(…)?$~iu', '$1', (string) $t );
		$t = trim( (string) $t );
		if ( '' === $t ) {
			return $title; // nothing to do — leave as-is
		}
		return mb_strtoupper( mb_substr( $t, 0, 1 ) ) . mb_substr( $t, 1 );
	}

	/** ASCII slug from the cleaned title (emoji/curly punctuation dropped). */
	public static function slug( string $title ): string {
		$s = str_replace( [ '’', '‘', '`' ], '', $title );      // keep contractions tight: who’s → whos
		$s = preg_replace( '/[^\x00-\x7F]+/u', ' ', $s );        // drop emoji / non-ASCII
		return sanitize_title( (string) $s );
	}
}
