<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Admin;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use NOP\IndieWeb\IndieAuth\Auth_Endpoint;
use NOP\IndieWeb\IndieAuth\Token_Store;

/**
 * REST API endpoints for the settings React app.
 *
 * GET/POST /nop-indieweb/v1/settings          — read and write plugin settings
 * GET      /nop-indieweb/v1/settings/sessions  — list active IndieAuth tokens
 * DELETE   /nop-indieweb/v1/settings/sessions/{id} — revoke a token
 * POST     /nop-indieweb/v1/test-connection    — test a syndicator connection
 *
 * Secret credential fields are never returned in plaintext. The sentinel
 * string '__redacted__' is returned for set values; the empty string for unset.
 * On POST, the sentinel is skipped (existing value preserved); empty string clears.
 */
class Settings_API {

	private const OPTION_KEY = 'nop_indieweb_settings';
	private const SENTINEL   = '__redacted__';

	private const SECRET_PATHS = [
		[ 'syndicators', 'mastodon',  'access_token'       ],
		[ 'syndicators', 'bluesky',   'app_password'        ],
		[ 'syndicators', 'pixelfed',  'access_token'       ],
		[ 'syndicators', 'tumblr',    'consumer_secret'    ],
		[ 'syndicators', 'tumblr',    'access_token'       ],
		[ 'syndicators', 'tumblr',    'refresh_token'      ],
		[ 'maps',        null,        'geoapify_api_key'    ],
		[ 'weather',     null,        'pirate_weather_api_key' ],
		[ 'venue',       null,        'foursquare_api_key'  ],
		[ 'lookups',     null,        'tmdb_api_key'        ],
	];

