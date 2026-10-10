<?php
/**
 * Makes the photo archive designable: real photographs, and real engagement.
 *
 * The photo fixtures from seed-kind-fixtures.php point at 20KB generated
 * placeholders — coloured circles on bands. A contact sheet designed against
 * those is design work done blind: you cannot judge crop, tone, or how a grid
 * of portraits sits next to a grid of landscapes. This repoints them at
 * photographs already in the local library, and tops the kind up to a full
 * page so the sheet's rhythm is visible.
 *
 * Engagement is created as real webmention comment rows, not as counts written
 * straight to meta. Meta alone is a lie the backfill correctly erases, and it
 * would skip the facepile and reply rendering entirely — so it would not tell
 * you what the design looks like. Engagement_Counts derives the totals from
 * these rows exactly as it does in production.
 *
 * Everything created or changed is marked, so the whole set comes out again:
 *
 *     # posts and attachments
 *     studio wp post list --post_type=post --post_status=any \
 *         --meta_key=nop_seed_fixture --field=ID | xargs studio wp post delete --force
 *
 *     # just the mock engagement, leaving the posts in place
 *     studio wp eval-file wp-content/plugins/nop-indieweb/bin/seed-photo-archive.php teardown
 *
 * Idempotent: re-running adds only what is missing.
 *
 *     studio wp eval-file wp-content/plugins/nop-indieweb/bin/seed-photo-archive.php
 *     studio wp eval-file wp-content/plugins/nop-indieweb/bin/seed-photo-archive.php 36
 *
 * @package NOP\IndieWeb
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// `wp eval-file` only forwards positional arguments — it rejects unknown
// flags outright — so these are read from $args, not $assoc_args.
$teardown = in_array( 'teardown', (array) ( $args ?? [] ), true );
$target   = 24;
foreach ( (array) ( $args ?? [] ) as $arg ) {
	if ( is_numeric( $arg ) ) {
		$target = max( 1, (int) $arg );
	}
}

const NOP_MOCK_COMMENT_FLAG = 'nop_seed_fixture';

// ── Teardown ─────────────────────────────────────────────────────────────────

if ( $teardown ) {
	$ids = get_comments( [
		'type'       => 'webmention',
		'status'     => 'all',
		'fields'     => 'ids',
		'number'     => 0,
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-off maintenance script
		'meta_query' => [ [ 'key' => NOP_MOCK_COMMENT_FLAG, 'value' => '1' ] ],
	] );

	foreach ( $ids as $cid ) {
		wp_delete_comment( (int) $cid, true );
	}

	WP_CLI::success( sprintf( 'Removed %d mock engagement row(s). Post fixtures left in place.', count( $ids ) ) );
	return;
}

// ── Real photographs from the library ────────────────────────────────────────

/**
 * Image attachments big enough to judge a crop by, excluding the generated
 * film-NN.jpg placeholders. Returned widest-variety-first so a short run still
 * gets a mix of portrait and landscape rather than a run of one shape.
 *
 * @return int[]
 */
function nop_photo_library( int $want ): array {
	global $wpdb;

	$rows = $wpdb->get_col(
		"SELECT p.ID
		 FROM {$wpdb->posts} p
		 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
		 WHERE p.post_type = 'attachment'
		   AND p.post_mime_type LIKE 'image/%'
		   AND pm.meta_value NOT LIKE '%film-%'
		 ORDER BY p.ID DESC
		 LIMIT 400"
	);

	$portrait = [];
	$landscape = [];

	foreach ( $rows as $id ) {
		$meta = wp_get_attachment_metadata( (int) $id );
		if ( empty( $meta['width'] ) || empty( $meta['height'] ) || $meta['width'] < 900 ) {
			continue;
		}
		if ( $meta['width'] >= $meta['height'] ) {
			$landscape[] = (int) $id;
		} else {
			$portrait[] = (int) $id;
		}
	}

	// Interleave, so the sheet shows both shapes from the first row.
	$mixed = [];
	while ( count( $mixed ) < $want && ( $portrait || $landscape ) ) {
		if ( $portrait ) {
			$mixed[] = array_shift( $portrait );
		}
		if ( count( $mixed ) < $want && $landscape ) {
			$mixed[] = array_shift( $landscape );
		}
	}

	return $mixed;
}

