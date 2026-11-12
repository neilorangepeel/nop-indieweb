<?php
// CLI-only — refuse to run if reached over HTTP.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'This file may only be executed via WP-CLI.' );
}
/**
 * Turn the old tags into Subjects (STRUCTURE.md in the Orange Peel theme, step 3).
 *
 *   wp eval-file wp-content/plugins/nop-indieweb/bin/merge-subjects.php          # dry run: what would happen
 *   wp eval-file wp-content/plugins/nop-indieweb/bin/merge-subjects.php apply    # make the changes
 *
 * - A tag that is one of a subject's names (Kind\Hashtags::SUBJECTS) is merged into that subject:
 *   its posts gain the subject's tag, then it is deleted. The subject's own tag is created or
 *   renamed to the subject's spelling (BORO → Boro).
 * - Place tags are KEPT: they set Place on posts without coordinates, which isn't built yet (step 8).
 * - Tags on the drop list, and tags on two posts or fewer, are deleted. The words stay in the posts.
 * - Anything else (3+ posts, on no list) is KEPT and listed, for Neil to sort into STRUCTURE.md.
 *
 * Only tags change: post content is never touched. Afterwards run bin/hashtags-to-tags.php so old
 * posts gain the subjects their #hashtags name, then `wp transient delete --all` to refresh counts.
 * Idempotent: a second run finds nothing to merge or drop.
 */

use NOP\IndieWeb\Kind\Hashtags;

$apply = in_array( 'apply', (array) ( $args ?? [] ), true );
WP_CLI::line( $apply ? 'APPLYING changes.' : 'DRY RUN: nothing is changed. Add "apply" to make the changes.' );

$places = [ 'NYC', 'USA', 'London', 'Dublin', 'Kilkenny', 'Paris', 'Melbourne', 'Adelaide', 'Perth', 'Brussels', 'Belgium', 'Europe', 'Philippines', 'South Korea', 'Algeria', 'Canada', 'Belfast', 'Northern Ireland', 'Cathedral Quarter', 'City Centre', 'Teesside', 'Travel' ];
$drops  = [ 'twitter', 'Facebook', 'nikeplus', 'makeitcount', 'Cardiff', 'Chelsea', 'LUFC', 'Liverpool', 'Pompy', 'POR', 'Hartlepool', 'ff', 'n', 'Phone', 'jealous', 'FREE', 'RED', 'Gutted', 'facepalm', 'jokes', 'stayhumble', 'Believe', 'wishiwasthere', 'Twitterversary', 'MyTwitterAnniversary', 'tweetup', 'SIAP', 'pools', 'brooch', 'pin', 'calendar', 'apple', 'iphone', 'vegan', 'Tetley', 'Blog', 'FLAwards', 'BowlBar', 'freeteafriday', 'Learning to drive', 'Coffee' ];

$subject_of = [];
foreach ( (array) apply_filters( 'nop_indieweb_subjects', Hashtags::SUBJECTS ) as $subject => $names ) {
	foreach ( [ $subject, ...$names ] as $name ) {
		$subject_of[ Hashtags::key( (string) $name ) ] = (string) $subject;
	}
}
$places = array_flip( array_map( [ Hashtags::class, 'key' ], $places ) );
$drops  = array_flip( array_map( [ Hashtags::class, 'key' ], $drops ) );

// The subjects' own tags, found by slug (created or renamed on apply).
$subject_terms = [];
foreach ( array_unique( $subject_of ) as $subject ) {
	$term = get_term_by( 'slug', sanitize_title( $subject ), 'post_tag' );
	$note = ! $term ? '(new tag)' : ( html_entity_decode( $term->name ) !== $subject ? '(renamed from ' . html_entity_decode( $term->name ) . ')' : '' );
	if ( $apply ) {
		if ( ! $term ) {
			$made = wp_insert_term( $subject, 'post_tag' );
			$term = is_wp_error( $made ) ? null : get_term( (int) $made['term_id'], 'post_tag' );
		} elseif ( '' !== $note ) {
			wp_update_term( $term->term_id, 'post_tag', [ 'name' => $subject ] );
		}
	}
	$subject_terms[ $subject ] = [ 'term' => $term instanceof WP_Term ? $term : null, 'note' => $note, 'merged' => [] ];
}

