<?php
declare( strict_types=1 );

namespace NOP\IndieWeb;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Daily health check of the plugin's external API dependencies.
 *
 * Born from the incident where Pirate Weather migrated /timemachine onto
 * a paid tier and the /post masthead silently lost weather for weeks. The
 * fetcher caught every failure with a quiet catch, cached the empty result,
 * and there was no signal until "the city's there but no temperature" — by
 * which point the cause was four months stale.
 *
 * Every provider that backs a live feature gets one ping per day. The
 * result lands in an option that admin notices read on every wp-admin page
 * load, so a degraded provider surfaces the next time you log in instead of
 * when you next happen to glance at the ticker. Cost: one request per day
 * per configured provider — trivial against every free tier (Pirate Weather
 * 10k/day, Geoapify 3k/day, Foursquare 1k/day, TMDB 50/sec).
 *
 * Empty/unconfigured keys are reported as 'no_key' (informational, no
 * notice). Only HTTP/network/shape failures raise the admin notice.
 */
class Health_Check {

	public const OPTION_KEY = 'nop_indieweb_health_status';
	private const HOOK      = 'nop_indieweb_health_check';

	public function register(): void {
		add_action( 'init',          [ $this, 'maybe_schedule' ] );
		add_action( self::HOOK,      [ $this, 'run' ] );
		add_action( 'admin_notices', [ $this, 'render_notice' ] );
	}

