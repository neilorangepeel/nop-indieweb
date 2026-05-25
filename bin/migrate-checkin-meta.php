<?php
// CLI-only — refuse to run if reached over HTTP.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'This file may only be executed via WP-CLI.' );
}
/**
 * Migrate legacy Jetpack map blocks in checkin posts to nop venue meta.
 *
 * Old posts stored location as a wp:jetpack/map block inside a wp:details
 * "View location map" toggle in post content. New posts use structured
 * nop_indieweb_venue_* meta fields read by the nop-indieweb/checkin-meta block.
 *
 * Run via WP-CLI (dry-run by default):
 *   wp eval-file /path/to/bin/migrate-checkin-meta.php
 *
 * Apply changes (positional arg avoids WP-CLI flag interception):
 *   wp eval-file /path/to/bin/migrate-checkin-meta.php live
 *
 * Idempotent — posts that already have nop_indieweb_venue_name are skipped.
 */

$live = ( ( $args[0] ?? '' ) === 'live' );

if ( ! $live ) {
	WP_CLI::line( '── DRY RUN (pass -- --live to apply) ──────────────────────────' );
}

/**
 * Parse a Foursquare/Mapbox caption like
 * "The SSE Arena, 2 Queen's Quay, Belfast, Northern Ireland BT3 9DE, United Kingdom"
 * into address / locality / country components.
 *
 * Format: {venue}, [{street},] {city}, {region postcode}, {country}
 */
$parse_caption = function( string $caption ): array {
	$parts = array_map( 'trim', explode( ',', $caption ) );

	if ( count( $parts ) < 3 ) {
		return [ 'address' => '', 'locality' => '', 'country' => '' ];
	}

	$country  = array_pop( $parts ); // "United Kingdom"
	array_pop( $parts );             // "Northern Ireland BT4 1HR" — discard region/postcode
	$locality = array_pop( $parts ); // "Belfast"

	// Anything left after skipping the first element (venue name) is street address.
	$address  = implode( ', ', array_slice( $parts, 1 ) );

	return compact( 'address', 'locality', 'country' );
};

// ── Find checkin posts without venue meta ─────────────────────────────────────

$posts = get_posts( [
	'post_type'      => 'post',
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'no_found_rows'  => true,
	'meta_query'     => [
		[
			'key'   => 'nop_indieweb_post_kind',
			'value' => 'checkin',
		],
		[
			'key'     => 'nop_indieweb_venue_name',
			'compare' => 'NOT EXISTS',
		],
	],
] );

WP_CLI::line( sprintf( 'Found %d checkin post(s) without venue meta.', count( $posts ) ) );

$migrated = $skipped = $no_map = 0;

foreach ( $posts as $post_id ) {
	$content = get_post_field( 'post_content', $post_id );
	$title   = get_the_title( $post_id );

	// ── Extract wp:jetpack/map block attributes ───────────────────────────────
	if ( ! preg_match( '/<!-- wp:jetpack\/map (\{.+?\}) -->/s', $content, $m ) ) {
		WP_CLI::warning( "  #{$post_id} \"{$title}\": no wp:jetpack/map block found -- skipped." );
		$no_map++;
		continue;
	}

	$attrs = json_decode( $m[1], true );
	if ( ! $attrs || empty( $attrs['points'][0] ) ) {
		WP_CLI::warning( "  #{$post_id} \"{$title}\": could not parse map attributes -- skipped." );
		$skipped++;
		continue;
	}

	$point   = $attrs['points'][0];
	$lat     = (string) ( $point['coordinates']['latitude']  ?? '' );
	$lng     = (string) ( $point['coordinates']['longitude'] ?? '' );
	$name    = $point['placeTitle'] ?? $point['title'] ?? '';
	$caption = $point['caption'] ?? '';

	if ( ! $lat || ! $lng || ! $name ) {
		WP_CLI::warning( "  #{$post_id} \"{$title}\": missing lat/lng/name -- skipped." );
		$skipped++;
		continue;
	}

	$parsed = $parse_caption( $caption );

	WP_CLI::line( sprintf(
		'  #%d "%s": name="%s" lat=%s lng=%s address="%s" locality="%s" country="%s"',
		$post_id, $title, $name, $lat, $lng,
		$parsed['address'], $parsed['locality'], $parsed['country']
	) );

	if ( $live ) {
		update_post_meta( $post_id, 'nop_indieweb_venue_name',     $name );
		update_post_meta( $post_id, 'nop_indieweb_venue_lat',      $lat );
		update_post_meta( $post_id, 'nop_indieweb_venue_lng',      $lng );
		update_post_meta( $post_id, 'nop_indieweb_venue_address',  $parsed['address'] );
		update_post_meta( $post_id, 'nop_indieweb_venue_locality', $parsed['locality'] );
		update_post_meta( $post_id, 'nop_indieweb_venue_country',  $parsed['country'] );

		// Remove the old wp:details/wp:jetpack/map block entirely.
		// The nop-indieweb/checkin-meta block lives in the checkin template,
		// not in individual post content.
		$new_content = preg_replace(
			'/\n*<!-- wp:details \{"summary":"View location map"\} -->.*?<!-- \/wp:details -->\s*/s',
			"\n",
			$content
		);

		if ( $new_content !== null && $new_content !== $content ) {
			wp_update_post( [ 'ID' => $post_id, 'post_content' => $new_content ] );
			WP_CLI::line( "      → meta written + content updated" );
		} else {
			WP_CLI::line( "      → meta written (content unchanged — details block not matched)" );
		}

		$migrated++;
	}
}

$label = $live ? 'Migrated' : 'Would migrate';
WP_CLI::line( '' );
WP_CLI::line( sprintf( '%s: %d  |  no map block: %d  |  parse error: %d', $label, $live ? $migrated : count( $posts ) - $no_map - $skipped, $no_map, $skipped ) );

if ( $live ) {
	WP_CLI::success( 'Migration complete.' );
} else {
	WP_CLI::line( 'Re-run with -- --live to apply.' );
}
