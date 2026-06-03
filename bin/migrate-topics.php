<?php
// CLI-only — refuse to run if reached over HTTP.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'This file may only be executed via WP-CLI.' );
}
/**
 * Topic migration: categories become curated topics, kinds carry the format.
 *
 * Run via WP-CLI (dry run first, then live):
 *   wp eval-file wp-content/plugins/nop-indieweb/bin/migrate-topics.php dry-run
 *   wp eval-file wp-content/plugins/nop-indieweb/bin/migrate-topics.php
 *
 * Idempotent — completed steps are no-ops on re-run.
 *
 * Steps:
 *   1. Create the six topic categories.
 *   2. Untyped posts → article kind.
 *   3. Old kind-shaped categories (Films/Links/Quotes/Videos/Development)
 *      → kinds + topic categories, then delete the old categories.
 *   4. Tag promotion: topic tags become categories (some tags deleted, some kept).
 *   5. Strip the "Articles" default category from every post and delete it.
 *   6. Kind → topic backfill for every post still uncategorized.
 *   7. Clear stale per-service post_category / post_tags settings.
 *   8. Report articles left with no category (manual review).
 */

use NOP\IndieWeb\Kind\Kind_Taxonomy;

$dry = in_array( 'dry-run', $args ?? [], true );
WP_CLI::line( $dry ? '═══ DRY RUN — nothing will be written ═══' : '═══ LIVE RUN ═══' );

$all_posts = static fn( array $extra = [] ): array => get_posts( array_merge( [
	'post_type'      => 'post',
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'no_found_rows'  => true,
], $extra ) );

$category_id = static function ( string $slug ) use ( $dry ): int {
	if ( $dry ) {
		$term = get_term_by( 'slug', $slug, 'category' );
		return $term instanceof WP_Term ? (int) $term->term_id : -1; // -1 = would be created
	}
	return Kind_Taxonomy::ensure_topic_category( $slug );
};

$add_category = static function ( int $post_id, string $slug ) use ( $dry, $category_id ): void {
	if ( $dry ) {
		return;
	}
	$term_id = $category_id( $slug );
	if ( $term_id > 0 ) {
		wp_set_object_terms( $post_id, [ $term_id ], 'category', true );
	}
};

$assign_kind = static function ( int $post_id, string $kind ) use ( $dry ): bool {
	$existing = wp_get_object_terms( $post_id, Kind_Taxonomy::TAXONOMY, [ 'fields' => 'ids' ] );
	if ( ! is_wp_error( $existing ) && $existing ) {
		return false; // already has a kind — never overwrite
	}
	if ( ! $dry ) {
		wp_set_object_terms( $post_id, $kind, Kind_Taxonomy::TAXONOMY );
	}
	return true;
};

// ── 1. Topic categories ───────────────────────────────────────────────────────
WP_CLI::line( "\n── 1. Topic categories ──" );
foreach ( Kind_Taxonomy::TOPIC_CATEGORIES as $slug => $name ) {
	$exists = get_term_by( 'slug', $slug, 'category' ) instanceof WP_Term;
	if ( $exists ) {
		WP_CLI::line( "  exists: {$name}" );
		continue;
	}
	if ( ! $dry ) {
		Kind_Taxonomy::ensure_topic_category( $slug );
	}
	WP_CLI::line( "  create: {$name} ({$slug})" );
}

// ── 2. Untyped posts → article kind ───────────────────────────────────────────
WP_CLI::line( "\n── 2. Untyped posts → article kind ──" );
$untyped = $all_posts( [
	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one-off migration
	'tax_query' => [ [
		'taxonomy' => Kind_Taxonomy::TAXONOMY,
		'operator' => 'NOT EXISTS',
	] ],
] );
$typed = 0;
foreach ( $untyped as $post_id ) {
	if ( $assign_kind( (int) $post_id, 'article' ) ) {
		$typed++;
	}
}
WP_CLI::line( '  ' . count( $untyped ) . " untyped posts found, {$typed} assigned article kind" );

// ── 3. Old kind-shaped categories → kinds + topics ────────────────────────────
WP_CLI::line( "\n── 3. Old kind-shaped categories ──" );
$old_categories = [
	// category slug => [ kind to assign ('' = keep current), topic category slug ]
	'films'       => [ 'watch',    'media-diet' ],
	'links'       => [ 'bookmark', 'journal' ],
	'quotes'      => [ 'quote',    'journal' ],
	'videos'      => [ 'video',    'photography' ],
	'development' => [ '',         'web-development' ],
];
foreach ( $old_categories as $cat_slug => [ $kind, $topic ] ) {
	$term = get_term_by( 'slug', $cat_slug, 'category' );
	if ( ! $term instanceof WP_Term ) {
		WP_CLI::line( "  skip (not found): {$cat_slug}" );
		continue;
	}
	$post_ids = $all_posts( [ 'cat' => $term->term_id ] );
	foreach ( $post_ids as $post_id ) {
		// Re-kind only posts that arrived as articles in step 2 or have no kind —
		// a post already typed (e.g. watch via Letterboxd) keeps its kind.
		if ( $kind ) {
			$current_kind = wp_get_object_terms( (int) $post_id, Kind_Taxonomy::TAXONOMY, [ 'fields' => 'slugs' ] );
			$current_kind = ! is_wp_error( $current_kind ) && $current_kind ? (string) $current_kind[0] : '';
			if ( '' === $current_kind || 'article' === $current_kind ) {
				if ( ! $dry ) {
					wp_set_object_terms( (int) $post_id, $kind, Kind_Taxonomy::TAXONOMY );
				}
			}
		}
		$add_category( (int) $post_id, $topic );
		if ( ! $dry ) {
			wp_remove_object_terms( (int) $post_id, $term->term_id, 'category' );
		}
	}
	if ( ! $dry ) {
		wp_delete_category( $term->term_id );
	}
	WP_CLI::line( '  ' . $term->name . ': ' . count( $post_ids ) . " posts → kind '" . ( $kind ?: 'unchanged' ) . "' + category '{$topic}', category deleted" );
}

