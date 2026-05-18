<?php
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'This file may only be executed via WP-CLI.' );
}

/**
 * Import Facebook checkin data into WordPress checkin posts.
 *
 * Processes two Facebook archive files:
 *   1. posts/check-ins.json — explicit check-ins with coords, address, shout text
 *   2. posts/places_you_have_been_tagged_in.json — tagged places (name + timestamp)
 *
 * Tagged places without coordinates are geocoded via Geoapify (cached per unique
 * venue name so each venue is only looked up once across the 218 entries).
 * Weather and map images are backfilled where coordinates are available.
 *
 * Idempotent: skips posts that already have a matching nop_indieweb_source_url.
 *
 * Run:
 *   studio wp eval-file wp-content/plugins/nop-indieweb/bin/import-facebook-checkins.php
 */

// ── Configure before running ───────────────────────────────────────────────────

// Root of the extracted Facebook archive (the folder containing your_facebook_activity/).
// JSON files are copied into bin/ because Studio's PHP WASM cannot reach ~/Downloads.
$fb_archive = WP_PLUGIN_DIR . '/nop-indieweb/bin/fb-archive';

// WordPress post status for imported checkins.
$fb_status = 'publish';

// Tags applied to every imported post.
$fb_tags = [ 'Facebook' ];

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Facebook archives multi-byte UTF-8 as if each byte were a Latin-1 character.
 * This re-encodes those strings back to proper UTF-8.
 */
function fb_fix_encoding( string $str ): string {
	return mb_convert_encoding( $str, 'UTF-8', 'ISO-8859-1' );
}

function fb_source_exists( string $source_url ): bool {
	return (bool) get_posts( [
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'post_status'    => 'any',
		'no_found_rows'  => true,
		'meta_key'       => 'nop_indieweb_source_url',
		'meta_value'     => $source_url,
	] );
}

/**
 * Parse "(lat , lng)" coordinate string from Facebook place tags.
 * Returns ['lat' => string, 'lng' => string] or [].
 */
function fb_parse_coords( string $raw ): array {
	if ( preg_match( '/\(\s*([-\d.]+)\s*,\s*([-\d.]+)\s*\)/', $raw, $m ) ) {
		return [ 'lat' => $m[1], 'lng' => $m[2] ];
	}
	return [];
}

/**
 * Best-effort parse of Facebook address strings like "34 Bedford Street, BT2 7FF Belfast".
 * Returns ['street', 'postcode', 'locality'].
 */
function fb_parse_address( string $raw ): array {
	$result = [ 'street' => '', 'postcode' => '', 'locality' => '' ];
	// Match a full UK postcode anywhere in the string.
	if ( preg_match( '/([A-Z]{1,2}\d[\dA-Z]?\s\d[A-Z]{2})/i', $raw, $m, PREG_OFFSET_CAPTURE ) ) {
		$postcode         = trim( $m[1][0] );
		$before           = trim( substr( $raw, 0, $m[1][1] ) );
		$after            = trim( substr( $raw, $m[1][1] + strlen( $postcode ) ) );
		$result['postcode'] = $postcode;
		$result['locality'] = $after;
		$result['street']   = rtrim( $before, ', ' );
	} else {
		$result['street'] = $raw;
	}
	return $result;
}

/**
 * Geocode a venue name via Geoapify. Returns ['lat', 'lng', 'locality', 'postcode', 'address', 'country'] or [].
 */
function fb_geocode( string $venue_name, string $api_key ): array {
	$url      = add_query_arg(
		[ 'text' => $venue_name, 'limit' => 1, 'apiKey' => $api_key ],
		'https://api.geoapify.com/v1/geocode/search'
	);
	$response = wp_remote_get( $url, [ 'timeout' => 10 ] );
	if ( is_wp_error( $response ) ) {
		return [];
	}
	$body    = json_decode( wp_remote_retrieve_body( $response ), true );
	$feature = $body['features'][0] ?? null;
	if ( ! $feature ) {
		return [];
	}
	$coords = $feature['geometry']['coordinates'] ?? null;
	if ( ! $coords || count( $coords ) < 2 ) {
		return [];
	}
	$props = $feature['properties'] ?? [];
	return [
		'lat'      => (string) $coords[1],
		'lng'      => (string) $coords[0],
		'locality' => $props['city'] ?? $props['county'] ?? $props['state'] ?? '',
		'postcode' => $props['postcode'] ?? '',
		'address'  => $props['address_line1'] ?? '',
		'country'  => $props['country'] ?? '',
	];
}

/**
 * Insert a checkin post. Enrichment (FSQ categories, weather, maps) is left
 * to the dedicated backfill commands so the import stays within the WP-CLI
 * 120 s timeout even with 200+ geocoding calls in phase 2.
 *
 * $data keys: content, post_date_gmt, source_url, venue_name, venue_lat, venue_lng,
 *             venue_address, venue_locality, venue_postcode, venue_region, venue_country.
 */