	/**
	 * Self-schedule on init when no event is registered yet — robust to fresh
	 * installs, activations that pre-date this class, and the rare cron-table
	 * wipe. First fire ≈ 1h after first init so the page that schedules it
	 * doesn't also pay for the check.
	 */
	public function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		$next = wp_next_scheduled( self::HOOK );
		if ( $next ) {
			wp_unschedule_event( $next, self::HOOK );
		}
	}

	/**
	 * Map of provider slug → check callable. Public so the wp-cli command can
	 * invoke a single provider or all of them on demand without duplicating
	 * the orchestration in run().
	 */
	public function providers(): array {
		return [
			'pirate_weather' => [ 'Pirate Weather', fn() => $this->check_pirate_weather() ],
			'geoapify'       => [ 'Geoapify',       fn() => $this->check_geoapify() ],
			'foursquare'     => [ 'Foursquare',     fn() => $this->check_foursquare() ],
			'tmdb'           => [ 'TMDB',           fn() => $this->check_tmdb() ],
		];
	}

	/**
	 * Run every provider check and merge results into the status option. Each
	 * check is fully isolated: a failure in one doesn't abort the others, and
	 * last_ok_at / last_error_at are preserved across runs so the notice can
	 * say "failing since 3 hours ago" instead of just "failing now".
	 */
	public function run(): array {
		$now      = time();
		$existing = (array) get_option( self::OPTION_KEY, [] );
		$prev     = (array) ( $existing['providers'] ?? [] );
		$next     = [];

		foreach ( $this->providers() as $slug => [ $_label, $check ] ) {
			$result   = $check();
			$previous = $prev[ $slug ] ?? [];

			$next[ $slug ] = [
				'status'         => $result['status'],
				'last_ok_at'     => 'ok'    === $result['status'] ? $now : ( $previous['last_ok_at']    ?? null ),
				'last_error_at'  => 'error' === $result['status'] ? $now : ( $previous['last_error_at'] ?? null ),
				'last_http_code' => $result['http_code'] ?? null,
				'last_message'   => (string) ( $result['message'] ?? '' ),
			];
		}

		update_option( self::OPTION_KEY, [
			'last_run_at' => $now,
			'providers'   => $next,
		], false );

		return $next;
	}

	// ——— Provider checks ————————————————————————————————————————————————————

	/**
	 * Pirate Weather — current-conditions /forecast (the endpoint that survived
	 * the Apiable migration on the legacy free tier). Belfast city centre is a
	 * known-stable coordinate.
	 */
	private function check_pirate_weather(): array {
		$key = (string) nop_indieweb_get_option( 'weather.pirate_weather_api_key', '' );
		if ( '' === $key ) {
			return [ 'status' => 'no_key' ];
		}
		$url = 'https://api.pirateweather.net/forecast/' . rawurlencode( $key )
		     . '/54.6,-5.9?units=si&exclude=minutely,hourly,daily,alerts';
		$res = wp_safe_remote_get( $url, [ 'timeout' => 8, 'limit_response_size' => 32 * 1024 ] );
		if ( is_wp_error( $res ) ) {
			return [ 'status' => 'error', 'message' => 'Network: ' . $res->get_error_message() ];
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			return [ 'status' => 'error', 'http_code' => $code, 'message' => 'HTTP ' . $code ];
		}
		$body = (array) json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! isset( $body['currently']['temperature'] ) ) {
			return [ 'status' => 'error', 'http_code' => $code, 'message' => '200 OK but missing currently.temperature' ];
		}
		return [ 'status' => 'ok', 'http_code' => $code ];
	}

	/**
	 * Geoapify — reverse-geocode lookup on Belfast city centre. A 200 with no
	 * locality at this coord would mean the API itself is returning empties
	 * (we treat that the same as failing).
	 */
	private function check_geoapify(): array {
		$key = (string) nop_indieweb_get_option( 'maps.geoapify_api_key', '' );
		if ( '' === $key ) {
			return [ 'status' => 'no_key' ];
		}
		$url = add_query_arg(
			[ 'lat' => 54.6, 'lon' => -5.9, 'apiKey' => $key ],
			'https://api.geoapify.com/v1/geocode/reverse'
		);
		$res = wp_safe_remote_get( $url, [ 'timeout' => 8, 'limit_response_size' => 32 * 1024 ] );
		if ( is_wp_error( $res ) ) {
			return [ 'status' => 'error', 'message' => 'Network: ' . $res->get_error_message() ];
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			return [ 'status' => 'error', 'http_code' => $code, 'message' => 'HTTP ' . $code ];
		}
		$body  = (array) json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$props = $body['features'][0]['properties'] ?? null;
		if ( ! is_array( $props ) || ( empty( $props['city'] ) && empty( $props['town'] ) && empty( $props['village'] ) && empty( $props['county'] ) ) ) {
			return [ 'status' => 'error', 'http_code' => $code, 'message' => '200 OK but no locality returned' ];
		}
		return [ 'status' => 'ok', 'http_code' => $code ];
	}

	/**
	 * Foursquare v3 — places search near Belfast. Uses the same endpoint /
	 * auth header / API version as Foursquare_Enricher so this check fails
	 * for the same reasons live enrichment would.
	 */
	private function check_foursquare(): array {
		$key = (string) nop_indieweb_get_option( 'venue.foursquare_api_key', '' );
		if ( '' === $key ) {
			return [ 'status' => 'no_key' ];
		}
		$url = add_query_arg(
			[ 'll' => '54.6,-5.9', 'limit' => 1 ],
			'https://places-api.foursquare.com/places/search'
		);
		$res = wp_safe_remote_get( $url, [
			'timeout'             => 8,
			'limit_response_size' => 32 * 1024,
			'headers'             => [
				'Authorization'        => 'Bearer ' . $key,
				'Accept'               => 'application/json',
				'X-Places-Api-Version' => '2025-06-17',
			],
		] );
		if ( is_wp_error( $res ) ) {
			return [ 'status' => 'error', 'message' => 'Network: ' . $res->get_error_message() ];
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			return [ 'status' => 'error', 'http_code' => $code, 'message' => 'HTTP ' . $code ];
		}
		$body = (array) json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( empty( $body['results'][0]['fsq_place_id'] ) ) {
			return [ 'status' => 'error', 'http_code' => $code, 'message' => '200 OK but no results' ];
		}
		return [ 'status' => 'ok', 'http_code' => $code ];
	}

	/**
	 * TMDB — /configuration is the cheapest "is the key valid" call: it
	 * returns image-base URLs and doesn't count toward any per-feature quota.
	 */
	private function check_tmdb(): array {
		$key = (string) nop_indieweb_get_option( 'lookups.tmdb_api_key', '' );
		if ( '' === $key ) {
			return [ 'status' => 'no_key' ];
		}
		$url = add_query_arg( [ 'api_key' => $key ], 'https://api.themoviedb.org/3/configuration' );
		$res = wp_safe_remote_get( $url, [ 'timeout' => 8, 'limit_response_size' => 32 * 1024 ] );
		if ( is_wp_error( $res ) ) {
			return [ 'status' => 'error', 'message' => 'Network: ' . $res->get_error_message() ];
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			return [ 'status' => 'error', 'http_code' => $code, 'message' => 'HTTP ' . $code ];
		}
		$body = (array) json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( empty( $body['images']['base_url'] ) ) {
			return [ 'status' => 'error', 'http_code' => $code, 'message' => '200 OK but configuration shape unexpected' ];
		}
		return [ 'status' => 'ok', 'http_code' => $code ];
	}

	// ——— Admin notice ————————————————————————————————————————————————————————

	/**
	 * Persistent admin notice — only shows when the most recent check found
	 * at least one provider in 'error'. 'no_key' is intentionally silent
	 * (it's a configuration choice, not a failure). Notice stays up until
	 * the next successful check so a real outage can't be missed.
	 */
	public function render_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$status    = (array) get_option( self::OPTION_KEY, [] );
		$providers = (array) ( $status['providers'] ?? [] );
		$failing   = [];
		foreach ( $providers as $slug => $info ) {
			if ( 'error' === ( $info['status'] ?? '' ) ) {
				$failing[ $slug ] = $info;
			}
		}
		if ( ! $failing ) {
			return;
		}
		$labels = array_combine(
			array_keys( $this->providers() ),
			array_map( static fn( $entry ) => $entry[0], $this->providers() )
		);
		?>
		<div class="notice notice-error">
			<p>
				<strong>
					<?php esc_html_e( 'NOP IndieWeb — external service health check failing', 'nop-indieweb' ); ?>
				</strong>
			</p>
			<ul style="margin-left: 1.5em; list-style: disc;">
				<?php foreach ( $failing as $slug => $info ) :
					$label = $labels[ $slug ] ?? $slug;
					$msg   = (string) ( $info['last_message'] ?? '' );
					$when  = ! empty( $info['last_error_at'] )
						? sprintf(
							/* translators: %s: human-readable time difference */
							esc_html__( 'failing since %s ago', 'nop-indieweb' ),
							esc_html( human_time_diff( (int) $info['last_error_at'], time() ) )
						)
						: esc_html__( 'failing now', 'nop-indieweb' );
					?>
					<li>
						<strong><?php echo esc_html( $label ); ?></strong> —
						<?php echo esc_html( $msg ); ?>
						<span style="opacity: 0.6;">(<?php echo esc_html( $when ); ?>)</span>
					</li>
				<?php endforeach; ?>
			</ul>
			<p>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=nop-indieweb' ) ); ?>">
					<?php esc_html_e( 'Open IndieWeb settings →', 'nop-indieweb' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
