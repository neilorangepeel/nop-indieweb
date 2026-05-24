<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Cli;

use NOP\IndieWeb\Kind\Kind_Taxonomy;
use WP_CLI;

/**
 * `wp nop-indieweb import-swarm-checkins`
 *
 * Bulk-imports historical Swarm checkins via the Foursquare API. Requires a
 * personal OAuth token stored at venue.foursquare_user_token in plugin settings
 * (obtained by visiting /wp-json/nop-indieweb/v1/foursquare-auth).
 *
 * Produces posts identical to live OwnYourSwarm Micropub imports so the two
 * sources are indistinguishable. Idempotent: skips posts whose
 * nop_indieweb_checkin_url already exists.
 *
 * Afterward run:
 *   wp nop-indieweb backfill-venue-categories --search-by-name
 *   wp nop-indieweb backfill-weather
 *   wp nop-indieweb backfill-checkin-maps
 */
class Import_Swarm_Checkins {

	private const API_BASE   = 'https://api.foursquare.com/v2';
	private const API_V      = '20240101';
	private const PAGE_SIZE  = 250;

	/**
	 * Import historical Swarm checkins from the Foursquare API.
	 *
	 * ## OPTIONS
	 *
	 * [--with-photos]
	 * : Sideload checkin photos into the media library. Sets featured image and
	 *   appends an image/gallery block. Safe to re-run: skips posts that already
	 *   have nop_indieweb_photo_ids set.
	 *
	 * [--status=<status>]
	 * : Post status for imported posts. Default: publish
	 *
	 * [--limit=<n>]
	 * : Maximum number of checkins to import. Default: all.
	 *
	 * [--dry-run]
	 * : Show what would be imported without creating any posts.
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$opts        = get_option( 'nop_indieweb_settings', [] );
		$token       = $opts['venue']['foursquare_user_token'] ?? '';
		$status      = $assoc_args['status'] ?? 'publish';
		$with_photos = isset( $assoc_args['with-photos'] );
		$dry_run     = isset( $assoc_args['dry-run'] );
		$limit       = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : PHP_INT_MAX;
		$tags        = [ 'Swarm' ];

		if ( ! $token ) {
			WP_CLI::error( 'No Foursquare user token found. Visit /wp-json/nop-indieweb/v1/foursquare-auth to authenticate.' );
		}

		// ── Fetch total count ──────────────────────────────────────────────────

		$first = $this->api_get( $token, 0, 1 );
		if ( is_wp_error( $first ) ) {
			WP_CLI::error( 'API error: ' . $first->get_error_message() );
		}

		$total     = min( (int) ( $first['response']['checkins']['count'] ?? 0 ), $limit );
		$pages     = (int) ceil( $total / self::PAGE_SIZE );

		WP_CLI::log( "Found {$total} checkins across {$pages} page(s)." );

		$progress = \WP_CLI\Utils\make_progress_bar( $dry_run ? 'Scanning' : 'Importing', $total );
		$counts   = [ 'created' => 0, 'skipped' => 0, 'failed' => 0, 'photos' => 0 ];

		// ── Paginate ───────────────────────────────────────────────────────────

		$imported = 0;

		for ( $page = 0; $page < $pages; $page++ ) {
			$offset    = $page * self::PAGE_SIZE;
			$fetch     = min( self::PAGE_SIZE, $total - $offset );
			$page_num  = $page + 1;

			WP_CLI::log( "Fetching page {$page_num}/{$pages} (offset {$offset})…" );

			$data = $this->api_get( $token, $offset, $fetch );
			if ( is_wp_error( $data ) ) {
				WP_CLI::warning( "Page {$page_num} failed: " . $data->get_error_message() );
				continue;
			}

			$items      = $data['response']['checkins']['items'] ?? [];
			$page_start = $counts['created'];

			foreach ( $items as $item ) {
				if ( $imported >= $limit ) {
					break 2;
				}

				$progress->tick();

				$checkin_id    = (string) ( $item['id'] ?? '' );
				$source_url    = $checkin_id ? "https://www.swarmapp.com/checkin/{$checkin_id}" : '';
				$ts            = (int) ( $item['createdAt'] ?? 0 );
				$tz_offset_min = (int) ( $item['timeZoneOffset'] ?? 0 );
				$shout         = (string) ( $item['shout'] ?? '' );
				$venue       = $item['venue'] ?? [];
				$location    = $venue['location'] ?? [];
				$categories  = array_column( $venue['categories'] ?? [], 'name' );
				$photo_items = $item['photos']['items'] ?? [];

				$venue_name  = (string) ( $venue['name'] ?? '' );
				$fsq_id      = (string) ( $venue['id'] ?? '' );
				$lat         = (string) ( $location['lat'] ?? '' );
				$lng         = (string) ( $location['lng'] ?? '' );
				$address     = (string) ( $location['address'] ?? '' );
				$locality    = (string) ( $location['city'] ?? '' );
				$region      = (string) ( $location['state'] ?? '' );
				$country     = (string) ( $location['country'] ?? '' );
				$postcode    = (string) ( $location['postalCode'] ?? '' );

				$label = $locality ? "{$venue_name}, {$locality}" : $venue_name;
				$date  = gmdate( 'Y-m-d', $ts );

				if ( $source_url && $this->checkin_exists( $source_url ) ) {
					if ( $with_photos && $photo_items && ! $dry_run ) {
						$post_id = $this->find_post_by_checkin_url( $source_url );
						if ( $post_id && ! get_post_meta( $post_id, 'nop_indieweb_photo_ids', true ) ) {
							$n = $this->sideload_photos( $post_id, $photo_items );
							if ( $n ) {
								WP_CLI::log( "  ↺ {$label} ({$date}) — {$n} photo(s) backfilled" );
								$counts['photos'] += $n;
							}
						}
					}
					$counts['skipped']++;
					continue;
				}

				if ( $dry_run ) {
					$note = $photo_items ? ' [' . count( $photo_items ) . ' photo(s)]' : '';
					WP_CLI::log( "  + {$label} ({$date}){$note}" );
					$counts['created']++;
					$imported++;
					continue;
				}

				$post_id = $this->insert( [
					'ts'            => $ts,
					'tz_offset_min' => $tz_offset_min,
					'shout'         => $shout,
					'source_url'  => $source_url,
					'venue_name'  => $venue_name,
					'fsq_id'      => $fsq_id,
					'lat'         => $lat,
					'lng'         => $lng,
					'address'     => $address,
					'locality'    => $locality,
					'region'      => $region,
					'country'     => $country,
					'postcode'    => $postcode,
					'categories'  => $categories,
					'raw'         => $item,
				], $tags, $status );

				if ( is_wp_error( $post_id ) ) {
					WP_CLI::warning( "  ✗ {$label} — " . $post_id->get_error_message() );
					$counts['failed']++;
				} else {
					$counts['created']++;
					$imported++;
					$photo_note = '';
					if ( $with_photos && $photo_items ) {
						$n = $this->sideload_photos( $post_id, $photo_items );
						$counts['photos'] += $n;
						$photo_note = $n ? " [+{$n} photo(s)]" : '';
					}
					WP_CLI::log( "  ✓ #{$post_id} {$label} ({$date}){$photo_note}" );
				}
			}
		}

		$page_created = $counts['created'] - $page_start;
		WP_CLI::log( "  Page {$page_num} done: {$page_created} created · " . ( count( $items ) - $page_created ) . " skipped" );

		$progress->finish();

		WP_CLI::log( sprintf(
			'  → %d created · %d skipped · %d failed · %d photos',
			$counts['created'], $counts['skipped'], $counts['failed'], $counts['photos']
		) );

		if ( ! $dry_run && $counts['created'] > 0 ) {
			WP_CLI::success( sprintf(
				'Imported %d posts. Run backfill-venue-categories --search-by-name, backfill-weather, backfill-checkin-maps to enrich.',
				$counts['created']
			) );
		}
	}

	private function insert( array $data, array $tags, string $status ): int|\WP_Error {
		$venue    = $data['venue_name'];
		$locality = $data['locality'];
		$title    = $venue
			? ( $locality ? "{$venue}, {$locality}" : $venue )
			: 'Checked in';

		$shout  = trim( $data['shout'] );
		$blocks = $shout
			? "<!-- wp:paragraph -->\n<p>" . esc_html( $shout ) . "</p>\n<!-- /wp:paragraph -->"
			: '';

		$post_date_gmt = gmdate( 'Y-m-d H:i:s', $data['ts'] );
		$post_date     = gmdate( 'Y-m-d H:i:s', $data['ts'] + ( ( $data['tz_offset_min'] ?? 0 ) * 60 ) );

		$post_id = wp_insert_post( [
			'post_title'    => $title,
			'post_content'  => $blocks,
			'post_status'   => $status,
			'post_type'     => 'post',
			'post_date'     => $post_date,
			'post_date_gmt' => $post_date_gmt,
			'tags_input'    => $tags,
			'meta_input'    => [
				'nop_indieweb_service'          => 'swarm',
				'nop_indieweb_platform'         => 'swarm',
				'nop_indieweb_source_url'       => $data['source_url'],
				'nop_indieweb_checkin_url'      => $data['source_url'],
				'nop_indieweb_venue_name'       => $data['venue_name'],
				'nop_indieweb_venue_uid'        => $data['fsq_id'],
				'nop_indieweb_venue_fsq_id'     => $data['fsq_id'],
				'nop_indieweb_venue_lat'        => $data['lat'],
				'nop_indieweb_venue_lng'        => $data['lng'],
				'nop_indieweb_venue_address'    => $data['address'],
				'nop_indieweb_venue_locality'   => $locality,
				'nop_indieweb_venue_region'     => $data['region'],
				'nop_indieweb_venue_country'    => $data['country'],
				'nop_indieweb_venue_postcode'   => $data['postcode'],
				'nop_indieweb_venue_categories' => $data['categories'],
				'nop_indieweb_raw_payload'      => wp_json_encode( $data['raw'] ),
			],
		], true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		wp_set_object_terms( $post_id, 'checkin', Kind_Taxonomy::TAXONOMY );

		return $post_id;
	}

	private function sideload_photos( int $post_id, array $photo_items ): int {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$cap_bytes    = (int) apply_filters( 'nop_indieweb_photo_size_cap', 25 * 1024 * 1024 );
		$ids          = [];
		$set_featured = ! has_post_thumbnail( $post_id );

		foreach ( $photo_items as $photo ) {
			$prefix = (string) ( $photo['prefix'] ?? '' );
			$suffix = (string) ( $photo['suffix'] ?? '' );
			if ( ! $prefix || ! $suffix ) {
				continue;
			}
			$url = $prefix . 'original' . $suffix;

			$tmp = download_url( $url );
			if ( is_wp_error( $tmp ) ) {
				WP_CLI::warning( "  photo download failed: {$url}" );
				continue;
			}

			if ( filesize( $tmp ) > $cap_bytes ) {
				WP_CLI::warning( "  photo too large, skipping: {$url}" );
				@unlink( $tmp );
				continue;
			}

			$file = [
				'name'     => basename( $suffix ),
				'tmp_name' => $tmp,
			];

			$id = media_handle_sideload( $file, $post_id );
			if ( is_wp_error( $id ) ) {
				WP_CLI::warning( "  sideload failed: " . $id->get_error_message() );
				@unlink( $tmp );
				continue;
			}

			$ids[] = $id;

			if ( $set_featured ) {
				set_post_thumbnail( $post_id, $id );
				$set_featured = false;
			}
		}

		if ( ! $ids ) {
			return 0;
		}

		$existing_ids  = array_filter( (array) get_post_meta( $post_id, 'nop_indieweb_photo_ids', true ) );
		$existing_urls = array_filter( (array) get_post_meta( $post_id, 'nop_indieweb_photos', true ) );

		update_post_meta( $post_id, 'nop_indieweb_photo_ids', array_merge( $existing_ids, $ids ) );
		update_post_meta( $post_id, 'nop_indieweb_photos', array_merge(
			$existing_urls,
			array_filter( array_map( 'wp_get_attachment_url', $ids ) )
		) );

		$blocks = $this->build_photo_blocks( $ids );
		if ( $blocks ) {
			$post    = get_post( $post_id );
			$current = rtrim( (string) $post->post_content );
			wp_update_post( [
				'ID'           => $post_id,
				'post_content' => ( $current ? $current . "\n\n" : '' ) . $blocks,
			] );
		}

		return count( $ids );
	}

	private function build_photo_blocks( array $ids ): string {
		if ( ! $ids ) {
			return '';
		}
		if ( 1 === count( $ids ) ) {
			$src = wp_get_attachment_url( $ids[0] );
			return sprintf(
				"<!-- wp:image {\"id\":%d,\"sizeSlug\":\"large\",\"linkDestination\":\"none\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"%s\" alt=\"\" class=\"wp-image-%d\"/></figure>\n<!-- /wp:image -->",
				$ids[0], esc_url( $src ), $ids[0]
			);
		}
		$inner = '';
		foreach ( $ids as $id ) {
			$src    = wp_get_attachment_url( $id );
			$inner .= sprintf(
				"\n<!-- wp:image {\"id\":%d,\"sizeSlug\":\"large\",\"linkDestination\":\"none\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"%s\" alt=\"\" class=\"wp-image-%d\"/></figure>\n<!-- /wp:image -->",
				$id, esc_url( $src ), $id
			);
		}
		return "<!-- wp:gallery {\"columns\":2,\"linkTo\":\"none\"} -->\n<figure class=\"wp-block-gallery has-nested-images columns-2 is-cropped\">{$inner}\n</figure>\n<!-- /wp:gallery -->";
	}

	private function checkin_exists( string $url ): bool {
		return (bool) get_posts( [
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'post_status'    => 'any',
			'no_found_rows'  => true,
			'meta_query'     => [
				'relation' => 'OR',
				[ 'key' => 'nop_indieweb_source_url',  'value' => $url ],
				[ 'key' => 'nop_indieweb_checkin_url', 'value' => $url ],
			],
		] );
	}

	private function find_post_by_checkin_url( string $url ): int {
		$ids = get_posts( [
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'post_status'    => 'any',
			'no_found_rows'  => true,
			'meta_query'     => [
				'relation' => 'OR',
				[ 'key' => 'nop_indieweb_source_url',  'value' => $url ],
				[ 'key' => 'nop_indieweb_checkin_url', 'value' => $url ],
			],
		] );
		return $ids ? (int) $ids[0] : 0;
	}

	private function api_get( string $token, int $offset, int $limit ): array|\WP_Error {
		$url = add_query_arg( [
			'oauth_token' => $token,
			'v'           => self::API_V,
			'limit'       => $limit,
			'offset'      => $offset,
		], self::API_BASE . '/users/self/checkins' );

		$response = wp_remote_get( $url, [ 'timeout' => 30 ] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$msg = $body['meta']['errorDetail'] ?? "HTTP {$code}";
			return new \WP_Error( 'api_error', $msg );
		}

		return $body;
	}
}