// ── 4. Tag promotion ──────────────────────────────────────────────────────────
WP_CLI::line( "\n── 4. Tag promotion ──" );
$promotions = [
	// tag slug => [ topic category slug ('' = none), delete tag? ]
	// Tags that duplicate their category → promote and delete.
	'photography' => [ 'photography',     true ],
	'dance'       => [ 'performance',     true ],
	'circus'      => [ 'performance',     true ],
	'personal'    => [ 'journal',         true ],
	'thoughts'    => [ 'journal',         true ],
	'travel'      => [ 'places-travel',   true ],
	// Provenance tags → delete without promotion (platform meta records this).
	'swarm'       => [ '',                true ],
	'facebook'    => [ '',                true ],
	// Tags more specific than their category → promote and keep.
	'wordpress'            => [ 'web-development', false ],
	'css'                  => [ 'web-development', false ],
	'themes'               => [ 'web-development', false ],
	'open-source'          => [ 'web-development', false ],
	'social-media'         => [ 'web-development', false ],
	'belfast'              => [ 'places-travel',   false ],
	'middlesbrough'        => [ 'places-travel',   false ],
	'cathedral-quarter'    => [ 'places-travel',   false ],
	'city-centre'          => [ 'places-travel',   false ],
	'street-photography'   => [ 'photography',     false ],
	'street'               => [ 'photography',     false ],
	'camera'               => [ 'photography',     false ],
	'35mm'                 => [ 'photography',     false ],
	'analog'               => [ 'photography',     false ],
	'grain'                => [ 'photography',     false ],
	'darkroom'             => [ 'photography',     false ],
	'phone'                => [ 'photography',     false ],
	'film'                 => [ 'photography',     false ],
	'ponydance'            => [ 'performance',     false ],
	'vault-artist-studios' => [ 'performance',     false ],
	'learning-to-drive'    => [ 'journal',         false ],
	'teaching'             => [ 'journal',         false ],
	'football'             => [ 'journal',         false ],
];
foreach ( $promotions as $tag_slug => [ $topic, $delete_tag ] ) {
	$tag = get_term_by( 'slug', $tag_slug, 'post_tag' );
	if ( ! $tag instanceof WP_Term ) {
		continue;
	}
	$post_ids = $all_posts( [ 'tag_id' => $tag->term_id ] );
	if ( $topic ) {
		foreach ( $post_ids as $post_id ) {
			$add_category( (int) $post_id, $topic );
		}
	}
	if ( $delete_tag && ! $dry ) {
		wp_delete_term( $tag->term_id, 'post_tag' );
	}
	WP_CLI::line( sprintf(
		'  %s: %d posts%s%s',
		$tag->name,
		count( $post_ids ),
		$topic ? " → category '{$topic}'" : '',
		$delete_tag ? ', tag deleted' : ', tag kept'
	) );
}

// ── 5. Strip the "Articles" default category ──────────────────────────────────
WP_CLI::line( "\n── 5. Articles category ──" );
$articles_cat = get_term_by( 'slug', 'articles', 'category' );
if ( $articles_cat instanceof WP_Term ) {
	// The default category cannot be deleted — repoint the option at Uncategorized first.
	if ( (int) get_option( 'default_category' ) === (int) $articles_cat->term_id ) {
		if ( ! $dry ) {
			$uncategorized = get_term_by( 'slug', 'uncategorized', 'category' );
			if ( ! $uncategorized instanceof WP_Term ) {
				$created       = wp_insert_term( 'Uncategorized', 'category', [ 'slug' => 'uncategorized' ] );
				$uncategorized = is_wp_error( $created ) ? null : get_term( $created['term_id'], 'category' );
			}
			if ( $uncategorized instanceof WP_Term ) {
				update_option( 'default_category', (int) $uncategorized->term_id );
			}
		}
		WP_CLI::line( '  default_category option: Articles → Uncategorized (sentinel, created if missing)' );
	}

	$post_ids = $all_posts( [ 'cat' => $articles_cat->term_id ] );
	if ( ! $dry ) {
		foreach ( $post_ids as $post_id ) {
			wp_remove_object_terms( (int) $post_id, $articles_cat->term_id, 'category' );
		}
		wp_delete_category( $articles_cat->term_id );
	}
	WP_CLI::line( '  stripped from ' . count( $post_ids ) . ' posts, category deleted' );
} else {
	WP_CLI::line( '  skip (no Articles category)' );
}

