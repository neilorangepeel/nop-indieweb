<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Syndication;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tumblr OAuth2 connect flow: starts the authorisation redirect (admin only,
 * CSRF-guarded by a one-time state) and handles the callback that exchanges the
 * code for an access + refresh token pair, stored in the plugin settings. Mirrors
 * Venue\Foursquare_OAuth; the difference is Tumblr issues a short-lived access
 * token plus a long-lived refresh token (Tumblr_Client refreshes on demand).
 */
class Tumblr_OAuth {

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( 'nop-indieweb/v1', '/tumblr-auth', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'auth_redirect' ],
			'permission_callback' => '__return_true',
		] );
		register_rest_route( 'nop-indieweb/v1', '/tumblr-callback', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'oauth_callback' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function auth_redirect(): void {
		// Only an admin ever legitimately starts the flow; without this an anonymous
		// visitor could overwrite the stored state and race a real admin's connect.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'nop-indieweb' ), '', [ 'response' => 403 ] );
		}

		$client_id = (string) \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.tumblr.consumer_key', '' );
		if ( '' === $client_id ) {
			wp_die( esc_html__( 'Tumblr consumer key not configured in plugin settings.', 'nop-indieweb' ) );
		}

		// CSRF protection. These routes are OAuth redirect targets reached by plain
		// browser navigation, so a WP nonce can't guard them — issue a one-time
		// random state, stash it server-side, and require it back on the callback.
		$state = wp_generate_password( 32, false );
		set_transient( 'nop_indieweb_tumblr_oauth_state', $state, 15 * MINUTE_IN_SECONDS );

		$url = add_query_arg( [
			'client_id'     => $client_id,
			'response_type' => 'code',
			'redirect_uri'  => rest_url( 'nop-indieweb/v1/tumblr-callback' ),
			'scope'         => 'write offline_access',
			'state'         => $state,
		], 'https://www.tumblr.com/oauth2/authorize' );

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- intentional cross-origin redirect to the OAuth provider; target is a fixed Tumblr URL
		wp_redirect( $url );
		exit;
	}

	public function oauth_callback( \WP_REST_Request $request ): void {
		$code  = sanitize_text_field( (string) ( $request->get_param( 'code' ) ?? '' ) );
		$error = sanitize_text_field( (string) ( $request->get_param( 'error' ) ?? '' ) );
		$state = sanitize_text_field( (string) ( $request->get_param( 'state' ) ?? '' ) );

		// Verify (and one-time consume) the state issued by auth_redirect().
		$expected = get_transient( 'nop_indieweb_tumblr_oauth_state' );
		delete_transient( 'nop_indieweb_tumblr_oauth_state' );
		if ( ! $expected || '' === $state || ! hash_equals( (string) $expected, $state ) ) {
			wp_die( esc_html__( 'Invalid or expired authorisation request. Please start the Tumblr connection again.', 'nop-indieweb' ) );
		}

		if ( $error || '' === $code ) {
			wp_die( esc_html( sprintf(
				/* translators: %s: error message returned by Tumblr */
				__( 'Tumblr authorisation denied or failed: %s', 'nop-indieweb' ),
				$error ?: __( 'no code returned', 'nop-indieweb' )
			) ) );
		}

		$client_id     = (string) \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.tumblr.consumer_key', '' );
		$client_secret = (string) \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.tumblr.consumer_secret', '' );

		$response = wp_remote_post( 'https://api.tumblr.com/v2/oauth2/token', [
			'timeout' => 15,
			'body'    => [
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'redirect_uri'  => rest_url( 'nop-indieweb/v1/tumblr-callback' ),
			],
		] );

		if ( is_wp_error( $response ) ) {
			wp_die( esc_html( sprintf(
				/* translators: %s: HTTP error message */
				__( 'Token exchange failed: %s', 'nop-indieweb' ),
				$response->get_error_message()
			) ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) || empty( $body['refresh_token'] ) ) {
			// Don't echo the raw body — an error payload can include the secret we sent.
			wp_die( esc_html__( 'No tokens in Tumblr response.', 'nop-indieweb' ) );
		}

		// Write via the helper so the secrets-bearing settings option keeps
		// autoload=false (a raw update_option would re-enable autoload).
		\NOP\IndieWeb\nop_indieweb_update_option( 'syndicators.tumblr.access_token', (string) $body['access_token'] );
		\NOP\IndieWeb\nop_indieweb_update_option( 'syndicators.tumblr.refresh_token', (string) $body['refresh_token'] );
		\NOP\IndieWeb\nop_indieweb_update_option( 'syndicators.tumblr.token_expires_at', time() + (int) ( $body['expires_in'] ?? 2520 ) );

		// Cache the connected blog name/URL for the settings status line.
		( new Tumblr_Client() )->verify();

		wp_die(
			'<h2>' . esc_html__( 'Tumblr connected!', 'nop-indieweb' ) . '</h2><p>'
			. esc_html__( 'Your account is connected. You can close this tab and enable Tumblr syndication.', 'nop-indieweb' )
			. '</p>',
			esc_html__( 'Tumblr connected', 'nop-indieweb' ),
			[ 'response' => 200 ]
		);
	}
}
