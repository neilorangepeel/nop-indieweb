<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Post_Meta;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
			// Derived read-cache — do not write directly. Written only by the
			// nop_kind taxonomy mirror hook in Kind_Taxonomy::mirror_kind_to_meta().
			'nop_indieweb_post_kind'        => array_merge( $string, [
				'label'       => __( 'Post Kind', 'nop-indieweb' ),
				'description' => 'Read-cache of the nop_kind taxonomy term slug. Kept in sync by a mirror hook — do not write directly.',
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

			'nop_indieweb_venue_visit_number' => [
				'type'          => 'integer',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => fn() => current_user_can( 'edit_posts' ),
				'label'         => __( 'Venue Visit Number', 'nop-indieweb' ),
				'description'   => 'Ordinal visit number (1 = first visit to this venue).',
			],

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

			// ── Weather ──────────────────────────────────────────────────────────
			// Snapshotted at post-create time from the venue lat/lng + post date.
			// Populated by Weather_Fetcher for kinds where location is inherent
			// to the post (checkins now, workouts later). Strings throughout to
			// mirror the venue_lat/lng convention.
			'nop_indieweb_weather_temp_c'    => array_merge( $string, [
				'label'       => __( 'Weather Temperature (°C)', 'nop-indieweb' ),
				'description' => 'Temperature in Celsius at the time and place of the post.',
			] ),
			'nop_indieweb_weather_temp_f'    => array_merge( $string, [
				'label'       => __( 'Weather Temperature (°F)', 'nop-indieweb' ),
				'description' => 'Temperature in Fahrenheit at the time and place of the post.',
			] ),
			'nop_indieweb_weather_icon'      => array_merge( $string, [
				'label'       => __( 'Weather Icon', 'nop-indieweb' ),
				'description' => 'Provider icon slug (Pirate Weather vocabulary: clear-day, cloudy, rain, snow, sleet, wind, fog, partly-cloudy-day, partly-cloudy-night, clear-night).',
			] ),
			'nop_indieweb_weather_summary'   => array_merge( $string, [
				'label'       => __( 'Weather Summary', 'nop-indieweb' ),
				'description' => 'Human-readable conditions ("Partly Cloudy").',
			] ),
			'nop_indieweb_weather_provider'  => array_merge( $string, [
				'label'       => __( 'Weather Provider', 'nop-indieweb' ),
				'description' => 'Provenance slug (e.g. pirate-weather).',
			] ),
			'nop_indieweb_weather_fetched_at' => array_merge( $string, [
				'label'       => __( 'Weather Fetched At', 'nop-indieweb' ),
				'description' => 'ISO8601 timestamp of when the weather lookup ran.',
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
			'nop_indieweb_skip_syndication' => array_merge( $string, [
				'label'       => __( 'Skip Syndication', 'nop-indieweb' ),
				'description' => 'When "1", the syndication scheduler bails for this post. Set by services like Swarm for private checkins; can also be toggled manually.',
			] ),
			'nop_indieweb_syndication_status' => [
				'type'          => 'object',
				'single'        => true,
				'auth_callback' => fn() => current_user_can( 'edit_posts' ),
				'show_in_rest'  => [
					'schema' => [
						'type'                 => 'object',
						'additionalProperties' => [
							'type'       => 'object',
							'properties' => [
								'state'    => [ 'type' => 'string' ],
								'url'      => [ 'type' => 'string' ],
								'error'    => [ 'type' => 'string' ],
								'attempts' => [ 'type' => 'integer' ],
								'updated'  => [ 'type' => 'integer' ],
							],
						],
					],
				],
				'label'         => __( 'Syndication Status', 'nop-indieweb' ),
				'description'   => 'Per-platform delivery journal (pending/sent/failed), keyed by syndicator slug. Written by Syndication_Manager.',
			],

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
			'nop_indieweb_quote_of'    => array_merge( $string, [
				'label'       => __( 'Quote Of', 'nop-indieweb' ),
				'description' => 'URL this post is quoting (u-quotation-of).',
			] ),

			// ── RSVP event details (h-event) ─────────────────────────────────────────
			// The event being responded to. The event URL and the RSVP value reuse the
			// existing nop_indieweb_in_reply_to + nop_indieweb_rsvp fields above (so the
			// webmention sender and the response microformats keep working unchanged).
			// These fields carry the rest of the cited h-event, pre-filled by the
			// fetch-event endpoint but always editable in the RSVP sidebar panel.
			'nop_indieweb_rsvp_event_name'     => array_merge( $string, [
				'label'       => __( 'Event Name', 'nop-indieweb' ),
				'description' => 'Title of the event being responded to (h-event p-name).',
			] ),
			'nop_indieweb_rsvp_event_start'    => array_merge( $string, [
				'label'       => __( 'Event Start', 'nop-indieweb' ),
				'description' => 'Event start datetime, ISO8601 / local (h-event dt-start).',
			] ),
			'nop_indieweb_rsvp_event_end'      => array_merge( $string, [
				'label'       => __( 'Event End', 'nop-indieweb' ),
				'description' => 'Optional event end datetime, ISO8601 / local (h-event dt-end).',
			] ),
			'nop_indieweb_rsvp_event_location' => array_merge( $string, [
				'label'       => __( 'Event Location', 'nop-indieweb' ),
				'description' => 'Optional event location (h-event p-location).',
			] ),
			'nop_indieweb_rsvp_note'           => array_merge( $string, [
				'label'       => __( 'RSVP Note', 'nop-indieweb' ),
				'description' => 'Optional free-text note shown with the response (p-content / p-summary).',
			] ),

			// ── Cited target context (h-cite) ────────────────────────────────────────
			// Captured once at save time from the like/bookmark/repost/reply target so
			// the post carries real context instead of a bare link. See Cite_Extractor.
			'nop_indieweb_cite_title'        => array_merge( $string, [
				'label'       => __( 'Cite Title', 'nop-indieweb' ),
				'description' => 'Title of the cited target page (h-cite p-name).',
			] ),
			'nop_indieweb_cite_author_name'  => array_merge( $string, [
				'label'       => __( 'Cite Author', 'nop-indieweb' ),
				'description' => 'Author name of the cited target (h-cite p-author h-card p-name).',
			] ),
			'nop_indieweb_cite_author_url'   => array_merge( $string, [
				'label'       => __( 'Cite Author URL', 'nop-indieweb' ),
				'description' => 'Author URL of the cited target (h-card u-url).',
			] ),
			'nop_indieweb_cite_author_photo' => array_merge( $string, [
				'label'       => __( 'Cite Author Photo', 'nop-indieweb' ),
				'description' => 'Author photo of the cited target (h-card u-photo).',
			] ),
			'nop_indieweb_cite_excerpt'      => array_merge( $string, [
				'label'       => __( 'Cite Excerpt', 'nop-indieweb' ),
				'description' => 'Short excerpt/summary of the cited target (h-cite p-summary).',
			] ),
			'nop_indieweb_cite_image'        => array_merge( $string, [
				'label'       => __( 'Cite Image', 'nop-indieweb' ),
				'description' => 'Representative image URL of the cited target (h-cite u-photo).',
			] ),
			'nop_indieweb_cite_site_name'    => array_merge( $string, [
				'label'       => __( 'Cite Site Name', 'nop-indieweb' ),
				'description' => 'Site name of the cited target (h-cite p-publication / source host).',
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
			'nop_indieweb_film_tmdb_id' => array_merge( $string, [
				'label'       => __( 'TMDB ID', 'nop-indieweb' ),
				'description' => 'TMDB movie ID, set by the in-editor lookup picker.',
			] ),

			// ── Exercise / Activity ─────────────────────────────────────────────
			'nop_indieweb_exercise_type'            => array_merge( $string, [
				'label'       => __( 'Exercise Type', 'nop-indieweb' ),
				'description' => 'Activity type slug: run, ride, swim, walk, hike, strength, yoga, workout, …',
			] ),
			'nop_indieweb_exercise_distance_m'      => [
				'type'          => 'number',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => fn() => current_user_can( 'edit_posts' ),
				'label'         => __( 'Distance (m)', 'nop-indieweb' ),
				'description'   => 'Distance in metres.',
			],
			'nop_indieweb_exercise_duration_s'      => [
				'type'          => 'integer',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => fn() => current_user_can( 'edit_posts' ),
				'label'         => __( 'Duration (s)', 'nop-indieweb' ),
				'description'   => 'Elapsed time in seconds.',
			],
			'nop_indieweb_exercise_moving_time_s'   => [
				'type'          => 'integer',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => fn() => current_user_can( 'edit_posts' ),
				'label'         => __( 'Moving Time (s)', 'nop-indieweb' ),
				'description'   => 'Moving time in seconds (excludes stopped intervals).',
			],
			'nop_indieweb_exercise_elevation_gain_m' => [
				'type'          => 'number',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => fn() => current_user_can( 'edit_posts' ),
				'label'         => __( 'Elevation Gain (m)', 'nop-indieweb' ),
				'description'   => 'Total elevation gain in metres.',
			],
			'nop_indieweb_exercise_elevation_loss_m' => [
				'type'          => 'number',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => fn() => current_user_can( 'edit_posts' ),
				'label'         => __( 'Elevation Loss (m)', 'nop-indieweb' ),
				'description'   => 'Total elevation loss in metres.',
			],
			'nop_indieweb_exercise_avg_heart_rate'  => [
				'type'          => 'integer',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => fn() => current_user_can( 'edit_posts' ),
				'label'         => __( 'Avg Heart Rate (bpm)', 'nop-indieweb' ),
				'description'   => 'Average heart rate in beats per minute.',
			],
			'nop_indieweb_exercise_max_heart_rate'  => [
				'type'          => 'integer',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => fn() => current_user_can( 'edit_posts' ),
				'label'         => __( 'Max Heart Rate (bpm)', 'nop-indieweb' ),
				'description'   => 'Maximum heart rate in beats per minute.',
			],
			'nop_indieweb_exercise_calories'        => [
				'type'          => 'integer',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => fn() => current_user_can( 'edit_posts' ),
				'label'         => __( 'Calories (kcal)', 'nop-indieweb' ),
				'description'   => 'Active calories burned in kcal.',
			],
			'nop_indieweb_exercise_start_lat'       => array_merge( $string, [
				'label'       => __( 'Start Latitude', 'nop-indieweb' ),
				'description' => 'Latitude of workout start point.',
			] ),
			'nop_indieweb_exercise_start_lng'       => array_merge( $string, [
				'label'       => __( 'Start Longitude', 'nop-indieweb' ),
				'description' => 'Longitude of workout start point.',
			] ),
			'nop_indieweb_exercise_route'           => array_merge( $string, [
				'label'       => __( 'Route (polyline)', 'nop-indieweb' ),
				'description' => 'Google-encoded polyline for the GPS route. Populated in Phase 2 when a dedicated app source is available.',
			] ),
			'nop_indieweb_exercise_source_url'      => array_merge( $string, [
				'label'       => __( 'Exercise Source URL', 'nop-indieweb' ),
				'description' => 'Permalink on the originating platform (Strava, Garmin, etc.).',
			] ),
			'nop_indieweb_exercise_source_id'       => array_merge( $string, [
				'label'       => __( 'Exercise Source ID', 'nop-indieweb' ),
				'description' => 'Platform-native activity ID used for duplicate detection.',
			] ),
			'nop_indieweb_exercise_map_url'         => array_merge( $string, [
				'label'       => __( 'Exercise Map URL', 'nop-indieweb' ),
				'description' => 'Cached static map image URL for the workout route.',
			] ),
			'nop_indieweb_exercise_gpx_url'         => array_merge( $string, [
				'label'       => __( 'Exercise GPX URL', 'nop-indieweb' ),
				'description' => 'URL of the stored GPX track — the canonical own-your-data route artifact.',
			] ),
			'nop_indieweb_exercise_gear'            => array_merge( $string, [
				'label'       => __( 'Exercise Gear', 'nop-indieweb' ),
				'description' => 'Gear used (bike or shoes), when reported by the source.',
			] ),
			'nop_indieweb_exercise_max_speed_ms'    => [
				'type'          => 'number',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => fn() => current_user_can( 'edit_posts' ),
				'label'         => __( 'Max Speed (m/s)', 'nop-indieweb' ),
				'description'   => 'Maximum speed in metres per second.',
			],
			'nop_indieweb_exercise_max_grade'       => [
				'type'          => 'number',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => fn() => current_user_can( 'edit_posts' ),
				'label'         => __( 'Max Grade (%)', 'nop-indieweb' ),
				'description'   => 'Maximum gradient as a percentage.',
			],
			'nop_indieweb_exercise_elevation_high_m' => [
				'type'          => 'number',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => fn() => current_user_can( 'edit_posts' ),
				'label'         => __( 'Elevation High (m)', 'nop-indieweb' ),
				'description'   => 'Highest elevation in metres.',
			],
			'nop_indieweb_exercise_elevation_low_m' => [
				'type'          => 'number',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => fn() => current_user_can( 'edit_posts' ),
				'label'         => __( 'Elevation Low (m)', 'nop-indieweb' ),
				'description'   => 'Lowest elevation in metres.',
			],

			// ── Photos ───────────────────────────────────────────────────────────
			// Source URLs are always stored as a permanent record even when photos are sideloaded.
			'nop_indieweb_photos'           => array_merge( $array, [
				'label'       => __( 'Photos', 'nop-indieweb' ),
				'description' => 'Source photo URLs (Swarm CDN, Bluesky getBlob, etc.) preserved alongside any sideloaded attachments.',
			] ),
			// Attachment IDs are set after sideloading; absent if sideloading is disabled.
			'nop_indieweb_photo_ids'        => array_merge( $int_array, [
				'label'       => __( 'Photo IDs', 'nop-indieweb' ),
				'description' => 'WordPress attachment IDs for sideloaded photos.',
			] ),

			// ── Videos ───────────────────────────────────────────────────────────
			'nop_indieweb_videos'           => array_merge( $array, [
				'label'       => __( 'Videos', 'nop-indieweb' ),
				'description' => 'Source video URLs (Bluesky getBlob, etc.) preserved alongside any sideloaded attachments.',
			] ),
			'nop_indieweb_video_ids'        => array_merge( $int_array, [
				'label'       => __( 'Video IDs', 'nop-indieweb' ),
				'description' => 'WordPress attachment IDs for sideloaded videos.',
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
