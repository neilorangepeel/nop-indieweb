<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Services;

/**
 * Handles Micropub payloads from OwnYourSwarm (Foursquare/Swarm checkins).
 *
 * OwnYourSwarm sends h-entry posts with an h-card checkin. Payload shape:
 *
 *   properties.checkin[0].properties.name[0]           → venue name
 *   properties.checkin[0].properties.url[0]            → Foursquare venue URL
 *   properties.checkin[0].properties.uid[0]            → Foursquare venue ID
 *   properties.checkin[0].properties.latitude[0]       → latitude
 *   properties.checkin[0].properties.longitude[0]      → longitude
 *   properties.checkin[0].properties.street-address[0] → street address
 *   properties.checkin[0].properties.locality[0]       → city / town
 *   properties.checkin[0].properties.region[0]         → county / state
 *   properties.checkin[0].properties.country-name[0]   → country
 *   properties.checkin[0].properties.postal-code[0]    → postcode
 *   properties.checkin[0].properties.category[]        → venue categories (e.g. ["Bar","Pub"])
 *   properties.content[0]                              → shout text or auto-generated note
 *   properties.published[0]                            → ISO 8601 checkin timestamp
 *   properties.syndication[]                           → Swarm checkin permalink(s)
 *   properties.photo[]                                 → photo CDN URLs (if any)
 */
class Swarm extends Service_Base {

	public function get_name(): string {
		return 'Swarm';
	}

	public function get_slug(): string {
		return 'swarm';
	}

	public function can_handle( array $payload ): bool {
		if ( ! in_array( 'h-entry', $payload['type'] ?? [], true ) ) {
			return false;
		}
		$checkin = $payload['properties']['checkin'][0] ?? null;
		return is_array( $checkin ) && in_array( 'h-card', $checkin['type'] ?? [], true );
	}

	// The first syndication URL is the Swarm checkin permalink — globally unique per checkin.
	protected function get_dedup_key( array $parsed ): ?string {
		return $parsed['checkin_url'] ?: null;
	}

	public function parse( array $payload ): array {
		$props         = $payload['properties'] ?? [];
		$checkin_props = $props['checkin'][0]['properties'] ?? [];

		// Content can arrive as a plain string or as {'html':'...','value':'...'}.
		$content_raw = $props['content'][0] ?? '';
		$content     = is_array( $content_raw )
			? ( $content_raw['value'] ?? '' )
			: (string) $content_raw;

		$syndication = array_map( 'esc_url_raw', array_values( array_filter( (array) ( $props['syndication'] ?? [] ) ) ) );

		// Venue categories come as an array of strings, e.g. ["Bar", "Pub"].
		$venue_categories = array_map(
			'sanitize_text_field',
			array_values( array_filter( (array) ( $checkin_props['category'] ?? [] ) ) )
		);

		return [
			'content'           => sanitize_textarea_field( $content ),
			'published'         => sanitize_text_field( $props['published'][0] ?? '' ),

			// Venue identity
			'venue_name'        => sanitize_text_field( $checkin_props['name'][0] ?? '' ),
			'venue_url'         => esc_url_raw( $checkin_props['url'][0] ?? '' ),
			'venue_uid'         => sanitize_text_field( $checkin_props['uid'][0] ?? '' ),

			// Coordinates
			'venue_lat'         => sanitize_text_field( $checkin_props['latitude'][0] ?? '' ),
			'venue_lng'         => sanitize_text_field( $checkin_props['longitude'][0] ?? '' ),
			'venue_altitude'    => sanitize_text_field( $checkin_props['altitude'][0] ?? '' ),
			'venue_accuracy'    => sanitize_text_field( $checkin_props['accuracy'][0] ?? '' ),

			// Full address
			'venue_address'     => sanitize_text_field( $checkin_props['street-address'][0] ?? '' ),
			'venue_locality'    => sanitize_text_field( $checkin_props['locality'][0] ?? '' ),
			'venue_region'      => sanitize_text_field( $checkin_props['region'][0] ?? '' ),
			'venue_country'     => sanitize_text_field( $checkin_props['country-name'][0] ?? '' ),
			'venue_postcode'    => sanitize_text_field( $checkin_props['postal-code'][0] ?? '' ),

			// Venue categories (e.g. ["Bar", "Pub"])
			'venue_categories'  => $venue_categories,

			// Syndication
			'syndication'       => $syndication,
			// The first syndication URL is the unique Swarm checkin ID — stored separately
			// so we can query it directly for duplicate detection without deserializing arrays.
			'checkin_url'       => $syndication[0] ?? '',

			// Photos — CDN URLs always stored; sideloaded IDs added after insert if enabled.
			'photos'            => array_map( 'esc_url_raw', array_values( array_filter( (array) ( $props['photo'] ?? [] ) ) ) ),

			'raw_payload'       => $payload,
		];
	}