$own     = array_filter( array_map( static fn( array $s ): int => $s['term'] ? $s['term']->term_id : 0, $subject_terms ) );
$kept    = [];
$dropped = [];
$links   = 0;
foreach ( get_terms( [ 'taxonomy' => 'post_tag', 'hide_empty' => false ] ) as $term ) {
	if ( in_array( $term->term_id, $own, true ) ) {
		continue;
	}
	$name    = html_entity_decode( $term->name );
	$key     = Hashtags::key( $name );
	$ids     = get_objects_in_term( $term->term_id, 'post_tag' );
	$ids     = is_wp_error( $ids ) ? [] : array_map( 'intval', $ids );
	$subject = $subject_of[ $key ] ?? null;

	if ( null !== $subject ) {
		$subject_terms[ $subject ]['merged'][] = sprintf( '%s (%d)', $name, count( $ids ) );
		$links += count( $ids );
		$target = $subject_terms[ $subject ]['term'];
		if ( $apply && $target ) {
			foreach ( $ids as $post_id ) {
				wp_add_object_terms( $post_id, $target->term_id, 'post_tag' );
			}
			wp_delete_term( $term->term_id, 'post_tag' );
		}
	} elseif ( isset( $places[ $key ] ) ) {
		$kept['Place (kept until Place is built)'][] = sprintf( '%s (%d)', $name, count( $ids ) );
	} elseif ( isset( $drops[ $key ] ) || count( $ids ) <= 2 ) {
		$dropped[ isset( $drops[ $key ] ) ? 'on the drop list' : 'on 2 posts or fewer' ][] = sprintf( '%s (%d)', $name, count( $ids ) );
		if ( $apply ) {
			wp_delete_term( $term->term_id, 'post_tag' );
		}
	} else {
		$kept['Unsorted, 3+ posts (kept: add to STRUCTURE.md as a subject name, place or drop)'][] = sprintf( '%s (%d)', $name, count( $ids ) );
	}
}

WP_CLI::line( '' );
WP_CLI::line( 'SUBJECTS' );
foreach ( $subject_terms as $subject => $s ) {
	$has = $s['term'] ? count( (array) get_objects_in_term( $s['term']->term_id, 'post_tag' ) ) : 0;
	WP_CLI::line( sprintf( '  %-12s %5d posts%s %s', $subject, $has, $apply ? '' : ' now', $s['note'] ) );
	if ( $s['merged'] ) {
		WP_CLI::line( '               ← ' . implode( ', ', $s['merged'] ) );
	}
}
foreach ( [ 'KEPT' => $kept, 'DELETED' => $dropped ] as $heading => $groups ) {
	foreach ( $groups as $why => $names ) {
		WP_CLI::line( '' );
		WP_CLI::line( sprintf( '%s, %s: %d', $heading, $why, count( $names ) ) );
		WP_CLI::line( '  ' . implode( ', ', array_slice( $names, 0, 80 ) ) . ( count( $names ) > 80 ? sprintf( ', … and %d more', count( $names ) - 80 ) : '' ) );
	}
}

$merged = array_sum( array_map( static fn( array $s ): int => count( $s['merged'] ), $subject_terms ) );
WP_CLI::line( '' );
WP_CLI::line( sprintf( 'Tags merged into subjects: %d (%d post links). Tags deleted: %d. Tags kept: %d.', $merged, $links, array_sum( array_map( 'count', $dropped ) ), array_sum( array_map( 'count', $kept ) ) ) );
$apply ? WP_CLI::success( 'Done. Next: bin/hashtags-to-tags.php apply, then wp transient delete --all.' ) : WP_CLI::success( 'Dry run finished.' );
