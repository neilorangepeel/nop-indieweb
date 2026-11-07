<?php
// CLI-only — refuse to run if reached over HTTP.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'This file may only be executed via WP-CLI.' );
}
/**
 * Apply the tag sort decided in the Orange Peel theme's STRUCTURE.md (2026-11-07): looks move off tags into the
 * Look taxonomy, film and gear tags on writing merge into the subject Film photography, Vintage joins Nostalgia,
 * and place and venue-type tags go (the Place and Venue filters cover them). Leica and Fujifilm stay as tags until
 * the camera posts exist.
 *
 *   wp eval-file wp-content/plugins/nop-indieweb/bin/sort-tags-structure.php          # dry run: counts only
 *   wp eval-file wp-content/plugins/nop-indieweb/bin/sort-tags-structure.php apply    # make the changes
 *
 * Idempotent: once applied, the source tags no longer exist, so a second run finds nothing to do.
 */

use NOP\IndieWeb\Kind\Look_Taxonomy;

$apply = in_array( 'apply', (array) ( $args ?? [] ), true );
WP_CLI::line( $apply ? 'APPLYING changes.' : 'DRY RUN: nothing is changed. Add "apply" to make the changes.' );

// Tag slug => look slug. Film and Analog become the Film look on photos only (below).
$to_look = [
	'black-and-white' => 'black-and-white',
	'grain'           => 'grain',
	'shadows'         => 'shadows-light',
	'light'           => 'shadows-light',
	'texture'         => 'texture',
	'double-exposure' => 'double-exposure',
];
// Tag slug => subject (tag) name. Film and Analog join Film photography on everything but photos.
$to_subject = [
	'35mm'          => 'Film photography',
	'kodak'         => 'Film photography',
	'expired-film'  => 'Film photography',
	'medium-format' => 'Film photography',
	'vintage'       => 'Nostalgia',
];
$film_tags = [ 'film', 'analog' ];
$drop      = [
	// Places: the Place filter covers them (all on check-ins with coordinates).
	'belfast', 'cathedral-quarter', 'city-centre', 'botanic', 'ormeau', 'stranmillis', 'great-victoria-street', 'bradbury-place', 'howard-street',
	// Venue types: the Venue filter covers them (all on check-ins).
	'coffee', 'craft-beer', 'pub', 'cocktails', 'restaurant', 'fine-dining', 'brunch', 'live-music', 'historic', 'victorian',
	// Unused.
	'color', 'dining', 'indieweb', 'lisburn-road', 'market', 'pint', 'trad',
];

$report = [];
$note   = static function ( string $line ) use ( &$report ): void {
	$report[ $line ] = ( $report[ $line ] ?? 0 ) + 1;
};

foreach ( array_merge( array_keys( $to_look ), array_keys( $to_subject ), $film_tags, $drop ) as $slug ) {
	$tag = get_term_by( 'slug', $slug, 'post_tag' );
	if ( ! $tag instanceof WP_Term ) {
		continue;
	}
	$ids = get_objects_in_term( $tag->term_id, 'post_tag' );
	foreach ( is_wp_error( $ids ) ? [] : $ids as $post_id ) {
		$post_id = (int) $post_id;
		$kind    = (string) get_post_meta( $post_id, 'nop_indieweb_post_kind', true );
		if ( isset( $to_look[ $slug ] ) ) {
			$note( "{$tag->name} → Look: " . html_entity_decode( (string) get_term_by( 'slug', $to_look[ $slug ], Look_Taxonomy::TAXONOMY )->name ) );
			$apply && wp_set_object_terms( $post_id, [ $to_look[ $slug ] ], Look_Taxonomy::TAXONOMY, true );
		} elseif ( in_array( $slug, $film_tags, true ) && 'photo' === $kind ) {
			$note( "{$tag->name} (photo) → Look: Film" );
			$apply && wp_set_object_terms( $post_id, [ 'film' ], Look_Taxonomy::TAXONOMY, true );
		} elseif ( in_array( $slug, $film_tags, true ) ) {
			$note( "{$tag->name} ({$kind}) → Subject: Film photography" );
			$apply && wp_set_post_tags( $post_id, [ 'Film photography' ], true );
		} elseif ( isset( $to_subject[ $slug ] ) ) {
			$note( "{$tag->name} → Subject: {$to_subject[ $slug ]}" );
			$apply && wp_set_post_tags( $post_id, [ $to_subject[ $slug ] ], true );
		} else {
			$note( "{$tag->name} → dropped" );
		}
	}
	if ( 0 === (int) $tag->count ) {
		$note( "{$tag->name} → deleted (unused)" );
	}
	$apply && wp_delete_term( $tag->term_id, 'post_tag' );
}

ksort( $report );
foreach ( $report as $line => $count ) {
	WP_CLI::line( sprintf( '  %-48s %4d', $line, $count ) );
}
WP_CLI::line( sprintf( 'Changes: %d.', array_sum( $report ) ) );
$apply ? WP_CLI::success( 'Done.' ) : WP_CLI::success( 'Dry run finished.' );
