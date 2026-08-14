<?php
/**
 * Seeds local example content for the post kinds that ship with no posts.
 *
 * Production is the only complete example of this site's content, and design
 * work done against empty kinds is design work done blind. This fills the gap
 * locally: one or two realistic posts for each kind that would otherwise have
 * none, with the meta a real post of that kind would carry.
 *
 * These are fixtures, not content. Every post and attachment it creates carries
 * `nop_seed_fixture = 1`, so the whole set can be found — and removed — in one
 * query:
 *
 *     studio wp post list --post_type=post,attachment --post_status=any \
 *         --meta_key=nop_seed_fixture --field=ID | xargs studio wp post delete --force
 *
 * Idempotent. A post is skipped when one with the same slug already exists, so
 * re-running after adding a kind only creates what is missing.
 *
 *     studio wp eval-file wp-content/plugins/nop-indieweb/bin/seed-kind-fixtures.php
 *
 * ── Video fixtures ───────────────────────────────────────────────────────────
 * The video and story kinds need real files: an empty <video> element lies
 * about the shape of the block, and design work against it is worthless. The
 * four clips are rendered from photographs already in the library with a slow
 * push, five seconds each. Regenerate them with ffmpeg if they are missing —
 * VIDEO_RECIPES below carries the exact source image and filter chain for each,
 * and --recipes prints them as runnable commands.
 *
 * @package NOP\IndieWeb
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Where the rendered clips are looked for. Override with --media-dir=<path>.
$media_dir = rtrim( (string) ( WP_CLI\Utils\get_flag_value( $assoc_args ?? [], 'media-dir' ) ?: sys_get_temp_dir() . '/nop-kind-fixtures' ), '/' );

/**
 * Source image + filter chain for each clip, so the media can be rebuilt from
 * the library rather than carried in the repository as 1.8MB of binary.
 *
 * Keyed by output filename. `image` is matched against attachment titles.
 */
const VIDEO_RECIPES = [
	'daft-eddies-on-fixed.mp4' => [
		'image'  => 'Daft Eddies on fixed',
		'title'  => 'Daft Eddies on fixed',
		'filter' => "scale=2560:-1,zoompan=z='min(zoom+0.0008,1.12)':d=125:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s=1280x720:fps=25,format=yuv420p",
	],
	'comber-cine-cycle.mp4' => [
		'image'  => 'Trip to comber with cine-cycle',
		'title'  => 'Comber on the cine-cycle',
		'filter' => "crop=2048:1152:0:192,scale=2560:-1,zoompan=z='if(lte(zoom,1.0),1.10,max(1.001,zoom-0.0008))':d=125:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s=1280x720:fps=25,format=yuv420p",
	],
	'park-loop-story.mp4' => [
		'image'  => 'Park Loop',
		'title'  => 'Park loop, last light',
		'filter' => "crop=1152:2048,scale=1440:-1,zoompan=z='min(zoom+0.0007,1.10)':d=125:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s=720x1280:fps=25,format=yuv420p",
	],
	'collecting-flies-story.mp4' => [
		'image'  => 'Collecting flies',
		'title'  => 'Collecting flies',
		'filter' => "crop=1152:2048,scale=1440:-1,zoompan=z='min(zoom+0.0006,1.08)':d=125:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s=720x1280:fps=25,format=yuv420p",
	],
];

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

/**
 * Prints the ffmpeg commands that rebuild the clips, resolving each source
 * image to its real path in the uploads directory.
 */
function nop_fixture_print_recipes( string $media_dir ): void {
	WP_CLI::log( "mkdir -p $media_dir" );
	foreach ( VIDEO_RECIPES as $out => $recipe ) {
		$src = nop_fixture_find_image( $recipe['image'] );
		$in  = $src ? get_attached_file( $src ) : '<source image not in library>';
		WP_CLI::log( sprintf(
			"ffmpeg -y -loop 1 -i %s \\\n  -vf \"%s\" \\\n  -t 5 -c:v libx264 -preset slow -crf 26 -movflags +faststart %s/%s\n",
			escapeshellarg( $in ),
			$recipe['filter'],
			$media_dir,
			$out
		) );
	}
}