$titles = [
	'Waiting Room, 4pm', 'Low Tide', 'Two Chairs', 'Rain on the Window',
	'Backstage, Before', 'The Long Corridor', 'Bridge at Night', 'Hands, Rehearsal',
	'Empty Stage', 'Bus Shelter', 'Kitchen Light', 'Across the Lagan',
	'Curtain Call', 'Dust and Sunlight', 'The Stairwell', 'Late Train',
	'Pavement, Frost', 'Open Door', 'Studio Corner', 'Gulls',
	'Blue Hour, Docks', 'Coat on a Hook', 'Reflected Ceiling', 'Last One on the Roll',
];

$photo_posts = get_posts( [
	'post_type'      => 'post',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'post_status'    => 'any',
	'tax_query'      => [ [ 'taxonomy' => 'nop_kind', 'field' => 'slug', 'terms' => 'photo' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery
] );

$library = nop_photo_library( max( $target, count( $photo_posts ) ) );

if ( count( $library ) < 4 ) {
	WP_CLI::error( 'Not enough usable photographs in the library — nothing to design against.' );
}

WP_CLI::log( sprintf( '%d photograph(s) available, %d photo post(s) present, target %d.', count( $library ), count( $photo_posts ), $target ) );

// ── Repoint the existing fixtures at real photographs ────────────────────────

$repointed = 0;
$cursor    = 0;

foreach ( $photo_posts as $pid ) {
	$pid   = (int) $pid;
	$thumb = (int) get_post_thumbnail_id( $pid );
	$file  = $thumb ? (string) get_post_meta( $thumb, '_wp_attached_file', true ) : '';

	// Only touch fixtures still on a generated placeholder — never a real post.
	if ( ! str_contains( $file, 'film-' ) ) {
		continue;
	}
	if ( ! isset( $library[ $cursor ] ) ) {
		break;
	}

	set_post_thumbnail( $pid, $library[ $cursor ] );
	update_post_meta( $pid, 'nop_seed_fixture', 1 );
	$cursor++;
	$repointed++;
}

WP_CLI::log( sprintf( '  repointed %d fixture(s) onto real photographs', $repointed ) );

// ── Top the kind up to a full page ───────────────────────────────────────────

$created = 0;
$index   = count( $photo_posts );

while ( count( $photo_posts ) + $created < $target && isset( $library[ $cursor ] ) ) {
	$title = $titles[ $created % count( $titles ) ] . ( $created >= count( $titles ) ? ' II' : '' );
	$slug  = sanitize_title( $title );

	if ( get_page_by_path( $slug, OBJECT, 'post' ) ) {
		$cursor++;
		$created++;
		continue;
	}

	$id = wp_insert_post( [
		'post_type'   => 'post',
		'post_status' => 'publish',
		'post_title'  => $title,
		'post_name'   => $slug,
		'post_date'   => gmdate( 'Y-m-d H:i:s', strtotime( sprintf( '-%d days', 3 * ( $index + $created ) ) ) ),
		'post_author' => 1,
	], true );

	if ( is_wp_error( $id ) ) {
		WP_CLI::warning( $slug . ': ' . $id->get_error_message() );
		break;
	}

	wp_set_object_terms( $id, 'photo', 'nop_kind', false );
	update_post_meta( $id, 'nop_seed_fixture', 1 );
	set_post_thumbnail( $id, $library[ $cursor ] );

	$cursor++;
	$created++;
}

WP_CLI::log( sprintf( '  created   %d photo post(s)', $created ) );

// ── Engagement, as real webmention rows ──────────────────────────────────────

$reactors = [
	[ 'Jeremy Keith',   'https://adactio.com',            '@adactio@mastodon.social',  'mastodon' ],
	[ 'Sophie Koonin',  'https://localghost.dev',         '@sophie.bsky.social',       'bluesky'  ],
	[ 'Chris Coyier',   'https://chriscoyier.net',        '@chriscoyier@front-end.social', 'mastodon' ],
	[ 'Lynn Fisher',    'https://lynnandtonic.com',       '@lynn.bsky.social',         'bluesky'  ],
	[ 'Ethan Marcotte', 'https://ethanmarcotte.com',      '@beep@mastodon.social',     'mastodon' ],
	[ 'Sara Soueidan',  'https://sarasoueidan.com',       '@sara.bsky.social',         'bluesky'  ],
	[ 'Heydon Pickering','https://heydonworks.com',       '@heydon@mastodon.social',   'mastodon' ],
	[ 'Anna Debenham',  'https://maban.co.uk',            '@anna.bsky.social',         'bluesky'  ],
];

$remarks = [
	'The light on the left edge is doing all the work here.',
	'That grain is lovely. HP5?',
	'Been past this spot a hundred times and never seen it.',
	'The restraint in this one. Nothing extra.',
	'Printed this in my head already.',
];

// A realistic long tail: most posts quiet, a few with a little, one with a lot.
// Index → [likes, reposts, replies]. Anything past the end gets nothing.
$spread = [
	[ 14, 3, 4 ],
	[ 6, 1, 1 ],
	[ 3, 0, 2 ],
	[ 2, 0, 0 ],
	[ 1, 1, 0 ],
	[ 1, 0, 0 ],
	[ 9, 2, 3 ],
	[ 1, 0, 1 ],
];

$all_photos = get_posts( [
	'post_type'      => 'post',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'post_status'    => 'publish',
	'orderby'        => 'date',
	'order'          => 'DESC',
	'tax_query'      => [ [ 'taxonomy' => 'nop_kind', 'field' => 'slug', 'terms' => 'photo' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery
] );

$rows  = 0;
$spin  = 0;

foreach ( $all_photos as $i => $pid ) {
	if ( ! isset( $spread[ $i ] ) ) {
		break;
	}
	$pid = (int) $pid;

	// Already seeded? Leave it — this script is idempotent.
	$existing = get_comments( [
		'post_id'    => $pid,
		'type'       => 'webmention',
		'status'     => 'all',
		'count'      => true,
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-off maintenance script
		'meta_query' => [ [ 'key' => NOP_MOCK_COMMENT_FLAG, 'value' => '1' ] ],
	] );
	if ( $existing ) {
		continue;
	}

	[ $likes, $reposts, $replies ] = $spread[ $i ];

	foreach ( [ 'like' => $likes, 'repost' => $reposts, 'reply' => $replies ] as $type => $n ) {
		for ( $k = 0; $k < $n; $k++ ) {
			[ $name, $url, $handle, $platform ] = $reactors[ $spin % count( $reactors ) ];
			$spin++;

			$cid = wp_insert_comment( [
				'comment_post_ID'      => $pid,
				'comment_author'       => $name,
				'comment_author_url'   => $url,
				'comment_content'      => 'reply' === $type ? $remarks[ $k % count( $remarks ) ] : '',
				'comment_type'         => 'webmention',
				'comment_approved'     => 1,
				'comment_date'         => gmdate( 'Y-m-d H:i:s', strtotime( get_post_field( 'post_date_gmt', $pid ) . ' +' . ( $k + 1 ) . ' hours' ) ),
			] );

			if ( ! $cid ) {
				continue;
			}

			add_comment_meta( $cid, 'webmention_type', $type );
			add_comment_meta( $cid, 'webmention_platform', $platform );
			add_comment_meta( $cid, 'webmention_author_handle', $handle );
			add_comment_meta( $cid, 'webmention_source', rtrim( $url, '/' ) . '/note/' . $cid );
			add_comment_meta( $cid, NOP_MOCK_COMMENT_FLAG, 1 );
			$rows++;
		}
	}
}

WP_CLI::log( sprintf( '  engagement %d webmention row(s) across %d post(s)', $rows, min( count( $all_photos ), count( $spread ) ) ) );

// Counts are derived, never written directly — same path as production.
( new NOP\IndieWeb\Webmention\Engagement_Counts() )->flush();
foreach ( $all_photos as $pid ) {
	( new NOP\IndieWeb\Webmention\Engagement_Counts() )->recount( (int) $pid );
}

WP_CLI::success( sprintf( 'Photo archive ready: %d post(s), %d on real photographs, %d engagement row(s).', count( $all_photos ), $repointed + $created, $rows ) );