	private const VALID_STATUS   = [ 'publish', 'draft', 'private' ];
	private const VALID_APPROVAL = [ 'bridgy_only', 'auto_all', 'manual_all' ];

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( 'nop-indieweb/v1', '/settings', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_settings' ],
				'permission_callback' => [ $this, 'check_permission' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'update_settings' ],
				'permission_callback' => [ $this, 'check_permission' ],
			],
		] );

		register_rest_route( 'nop-indieweb/v1', '/settings/sessions', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_sessions' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );

		register_rest_route( 'nop-indieweb/v1', '/settings/sessions/(?P<id>[\d]+)', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'revoke_session' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'id' => [
					'required'          => true,
					'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
					'sanitize_callback' => 'absint',
				],
			],
		] );

		register_rest_route( 'nop-indieweb/v1', '/test-connection', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'test_connection' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'platform' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				],
			],
		] );

		// Health-check status (the stored snapshot from the daily WP-Cron run) +
		// an on-demand re-run that hits every provider live, then refreshes the
		// stored snapshot. Same code path the cron and the wp-cli command use.
		register_rest_route( 'nop-indieweb/v1', '/settings/health', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_health_status' ],
				'permission_callback' => [ $this, 'check_permission' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'run_health_check' ],
				'permission_callback' => [ $this, 'check_permission' ],
			],
		] );
	}

	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	// ——— GET /settings ————————————————————————————————————————————————————————

	public function get_settings(): \WP_REST_Response {
		$raw = get_option( self::OPTION_KEY, [] );

		$data = [
			'me_urls'             => (string) ( $raw['me_urls'] ?? '' ),
			'mf2_enabled'         => (bool)   ( $raw['mf2_enabled'] ?? true ),
			'block_ai_training'   => (bool)   ( $raw['block_ai_training'] ?? false ),
			'debug_mode'          => (bool)   ( $raw['debug_mode'] ?? false ),
			'twitter_archive_url' => (string) ( $raw['twitter_archive_url'] ?? '' ),
			'syndicators'         => $this->read_syndicators( $raw ),
			'services'            => $this->read_services( $raw ),
			'webmentions'         => [
				'receive_enabled' => (bool)   ( $raw['webmentions']['receive_enabled'] ?? true ),
				'approval'        => (string) ( $raw['webmentions']['approval']        ?? 'bridgy_only' ),
				'hub_url'         => (string) ( $raw['webmentions']['hub_url']         ?? '' ),
			],
			'maps'    => [ 'geoapify_api_key'      => $this->redact( $raw['maps']['geoapify_api_key'] ?? '' ) ],
			'weather' => [ 'pirate_weather_api_key' => $this->redact( $raw['weather']['pirate_weather_api_key'] ?? '' ) ],
			'venue'   => [ 'foursquare_api_key'     => $this->redact( $raw['venue']['foursquare_api_key'] ?? '' ) ],
			'lookups' => [ 'tmdb_api_key'            => $this->redact( $raw['lookups']['tmdb_api_key'] ?? '' ) ],
			'_meta'   => $this->build_meta(),
		];

		return new \WP_REST_Response( $data, 200 );
	}

	private function read_syndicators( array $raw ): array {
		$out = [];
		foreach ( [ 'mastodon', 'bluesky', 'pixelfed', 'tumblr' ] as $slug ) {
			$s = $raw['syndicators'][ $slug ] ?? [];
			$out[ $slug ] = [
				'enabled'        => (bool)   ( $s['enabled']        ?? false ),
				'import_enabled' => (bool)   ( $s['import_enabled'] ?? false ),
				'import_last_at' => (string) ( $s['import_last_at'] ?? '' ),
				'post_status'    => (string) ( $s['post_status']    ?? 'publish' ),
				'post_category'  => (string) ( $s['post_category']  ?? '' ),
				'post_tags'      => (string) ( $s['post_tags']      ?? '' ),
				'sideload_photos' => (bool)  ( $s['sideload_photos'] ?? false ),
			];
			if ( 'mastodon' === $slug || 'pixelfed' === $slug ) {
				$out[ $slug ]['instance']      = (string) ( $s['instance'] ?? '' );
				$out[ $slug ]['access_token']  = $this->redact( $s['access_token'] ?? '' );
			}
			if ( 'bluesky' === $slug ) {
				$out[ $slug ]['handle']       = (string) ( $s['handle'] ?? '' );
				$out[ $slug ]['app_password'] = $this->redact( $s['app_password'] ?? '' );
			}
			if ( 'tumblr' === $slug ) {
				// App credentials are form-editable; the tokens are written only by
				// the OAuth callback, so surface a connection flag, not the tokens.
				$out[ $slug ]['consumer_key']     = (string) ( $s['consumer_key'] ?? '' );
				$out[ $slug ]['consumer_secret']  = $this->redact( $s['consumer_secret'] ?? '' );
				$out[ $slug ]['blog_identifier']  = (string) ( $s['blog_identifier'] ?? '' );
				$out[ $slug ]['connected']        = '' !== (string) ( $s['refresh_token'] ?? '' );
				$out[ $slug ]['user_name']        = (string) ( $s['user_name'] ?? '' );
			}
		}
		return $out;
	}

	private function read_services( array $raw ): array {
		$kinds = [ 'bookmark', 'reply', 'like', 'repost', 'quote', 'rsvp' ];
		$out   = [];

		$e = $raw['services']['entries'] ?? [];
		$out['entries'] = [
			'enabled'         => (bool)   ( $e['enabled']         ?? true ),
			'post_status'     => (string) ( $e['post_status']     ?? 'publish' ),
			'post_category'   => (string) ( $e['post_category']   ?? '' ),
			'post_tags'       => (string) ( $e['post_tags']       ?? '' ),
			'sideload_photos' => (bool)   ( $e['sideload_photos'] ?? true ),
		];

		$sw = $raw['services']['swarm'] ?? [];
		$out['swarm'] = [
			'enabled'         => (bool)   ( $sw['enabled']         ?? false ),
			'post_status'     => (string) ( $sw['post_status']     ?? 'publish' ),
			'post_category'   => (string) ( $sw['post_category']   ?? '' ),
			'post_tags'       => (string) ( $sw['post_tags']       ?? '' ),
			'sideload_photos' => (bool)   ( $sw['sideload_photos'] ?? true ),
		];

		$lb = $raw['services']['letterboxd'] ?? [];
		$out['letterboxd'] = [
			'import_enabled'  => (bool)   ( $lb['import_enabled']  ?? false ),
			'username'        => (string) ( $lb['username']        ?? '' ),
			'post_status'     => (string) ( $lb['post_status']     ?? 'publish' ),
			'post_category'   => (string) ( $lb['post_category']   ?? '' ),
			'post_tags'       => (string) ( $lb['post_tags']       ?? '' ),
			'sideload_poster' => (bool)   ( $lb['sideload_poster'] ?? true ),
		];

		foreach ( $kinds as $kind ) {
			$k = $raw['services'][ $kind ] ?? [];
			$out[ $kind ] = [
				'enabled'       => (bool)   ( $k['enabled']       ?? true ),
				'post_status'   => (string) ( $k['post_status']   ?? 'publish' ),
				'post_category' => (string) ( $k['post_category'] ?? '' ),
			];
		}

		return $out;
	}

	private function build_meta(): array {
		$example_post = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- low-frequency admin-only call
			'meta_key'       => 'nop_indieweb_service',
		] );

		return [
			'endpoints'         => [
				'micropub'      => \NOP\IndieWeb\nop_indieweb_endpoint_url(),
				'webmention'    => rest_url( 'nop-indieweb/v1/webmention' ),
				'authorization' => Auth_Endpoint::url(),
				'token'         => rest_url( 'nop-indieweb/v1/token' ),
				'mf2'           => $example_post
					? rest_url( 'nop-indieweb/v1/mf2/' . $example_post[0] )
					: rest_url( 'nop-indieweb/v1/mf2/{post_id}' ),
			],
			'swarm_micropub_url' => \NOP\IndieWeb\nop_indieweb_endpoint_url(),
			'post_page_url'      => home_url( '/post' ),
			'network_status'     => $this->build_network_status(),
			'reaction_stats'     => $this->build_reaction_stats(),
		];
	}

	private function build_network_status(): array {
		$syndicators = \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators', [] );
		$services    = \NOP\IndieWeb\nop_indieweb_get_option( 'services', [] );

		$mastodon = $syndicators['mastodon'] ?? [];
		$bluesky  = $syndicators['bluesky']  ?? [];
		$pixelfed = $syndicators['pixelfed'] ?? [];
		$tumblr   = $syndicators['tumblr']   ?? [];
		$lboxd    = $services['letterboxd']  ?? [];
		$swarm    = $services['swarm']       ?? [];

		$mastodon_ok = ! empty( $mastodon['enabled'] ) && ! empty( $mastodon['instance'] ) && ! empty( $mastodon['access_token'] );
		$bluesky_ok  = ! empty( $bluesky['enabled'] ) && ! empty( $bluesky['handle'] ) && ! empty( $bluesky['app_password'] );
		$pixelfed_ok = ! empty( $pixelfed['enabled'] ) && ! empty( $pixelfed['instance'] ) && ! empty( $pixelfed['access_token'] );
		$tumblr_ok   = ! empty( $tumblr['enabled'] ) && ! empty( $tumblr['refresh_token'] ) && ! empty( $tumblr['blog_identifier'] );
		$lboxd_ok    = ! empty( $lboxd['import_enabled'] ) && ! empty( $lboxd['username'] );
		$swarm_ok    = ! empty( $swarm['enabled'] );

		$swarm_last_at = null;
		if ( $swarm_ok ) {
			$last = get_posts( [
				'post_type'      => 'post',
				'post_status'    => [ 'publish', 'draft', 'private' ],
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- low-frequency admin overview metric
				'meta_query'     => [ [ 'key' => 'nop_indieweb_service', 'value' => 'swarm' ] ],
			] );
			if ( $last ) {
				$swarm_last_at = get_post_field( 'post_date_gmt', $last[0] );
			}
		}

		return [
			'mastodon'   => [ 'active' => $mastodon_ok, 'color' => '#6364FF', 'last_label' => $mastodon_ok ? $this->human_time_diff( $mastodon['import_last_at'] ?? null, __( 'Synced', 'nop-indieweb' ) ) : null ],
			'bluesky'    => [ 'active' => $bluesky_ok,  'color' => '#0085FF', 'last_label' => $bluesky_ok  ? $this->human_time_diff( $bluesky['import_last_at']  ?? null, __( 'Synced', 'nop-indieweb' ) ) : null ],
			'pixelfed'   => [ 'active' => $pixelfed_ok, 'color' => '#1A9C5B', 'last_label' => $pixelfed_ok ? $this->human_time_diff( $pixelfed['import_last_at'] ?? null, __( 'Synced', 'nop-indieweb' ) ) : null ],
			'tumblr'     => [ 'active' => $tumblr_ok,   'color' => '#36465D', 'last_label' => $tumblr_ok   ? $this->human_time_diff( $tumblr['import_last_at']   ?? null, __( 'Synced', 'nop-indieweb' ) ) : null, 'authUrl' => rest_url( 'nop-indieweb/v1/tumblr-auth' ), 'connected' => ! empty( $tumblr['refresh_token'] ), 'userName' => (string) ( $tumblr['user_name'] ?? '' ) ],
			'letterboxd' => [ 'active' => $lboxd_ok,    'color' => '#00C030', 'last_label' => $lboxd_ok    ? $this->human_time_diff( $lboxd['import_last_at']    ?? null, __( 'Synced', 'nop-indieweb' ) ) : null ],
			'swarm'      => [ 'active' => $swarm_ok, 'color' => '#FC8D1D', 'last_label' => $swarm_ok ? $this->human_time_diff( $swarm_last_at, __( 'Last check-in', 'nop-indieweb' ) ) : null, 'micropubUrl' => \NOP\IndieWeb\nop_indieweb_endpoint_url() ],
		];
	}

	private function build_reaction_stats(): array {
		$count_wm = static function ( string $type, ?string $platform = null ): int {
			$meta_query = [ [ 'key' => 'webmention_type', 'value' => $type ] ];
			if ( null !== $platform ) {
				$meta_query[] = [ 'key' => 'webmention_platform', 'value' => $platform ];
			}
			return (int) get_comments( [
				'type'       => 'webmention',
				'status'     => 'approve',
				'count'      => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- low-frequency admin overview metric
				'meta_query' => $meta_query,
			] );
		};

		$native_comments = (int) get_comments( [ 'type' => 'comment', 'status' => 'approve', 'count' => true ] );
		$wm_replies      = (int) get_comments( [
			'type'       => 'webmention',
			'status'     => 'approve',
			'count'      => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- low-frequency admin overview metric
			'meta_query' => [
				'relation' => 'OR',
				[ 'key' => 'webmention_type', 'compare' => 'NOT EXISTS' ],
				[ 'key' => 'webmention_type', 'value' => [ 'like', 'repost' ], 'compare' => 'NOT IN' ],
			],
		] );

		$networks = [];
		foreach ( [ 'mastodon', 'bluesky' ] as $slug ) {
			$networks[ $slug ] = [
				'likes'   => $count_wm( 'like', $slug ),
				'reposts' => $count_wm( 'repost', $slug ),
			];
		}

		return [
			'likes'    => $count_wm( 'like' ),
			'comments' => $native_comments + $wm_replies,
			'reposts'  => $count_wm( 'repost' ),
			'pending'  => (int) get_comments( [ 'type' => 'webmention', 'status' => 'hold', 'count' => true ] ),
			'networks' => $networks,
		];
	}

	private function human_time_diff( ?string $iso_date, string $prefix = '' ): ?string {
		if ( ! $iso_date ) {
			return null;
		}
		$ts = strtotime( $iso_date );
		if ( ! $ts ) {
			return null;
		}
		if ( $prefix ) {
			/* translators: 1: label e.g. "Synced", 2: human time difference e.g. "3 hours" */
			return sprintf( __( '%1$s %2$s ago', 'nop-indieweb' ), $prefix, human_time_diff( $ts ) );
		}
		/* translators: %s: human time difference e.g. "3 hours" */
		return sprintf( __( '%s ago', 'nop-indieweb' ), human_time_diff( $ts ) );
	}

	// ——— POST /settings ———————————————————————————————————————————————————————

	public function update_settings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'invalid_body', __( 'Request body must be a JSON object.', 'nop-indieweb' ), [ 'status' => 400 ] );
		}

		$raw   = get_option( self::OPTION_KEY, [] );
		$clean = $this->sanitize_input( $body, $raw );

		update_option( self::OPTION_KEY, $clean, false );

		return new \WP_REST_Response( [ 'saved' => true ], 200 );
	}

	private function sanitize_input( array $input, array $existing ): array {
		$clean = $existing;

		// Ensure legacy token is gone.
		unset( $clean['secret_token'] );

		// — General ——————————————————————————————————————————————————————————
		if ( isset( $input['debug_mode'] ) ) {
			$clean['debug_mode'] = (bool) $input['debug_mode'];
		}
		if ( isset( $input['me_urls'] ) ) {
			$clean['me_urls'] = sanitize_textarea_field( (string) $input['me_urls'] );
		}
		if ( isset( $input['mf2_enabled'] ) ) {
			$clean['mf2_enabled'] = (bool) $input['mf2_enabled'];
		}
		if ( isset( $input['block_ai_training'] ) ) {
			$clean['block_ai_training'] = (bool) $input['block_ai_training'];
		}
		if ( isset( $input['twitter_archive_url'] ) ) {
			$clean['twitter_archive_url'] = esc_url_raw( (string) $input['twitter_archive_url'] );
		}

		// — Enrichment API keys (skip sentinel) ———————————————————————————————
		$this->maybe_set_secret( $clean, $input, 'maps',    null,   'geoapify_api_key' );
		$this->maybe_set_secret( $clean, $input, 'weather', null,   'pirate_weather_api_key' );
		$this->maybe_set_secret( $clean, $input, 'venue',   null,   'foursquare_api_key' );
		$this->maybe_set_secret( $clean, $input, 'lookups', null,   'tmdb_api_key' );

		// — Syndicators ———————————————————————————————————————————————————————
		foreach ( [ 'mastodon', 'bluesky', 'pixelfed', 'tumblr' ] as $slug ) {
			$in = $input['syndicators'][ $slug ] ?? null;
			if ( ! is_array( $in ) ) {
				continue;
			}
			$existing_slug = $clean['syndicators'][ $slug ] ?? [];

			if ( isset( $in['enabled'] ) )         $existing_slug['enabled']         = (bool) $in['enabled'];
			if ( isset( $in['import_enabled'] ) )  $existing_slug['import_enabled']  = (bool) $in['import_enabled'];
			if ( isset( $in['post_status'] ) )     $existing_slug['post_status']     = $this->sanitize_status( (string) $in['post_status'] );
			if ( isset( $in['post_category'] ) )   $existing_slug['post_category']   = sanitize_text_field( (string) $in['post_category'] );
			if ( isset( $in['post_tags'] ) )       $existing_slug['post_tags']       = sanitize_text_field( (string) $in['post_tags'] );
			if ( isset( $in['sideload_photos'] ) ) $existing_slug['sideload_photos'] = (bool) $in['sideload_photos'];

			if ( 'mastodon' === $slug || 'pixelfed' === $slug ) {
				if ( isset( $in['instance'] ) ) {
					$existing_slug['instance'] = esc_url_raw( (string) $in['instance'] );
				}
				$this->maybe_set_secret( $existing_slug, $in, null, null, 'access_token' );
			}
			if ( 'bluesky' === $slug ) {
				if ( isset( $in['handle'] ) ) {
					$existing_slug['handle'] = sanitize_text_field( (string) $in['handle'] );
				}
				$this->maybe_set_secret( $existing_slug, $in, null, null, 'app_password' );
			}
			if ( 'tumblr' === $slug ) {
				// App credentials only; the OAuth callback writes the tokens.
				if ( isset( $in['consumer_key'] ) ) {
					$existing_slug['consumer_key'] = sanitize_text_field( (string) $in['consumer_key'] );
				}
				if ( isset( $in['blog_identifier'] ) ) {
					$existing_slug['blog_identifier'] = sanitize_text_field( (string) $in['blog_identifier'] );
				}
				$this->maybe_set_secret( $existing_slug, $in, null, null, 'consumer_secret' );
			}

			$clean['syndicators'][ $slug ] = $existing_slug;
		}

		// — Entries / Notes ————————————————————————————————————————————————————
		$in = $input['services']['entries'] ?? null;
		if ( is_array( $in ) ) {
			$e = $clean['services']['entries'] ?? [];
			if ( isset( $in['enabled'] ) )         $e['enabled']         = (bool) $in['enabled'];
			if ( isset( $in['post_status'] ) )     $e['post_status']     = $this->sanitize_status( (string) $in['post_status'] );
			if ( isset( $in['post_category'] ) )   $e['post_category']   = sanitize_text_field( (string) $in['post_category'] );
			if ( isset( $in['post_tags'] ) )       $e['post_tags']       = sanitize_text_field( (string) $in['post_tags'] );
			if ( isset( $in['sideload_photos'] ) ) $e['sideload_photos'] = (bool) $in['sideload_photos'];
			$clean['services']['entries'] = $e;
		}

		// — Swarm ——————————————————————————————————————————————————————————————
		$in = $input['services']['swarm'] ?? null;
		if ( is_array( $in ) ) {
			$sw = $clean['services']['swarm'] ?? [];
			if ( isset( $in['enabled'] ) )         $sw['enabled']         = (bool) $in['enabled'];
			if ( isset( $in['post_status'] ) )     $sw['post_status']     = $this->sanitize_status( (string) $in['post_status'] );
			if ( isset( $in['post_category'] ) )   $sw['post_category']   = sanitize_text_field( (string) $in['post_category'] );
			if ( isset( $in['post_tags'] ) )       $sw['post_tags']       = sanitize_text_field( (string) $in['post_tags'] );
			if ( isset( $in['sideload_photos'] ) ) $sw['sideload_photos'] = (bool) $in['sideload_photos'];
			$clean['services']['swarm'] = $sw;
		}

		// — Letterboxd ————————————————————————————————————————————————————————
		$in = $input['services']['letterboxd'] ?? null;
		if ( is_array( $in ) ) {
			$lb = $clean['services']['letterboxd'] ?? [];
			if ( isset( $in['import_enabled'] ) )  $lb['import_enabled']  = (bool) $in['import_enabled'];
			if ( isset( $in['username'] ) )         $lb['username']        = sanitize_text_field( (string) $in['username'] );
			if ( isset( $in['post_status'] ) )      $lb['post_status']     = $this->sanitize_status( (string) $in['post_status'] );
			if ( isset( $in['post_category'] ) )    $lb['post_category']   = sanitize_text_field( (string) $in['post_category'] );
			if ( isset( $in['post_tags'] ) )        $lb['post_tags']       = sanitize_text_field( (string) $in['post_tags'] );
			if ( isset( $in['sideload_poster'] ) )  $lb['sideload_poster'] = (bool) $in['sideload_poster'];
			$clean['services']['letterboxd'] = $lb;
		}

		// — Interaction kinds ——————————————————————————————————————————————————
		foreach ( [ 'bookmark', 'reply', 'like', 'repost', 'quote', 'rsvp' ] as $kind ) {
			$in = $input['services'][ $kind ] ?? null;
			if ( ! is_array( $in ) ) {
				continue;
			}
			$k = $clean['services'][ $kind ] ?? [];
			if ( isset( $in['enabled'] ) )       $k['enabled']       = (bool) $in['enabled'];
			if ( isset( $in['post_status'] ) )   $k['post_status']   = $this->sanitize_status( (string) $in['post_status'] );
			if ( isset( $in['post_category'] ) ) $k['post_category'] = sanitize_text_field( (string) $in['post_category'] );
			$clean['services'][ $kind ] = $k;
		}

		// — Webmentions ————————————————————————————————————————————————————————
		$in = $input['webmentions'] ?? null;
		if ( is_array( $in ) ) {
			if ( isset( $in['receive_enabled'] ) ) {
				$clean['webmentions']['receive_enabled'] = (bool) $in['receive_enabled'];
			}
			if ( isset( $in['approval'] ) && in_array( $in['approval'], self::VALID_APPROVAL, true ) ) {
				$clean['webmentions']['approval'] = $in['approval'];
			}
			if ( isset( $in['hub_url'] ) ) {
				$clean['webmentions']['hub_url'] = esc_url_raw( (string) $in['hub_url'] );
			}
		}

		return $clean;
	}

	/**
	 * Writes a field value into $target unless it equals the sentinel.
	 * When $group2 is null, the path is $target[$key] directly.
	 * When $group1 is null, the path is $target[$key] directly (flat target).
	 */
	private function maybe_set_secret( array &$target, array $source, ?string $group1, ?string $group2, string $key ): void {
		$value = null;
		if ( $group1 === null ) {
			$value = $source[ $key ] ?? null;
		} elseif ( $group2 === null ) {
			$value = $source[ $group1 ][ $key ] ?? null;
		} else {
			$value = $source[ $group1 ][ $group2 ][ $key ] ?? null;
		}

		if ( $value === null ) {
			return;
		}

		$sanitized = sanitize_text_field( (string) $value );

		if ( $sanitized === self::SENTINEL ) {
			return;
		}

		if ( $group1 === null ) {
			$target[ $key ] = $sanitized;
		} elseif ( $group2 === null ) {
			$target[ $group1 ][ $key ] = $sanitized;
		} else {
			$target[ $group1 ][ $group2 ][ $key ] = $sanitized;
		}
	}

	private function sanitize_status( string $value ): string {
		return in_array( $value, self::VALID_STATUS, true ) ? $value : 'publish';
	}

	private function redact( string $value ): string {
		return '' !== $value ? self::SENTINEL : '';
	}

	// ——— GET /settings/sessions ——————————————————————————————————————————————

	public function get_sessions(): \WP_REST_Response {
		$sessions = Token_Store::get_by_user( get_current_user_id() );

		$data = array_map( static function ( array $s ): array {
			return [
				'id'          => (int) $s['id'],
				'client_name' => (string) ( $s['client_name'] ?: $s['client_id'] ),
				'client_id'   => (string) $s['client_id'],
				'scope'       => (string) $s['scope'],
				'issued_at'   => (string) $s['issued_at'],
				'last_used_at' => (string) ( $s['last_used_at'] ?? '' ),
			];
		}, $sessions );

		return new \WP_REST_Response( $data, 200 );
	}

	// ——— DELETE /settings/sessions/{id} ——————————————————————————————————————

	public function revoke_session( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id      = (int) $request->get_param( 'id' );
		$session = Token_Store::find_by_id( $id );

		if ( ! $session ) {
			return new \WP_Error( 'not_found', __( 'Session not found.', 'nop-indieweb' ), [ 'status' => 404 ] );
		}

		if ( (int) $session['user_id'] !== get_current_user_id() ) {
			return new \WP_Error( 'forbidden', __( 'You can only revoke your own sessions.', 'nop-indieweb' ), [ 'status' => 403 ] );
		}

		Token_Store::delete_by_id( $id );

		return new \WP_REST_Response( [ 'revoked' => true ], 200 );
	}

	// ——— POST /test-connection ————————————————————————————————————————————————

	public function test_connection( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$slug       = $request->get_param( 'platform' );
		$manager    = \NOP\IndieWeb\Plugin::get_instance()->syndication_manager();
		$syndicator = $manager ? $manager->get( $slug ) : null;

		if ( ! $syndicator ) {
			return new \WP_Error( 'unknown_platform', __( 'Unknown platform.', 'nop-indieweb' ), [ 'status' => 400 ] );
		}

		$result = $syndicator->test_connection();

		return new \WP_REST_Response( [
			'ok'      => (bool) $result['ok'],
			'message' => (string) $result['message'],
		], 200 );
	}

	// ——— GET /settings/health + POST /settings/health ————————————————————————

	/**
	 * Returns the latest stored health snapshot — no live API calls. The shape
	 * mirrors what HealthTable.js renders directly.
	 */
	public function get_health_status(): \WP_REST_Response {
		return new \WP_REST_Response( $this->health_payload(), 200 );
	}

	/**
	 * On-demand health check — re-runs every provider live, then returns the
	 * fresh snapshot. Same code path the daily cron and the wp-cli command
	 * use, so the three paths can't drift.
	 */
	public function run_health_check(): \WP_REST_Response {
		( new \NOP\IndieWeb\Health_Check() )->run();
		return new \WP_REST_Response( $this->health_payload(), 200 );
	}

	private function health_payload(): array {
		$status    = (array) get_option( \NOP\IndieWeb\Health_Check::OPTION_KEY, [] );
		$providers = (array) ( $status['providers'] ?? [] );
		$labels    = [
			'pirate_weather' => 'Pirate Weather',
			'geoapify'       => 'Geoapify',
			'foursquare'     => 'Foursquare',
			'tmdb'           => 'TMDB',
		];
		$rows = [];
		foreach ( $labels as $slug => $label ) {
			$info   = (array) ( $providers[ $slug ] ?? [] );
			$rows[] = [
				'slug'           => $slug,
				'label'          => $label,
				'status'         => (string) ( $info['status']         ?? 'unknown' ),
				'last_ok_at'     => isset( $info['last_ok_at'] )    ? (int) $info['last_ok_at']    : null,
				'last_error_at'  => isset( $info['last_error_at'] ) ? (int) $info['last_error_at'] : null,
				'last_http_code' => $info['last_http_code'] ?? null,
				'last_message'   => (string) ( $info['last_message']   ?? '' ),
			];
		}
		return [
			'last_run_at' => isset( $status['last_run_at'] ) ? (int) $status['last_run_at'] : null,
			'providers'   => $rows,
		];
	}
}