/**
 * Finds an image attachment by title, falling back to any image so a fixture
 * still gets a picture on a library that does not have the named one.
 */
function nop_fixture_find_image( string $prefer_title ): int {
	$hit = get_posts( [
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'post_mime_type' => 'image/jpeg',
		'posts_per_page' => 1,
		's'              => $prefer_title,
		'fields'         => 'ids',
	] );
	if ( $hit ) {
		return (int) $hit[0];
	}
	$any = get_posts( [
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'post_mime_type' => 'image/jpeg',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'orderby'        => 'rand',
	] );
	return $any ? (int) $any[0] : 0;
}

/**
 * Sideloads a video, reusing an existing one of the same title.
 *
 * The mime constraint is not decoration: the library already holds photographs
 * sharing these titles, and an unconstrained lookup silently returned a .jpg
 * for the video block to point at.
 */
function nop_fixture_video( string $path, string $title ): int {
	$existing = get_posts( [
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'post_mime_type' => 'video',
		'posts_per_page' => 1,
		'title'          => $title,
		'fields'         => 'ids',
	] );
	if ( $existing ) {
		return (int) $existing[0];
	}
	if ( ! file_exists( $path ) ) {
		return 0;
	}
	$tmp = wp_tempnam( basename( $path ) );
	copy( $path, $tmp );
	$id = media_handle_sideload( [ 'name' => basename( $path ), 'tmp_name' => $tmp ], 0, $title );
	if ( is_wp_error( $id ) ) {
		WP_CLI::warning( "$title: " . $id->get_error_message() );
		@unlink( $tmp );
		return 0;
	}
	update_post_meta( $id, 'nop_seed_fixture', 1 );
	return (int) $id;
}

// ── Block helpers ────────────────────────────────────────────────────────────

function nop_fixture_p( string $text ): string {
	return "<!-- wp:paragraph -->\n<p>$text</p>\n<!-- /wp:paragraph -->\n\n";
}

function nop_fixture_video_block( int $id ): string {
	if ( ! $id ) {
		return '';
	}
	$url = esc_url( wp_get_attachment_url( $id ) );
	return "<!-- wp:video {\"id\":$id} -->\n"
		. "<figure class=\"wp-block-video\"><video controls src=\"$url\" playsinline preload=\"metadata\"></video></figure>\n"
		. "<!-- /wp:video -->\n\n";
}