	public function map_to_post( array $parsed ): array {
		$settings    = $this->get_settings();
		$post_status = $settings['post_status'] ?? 'publish';

		// Use the Swarm checkin timestamp as the post date.
		// Drop it if it's more than 60 seconds in the future (timezone mismatch in test
		// payloads) — avoids WordPress scheduling the post as 'future'.
		$post_date     = '';
		$post_date_gmt = '';
		if ( $parsed['published'] ) {
			$timestamp = strtotime( $parsed['published'] );
			if ( $timestamp && $timestamp <= ( time() + 60 ) ) {
				$post_date     = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ) );
				$post_date_gmt = gmdate( 'Y-m-d H:i:s', $timestamp );
			}
		}

		$title = $parsed['venue_name']
			? sprintf( 'Checked in at %s', $parsed['venue_name'] )
			: 'Checked in';

		$category_names = array_filter( array_map( 'trim', explode( ',', $settings['post_category'] ?? 'Checkin' ) ) );
		$category_ids   = array_values( array_filter( array_map( [ $this, 'ensure_category' ], $category_names ) ) );

		$tags = array_filter( array_map( 'trim', explode( ',', $settings['post_tags'] ?? 'Swarm' ) ) );

		$note   = trim( $parsed['content'] );
		$blocks = $note
			? "<!-- wp:paragraph -->\n<p>{$note}</p>\n<!-- /wp:paragraph -->"
			: '';
		// Photos are injected as real image blocks in after_insert() once sideloading completes.
		// The checkin-card block lives in the template, not in post content.

		$args = [
			'post_title'   => $title,
			'post_content' => $blocks,
			'post_status'  => $post_status,
			'post_type'    => 'post',
			'tags_input'   => $tags,
		];

		if ( $category_ids ) {
			$args['post_category'] = $category_ids;
		}
		if ( $post_date ) {
			$args['post_date']     = $post_date;
			$args['post_date_gmt'] = $post_date_gmt;
		}

		return $args;
	}

	private function ensure_category( string $name ): int {
		$slug = sanitize_title( $name );
		$term = get_term_by( 'slug', $slug, 'category' );
		if ( $term instanceof \WP_Term ) {
			return $term->term_id;
		}
		$result = wp_insert_term( $name, 'category' );
		return is_wp_error( $result ) ? 0 : (int) $result['term_id'];
	}

	public function get_meta( array $parsed ): array {
		return [
			// Venue identity
			'nop_indieweb_venue_name'       => $parsed['venue_name'],
			'nop_indieweb_venue_url'        => $parsed['venue_url'],
			'nop_indieweb_venue_uid'        => $parsed['venue_uid'],

			// Coordinates
			'nop_indieweb_venue_lat'        => $parsed['venue_lat'],
			'nop_indieweb_venue_lng'        => $parsed['venue_lng'],
			'nop_indieweb_venue_altitude'   => $parsed['venue_altitude'],
			'nop_indieweb_venue_accuracy'   => $parsed['venue_accuracy'],

			// Address
			'nop_indieweb_venue_address'    => $parsed['venue_address'],
			'nop_indieweb_venue_locality'   => $parsed['venue_locality'],
			'nop_indieweb_venue_region'     => $parsed['venue_region'],
			'nop_indieweb_venue_country'    => $parsed['venue_country'],
			'nop_indieweb_venue_postcode'   => $parsed['venue_postcode'],

			// Categories
			'nop_indieweb_venue_categories' => $parsed['venue_categories'],

			// Syndication — unique checkin URL stored separately for fast dedup queries.
			'nop_indieweb_checkin_url'      => $parsed['checkin_url'],
			'nop_indieweb_syndication'      => $parsed['syndication'],

			// Photos — CDN URLs always stored for reference and fallback display.
			'nop_indieweb_photos'           => $parsed['photos'],

			// Full payload archived as JSON — useful for re-processing or debugging.
			'nop_indieweb_raw_payload'      => wp_json_encode( $parsed['raw_payload'] ),
		];
	}

	protected function after_insert( int $post_id, array $parsed ): void {
		$settings = $this->get_settings();

		if ( ! $parsed['photos'] ) {
			return;
		}

		$ids = [];
		if ( ! empty( $settings['sideload_photos'] ) ) {
			$ids = $this->sideload_photos( $parsed['photos'], $post_id );
			if ( $ids ) {
				update_post_meta( $post_id, 'nop_indieweb_photo_ids', $ids );
			}
		}

		// Inject real image/gallery blocks into post content so photos are
		// first-class WordPress content rather than meta-stored CDN references.
		$photo_blocks = $this->build_photo_blocks( $ids, $ids ? [] : $parsed['photos'] );
		if ( $photo_blocks ) {
			$post = get_post( $post_id );
			wp_update_post( [
				'ID'           => $post_id,
				'post_content' => rtrim( $post->post_content ) . "\n\n" . $photo_blocks,
			] );
		}
	}

	private function build_photo_blocks( array $ids, array $urls ): string {
		if ( $ids ) {
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

		if ( $urls ) {
			if ( 1 === count( $urls ) ) {
				return sprintf(
					"<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"%s\" alt=\"\"/></figure>\n<!-- /wp:image -->",
					esc_url( $urls[0] )
				);
			}
			$inner = '';
			foreach ( $urls as $url ) {
				$inner .= sprintf(
					"\n<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"%s\" alt=\"\"/></figure>\n<!-- /wp:image -->",
					esc_url( $url )
				);
			}
			return "<!-- wp:gallery {\"columns\":2,\"linkTo\":\"none\"} -->\n<figure class=\"wp-block-gallery has-nested-images columns-2 is-cropped\">{$inner}\n</figure>\n<!-- /wp:gallery -->";
		}

		return '';
	}

	public function get_post_format( array $parsed ): string {
		return $this->get_settings()['post_format'] ?? 'status';
	}
}
