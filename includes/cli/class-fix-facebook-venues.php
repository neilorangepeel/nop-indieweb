<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Cli;

use NOP\IndieWeb\Kind\Venue_Category_Taxonomy;
use WP_CLI;

/**
 * `wp nop-indieweb fix-facebook-venues`
 *
 * Two operations for cleaning up Facebook-imported check-ins:
 *
 * --clear-bad-matches
 *   Detects FSQ venue IDs shared by multiple posts with different venue
 *   names (a clear signal that one or more are wrong). Within each cluster,
 *   posts whose venue name does NOT appear in the stored FSQ address are
 *   cleared (venue uid/url/address/coords/categories/map all deleted).
 *   Also clears airport-named posts that were matched to a non-airport
 *   category.
 *
 * --fix-titles
 *   Updates post titles to "Venue Name, Locality" for posts that have a
 *   locality but whose title is just the venue name. Run after
 *   --clear-bad-matches so wrong localities don't get baked into titles.
 */
class Fix_Facebook_Venues {

	/**
	 * Fix bad FSQ matches and/or update post titles for Facebook checkins.
	 *
	 * ## OPTIONS
	 *
	 * [--clear-bad-matches]
	 * : Detect and clear incorrect FSQ venue matches.
	 *
	 * [--fix-titles]
	 * : Add locality to post titles that are missing it.
	 *
	 * [--dry-run]
	 * : Preview changes without writing anything.
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$dry_run    = isset( $assoc_args['dry-run'] );
		$clear_bad  = isset( $assoc_args['clear-bad-matches'] );
		$fix_titles = isset( $assoc_args['fix-titles'] );

		if ( ! $clear_bad && ! $fix_titles ) {
			WP_CLI::error( 'Specify at least one of: --clear-bad-matches, --fix-titles' );
		}

		$fb_posts = $this->get_fb_post_ids();
		WP_CLI::log( sprintf( 'Found %d Facebook check-in posts.', count( $fb_posts ) ) );

		if ( $clear_bad ) {
			$cleared = $this->clear_bad_matches( $fb_posts, $dry_run );
			// Re-fetch so fix-titles only sees posts with valid locality.
			if ( ! $dry_run && $cleared > 0 ) {
				$fb_posts = $this->get_fb_post_ids();
			}
		}

		if ( $fix_titles ) {
			$this->fix_titles( $fb_posts, $dry_run );
		}
	}

	private function get_fb_post_ids(): array {
		return get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => 'nop_indieweb_platform',
			'meta_value'     => 'facebook',
		] );
	}

	private function clear_bad_matches( array $post_ids, bool $dry_run ): int {
		// Prime the postmeta cache so the loops below serve from memory instead
		// of issuing one DB query per post per meta key (N×3 without this).
		update_postmeta_cache( $post_ids );

		// ── Build UID → posts map ─────────────────────────────────────────────
		$uid_map  = [];
		$progress = WP_CLI\Utils\make_progress_bar(
			'Analysing venue matches',
			count( $post_ids )
		);
		foreach ( $post_ids as $id ) {
			$uid  = (string) get_post_meta( $id, 'nop_indieweb_venue_uid', true );
			$name = (string) get_post_meta( $id, 'nop_indieweb_venue_name', true );
			$progress->tick();
			if ( '' === $uid ) {
				continue;
			}
			$uid_map[ $uid ][] = [ 'id' => $id, 'name' => $name ];
		}
		$progress->finish();

		$to_clear = [];

		// ── Shared-UID clusters ───────────────────────────────────────────────
		foreach ( $uid_map as $uid => $posts ) {
			$distinct_names = array_unique( array_column( $posts, 'name' ) );
			if ( count( $distinct_names ) < 2 ) {
				continue;
			}

			// For each post, check if its venue name appears in the FSQ address.
			// If yes → this post is likely the correct match, keep it.
			// If no  → wrong match, clear it.
			foreach ( $posts as $p ) {
				$address  = strtolower( (string) get_post_meta( $p['id'], 'nop_indieweb_venue_address', true ) );
				$locality = strtolower( (string) get_post_meta( $p['id'], 'nop_indieweb_venue_locality', true ) );
				$haystack = $address . ' ' . $locality;

				// Use first significant word of venue name as the search token.
				$token = strtolower( $p['name'] );
				// Strip trailing city suffix like ", Belfast" added at import.
				$token = preg_replace( '/,\s*.+$/', '', $token );
				// First word only (catches "Crescent" in "Crescent Arts Centre").
				$first_word = explode( ' ', trim( $token ) )[0];

				$matched = $first_word && str_contains( $haystack, $first_word );

				if ( ! $matched ) {
					$to_clear[ $p['id'] ] = $p['name'];
				}
			}
		}

		// ── Airport name + non-airport category ───────────────────────────────
		$airport_cats = [ 'airport', 'international airport', 'airport lounge', 'airport terminal' ];
		foreach ( $post_ids as $id ) {
			if ( isset( $to_clear[ $id ] ) ) {
				continue;
			}
			$name = strtolower( (string) get_post_meta( $id, 'nop_indieweb_venue_name', true ) );
			if ( ! str_contains( $name, 'airport' ) && ! str_contains( $name, 'terminal' ) ) {
				continue;
			}
			$uid = (string) get_post_meta( $id, 'nop_indieweb_venue_uid', true );
			if ( '' === $uid ) {
				continue;
			}
			$terms = wp_get_post_terms( $id, Venue_Category_Taxonomy::TAXONOMY, [ 'fields' => 'names' ] );
			$cats  = is_wp_error( $terms ) ? [] : array_map( 'strtolower', (array) $terms );
			if ( ! array_intersect( $cats, $airport_cats ) ) {
				$to_clear[ $id ] = get_post_meta( $id, 'nop_indieweb_venue_name', true );
			}
		}

		if ( empty( $to_clear ) ) {
			WP_CLI::log( 'No bad matches found.' );
			return 0;
		}

		WP_CLI::log( sprintf(
			'%s %d post(s) with bad FSQ matches:',
			$dry_run ? '[DRY RUN] Would clear' : 'Clearing',
			count( $to_clear )
		) );

		foreach ( $to_clear as $id => $name ) {
			WP_CLI::log( "  #{$id}: {$name}" );
		}

		if ( ! $dry_run ) {
			foreach ( array_keys( $to_clear ) as $id ) {
				$this->clear_venue( $id );
			}
		}

		WP_CLI::success( sprintf(
			'%s%d bad match(es) cleared. Fix individually with FSQ search, then run backfill-checkin-maps.',
			$dry_run ? '[DRY RUN] ' : '',
			count( $to_clear )
		) );

		return count( $to_clear );
	}

	private function clear_venue( int $id ): void {
		delete_post_meta( $id, 'nop_indieweb_venue_uid' );
		delete_post_meta( $id, 'nop_indieweb_venue_url' );
		delete_post_meta( $id, 'nop_indieweb_venue_address' );
		delete_post_meta( $id, 'nop_indieweb_venue_locality' );
		delete_post_meta( $id, 'nop_indieweb_venue_region' );
		delete_post_meta( $id, 'nop_indieweb_venue_country' );
		delete_post_meta( $id, 'nop_indieweb_venue_postcode' );
		delete_post_meta( $id, 'nop_indieweb_venue_lat' );
		delete_post_meta( $id, 'nop_indieweb_venue_lng' );
		delete_post_meta( $id, 'nop_indieweb_map_url' );
		wp_set_object_terms( $id, [], Venue_Category_Taxonomy::TAXONOMY );
	}

	private function fix_titles( array $post_ids, bool $dry_run ): void {
		$updated  = 0;
		$skipped  = 0;
		$progress = WP_CLI\Utils\make_progress_bar( 'Fixing titles', count( $post_ids ) );

		foreach ( $post_ids as $id ) {
			$progress->tick();
			$p        = get_post( $id );
			$name     = (string) get_post_meta( $id, 'nop_indieweb_venue_name', true );
			$locality = (string) get_post_meta( $id, 'nop_indieweb_venue_locality', true );

			if ( ! $name || ! $locality ) {
				$skipped++;
				continue;
			}

			// Skip venue names that already carry geographic context (e.g.
			// "New York, New York" or "Palermo, Italy") — appending a locality
			// would create nonsense like "New York, New York, Belfast".
			if ( str_contains( $name, ',' ) ) {
				$skipped++;
				continue;
			}

			$new_title = "{$name}, {$locality}";
			$current   = html_entity_decode( $p->post_title, ENT_QUOTES | ENT_HTML5 );

			if ( $current === $new_title ) {
				$skipped++;
				continue;
			}

			WP_CLI::log( "  #{$id}: '{$current}' → '{$new_title}'" );

			if ( ! $dry_run ) {
				wp_update_post( [ 'ID' => $id, 'post_title' => $new_title ] );
			}
			$updated++;
		}

		$progress->finish();
		WP_CLI::success( sprintf(
			'%s%d title(s) updated · %d skipped (already correct or no locality).',
			$dry_run ? '[DRY RUN] ' : '',
			$updated,
			$skipped
		) );
	}
}
