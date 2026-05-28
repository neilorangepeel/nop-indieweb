<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\IndieAuth;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;
use WP_REST_Request;

/**
 * IndieAuth authorization endpoint.
 *
 * Registered as a REST route so the discovery URL is free of query-string
 * characters. An admin.php?page=... URL already contains "?" — IndieAuth
 * clients append their parameters with another "?" creating a double-? URL
 * that WordPress cannot route.
 *
 * Discovery URL: /wp-json/nop-indieweb/v1/authorize  (no "?" in the base)
 * Link tag:      <link rel="authorization_endpoint" href="...">
 *
 * The route renders HTML directly and exits, bypassing REST serialisation.
 * WordPress cookie auth applies: if the user is not logged in the handler
 * redirects to wp-login.php with redirect_to preserving all IndieAuth params.
 */
class Auth_Endpoint {

	public function register(): void {
		add_action( 'rest_api_init',                          [ $this, 'register_rest_route' ] );
		add_action( 'admin_post_nop_indieweb_auth_submit',    [ $this, 'process_form' ] );
	}

	public static function url(): string {
		return rest_url( 'nop-indieweb/v1/authorize' );
	}

	// ── REST route ────────────────────────────────────────────────────────────

	public function register_rest_route(): void {
		register_rest_route( 'nop-indieweb/v1', '/authorize', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'rest_render_page' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function rest_render_page( WP_REST_Request $request ): void {
		// Use wp_validate_auth_cookie() rather than is_user_logged_in().
		// The REST API's rest_cookie_check_errors hook calls wp_set_current_user(0)
		// on any request that has no X-WP-Nonce header — even if the browser sent
		// a valid session cookie — so is_user_logged_in() always returns false here,
		// causing an infinite redirect loop with wp-login.php.
		// Validating the raw cookie directly bypasses that layer entirely.
		$user_id = wp_validate_auth_cookie( '', 'logged_in' );
		if ( ! $user_id ) {
			$redirect_to = add_query_arg( $request->get_params(), static::url() );
			wp_redirect( wp_login_url( $redirect_to ), 302 );
			exit;
		}
		wp_set_current_user( $user_id );

		// Only users with the configured capability may issue IndieAuth tokens.
		// Default is manage_options (single-author site) — sites with multiple
		// authors can broaden via the filter to allow each author to issue
		// tokens scoped to their own posts.
		if ( ! current_user_can( self::authorize_capability() ) ) {
			wp_die(
				esc_html__( 'You do not have permission to authorize applications on this site.', 'nop-indieweb' ),
				esc_html__( 'Authorization Error', 'nop-indieweb' ),
				[ 'response' => 403 ]
			);
		}

		$params = $this->validate_request( $request->get_params() );
		if ( is_wp_error( $params ) ) {
			wp_die(
				esc_html( $params->get_error_message() ),
				esc_html__( 'Authorization Error', 'nop-indieweb' ),
				[ 'response' => 400 ]
			);
		}

		$this->render_html( $params );
		exit;
	}

	// ── Form processing ───────────────────────────────────────────────────────

	public function process_form(): void {
		check_admin_referer( 'nop_indieweb_auth' );

		if ( ! current_user_can( self::authorize_capability() ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'nop-indieweb' ), '', [ 'response' => 403 ] );
		}

		// Re-validate every parameter on submit — never trust the hidden form
		// fields. The same host-pin/scheme checks the GET handler ran are
		// re-run here so a tampered form (extension, MITM, devtools) can't
		// smuggle a mismatched redirect_uri past the host-pin check.
		$resolved = $this->validate_core_params( wp_unslash( $_POST ) );
		if ( is_wp_error( $resolved ) ) {
			wp_die(
				esc_html( $resolved->get_error_message() ),
				esc_html__( 'Authorization Error', 'nop-indieweb' ),
				[ 'response' => 400 ]
			);
		}

		// Whitelist scopes so a tampered form can't request undefined permissions.
		$scope = self::filter_scopes( $resolved['scope'] );

		// Issue auth code — valid for 10 minutes, single-use.
		$code = bin2hex( random_bytes( 16 ) );
		set_transient( 'nop_ia_code_' . $code, [
			'client_id'        => $resolved['client_id'],
			'redirect_uri'     => $resolved['redirect_uri'],
			'scope'            => $scope,
			'challenge'        => $resolved['code_challenge'],
			'challenge_method' => $resolved['code_challenge_method'],
			'me'               => home_url( '/' ),
			'user_id'          => get_current_user_id(),
			'client_name'      => wp_parse_url( $resolved['client_id'], PHP_URL_HOST ) ?? $resolved['client_id'],
		], 10 * MINUTE_IN_SECONDS );

		$redirect = add_query_arg( [
			'code'  => $code,
			'state' => rawurlencode( $resolved['state'] ),
		], $resolved['redirect_uri'] );

		// wp_safe_redirect blocks cross-origin redirects — use wp_redirect since
		// redirect_uri was just re-validated against client_id by validate_request().
		wp_redirect( $redirect, 302 );
		exit;
	}

	/**
	 * Capability required to authorize an IndieAuth client.
	 * Defaults to manage_options; filter to broaden for multi-author sites.
	 */
	private static function authorize_capability(): string {
		$cap = (string) apply_filters( 'nop_indieweb_authorize_capability', 'manage_options' );
		return $cap !== '' ? $cap : 'manage_options';
	}

	/**
	 * Drops unknown / unsupported scopes from the requested scope string.
	 * Prevents a tampered form from requesting fabricated permissions.
	 */
	private static function filter_scopes( string $scope_string ): string {
		$allowed   = [ 'create', 'update', 'delete', 'undelete', 'media', 'draft' ];
		$requested = array_filter( array_map( 'trim', explode( ' ', strtolower( $scope_string ) ) ) );
		$kept      = array_values( array_intersect( $allowed, $requested ) );
		return $kept ? implode( ' ', $kept ) : 'create';
	}

	// ── HTML output ───────────────────────────────────────────────────────────

	private function render_html( array $params ): void {
		header( 'Content-Type: text/html; charset=UTF-8' );
		$client_label  = esc_html( wp_parse_url( $params['client_id'], PHP_URL_HOST ) ?? $params['client_id'] );
		$scope_labels  = $this->describe_scopes( $params['scope'] );
		$site_name     = get_bloginfo( 'name' );
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<?php /* translators: %s: site name */ ?>
			<title><?php printf( esc_html__( 'Authorize — %s', 'nop-indieweb' ), esc_html( $site_name ) ); ?></title>
			<link rel="stylesheet" href="<?php echo esc_url( includes_url( 'css/login.css' ) ); ?>">
			<style>
				body { background: #f0f0f1; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
				.nop-auth-wrap { max-width: 360px; margin: 60px auto; padding: 0 20px; }
				.nop-auth-logo { text-align: center; margin-bottom: 24px; font-size: 13px; font-weight: 600; color: #646970; }
				.nop-auth-logo a { color: inherit; text-decoration: none; }
				.nop-auth-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 3px; padding: 28px 28px 24px; }
				.nop-auth-card h1 { font-size: 17px; margin: 0 0 4px; color: #1d2327; font-weight: 600; }
				.nop-auth-subtitle { font-size: 13px; color: #646970; margin: 0 0 24px; }
				.nop-auth-client { font-size: 14px; font-weight: 600; color: #1d2327; margin: 0 0 16px; }
				.nop-auth-scopes-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: #646970; margin: 0 0 8px; }
				.nop-auth-scopes { margin: 0 0 24px; padding: 12px 14px; background: #f6f7f7; border-radius: 3px; }
				.nop-auth-scopes ul { margin: 0; padding: 0; list-style: none; }
				.nop-auth-scopes li { font-size: 13px; padding: 3px 0 3px 22px; position: relative; color: #1d2327; }
				.nop-auth-scopes li::before { content: '✓'; position: absolute; left: 0; color: #00a32a; font-weight: 700; }
				.nop-auth-actions { display: flex; gap: 8px; }
				.nop-auth-actions .button { flex: 1; text-align: center; justify-content: center; }
				.nop-auth-me { font-size: 11px; color: #646970; margin: 16px 0 0; text-align: center; }
				.nop-auth-me strong { color: #1d2327; }
			</style>
		</head>
		<body>
		<main>
		<div class="nop-auth-wrap">
			<p class="nop-auth-logo"><a href="<?php echo esc_url( home_url() ); ?>"><?php echo esc_html( $site_name ); ?></a></p>

			<div class="nop-auth-card">
				<h1><?php esc_html_e( 'Authorize Application', 'nop-indieweb' ); ?></h1>
				<p class="nop-auth-subtitle"><?php esc_html_e( 'Review the permissions below before approving.', 'nop-indieweb' ); ?></p>

				<p class="nop-auth-client"><?php echo $client_label; ?></p>

				<div class="nop-auth-scopes">
					<p class="nop-auth-scopes-label"><?php esc_html_e( 'Requesting permission to', 'nop-indieweb' ); ?></p>
					<ul>
						<?php foreach ( $scope_labels as $label ) : ?>
							<li><?php echo esc_html( $label ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'nop_indieweb_auth' ); ?>
					<input type="hidden" name="action"                value="nop_indieweb_auth_submit">
					<input type="hidden" name="client_id"             value="<?php echo esc_attr( $params['client_id'] ); ?>">
					<input type="hidden" name="redirect_uri"          value="<?php echo esc_attr( $params['redirect_uri'] ); ?>">
					<input type="hidden" name="state"                 value="<?php echo esc_attr( $params['state'] ); ?>">
					<input type="hidden" name="scope"                 value="<?php echo esc_attr( $params['scope'] ); ?>">
					<input type="hidden" name="code_challenge"        value="<?php echo esc_attr( $params['code_challenge'] ); ?>">
					<input type="hidden" name="code_challenge_method" value="<?php echo esc_attr( $params['code_challenge_method'] ); ?>">

					<div class="nop-auth-actions">
						<a href="<?php echo esc_url( $this->build_denial_url( $params ) ); ?>"
						   class="button button-secondary"><?php esc_html_e( 'Cancel', 'nop-indieweb' ); ?></a>
						<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Authorize', 'nop-indieweb' ); ?>">
					</div>
				</form>

				<p class="nop-auth-me">
					<?php esc_html_e( 'Posting as', 'nop-indieweb' ); ?>
					<strong><?php echo esc_html( home_url( '/' ) ); ?></strong>
				</p>
			</div>
		</div>
		</main>
		</body>
		</html>
		<?php
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Validates the full IndieAuth GET request, including response_type.
	 */
	private function validate_request( array $params ): array|WP_Error {
		if ( empty( $params['response_type'] ) ) {
			return new WP_Error( 'missing_param', 'Missing required parameter: response_type' );
		}
		if ( 'code' !== $params['response_type'] ) {
			return new WP_Error( 'unsupported_response_type', 'Only response_type=code is supported.' );
		}
		return $this->validate_core_params( $params );
	}

	/**
	 * Validates parameters common to GET and POST: client_id, redirect_uri host
	 * pin, state, scope, code_challenge. Called from both render and submit so
	 * the same host-pin check applies on every code-issuance path.
	 */
	private function validate_core_params( array $params ): array|WP_Error {
		foreach ( [ 'client_id', 'redirect_uri', 'state' ] as $key ) {
			if ( empty( $params[ $key ] ) ) {
				return new WP_Error( 'missing_param', "Missing required parameter: {$key}" );
			}
		}

		// Reject non-HTTP(S) schemes — IndieAuth specifies https/http URLs only.
		foreach ( [ 'client_id', 'redirect_uri' ] as $key ) {
			$scheme = strtolower( (string) wp_parse_url( $params[ $key ], PHP_URL_SCHEME ) );
			if ( 'https' !== $scheme && 'http' !== $scheme ) {
				return new WP_Error( 'invalid_uri_scheme', "{$key} must be an http(s) URL." );
			}
		}

		// redirect_uri host must match client_id host (IndieAuth spec §4.2.2).
		$client_host   = wp_parse_url( $params['client_id'],    PHP_URL_HOST );
		$redirect_host = wp_parse_url( $params['redirect_uri'], PHP_URL_HOST );
		if ( ! $client_host || $client_host !== $redirect_host ) {
			return new WP_Error( 'invalid_redirect_uri', 'redirect_uri host must match client_id host.' );
		}

		return [
			'client_id'             => esc_url_raw( $params['client_id'] ),
			'redirect_uri'          => esc_url_raw( $params['redirect_uri'] ),
			'state'                 => sanitize_text_field( $params['state'] ),
			'scope'                 => sanitize_text_field( $params['scope'] ?? 'create' ),
			'code_challenge'        => sanitize_text_field( $params['code_challenge'] ?? '' ),
			'code_challenge_method' => sanitize_text_field( $params['code_challenge_method'] ?? '' ),
		];
	}

	private function build_denial_url( array $params ): string {
		return add_query_arg( [
			'error'             => 'access_denied',
			'error_description' => rawurlencode( 'The user denied the authorization request.' ),
			'state'             => rawurlencode( $params['state'] ),
		], $params['redirect_uri'] );
	}

	private function describe_scopes( string $scope_string ): array {
		$map = [
			'create'   => 'Create new posts',
			'update'   => 'Edit existing posts',
			'delete'   => 'Delete posts',
			'undelete' => 'Restore deleted posts',
			'media'    => 'Upload media files',
			'draft'    => 'Create draft posts',
		];

		$scopes = array_filter( array_map( 'trim', explode( ' ', $scope_string ) ) );
		$labels = [];
		foreach ( $scopes as $scope ) {
			$labels[] = $map[ $scope ] ?? ucfirst( $scope );
		}

		return $labels ?: [ 'Create new posts' ];
	}
}