function nop_fixture_image_block( int $id, string $align = '' ): string {
	if ( ! $id ) {
		return '';
	}
	$url   = esc_url( wp_get_attachment_url( $id ) );
	$alt   = esc_attr( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
	$attrs = $align ? "{\"id\":$id,\"sizeSlug\":\"large\",\"align\":\"$align\"}" : "{\"id\":$id,\"sizeSlug\":\"large\"}";
	$cls   = $align ? "wp-block-image align$align size-large" : 'wp-block-image size-large';
	return "<!-- wp:image $attrs -->\n"
		. "<figure class=\"$cls\"><img src=\"$url\" alt=\"$alt\" class=\"wp-image-$id\"/></figure>\n"
		. "<!-- /wp:image -->\n\n";
}

function nop_fixture_quote_block( string $passage, string $cite ): string {
	return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">"
		. "<!-- wp:paragraph -->\n<p>$passage</p>\n<!-- /wp:paragraph -->"
		. "<cite>$cite</cite></blockquote>\n<!-- /wp:quote -->\n\n";
}

if ( in_array( '--recipes', $args ?? [], true ) ) {
	nop_fixture_print_recipes( $media_dir );
	return;
}

$video = [];
foreach ( VIDEO_RECIPES as $file => $recipe ) {
	$video[ $file ] = nop_fixture_video( "$media_dir/$file", $recipe['title'] );
	if ( ! $video[ $file ] ) {
		WP_CLI::warning( "no clip at $media_dir/$file — run with --recipes for the ffmpeg command" );
	}
}

// ── The fixtures ─────────────────────────────────────────────────────────────
// Title lengths vary deliberately. A layout that has only ever been shown
// six-word titles has not been tested.

$posts = [
	// quote — the passages are out of copyright (Morris d. 1896, Ruskin d. 1900),
	// so the fixtures carry real prose without borrowing anyone's rights.
	[
		'kind'    => 'quote',
		'title'   => 'Useful, or believe to be beautiful',
		'slug'    => 'useful-or-believe-to-be-beautiful',
		'date'    => '2026-07-02 09:14:00',
		'meta'    => [ 'nop_indieweb_quote_of' => 'https://www.marxists.org/archive/morris/works/1880/hopes/chapters/chapter2.htm' ],
		'content' => nop_fixture_quote_block(
			'Have nothing in your houses that you do not know to be useful, or believe to be beautiful.',
			'William Morris, <em>The Beauty of Life</em>, 1880'
		) . nop_fixture_p( 'Quoted so often it has worn smooth, which is a shame, because the second half is the difficult one. Useful you can test. Believe to be beautiful puts the burden back on you, and offers no way to check your work.' ),
	],
	[
		'kind'    => 'quote',
		'title'   => 'On the impossibility of finishing',
		'slug'    => 'on-the-impossibility-of-finishing',
		'date'    => '2026-05-19 20:41:00',
		'meta'    => [ 'nop_indieweb_quote_of' => '' ],
		'content' => nop_fixture_quote_block(
			'No good work whatever can be perfect, and the demand for perfection is always a sign of a misunderstanding of the ends of art.',
			'John Ruskin, <em>The Stones of Venice</em>, 1853'
		) . nop_fixture_p( 'The source link is empty on this one deliberately — a quote should not require a URL, and this is the fixture that proves the template copes without one.' ),
	],

	// story — vertical video.
	[
		'kind'    => 'story',
		'title'   => 'Last light, park loop',
		'slug'    => 'last-light-park-loop',
		'date'    => '2026-07-28 21:03:00',
		'content' => nop_fixture_video_block( $video['park-loop-story.mp4'] )
			. nop_fixture_p( 'Five seconds of the loop at the point where the light goes orange and everyone else has gone home.' ),
	],
	[
		// No paragraph at all — the fixture for a story that is only its video.
		'kind'    => 'story',
		'title'   => 'Collecting flies',
		'slug'    => 'collecting-flies',
		'date'    => '2026-06-11 18:22:00',
		'content' => nop_fixture_video_block( $video['collecting-flies-story.mp4'] ),
	],

	// video — landscape.
	[
		'kind'    => 'video',
		'title'   => 'Daft Eddies on the fixed gear, and the long way back',
		'slug'    => 'daft-eddies-on-the-fixed-gear',
		'date'    => '2026-06-30 16:47:00',
		'content' => nop_fixture_video_block( $video['daft-eddies-on-fixed.mp4'] )
			. nop_fixture_p( 'Out to Daft Eddies on the fixed gear. The way there is downhill in the way that only becomes obvious on the way home, when there is one gear and no more to be said about it.' )
			. nop_fixture_p( 'Filmed on the phone, propped on a gate, which is why the horizon is doing what it is doing.' ),
	],
	[
		'kind'    => 'video',
		'title'   => 'Comber',
		'slug'    => 'comber-cine-cycle',
		'date'    => '2026-04-14 12:05:00',
		'content' => nop_fixture_video_block( $video['comber-cine-cycle.mp4'] )
			. nop_fixture_p( 'A short one — the cine-cycle rig on its first proper outing.' ),
	],

	// film — the catalogue entry, as distinct from a watch, which is the dated
	// act of watching. Film reuses the registered nop_indieweb_film_* meta.
	[
		'kind'    => 'film',
		'title'   => 'Stalker',
		'slug'    => 'stalker-1979',
		'date'    => '2026-07-19 22:30:00',
		'meta'    => [
			'nop_indieweb_film_title'  => 'Stalker',
			'nop_indieweb_film_year'   => '1979',
			'nop_indieweb_film_rating' => '5',
			'nop_indieweb_film_poster' => 'https://a.ltrbxd.com/resized/film-poster/5/1/3/0/8/51308-stalker-0-1000-0-1500-crop.jpg',
			'nop_indieweb_source_url'  => 'https://letterboxd.com/film/stalker/',
		],
		'content' => nop_fixture_p( 'Three men walk into a room that grants your deepest wish, and spend two and a half hours deciding not to go in. The sepia gives way to colour at the threshold and I have never once seen it coming.' )
			. nop_fixture_p( 'Filed here rather than as a watch because this one is a fixture — I return to it, and the collection is the point.' ),
	],
	[
		'kind'    => 'film',
		'title'   => 'Local Hero',
		'slug'    => 'local-hero-1983',
		'date'    => '2026-03-08 21:15:00',
		'meta'    => [
			'nop_indieweb_film_title'  => 'Local Hero',
			'nop_indieweb_film_year'   => '1983',
			'nop_indieweb_film_rating' => '4.5',
			'nop_indieweb_film_poster' => 'https://a.ltrbxd.com/resized/film-poster/5/1/4/3/2/51432-local-hero-0-1000-0-1500-crop.jpg',
			'nop_indieweb_source_url'  => 'https://letterboxd.com/film/local-hero/',
		],
		'content' => nop_fixture_p( 'The phone box at the end is doing more work than most films manage in their entirety.' ),
	],

	// book — no book-specific meta is registered, so a book post is blocks and
	// nothing else. Worth seeing that plainly before designing for it.
	[
		'kind'    => 'book',
		'title'   => 'The Nature and Art of Workmanship',
		'slug'    => 'nature-and-art-of-workmanship',
		'date'    => '2026-07-11 08:30:00',
		'content' => nop_fixture_image_block( nop_fixture_find_image( 'Norsey' ) )
			. nop_fixture_p( 'Pye separates the workmanship of risk from the workmanship of certainty, and once you have the distinction you cannot stop applying it. A jig is certainty. A brush is risk. Most of what I do at a keyboard turns out to be certainty pretending to be risk.' )
			. nop_fixture_p( 'Read over about a fortnight, mostly in the mornings.' ),
	],
	[
		'kind'    => 'book',
		'title'   => 'Ways of Seeing',
		'slug'    => 'ways-of-seeing',
		'date'    => '2026-02-24 19:58:00',
		'content' => nop_fixture_p( 'Short, and it rearranges things. The chapters made only of images are the ones I think about most, which is presumably the joke.' ),
	],

	// music — likewise no dedicated meta yet.
	[
		'kind'    => 'music',
		'title'   => 'Music for Airports',
		'slug'    => 'music-for-airports',
		'date'    => '2026-08-01 07:20:00',
		'content' => nop_fixture_image_block( nop_fixture_find_image( 'Bangor' ) )
			. nop_fixture_p( 'Put on for work and then never quite noticed again, which is the entire specification and the reason it succeeds.' ),
	],
	[
		// Two sentences and no media — the shortest post on the site, and the
		// one most likely to break a card layout.
		'kind'    => 'music',
		'title'   => 'Kind of Blue',
		'slug'    => 'kind-of-blue',
		'date'    => '2026-01-16 22:44:00',
		'content' => nop_fixture_p( 'The one everybody owns. Still right.' ),
	],

	// collection — the parent term, for a set that is not one book, film or
	// record. Posts on the children roll up into this archive on their own:
	// WordPress tax queries include descendants for hierarchical taxonomies.
	[
		'kind'    => 'collection',
		'title'   => 'Six books that changed how I work',
		'slug'    => 'six-books-that-changed-how-i-work',
		'date'    => '2026-07-25 10:00:00',
		'content' => nop_fixture_p( 'Not the best six books I have read. The six that altered something in the way I do the work, which is a different and much shorter list.' )
			. "<!-- wp:list -->\n<ul class=\"wp-block-list\">"
			. '<!-- wp:list-item --><li>David Pye — <em>The Nature and Art of Workmanship</em></li><!-- /wp:list-item -->'
			. '<!-- wp:list-item --><li>Christopher Alexander — <em>A Pattern Language</em></li><!-- /wp:list-item -->'
			. '<!-- wp:list-item --><li>John Berger — <em>Ways of Seeing</em></li><!-- /wp:list-item -->'
			. '<!-- wp:list-item --><li>Robert Bringhurst — <em>The Elements of Typographic Style</em></li><!-- /wp:list-item -->'
			. '<!-- wp:list-item --><li>Ellen Lupton — <em>Thinking with Type</em></li><!-- /wp:list-item -->'
			. '<!-- wp:list-item --><li>Bret Victor — <em>Magic Ink</em>, which is an essay, and I do not care</li><!-- /wp:list-item -->'
			. "</ul>\n<!-- /wp:list -->\n\n"
			. nop_fixture_p( 'The last one is not a book. The list is better for it.' ),
	],
	[
		'kind'    => 'collection',
		'title'   => 'The darkroom shelf',
		'slug'    => 'the-darkroom-shelf',
		'date'    => '2026-05-05 17:36:00',
		'content' => nop_fixture_image_block( nop_fixture_find_image( 'Park Loop' ), 'wide' )
			. nop_fixture_p( 'Everything currently on the shelf, photographed before I tidy it and lose the arrangement. Ilford HP5 in bulk, the Paterson tank with the cracked lid, two timers because the first one lies.' ),
	],
];

// ── Insert ───────────────────────────────────────────────────────────────────

$created = 0;
$skipped = 0;

foreach ( $posts as $spec ) {
	$existing = get_page_by_path( $spec['slug'], OBJECT, 'post' );
	if ( $existing ) {
		WP_CLI::log( sprintf( '  skip    %-12s #%-5d %s', $spec['kind'], $existing->ID, $spec['title'] ) );
		$skipped++;
		continue;
	}

	$id = wp_insert_post( [
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => $spec['title'],
		'post_name'    => $spec['slug'],
		'post_content' => $spec['content'],
		'post_date'    => $spec['date'],
		'post_author'  => 1,
	], true );

	if ( is_wp_error( $id ) ) {
		WP_CLI::warning( $spec['slug'] . ': ' . $id->get_error_message() );
		continue;
	}

	// The kind term drives everything else: Kind_Taxonomy mirrors it to
	// nop_indieweb_post_kind and applies the kind's default category.
	wp_set_object_terms( $id, $spec['kind'], 'nop_kind', false );
	update_post_meta( $id, 'nop_seed_fixture', 1 );

	foreach ( $spec['meta'] ?? [] as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}

	WP_CLI::log( sprintf( '  created %-12s #%-5d %s', $spec['kind'], $id, $spec['title'] ) );
	$created++;
}

WP_CLI::success( "created $created, skipped $skipped" );

WP_CLI::log( '' );
WP_CLI::log( 'Kind counts:' );
foreach ( get_terms( [ 'taxonomy' => 'nop_kind', 'hide_empty' => false ] ) as $term ) {
	$template = NOP_INDIEWEB_DIR . "templates/single-nop_kind-{$term->slug}.html";
	$note     = 0 === $term->count ? '  ← empty' : ( file_exists( $template ) ? '' : '  ← no kind template, falls back to the theme' );
	WP_CLI::log( sprintf( '  %-12s %4d%s', $term->slug, $term->count, $note ) );
}