// ── 6. Kind → topic backfill ──────────────────────────────────────────────────
WP_CLI::line( "\n── 6. Kind → topic backfill ──" );
$sentinel        = (int) get_option( 'default_category' );
$backfill        = [];
$sentinel_pruned = 0;
foreach ( $all_posts() as $post_id ) {
	$cats = wp_get_object_terms( (int) $post_id, 'category', [ 'fields' => 'ids' ] );
	if ( is_wp_error( $cats ) ) {
		continue;
	}
	$cats = array_map( 'intval', $cats );
	$real = array_values( array_diff( $cats, [ $sentinel ] ) );

	if ( $real ) {
		// Has a real category — just prune the sentinel if it's tagging along
		// (e.g. appended by tag promotion next to the WP default).
		if ( in_array( $sentinel, $cats, true ) ) {
			if ( ! $dry ) {
				wp_remove_object_terms( (int) $post_id, $sentinel, 'category' );
			}
			$sentinel_pruned++;
		}
		continue;
	}

	$kind  = wp_get_object_terms( (int) $post_id, Kind_Taxonomy::TAXONOMY, [ 'fields' => 'slugs' ] );
	$kind  = ! is_wp_error( $kind ) && $kind ? (string) $kind[0] : '';
	$topic = Kind_Taxonomy::KIND_DEFAULT_CATEGORIES[ $kind ] ?? '';
	if ( ! $topic ) {
		// Article or unknown — left for manual review, but shed the sentinel so
		// "no topic yet" looks the same on every post (no category at all).
		if ( $cats && ! $dry ) {
			wp_set_object_terms( (int) $post_id, [], 'category' );
		}
		continue;
	}
	if ( ! $dry ) {
		$term_id = Kind_Taxonomy::ensure_topic_category( $topic );
		if ( $term_id ) {
			wp_set_object_terms( (int) $post_id, [ $term_id ], 'category' );
		}
	}
	$backfill[ $topic ] = ( $backfill[ $topic ] ?? 0 ) + 1;
}
foreach ( $backfill as $topic => $count ) {
	WP_CLI::line( "  {$topic}: {$count} posts" );
}
if ( $sentinel_pruned ) {
	WP_CLI::line( "  sentinel category pruned from {$sentinel_pruned} posts that have a real topic" );
}
if ( ! $backfill && ! $sentinel_pruned ) {
	WP_CLI::line( '  nothing to backfill' );
}

// ── 7. Stale per-service settings ─────────────────────────────────────────────
WP_CLI::line( "\n── 7. Per-service category/tag settings ──" );
$option  = get_option( 'nop_indieweb_settings', [] );
$changed = false;
foreach ( [ 'services', 'syndicators' ] as $group ) {
	foreach ( (array) ( $option[ $group ] ?? [] ) as $key => $service ) {
		foreach ( [ 'post_category', 'post_tags' ] as $field ) {
			$value = (string) ( $service[ $field ] ?? '' );
			if ( '' === $value ) {
				continue;
			}
			if ( ! $dry ) {
				$option[ $group ][ $key ][ $field ] = '';
			}
			$changed = true;
			WP_CLI::line( "  clear {$group}.{$key}.{$field}: '{$value}'" );
		}
	}
}
if ( $changed && ! $dry ) {
	update_option( 'nop_indieweb_settings', $option );
}
if ( ! $changed ) {
	WP_CLI::line( '  nothing to clear' );
}

// ── 8. Report: articles with no category ──────────────────────────────────────
WP_CLI::line( "\n── 8. Articles needing manual topic review ──" );
$needs_review = [];
foreach ( $all_posts() as $post_id ) {
	$cats = wp_get_object_terms( (int) $post_id, 'category', [ 'fields' => 'ids' ] );
	$cats = is_wp_error( $cats ) ? [] : array_map( 'intval', $cats );
	if ( $cats && $cats !== [ $sentinel ] ) {
		continue;
	}
	$kind = wp_get_object_terms( (int) $post_id, Kind_Taxonomy::TAXONOMY, [ 'fields' => 'slugs' ] );
	$kind = ! is_wp_error( $kind ) && $kind ? (string) $kind[0] : '';
	if ( 'article' === $kind || '' === $kind ) {
		$needs_review[] = $post_id;
	}
}
if ( $needs_review ) {
	WP_CLI::line( '  ' . count( $needs_review ) . ' articles have no topic category — assign one in wp-admin:' );
	foreach ( $needs_review as $post_id ) {
		WP_CLI::line( "    #{$post_id}  " . get_the_title( (int) $post_id ) );
	}
} else {
	WP_CLI::line( '  none — every article has a topic' );
}

WP_CLI::success( $dry ? 'Dry run complete. Re-run without dry-run to apply.' : 'Migration complete.' );