function fb_insert_checkin( array $data, array $tags, string $status ): int|WP_Error {
	$venue    = $data['venue_name'] ?? '';
	$locality = $data['venue_locality'] ?? '';
	$title    = $venue
		? ( $locality ? "{$venue}, {$locality}" : $venue )
		: 'Checked in';

	$note   = trim( $data['content'] ?? '' );
	$blocks = $note
		? "<!-- wp:paragraph -->\n<p>" . esc_html( $note ) . "</p>\n<!-- /wp:paragraph -->"
		: '';

	$post_date_gmt = $data['post_date_gmt'];
	$post_date     = get_date_from_gmt( $post_date_gmt );

	$post_id = wp_insert_post( [
		'post_title'    => $title,
		'post_content'  => $blocks,
		'post_status'   => $status,
		'post_type'     => 'post',
		'post_date'     => $post_date,
		'post_date_gmt' => $post_date_gmt,
		'tags_input'    => $tags,
		'meta_input'    => [
			'nop_indieweb_service'        => 'facebook',
			'nop_indieweb_platform'       => 'facebook',
			'nop_indieweb_source_url'     => $data['source_url'] ?? '',
			'nop_indieweb_checkin_url'    => $data['source_url'] ?? '',
			'nop_indieweb_venue_name'     => $venue,
			'nop_indieweb_venue_lat'      => $data['venue_lat'] ?? '',
			'nop_indieweb_venue_lng'      => $data['venue_lng'] ?? '',
			'nop_indieweb_venue_address'  => $data['venue_address'] ?? '',
			'nop_indieweb_venue_locality' => $locality,
			'nop_indieweb_venue_region'   => $data['venue_region'] ?? '',
			'nop_indieweb_venue_country'  => $data['venue_country'] ?? '',
			'nop_indieweb_venue_postcode' => $data['venue_postcode'] ?? '',
			'nop_indieweb_raw_payload'    => wp_json_encode( $data['raw'] ?? [] ),
		],
	], true );

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	wp_set_object_terms( $post_id, 'checkin', \NOP\IndieWeb\Kind\Kind_Taxonomy::TAXONOMY );

	return $post_id;
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────

// Geoapify is used only for geocoding tagged places (phase 2). FSQ categories,
// weather, and map images are handled by the backfill commands after import.
$geoapify_key = trim( (string) \NOP\IndieWeb\nop_indieweb_get_option( 'maps.geoapify_api_key', '' ) );

if ( ! $geoapify_key ) {
	WP_CLI::warning( 'No Geoapify key configured — tagged places will be created without coordinates.' );
}

// ── Phase 1: explicit check-ins ───────────────────────────────────────────────

$checkins_file = $fb_archive . '/your_facebook_activity/posts/check-ins.json';

if ( ! file_exists( $checkins_file ) ) {
	WP_CLI::error( "check-ins.json not found at: {$checkins_file}" );
}

$checkins = json_decode( file_get_contents( $checkins_file ), true ) ?? [];
WP_CLI::line( sprintf( 'Phase 1: %d explicit check-ins', count( $checkins ) ) );

$p1_created = $p1_skipped = $p1_failed = 0;

foreach ( $checkins as $item ) {
	$ts   = (int) ( $item['timestamp'] ?? 0 );
	$fbid = (string) ( $item['fbid'] ?? '' );

	// Index label_values by label name for easy access.
	$labels = [];
	foreach ( $item['label_values'] ?? [] as $lv ) {
		$labels[ $lv['label'] ?? '' ] = $lv;
	}

	$message    = fb_fix_encoding( (string) ( $labels['Message']['value'] ?? '' ) );
	$place_dict = array_column( $labels['Place tags']['dict'] ?? [], 'value', 'label' );
	$venue_name = fb_fix_encoding( (string) ( $place_dict['Name'] ?? '' ) );
	$raw_coords = (string) ( $place_dict['Coordinates'] ?? '' );
	$raw_addr   = fb_fix_encoding( (string) ( $place_dict['Address'] ?? '' ) );

	$source_url = (string) ( $labels['URL']['href'] ?? '' );
	if ( ! $source_url && $fbid ) {
		$source_url = "https://www.facebook.com/permalink.php?story_fbid={$fbid}";
	}

	if ( $source_url && fb_source_exists( $source_url ) ) {
		WP_CLI::line( "  skip (duplicate): {$venue_name}" );
		$p1_skipped++;
		continue;
	}

	$coords  = fb_parse_coords( $raw_coords );
	$address = fb_parse_address( $raw_addr );

	$data = [
		'content'        => $message,
		'post_date_gmt'  => gmdate( 'Y-m-d H:i:s', $ts ),
		'source_url'     => $source_url,
		'venue_name'     => $venue_name,
		'venue_lat'      => $coords['lat'] ?? '',
		'venue_lng'      => $coords['lng'] ?? '',
		'venue_address'  => $address['street'],
		'venue_locality' => $address['locality'],
		'venue_postcode' => $address['postcode'],
		'venue_region'   => '',
		'venue_country'  => '',
		'raw'            => $item,
	];

	$result = fb_insert_checkin( $data, $fb_tags, $fb_status );
	if ( is_wp_error( $result ) ) {
		WP_CLI::warning( "  fail: {$venue_name} — " . $result->get_error_message() );
		$p1_failed++;
	} else {
		WP_CLI::line( "  created #{$result}: {$venue_name}" );
		$p1_created++;
	}

	usleep( 300000 );
}

WP_CLI::line( "  → {$p1_created} created, {$p1_skipped} skipped, {$p1_failed} failed" );

// ── Phase 2: tagged places ────────────────────────────────────────────────────

$places_file = $fb_archive . '/your_facebook_activity/posts/places_you_have_been_tagged_in.json';

if ( ! file_exists( $places_file ) ) {
	WP_CLI::error( "places_you_have_been_tagged_in.json not found at: {$places_file}" );
}

$places = json_decode( file_get_contents( $places_file ), true ) ?? [];
WP_CLI::line( sprintf( 'Phase 2: %d tagged places (125 unique venues to geocode)', count( $places ) ) );

$geocode_cache = [];
$p2_created = $p2_skipped = $p2_failed = $p2_geocoded = 0;

foreach ( $places as $item ) {
	$ts   = 0;
	$name = '';
	$fbid = (string) ( $item['fbid'] ?? '' );

	foreach ( $item['label_values'] ?? [] as $lv ) {
		if ( ( $lv['label'] ?? '' ) === 'Visit time' ) {
			$ts = (int) ( $lv['timestamp_value'] ?? 0 );
		} elseif ( ( $lv['label'] ?? '' ) === 'Place name' ) {
			$name = fb_fix_encoding( (string) ( $lv['value'] ?? '' ) );
		}
	}

	if ( ! $name || ! $ts ) {
		WP_CLI::line( "  skip: missing name or timestamp (fbid={$fbid})" );
		$p2_skipped++;
		continue;
	}

	// Use the fbid as a unique identifier — tagged places have no post URL.
	$source_url = $fbid ? "https://www.facebook.com/tagged-place/{$fbid}" : '';

	if ( $source_url && fb_source_exists( $source_url ) ) {
		WP_CLI::line( "  skip (duplicate): {$name}" );
		$p2_skipped++;
		continue;
	}

	// Geocode — cache results so each unique venue name only hits the API once.
	$coords = [];
	if ( $geoapify_key ) {
		$cache_key = mb_strtolower( trim( $name ) );
		if ( array_key_exists( $cache_key, $geocode_cache ) ) {
			$coords = $geocode_cache[ $cache_key ];
		} else {
			$coords                       = fb_geocode( $name, $geoapify_key );
			$geocode_cache[ $cache_key ]  = $coords;
			if ( $coords ) {
				$p2_geocoded++;
			}
			usleep( 200000 ); // ~5 req/s — well within Geoapify free tier
		}
	}

	$data = [
		'content'        => '',
		'post_date_gmt'  => gmdate( 'Y-m-d H:i:s', $ts ),
		'source_url'     => $source_url,
		'venue_name'     => $name,
		'venue_lat'      => $coords['lat'] ?? '',
		'venue_lng'      => $coords['lng'] ?? '',
		'venue_address'  => $coords['address'] ?? '',
		'venue_locality' => $coords['locality'] ?? '',
		'venue_postcode' => $coords['postcode'] ?? '',
		'venue_region'   => '',
		'venue_country'  => $coords['country'] ?? '',
		'raw'            => $item,
	];

	$result = fb_insert_checkin( $data, $fb_tags, $fb_status );
	if ( is_wp_error( $result ) ) {
		WP_CLI::warning( "  fail: {$name} — " . $result->get_error_message() );
		$p2_failed++;
	} else {
		$geo_note = $coords ? 'geocoded' : 'no coords';
		WP_CLI::line( "  created #{$result}: {$name} ({$geo_note})" );
		$p2_created++;
	}

	usleep( 150000 );
}

WP_CLI::success( sprintf(
	"Done. Phase 1: %d created, %d skipped, %d failed. Phase 2: %d created (%d geocoded), %d skipped, %d failed.",
	$p1_created, $p1_skipped, $p1_failed,
	$p2_created, $p2_geocoded, $p2_skipped, $p2_failed,
) );
