<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Venue;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Foursquare OAuth connect flow: starts the authorisation redirect (admin only,
 * CSRF-guarded by a one-time state) and handles the callback that exchanges the
 * code for a personal access token, stored in the plugin settings.
 */
class Foursquare_OAuth {

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_foursquare_oauth_routes' ] );
	}

	public function register_foursquare_oauth_routes(): void {
		register_rest_route( 'nop-indieweb/v1', '/foursquare-auth', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'foursquare_auth_redirect' ],
			'permission_callback' => '__return_true',
		] );
		register_rest_route( 'nop-indieweb/v1', '/foursquare-callback', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'foursquare_oauth_callback' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function foursquare_auth_redirect(): void {
		// Only an admin ever legitimately starts the OAuth flow; without this an
		// anonymous visitor could repeatedly overwrite the stored OAuth state and
		// race a real admin's in-flight connect.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'nop-indieweb' ), '', [ 'response' => 403 ] );
		}

		$opts          = get_option( 'nop_indieweb_settings', [] );
		$client_id     = $opts['venue']['foursquare_client_id'] ?? '';
		$callback_url  = rest_url( 'nop-indieweb/v1/foursquare-callback' );

		if ( ! $client_id ) {
			wp_die( esc_html__( 'Foursquare Client ID not configured in plugin settings.', 'nop-indieweb' ) );
		}

		// CSRF protection. This route and its callback are OAuth redirect targets
		// reached by plain browser navigation, so a WordPress nonce can't guard
		// them (REST treats cookie-only requests as anonymous, and Foursquare
		// can't echo a nonce). Instead we issue a one-time random state, stash it
		// server-side, and require it back on the callback before exchanging the
		// code — the standard OAuth defence against forged callbacks.
		$state = wp_generate_password( 32, false );
		set_transient( 'nop_indieweb_fsq_oauth_state', $state, 15 * MINUTE_IN_SECONDS );

		$url = add_query_arg( [
			'client_id'     => $client_id,
			'response_type' => 'code',
			'redirect_uri'  => $callback_url,
			'state'         => $state,
		], 'https://foursquare.com/oauth2/authenticate' );

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- intentional cross-origin redirect (OAuth/IndieAuth client or provider); target validated above, wp_safe_redirect would wrongly block it
		wp_redirect( $url );
		exit;
	}

	public function foursquare_oauth_callback( \WP_REST_Request $request ): void {
		$code  = sanitize_text_field( $request->get_param( 'code' ) ?? '' );
		$error = sanitize_text_field( $request->get_param( 'error' ) ?? '' );
		$state = sanitize_text_field( $request->get_param( 'state' ) ?? '' );

		// Verify the state issued by foursquare_auth_redirect(). One-time use —
		// delete it whether or not it matches so a leaked value can't be replayed.
		$expected = get_transient( 'nop_indieweb_fsq_oauth_state' );
		delete_transient( 'nop_indieweb_fsq_oauth_state' );
		if ( ! $expected || '' === $state || ! hash_equals( (string) $expected, $state ) ) {
			wp_die( esc_html__( 'Invalid or expired authorisation request. Please start the Foursquare connection again.', 'nop-indieweb' ) );
		}

		if ( $error || ! $code ) {
			wp_die( esc_html( sprintf(
				/* translators: %s: error message returned by Foursquare */
				__( 'Foursquare authorisation denied or failed: %s', 'nop-indieweb' ),
				$error ?: __( 'no code returned', 'nop-indieweb' )
			) ) );
		}

		$opts          = get_option( 'nop_indieweb_settings', [] );
		$client_id     = $opts['venue']['foursquare_client_id'] ?? '';
		$client_secret = $opts['venue']['foursquare_client_secret'] ?? '';
		$callback_url  = rest_url( 'nop-indieweb/v1/foursquare-callback' );

		$response = wp_remote_post( 'https://foursquare.com/oauth2/access_token', [
			'timeout' => 15,
			'body'    => [
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'grant_type'    => 'authorization_code',
				'redirect_uri'  => $callback_url,
				'code'          => $code,
			],
		] );

		if ( is_wp_error( $response ) ) {
			wp_die( esc_html( sprintf(
				/* translators: %s: HTTP error message */
				__( 'Token exchange failed: %s', 'nop-indieweb' ),
				$response->get_error_message()
			) ) );
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$token = $body['access_token'] ?? '';

		if ( ! $token ) {
			// Don't echo the raw response body — an error payload can include the
			// client_secret we just sent.
			wp_die( esc_html__( 'No access token in Foursquare response.', 'nop-indieweb' ) );
		}

		// Write via the helper so the secrets-bearing settings option keeps
		// autoload=false (a raw update_option here would re-enable autoload and
		// put credentials in memory on every request).
		\NOP\IndieWeb\nop_indieweb_update_option( 'venue.foursquare_user_token', $token );

		wp_die(
			'<h2>' . esc_html__( 'Foursquare connected!', 'nop-indieweb' ) . '</h2><p>'
			. esc_html__( 'Your personal access token has been saved. You can close this tab and run the importer.', 'nop-indieweb' )
			. '</p>',
			esc_html__( 'Foursquare connected', 'nop-indieweb' ),
			[ 'response' => 200 ]
		);
	}
}
