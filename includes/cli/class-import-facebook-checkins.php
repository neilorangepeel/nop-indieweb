<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Cli;

use NOP\IndieWeb\Kind\Kind_Taxonomy;
use WP_CLI;

/**
 * `wp nop-indieweb import-facebook-checkins`
 *
 * Imports explicit check-ins and tagged places from a Facebook data archive.
 * Posts are created with venue name and date; run the following backfills
 * afterward to populate coordinates, categories, weather, and map images:
 *
 *   wp nop-indieweb backfill-venue-categories --search-by-name
 *   wp nop-indieweb backfill-weather
 *   wp nop-indieweb backfill-checkin-maps
 */
class Import_Facebook_Checkins {

	/**
	 * Import Facebook checkin data into WordPress checkin posts.
	 *
	 * Reads two files from the Facebook archive:
	 *   - posts/check-ins.json (explicit check-ins with coords and shout text)
	 *   - posts/places_you_have_been_tagged_in.json (tagged places, name + date only)
	 *
	 * Idempotent: skips posts whose nop_indieweb_source_url already exists.
	 *
	 * ## OPTIONS
	 *
	 * <archive>
	 * : Path to the root of the extracted Facebook archive (the folder containing
	 *   your_facebook_activity/, ads_information/, etc.).
	 *
	 * [--status=<status>]
	 * : Post status for imported posts. Default: publish
	 *
	 * [--dry-run]
	 * : Show what would be imported without creating any posts.
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$archive = rtrim( $args[0] ?? '', '/' );
		$status  = $assoc_args['status'] ?? 'publish';
		$dry_run = isset( $assoc_args['dry-run'] );
		$tags    = [ 'Facebook' ];

		if ( ! $archive ) {
			WP_CLI::error( 'Usage: wp nop-indieweb import-facebook-checkins <path-to-archive>' );
		}

		// ── Phase 1: explicit check-ins ────────────────────────────────────────

		$checkins_file = $archive . '/your_facebook_activity/posts/check-ins.json';
		if ( ! file_exists( $checkins_file ) ) {
			WP_CLI::error( "check-ins.json not found at: {$checkins_file}" );
		}

		$checkins = json_decode( (string) file_get_contents( $checkins_file ), true ) ?? [];
		$total1   = count( $checkins );
		WP_CLI::log( "Phase 1: {$total1} explicit check-ins" );

		$progress1 = \WP_CLI\Utils\make_progress_bar( $dry_run ? 'Scanning' : 'Importing', $total1 );
		$p1        = [ 'created' => 0, 'skipped' => 0, 'failed' => 0 ];

		foreach ( $checkins as $item ) {
			$progress1->tick();

			$ts   = (int) ( $item['timestamp'] ?? 0 );
			$fbid = (string) ( $item['fbid'] ?? '' );

			$labels = [];
			foreach ( $item['label_values'] ?? [] as $lv ) {
				$labels[ $lv['label'] ?? '' ] = $lv;
			}

			$message    = self::fix_encoding( (string) ( $labels['Message']['value'] ?? '' ) );
			$place_dict = array_column( $labels['Place tags']['dict'] ?? [], 'value', 'label' );
			$venue_name = self::fix_encoding( (string) ( $place_dict['Name'] ?? '' ) );
			$raw_coords = (string) ( $place_dict['Coordinates'] ?? '' );
			$raw_addr   = self::fix_encoding( (string) ( $place_dict['Address'] ?? '' ) );

			$source_url = (string) ( $labels['URL']['href'] ?? '' );
			if ( ! $source_url && $fbid ) {
				$source_url = "https://www.facebook.com/permalink.php?story_fbid={$fbid}";
			}

			if ( $source_url && $this->source_exists( $source_url ) ) {
				$p1['skipped']++;
				continue;
			}

			if ( $dry_run ) {
				WP_CLI::line( "  would create: {$venue_name}" );
				$p1['created']++;
				continue;
			}

			$coords  = self::parse_coords( $raw_coords );
			$address = self::parse_address( $raw_addr );

			$result = $this->insert( [
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
			], $tags, $status );

			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( "  fail: {$venue_name} — " . $result->get_error_message() );
				$p1['failed']++;
			} else {
				$p1['created']++;
			}
		}

		$progress1->finish();
		WP_CLI::log( "  → {$p1['created']} created · {$p1['skipped']} skipped · {$p1['failed']} failed" );

		// ── Phase 2: tagged places ──────────────────────────────────────────────

		$places_file = $archive . '/your_facebook_activity/posts/places_you_have_been_tagged_in.json';
		if ( ! file_exists( $places_file ) ) {
			WP_CLI::error( "places_you_have_been_tagged_in.json not found at: {$places_file}" );
		}

		$places = json_decode( (string) file_get_contents( $places_file ), true ) ?? [];
		$total2 = count( $places );
		WP_CLI::log( "Phase 2: {$total2} tagged places" );

		$progress2 = \WP_CLI\Utils\make_progress_bar( $dry_run ? 'Scanning' : 'Importing', $total2 );
		$p2        = [ 'created' => 0, 'skipped' => 0, 'failed' => 0 ];

		foreach ( $places as $item ) {
			$progress2->tick();

			$ts   = 0;
			$name = '';
			$fbid = (string) ( $item['fbid'] ?? '' );

			foreach ( $item['label_values'] ?? [] as $lv ) {
				if ( ( $lv['label'] ?? '' ) === 'Visit time' ) {
					$ts = (int) ( $lv['timestamp_value'] ?? 0 );
				} elseif ( ( $lv['label'] ?? '' ) === 'Place name' ) {
					$name = self::fix_encoding( (string) ( $lv['value'] ?? '' ) );
				}
			}

			if ( ! $name || ! $ts ) {
				$p2['skipped']++;
				continue;
			}

			$source_url = $fbid ? "https://www.facebook.com/tagged-place/{$fbid}" : '';

			if ( $source_url && $this->source_exists( $source_url ) ) {
				$p2['skipped']++;
				continue;
			}

			if ( $dry_run ) {
				WP_CLI::line( "  would create: {$name}" );
				$p2['created']++;
				continue;
			}

			$result = $this->insert( [
				'content'        => '',
				'post_date_gmt'  => gmdate( 'Y-m-d H:i:s', $ts ),
				'source_url'     => $source_url,
				'venue_name'     => $name,
				'venue_lat'      => '',
				'venue_lng'      => '',
				'venue_address'  => '',
				'venue_locality' => '',
				'venue_postcode' => '',
				'venue_region'   => '',
				'venue_country'  => '',
				'raw'            => $item,
			], $tags, $status );

			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( "  fail: {$name} — " . $result->get_error_message() );
				$p2['failed']++;
			} else {
				$p2['created']++;
			}
		}

		$progress2->finish();
		WP_CLI::log( "  → {$p2['created']} created · {$p2['skipped']} skipped · {$p2['failed']} failed" );

		WP_CLI::success( sprintf(
			'%sImported %d posts. Run backfill-venue-categories --search-by-name, backfill-weather, backfill-checkin-maps to enrich.',
			$dry_run ? '[DRY RUN] ' : '',
			$p1['created'] + $p2['created']
		) );
	}

	private function insert( array $data, array $tags, string $status ): int|\WP_Error {
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

		wp_set_object_terms( $post_id, 'checkin', Kind_Taxonomy::TAXONOMY );

		return $post_id;
	}

	private function source_exists( string $source_url ): bool {
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
	 * Facebook archives multi-byte UTF-8 as if each byte were a Latin-1 character.
	 * Re-encodes those strings back to proper UTF-8.
	 */
	private static function fix_encoding( string $str ): string {
		return mb_convert_encoding( $str, 'UTF-8', 'ISO-8859-1' );
	}

	/**
	 * Parse "(lat , lng)" coordinate string from Facebook place tags.
	 */
	private static function parse_coords( string $raw ): array {
		if ( preg_match( '/\(\s*([-\d.]+)\s*,\s*([-\d.]+)\s*\)/', $raw, $m ) ) {
			return [ 'lat' => $m[1], 'lng' => $m[2] ];
		}
		return [];
	}

	/**
	 * Best-effort parse of "34 Bedford Street, BT2 7FF Belfast" style addresses.
	 */
	private static function parse_address( string $raw ): array {
		$result = [ 'street' => '', 'postcode' => '', 'locality' => '' ];
		if ( preg_match( '/([A-Z]{1,2}\d[\dA-Z]?\s\d[A-Z]{2})/i', $raw, $m, PREG_OFFSET_CAPTURE ) ) {
			$postcode             = trim( $m[1][0] );
			$before               = trim( substr( $raw, 0, $m[1][1] ) );
			$after                = trim( substr( $raw, $m[1][1] + strlen( $postcode ) ) );
			$result['postcode']   = $postcode;
			$result['locality']   = $after;
			$result['street']     = rtrim( $before, ', ' );
		} else {
			$result['street'] = $raw;
		}
		return $result;
	}
}
