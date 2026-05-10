<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\IndieAuth;

use WP_Error;

/**
 * IndieAuth authorization endpoint.
 *
 * A hidden wp-admin page that handles the user-facing OAuth approval flow.
 * WordPress handles session authentication automatically — if the user is not
 * logged in, WP redirects to wp-login.php and preserves all IndieAuth GET
 * params via the redirect_to mechanism.
 *
 * Discovery URL: admin_url( 'admin.php?page=nop-indieweb-auth' )
 * Link tag:      <link rel="authorization_endpoint" href="...">
 */
class Auth_Endpoint {

	public function register(): void {
		add_action( 'admin_menu',                             [ $this, 'add_hidden_page' ] );
		add_action( 'admin_post_nop_indieweb_auth_submit',    [ $this, 'process_form' ] );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=nop-indieweb-auth' );
	}

	// ── Admin page ────────────────────────────────────────────────────────────

	public function add_hidden_page(): void {
		// parent = null → hidden from all menus, accessible via admin.php?page=...
		add_submenu_page(
			null,
			__( 'Authorize Application', 'nop-indieweb' ),
			__( 'Authorize Application', 'nop-indieweb' ),
			'manage_options',
			'nop-indieweb-auth',
			[ $this, 'render_page' ]
		);
	}

	public function render_page(): void {
		$params = $this->validate_request( $_GET );
		if ( is_wp_error( $params ) ) {
			wp_die(
				esc_html( $params->get_error_message() ),
				esc_html__( 'Authorization Error', 'nop-indieweb' ),
				[ 'response' => 400 ]
			);
		}

		$client_label  = esc_html( parse_url( $params['client_id'], PHP_URL_HOST ) ?? $params['client_id'] );
		$scope_labels  = $this->describe_scopes( $params['scope'] );
		$site_name     = get_bloginfo( 'name' );
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php printf( esc_html__( 'Authorize — %s', 'nop-indieweb' ), esc_html( $site_name ) ); ?></title>
			<?php wp_print_styles( 'login' ); ?>
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
		</body>
		</html>
		<?php
	}

	// ── Form processing ───────────────────────────────────────────────────────

	public function process_form(): void {
		check_admin_referer( 'nop_indieweb_auth' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'nop-indieweb' ), '', [ 'response' => 403 ] );
		}

		$client_id    = esc_url_raw( $_POST['client_id']    ?? '' );
		$redirect_uri = esc_url_raw( $_POST['redirect_uri'] ?? '' );
		$state        = sanitize_text_field( $_POST['state']  ?? '' );
		$scope        = sanitize_text_field( $_POST['scope']  ?? 'create' );
		$challenge    = sanitize_text_field( $_POST['code_challenge'] ?? '' );
		$challenge_m  = sanitize_text_field( $_POST['code_challenge_method'] ?? '' );

		if ( ! $redirect_uri ) {
			wp_die( esc_html__( 'Missing redirect_uri.', 'nop-indieweb' ) );
		}

		// Issue auth code — valid for 10 minutes, single-use.
		$code = bin2hex( random_bytes( 16 ) );
		set_transient( 'nop_ia_code_' . $code, [
			'client_id'        => $client_id,
			'redirect_uri'     => $redirect_uri,
			'scope'            => $scope,
			'challenge'        => $challenge,
			'challenge_method' => $challenge_m,
			'me'               => home_url( '/' ),
			'user_id'          => get_current_user_id(),
			'client_name'      => parse_url( $client_id, PHP_URL_HOST ) ?? $client_id,
		], 10 * MINUTE_IN_SECONDS );

		$redirect = add_query_arg( [
			'code'  => $code,
			'state' => rawurlencode( $state ),
		], $redirect_uri );

		// wp_safe_redirect blocks cross-origin redirects — use wp_redirect since
		// redirect_uri was validated against client_id during render_page().
		wp_redirect( $redirect, 302 );
		exit;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function validate_request( array $params ): array|WP_Error {
		foreach ( [ 'response_type', 'client_id', 'redirect_uri', 'state' ] as $key ) {
			if ( empty( $params[ $key ] ) ) {
				return new WP_Error( 'missing_param', "Missing required parameter: {$key}" );
			}
		}

		if ( 'code' !== $params['response_type'] ) {
			return new WP_Error( 'unsupported_response_type', 'Only response_type=code is supported.' );
		}

		// redirect_uri host must match client_id host (IndieAuth spec §4.2.2).
		$client_host   = parse_url( $params['client_id'],    PHP_URL_HOST );
		$redirect_host = parse_url( $params['redirect_uri'], PHP_URL_HOST );
		if ( ! $client_host || $client_host !== $redirect_host ) {
			return new WP_Error( 'invalid_redirect_uri', 'redirect_uri host must match client_id host.' );
		}

		return [
			'client_id'            => esc_url_raw( $params['client_id'] ),
			'redirect_uri'         => esc_url_raw( $params['redirect_uri'] ),
			'state'                => sanitize_text_field( $params['state'] ),
			'scope'                => sanitize_text_field( $params['scope'] ?? 'create' ),
			'code_challenge'       => sanitize_text_field( $params['code_challenge'] ?? '' ),
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
			'create' => 'Create new posts',
			'update' => 'Edit existing posts',
			'delete' => 'Delete posts',
			'media'  => 'Upload media files',
			'draft'  => 'Create draft posts',
		];

		$scopes = array_filter( array_map( 'trim', explode( ' ', $scope_string ) ) );
		$labels = [];
		foreach ( $scopes as $scope ) {
			$labels[] = $map[ $scope ] ?? ucfirst( $scope );
		}

		return $labels ?: [ 'Create new posts' ];
	}
}
