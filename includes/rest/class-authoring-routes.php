<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Rest;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes that feed the authoring UI: /lookup (provider search, e.g. TMDB
 * film lookup) and /now (the /post masthead's current place + weather). Both
 * require the edit_posts capability.
 */
class Authoring_Routes {

	/** @var \NOP\IndieWeb\Lookup\Lookup_Provider_Base[] */
	private array $lookup_providers;

	/**
	 * @param \NOP\IndieWeb\Lookup\Lookup_Provider_Base[] $lookup_providers
	 */
	public function __construct( array $lookup_providers ) {
		$this->lookup_providers = $lookup_providers;
	}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_lookup_route' ] );
		add_action( 'rest_api_init', [ $this, 'register_now_route' ] );
	}

	public function register_lookup_route(): void {
		register_rest_route( 'nop-indieweb/v1', '/lookup', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'lookup_route_handler' ],
			'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			'args'                => [
				'provider' => [ 'required' => true,  'sanitize_callback' => 'sanitize_key' ],
				'q'        => [ 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );
	}

	public function lookup_route_handler( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$slug     = $request->get_param( 'provider' );
		$query    = $request->get_param( 'q' );
		$provider = null;

		foreach ( $this->lookup_providers as $p ) {
			if ( $p->get_slug() === $slug ) {
				$provider = $p;
				break;
			}
		}

		if ( ! $provider ) {
			return new \WP_Error( 'unknown_provider', 'Unknown lookup provider.', [ 'status' => 400 ] );
		}

		$results = $provider->search( $query );
		if ( is_wp_error( $results ) ) {
			return $results;
		}

		return new \WP_REST_Response( [ 'results' => $results ], 200 );
	}

	/**
	 * The /post masthead's "current moment" data. The client hands over the
	 * device GPS lat/lon; we resolve a place name and current weather with the
	 * plugin's existing providers (Geoapify + Pirate Weather) so the keys stay
	 * server-side and the masthead matches the weather stamped on exercise/checkin
	 * posts. Both providers cache internally (Geoapify 30 days, weather 30 min),
	 * so this route needs no cache of its own.
	 */
	public function register_now_route(): void {
		register_rest_route( 'nop-indieweb/v1', '/now', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'now_route_handler' ],
			'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			'args'                => [
				'lat' => [ 'required' => true, 'type' => 'number' ],
				'lon' => [ 'required' => true, 'type' => 'number' ],
			],
		] );
	}

	public function now_route_handler( \WP_REST_Request $request ): \WP_REST_Response {
		$lat = (float) $request->get_param( 'lat' );
		$lon = (float) $request->get_param( 'lon' );

		// Out-of-range or null-island coordinates carry no signal — return an empty
		// payload so the client simply hides the place/weather cells.
		if ( $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 || ( 0.0 === $lat && 0.0 === $lon ) ) {
			return new \WP_REST_Response( [ 'place' => '', 'country' => '', 'temp_c' => null, 'icon' => '', 'summary' => '', 'sunrise' => null, 'sunset' => null, 'moonphase' => null ], 200 );
		}

		$geo     = \NOP\IndieWeb\Venue\Geoapify_Geocoder::reverse_geocode( $lat, $lon );
		$weather = \NOP\IndieWeb\Weather\Weather_Fetcher::fetch_current( $lat, $lon );

		return new \WP_REST_Response( [
			'place'   => (string) ( $geo['locality'] ?? '' ),
			'country' => (string) ( $geo['country'] ?? '' ),
			'temp_c'  => $weather['temp_c'] ?? null,
			'icon'    => (string) ( $weather['icon'] ?? '' ),
			'summary' => (string) ( $weather['summary'] ?? '' ),
			'sunrise'   => $weather['sunrise'] ?? null,
			'sunset'    => $weather['sunset'] ?? null,
			'moonphase' => $weather['moonphase'] ?? null,
		], 200 );
	}
}
