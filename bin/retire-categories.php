<?php
/**
 * Retire category-as-kind categories.
 *
 * Run via WP-CLI:
 *   wp eval-file wp-content/plugins/nop-indieweb/bin/retire-categories.php
 *
 * Idempotent — missing categories are skipped.
 * Deletes each category; WordPress automatically reassigns single-category
 * posts to the default category (Uncategorized).
 * Also clears stale post_category settings from the plugin options.
 */

$retire = [
	'statuses', 'photos', 'bookmarks', 'checkins', 'checkin',
	'likes', 'replies', 'reposts', 'rsvps', 'notes',
	'films', 'film',
];

foreach ( $retire as $slug ) {
	$term = get_term_by( 'slug', $slug, 'category' );
	if ( ! $term instanceof WP_Term ) {
		WP_CLI::line( "  skip (not found): {$slug}" );
		continue;
	}
	$count = (int) $term->count;
	wp_delete_category( $term->term_id );
	WP_CLI::line( "  deleted: {$term->name} ({$count} posts reassigned to default)" );
}

// ── Clear stale post_category plugin settings ─────────────────────────────────
$retire_names = array_map( 'ucfirst', $retire );
$option       = get_option( 'nop_indieweb_settings', [] );
$changed      = false;

$paths = [
	[ 'services',     'swarm'    ],
	[ 'services',     'entries'  ],
	[ 'syndicators',  'mastodon' ],
	[ 'syndicators',  'bluesky'  ],
	[ 'syndicators',  'pixelfed' ],
	[ 'services',     'bookmark' ],
	[ 'services',     'reply'    ],
	[ 'services',     'like'     ],
	[ 'services',     'repost'   ],
	[ 'services',     'rsvp'     ],
];

foreach ( $paths as [ $group, $key ] ) {
	$current = $option[ $group ][ $key ]['post_category'] ?? '';
	if ( ! $current ) {
		continue;
	}
	// post_category is a CSV of category names; strip the retired ones.
	$names = array_filter( array_map( 'trim', explode( ',', $current ) ) );
	$clean = array_values( array_diff( $names, $retire_names ) );
	$new   = implode( ', ', $clean );
	if ( $new !== $current ) {
		$option[ $group ][ $key ]['post_category'] = $new;
		$changed = true;
		WP_CLI::line( "  cleared '{$current}' → '{$new}' in {$group}.{$key}" );
	}
}

if ( $changed ) {
	update_option( 'nop_indieweb_settings', $option );
	WP_CLI::line( 'Plugin settings updated.' );
} else {
	WP_CLI::line( 'No stale settings found.' );
}

WP_CLI::success( 'Category retirement complete.' );
