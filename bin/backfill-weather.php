<?php
// CLI-only — refuse to run if reached over HTTP.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'This file may only be executed via WP-CLI.' );
}
/**
 * Backfill weather meta on existing checkin posts.
 *
 * Walks every checkin with venue lat/lng but no weather_provider meta, then
 * calls Weather_Fetcher::enrich_post() using the post's recorded date (not
 * the import date). Pirate Weather's time-machine endpoint serves historical
 * data, so the result reflects conditions at the checkin's actual moment.
 *
 * Idempotent: posts already enriched have the weather_provider meta and
 * are skipped on subsequent runs. Safe to re-run after partial failures.
 *
 * Run via WP-CLI:
 *   studio wp eval-file wp-content/plugins/nop-indieweb/bin/backfill-weather.php
 *
 * Throttled with a short sleep between requests so we don't hammer Pirate
 * Weather's free tier on large historical archives.
 */

$api_key = (string) \NOP\IndieWeb\nop_indieweb_get_option( 'weather.pirate_weather_api_key', '' );
if ( '' === $api_key ) {
	WP_CLI::error( 'No Pirate Weather API key configured. Set one in NOP IndieWeb settings → Swarm → Weather first.' );
}

$query = new \WP_Query( [
	'post_type'      => 'post',
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'no_found_rows'  => true,
	'tax_query'      => [
		[
			'taxonomy' => 'nop_kind',
			'field'    => 'slug',
			'terms'    => 'checkin',
		],
	],
	'meta_query'     => [
		'relation' => 'AND',
		[ 'key' => 'nop_indieweb_venue_lat',       'compare' => 'EXISTS' ],
		[ 'key' => 'nop_indieweb_venue_lng',       'compare' => 'EXISTS' ],
		[ 'key' => 'nop_indieweb_weather_provider', 'compare' => 'NOT EXISTS' ],
	],
] );

if ( ! $query->posts ) {
	WP_CLI::success( 'No checkins need backfill.' );
	return;
}

$total     = count( $query->posts );
$enriched  = 0;
$skipped   = 0;
$failed    = 0;

WP_CLI::line( "Found {$total} checkins to backfill." );

foreach ( $query->posts as $post_id ) {
	$lat = (float) get_post_meta( $post_id, 'nop_indieweb_venue_lat', true );
	$lng = (float) get_post_meta( $post_id, 'nop_indieweb_venue_lng', true );
	$ts  = (int) get_post_timestamp( $post_id, 'date_gmt' );

	if ( ( 0.0 === $lat && 0.0 === $lng ) || $ts <= 0 ) {
		WP_CLI::line( "  skip {$post_id}: missing lat/lng or timestamp" );
		$skipped++;
		continue;
	}

	$ok = \NOP\IndieWeb\Weather\Weather_Fetcher::enrich_post( $post_id, $lat, $lng, $ts );
	if ( $ok ) {
		$temp_c = (string) get_post_meta( $post_id, 'nop_indieweb_weather_temp_c', true );
		$icon   = (string) get_post_meta( $post_id, 'nop_indieweb_weather_icon', true );
		WP_CLI::line( "  enriched {$post_id}: {$temp_c}°C, {$icon}" );
		$enriched++;
	} else {
		WP_CLI::line( "  fail {$post_id}: fetcher returned false (see debug log)" );
		$failed++;
	}

	// Polite spacing — well inside Pirate Weather's free 10k/day budget.
	usleep( 250000 );
}

WP_CLI::success( "Done. {$enriched} enriched, {$skipped} skipped, {$failed} failed." );
