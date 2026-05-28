<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Services;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
			// uid is preferred; fall back to extracting from the venue URL (OwnYourSwarm
			// sometimes omits uid while still providing the Foursquare venue URL).
			'venue_uid'         => sanitize_text_field(
				$checkin_props['uid'][0]
				?? \NOP\IndieWeb\Venue\Foursquare_Enricher::extract_venue_id( $checkin_props['url'][0] ?? '' )
			),

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
		$settings = $this->get_settings();

		// Drop timestamps more than 60 s in the future (timezone mismatch in test
		// payloads) — avoids WordPress scheduling the post as 'future'.
		[ $post_date, $post_date_gmt ] = $this->parse_post_date( $parsed['published'], true );

		$venue    = $parsed['venue_name'];
		$locality = $parsed['venue_locality'];
		$title    = $venue
			? ( $locality ? "{$venue}, {$locality}" : $venue )
			: 'Checked in';

		$category_ids = $this->category_ids_from_setting( $settings['post_category'] ?? '' );
		$tags         = $this->tags_from_setting( $settings['post_tags'] ?? 'Swarm' );

		$note   = trim( $parsed['content'] );
		$blocks = $note
			? "<!-- wp:paragraph -->\n<p>{$note}</p>\n<!-- /wp:paragraph -->"
			: '';
		// Photos are injected as real image blocks in after_insert() once sideloading completes.
		// Venue meta is surfaced via Block Bindings in the active template.

		$args = [
			'post_title'   => $title,
			'post_content' => $blocks,
			'post_status'  => $settings['post_status'] ?? 'publish',
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

	public function get_kind( array $parsed = [] ): string {
		return 'checkin';
	}

	public function get_meta( array $parsed ): array {
		return [
			'nop_indieweb_service'          => 'swarm',
			'nop_indieweb_platform'         => 'swarm',
			'nop_indieweb_source_url'       => $parsed['checkin_url'],

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
		$settings  = $this->get_settings();
		$venue_uid = (string) ( $parsed['venue_uid'] ?? '' );

		if ( $venue_uid ) {
			$post_date   = get_post_field( 'post_date', $post_id );
			$visit_number = \NOP\IndieWeb\nop_indieweb_compute_venue_visit_number(
				$venue_uid,
				(string) $post_date,
				$post_id
			);
			update_post_meta( $post_id, 'nop_indieweb_venue_visit_number', $visit_number );
		}

		$cats = array_filter( (array) ( $parsed['venue_categories'] ?? [] ) );
		if ( ! $cats ) {
			// OwnYourSwarm doesn't forward Foursquare's venue categories
			// (aaronpk/ownyourswarm#47), so fall back to a direct Places API
			// lookup when an API key is configured. Returns [] silently if
			// the key is unset, the venue isn't on Foursquare, or the API
			// is unreachable — we never want this to block the checkin.
			$venue_id = \NOP\IndieWeb\Venue\Foursquare_Enricher::extract_venue_id( $parsed['venue_url'] ?? '' );
			if ( $venue_id ) {
				$cats = \NOP\IndieWeb\Venue\Foursquare_Enricher::fetch_categories( $venue_id );
			}
		}
		if ( $cats ) {
			wp_set_object_terms( $post_id, $cats, \NOP\IndieWeb\Kind\Venue_Category_Taxonomy::TAXONOMY );
		}

		// Weather enrichment uses the checkin's real time (post_date_gmt), not
		// the import time — for live webhooks they're the same, for any future
		// retro-import they can differ by hours.
		$lat = (float) ( $parsed['venue_lat'] ?? 0 );
		$lng = (float) ( $parsed['venue_lng'] ?? 0 );
		if ( $lat || $lng ) {
			\NOP\IndieWeb\Weather\Weather_Fetcher::enrich_post(
				$post_id,
				$lat,
				$lng,
				(int) get_post_timestamp( $post_id, 'date_gmt' )
			);

			$geoapify_key = trim( (string) \NOP\IndieWeb\nop_indieweb_get_option( 'maps.geoapify_api_key', '' ) );
			if ( $geoapify_key ) {
				\NOP\IndieWeb\nop_indieweb_get_or_cache_map_image( $post_id, $lat, $lng, 620, 310, $geoapify_key );
			}
		}

		if ( ! $parsed['photos'] ) {
			return;
		}

		$ids = [];
		if ( ! empty( $settings['sideload_photos'] ) ) {
			$ids = $this->sideload_photos( $parsed['photos'], $post_id );
			if ( $ids ) {
				update_post_meta( $post_id, 'nop_indieweb_photo_ids', $ids );
				$this->set_photo_alt_text( $ids, $parsed );
			}
		}

		// Inject real image/gallery blocks into post content so photos are
		// first-class WordPress content rather than meta-stored CDN references.
		// When falling back to CDN URLs (sideloading disabled), pass a venue-
		// derived alt so the img tags have meaningful accessible text.
		$cdn_alt      = $this->derive_photo_alt( $parsed );
		$photo_blocks = $this->build_photo_blocks( $ids, $ids ? [] : $parsed['photos'], $cdn_alt );
		if ( $photo_blocks ) {
			$post = get_post( $post_id );
			wp_update_post( [
				'ID'           => $post_id,
				'post_content' => rtrim( $post->post_content ) . "\n\n" . $photo_blocks,
			] );
		}
	}

	private function derive_photo_alt( array $parsed ): string {
		$venue    = trim( $parsed['venue_name']     ?? '' );
		$locality = trim( $parsed['venue_locality'] ?? '' );
		$cats     = is_array( $parsed['venue_categories'] ?? null ) ? $parsed['venue_categories'] : [];
		$category = $cats ? $cats[0] : '';

		if ( $venue && $category && $locality ) {
			return sprintf( 'Photo taken at %s, a %s in %s', $venue, $category, $locality );
		}
		if ( $venue && $locality ) {
			return sprintf( 'Photo taken at %s, %s', $venue, $locality );
		}
		if ( $venue ) {
			return sprintf( 'Photo taken at %s', $venue );
		}
		return 'Photo from a checkin';
	}

	private function set_photo_alt_text( array $ids, array $parsed ): void {
		$alt = $this->derive_photo_alt( $parsed );
		foreach ( $ids as $attachment_id ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
			update_post_meta( $attachment_id, '_nop_alt_needs_review', '1' );
		}
	}

}
