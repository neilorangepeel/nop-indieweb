<?php
/**
 * Phase 1 kind migration.
 *
 * Run via WP-CLI:
 *   wp eval-file wp-content/plugins/nop-indieweb/bin/migrate-kinds.php
 *
 * Idempotent — posts that already have an nop_kind term are skipped.
 * Run locally first, verify counts, then run on the server.
 */

use NOP\IndieWeb\Kind\Kind_Taxonomy;

$taxonomy = Kind_Taxonomy::TAXONOMY;

// ── 1. Seed any missing terms ─────────────────────────────────────────────────
// Triggering register_taxonomy() via init is already done; just confirm terms exist.
foreach ( array_keys( Kind_Taxonomy::FLAT_KINDS ) as $slug ) {
	if ( ! term_exists( $slug, $taxonomy ) ) {
		WP_CLI::warning( "Term missing after seed: {$slug}" );
	}
}
WP_CLI::line( 'Terms verified.' );

// ── Helper: assign kind to a post if it has no nop_kind term yet ─────────────
$assign = function( int $post_id, string $kind ) use ( $taxonomy ): bool {
	$existing = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
	if ( ! is_wp_error( $existing ) && $existing ) {
		return false; // already has a kind, skip
	}
	$result = wp_set_object_terms( $post_id, $kind, $taxonomy );
	return ! is_wp_error( $result );
};

// ── 2. Statuses category → inferred kind from meta ───────────────────────────
$statuses_term = get_term_by( 'slug', 'statuses', 'category' );
if ( ! $statuses_term instanceof WP_Term ) {
	$statuses_term = get_term_by( 'name', 'Statuses', 'category' );
}

if ( $statuses_term instanceof WP_Term ) {
	$statuses_posts = get_posts( [
		'post_type'      => 'post',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'cat'            => $statuses_term->term_id,
	] );

	$counts = [ 'rsvp' => 0, 'reply' => 0, 'like' => 0, 'repost' => 0, 'bookmark' => 0, 'note' => 0, 'skipped' => 0 ];

	foreach ( $statuses_posts as $post_id ) {
		// Priority: rsvp > reply > like > repost > bookmark > note
		if ( get_post_meta( $post_id, 'nop_indieweb_rsvp', true ) ) {
			$kind = 'rsvp';
		} elseif ( get_post_meta( $post_id, 'nop_indieweb_in_reply_to', true ) ) {
			$kind = 'reply';
		} elseif ( get_post_meta( $post_id, 'nop_indieweb_like_of', true ) ) {
			$kind = 'like';
		} elseif ( get_post_meta( $post_id, 'nop_indieweb_repost_of', true ) ) {
			$kind = 'repost';
		} elseif ( get_post_meta( $post_id, 'nop_indieweb_bookmark_of', true ) ) {
			$kind = 'bookmark';
		} else {
			$kind = 'note';
		}

		if ( $assign( $post_id, $kind ) ) {
			$counts[ $kind ]++;
		} else {
			$counts['skipped']++;
		}
	}

	WP_CLI::line( sprintf(
		'Statuses (%d posts): rsvp=%d reply=%d like=%d repost=%d bookmark=%d note=%d skipped=%d',
		count( $statuses_posts ),
		$counts['rsvp'], $counts['reply'], $counts['like'], $counts['repost'],
		$counts['bookmark'], $counts['note'], $counts['skipped']
	) );
} else {
	WP_CLI::line( 'Statuses category not found — skipping.' );
}

// ── 3. Photos category → photo kind ──────────────────────────────────────────
$photos_term = get_term_by( 'slug', 'photos', 'category' );
if ( ! $photos_term instanceof WP_Term ) {
	$photos_term = get_term_by( 'name', 'Photos', 'category' );
}

if ( $photos_term instanceof WP_Term ) {
	$photos_posts = get_posts( [
		'post_type'      => 'post',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'cat'            => $photos_term->term_id,
	] );

	$assigned = $skipped = 0;
	foreach ( $photos_posts as $post_id ) {
		$assign( $post_id, 'photo' ) ? $assigned++ : $skipped++;
	}
	WP_CLI::line( "Photos ({$photos_term->count} posts): assigned={$assigned} skipped(already had kind)={$skipped}" );
} else {
	WP_CLI::line( 'Photos category not found — skipping.' );
}

// ── 4. Bookmarks/Checkins — verify existing terms are assigned ────────────────
foreach ( [ 'bookmarks' => 'bookmark', 'checkins' => 'checkin' ] as $cat_slug => $kind_slug ) {
	$cat = get_term_by( 'slug', $cat_slug, 'category' )
		?: get_term_by( 'name', ucfirst( $cat_slug ), 'category' );

	if ( ! $cat instanceof WP_Term ) {
		WP_CLI::line( ucfirst( $cat_slug ) . " category not found — skipping." );
		continue;
	}

	$posts = get_posts( [
		'post_type'      => 'post',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'cat'            => $cat->term_id,
	] );

	$assigned = $skipped = 0;
	foreach ( $posts as $post_id ) {
		$assign( $post_id, $kind_slug ) ? $assigned++ : $skipped++;
	}
	WP_CLI::line( ucfirst( $cat_slug ) . " ({$cat->count} posts): assigned={$assigned} already-had-kind={$skipped}" );
}

// ── Summary ───────────────────────────────────────────────────────────────────
WP_CLI::line( '' );
WP_CLI::line( 'nop_kind term counts after migration:' );
$terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
foreach ( $terms as $term ) {
	WP_CLI::line( sprintf( '  %-12s %d', $term->slug, $term->count ) );
}
WP_CLI::success( 'Migration complete.' );
