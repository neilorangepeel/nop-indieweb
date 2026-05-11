<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Post_Meta;

/**
 * Registers all IndieWeb post meta fields.
 *
 * All fields use show_in_rest so they're available to the Block Editor,
 * REST API, and Block Bindings source. Exception: raw_payload (large, arbitrary).
 *
 * Naming convention: nop_indieweb_{field}
 */
class Registry {

	public function register(): void {
		add_action( 'init', [ $this, 'register_meta' ] );
	}

	public function register_meta(): void {
		foreach ( $this->get_field_definitions() as $key => $args ) {
			register_post_meta( 'post', $key, $args );
		}
	}

	private function get_field_definitions(): array {
		$string = [
			'type'          => 'string',
			'single'        => true,
			'show_in_rest'  => true,
			'auth_callback' => fn() => current_user_can( 'edit_posts' ),
		];

		$array = [
			'type'         => 'array',
			'single'       => true,
			'auth_callback' => fn() => current_user_can( 'edit_posts' ),
			'show_in_rest' => [
				'schema' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			],
		];

		$int_array = [
			'type'         => 'array',
			'single'       => true,
			'auth_callback' => fn() => current_user_can( 'edit_posts' ),
			'show_in_rest' => [
				'schema' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
			],
		];

		return [
			// ── Post kind ────────────────────────────────────────────────────────
			'nop_indieweb_post_kind'        => array_merge( $string, [
				'label'       => __( 'Post Kind', 'nop-indieweb' ),
				'description' => 'Explicit post kind (e.g. checkin, workout, note). Drives template selection.',
			] ),

			// ── Service provenance ───────────────────────────────────────────────
			'nop_indieweb_service'          => array_merge( $string, [
				'label'       => __( 'Service', 'nop-indieweb' ),
				'description' => 'Source service slug (e.g. swarm, entries).',
			] ),
			'nop_indieweb_platform'         => array_merge( $string, [
				'label'       => __( 'Platform', 'nop-indieweb' ),
				'description' => 'Source social platform slug (mastodon, bluesky, entries).',
			] ),

			// ── Venue identity ───────────────────────────────────────────────────
			'nop_indieweb_venue_name'       => array_merge( $string, [
				'label'       => __( 'Venue Name', 'nop-indieweb' ),
				'description' => 'Venue name.',
			] ),
			'nop_indieweb_venue_url'        => array_merge( $string, [
				'label'       => __( 'Venue URL', 'nop-indieweb' ),
				'description' => 'Venue URL (Foursquare).',
			] ),
			'nop_indieweb_venue_uid'        => array_merge( $string, [
				'label'       => __( 'Venue ID', 'nop-indieweb' ),
				'description' => 'Foursquare venue ID.',
			] ),

			// ── Coordinates ──────────────────────────────────────────────────────
			'nop_indieweb_venue_lat'        => array_merge( $string, [
				'label'       => __( 'Latitude', 'nop-indieweb' ),
				'description' => 'Latitude.',
			] ),
			'nop_indieweb_venue_lng'        => array_merge( $string, [
				'label'       => __( 'Longitude', 'nop-indieweb' ),
				'description' => 'Longitude.',
			] ),
			'nop_indieweb_venue_altitude'   => array_merge( $string, [
				'label'       => __( 'Altitude', 'nop-indieweb' ),
				'description' => 'Altitude in metres.',
			] ),
			'nop_indieweb_venue_accuracy'   => array_merge( $string, [
				'label'       => __( 'GPS Accuracy', 'nop-indieweb' ),
				'description' => 'GPS accuracy in metres.',
			] ),

			// ── Full address ─────────────────────────────────────────────────────
			'nop_indieweb_venue_address'    => array_merge( $string, [
				'label'       => __( 'Street Address', 'nop-indieweb' ),
				'description' => 'Street address.',
			] ),
			'nop_indieweb_venue_locality'   => array_merge( $string, [
				'label'       => __( 'City / Town', 'nop-indieweb' ),
				'description' => 'City or town.',
			] ),
			'nop_indieweb_venue_region'     => array_merge( $string, [
				'label'       => __( 'Region', 'nop-indieweb' ),
				'description' => 'County, state, or region.',
			] ),
			'nop_indieweb_venue_country'    => array_merge( $string, [
				'label'       => __( 'Country', 'nop-indieweb' ),
				'description' => 'Country name.',
			] ),
			'nop_indieweb_venue_postcode'   => array_merge( $string, [
				'label'       => __( 'Postcode', 'nop-indieweb' ),
				'description' => 'Postal code.',
			] ),

			// ── Venue taxonomy ───────────────────────────────────────────────────
			'nop_indieweb_venue_categories' => array_merge( $array, [
				'label'       => __( 'Venue Categories', 'nop-indieweb' ),
				'description' => 'Venue categories from Foursquare (e.g. ["Bar","Pub"]).',
			] ),

			// ── Syndication ──────────────────────────────────────────────────────
			// Stored separately so we can query it directly without deserializing the array.
			'nop_indieweb_source_url'       => array_merge( $string, [
				'label'       => __( 'Source URL', 'nop-indieweb' ),
				'description' => 'Canonical URL on the originating platform (used for duplicate detection on inbound notes).',
			] ),
			'nop_indieweb_checkin_url'      => array_merge( $string, [
				'label'       => __( 'Checkin URL', 'nop-indieweb' ),
				'description' => 'Swarm checkin permalink (unique per checkin, used for duplicate detection).',
			] ),
			'nop_indieweb_syndication'      => array_merge( $array, [
				'label'       => __( 'Syndication URLs', 'nop-indieweb' ),
				'description' => 'All syndication URLs for this post.',
			] ),
			'nop_indieweb_syndicate_to'     => array_merge( $array, [
				'label'       => __( 'Syndicate To', 'nop-indieweb' ),
				'description' => 'Platform slugs to syndicate to on publish (editor selection).',
			] ),

			// ── Post kinds ──────────────────────────────────────────────────────────
			'nop_indieweb_bookmark_of' => array_merge( $string, [
				'label'       => __( 'Bookmark Of', 'nop-indieweb' ),
				'description' => 'URL this post is bookmarking (u-bookmark-of).',
			] ),
			'nop_indieweb_in_reply_to' => array_merge( $string, [
				'label'       => __( 'In Reply To', 'nop-indieweb' ),
				'description' => 'URL this post is replying to (u-in-reply-to).',
			] ),
			'nop_indieweb_like_of'     => array_merge( $string, [
				'label'       => __( 'Like Of', 'nop-indieweb' ),
				'description' => 'URL this post is liking (u-like-of).',
			] ),
			'nop_indieweb_repost_of'   => array_merge( $string, [
				'label'       => __( 'Repost Of', 'nop-indieweb' ),
				'description' => 'URL this post is reposting (u-repost-of).',
			] ),
			'nop_indieweb_rsvp'        => array_merge( $string, [
				'label'       => __( 'RSVP', 'nop-indieweb' ),
				'description' => 'RSVP value: yes, no, maybe, or interested (p-rsvp).',
			] ),

			// ── Film / watch ─────────────────────────────────────────────────────
			'nop_indieweb_film_title'   => array_merge( $string, [
				'label'       => __( 'Film Title', 'nop-indieweb' ),
				'description' => 'Title of the watched film.',
			] ),
			'nop_indieweb_film_year'    => array_merge( $string, [
				'label'       => __( 'Film Year', 'nop-indieweb' ),
				'description' => 'Release year of the film.',
			] ),
			'nop_indieweb_film_rating'  => array_merge( $string, [
				'label'       => __( 'Film Rating', 'nop-indieweb' ),
				'description' => 'Letterboxd member rating (0.5–5.0).',
			] ),
			'nop_indieweb_watch_date'   => array_merge( $string, [
				'label'       => __( 'Watch Date', 'nop-indieweb' ),
				'description' => 'ISO date the film was watched.',
			] ),
			'nop_indieweb_film_poster'  => array_merge( $string, [
				'label'       => __( 'Film Poster URL', 'nop-indieweb' ),
				'description' => 'Remote poster image URL from Letterboxd.',
			] ),
			'nop_indieweb_film_rewatch' => array_merge( $string, [
				'label'       => __( 'Rewatch', 'nop-indieweb' ),
				'description' => '1 if this is a rewatch, 0 if first viewing.',
			] ),

			// ── Photos ───────────────────────────────────────────────────────────
			// CDN URLs are always stored as a permanent record even when photos are sideloaded.
			'nop_indieweb_photos'           => array_merge( $array, [
				'label'       => __( 'Photos', 'nop-indieweb' ),
				'description' => 'Original CDN photo URLs from Swarm.',
			] ),
			// Attachment IDs are set after sideloading; absent if sideloading is disabled.
			'nop_indieweb_photo_ids'        => array_merge( $int_array, [
				'label'       => __( 'Photo IDs', 'nop-indieweb' ),
				'description' => 'WordPress attachment IDs for sideloaded photos.',
			] ),

			// ── Raw payload ──────────────────────────────────────────────────────
			// Not in REST — can be large and contain arbitrary service data.
			'nop_indieweb_raw_payload'      => [
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => fn() => current_user_can( 'edit_posts' ),
				'description'   => 'Full Micropub payload as JSON — archived for debugging and re-processing.',
			],
		];
	}
}
