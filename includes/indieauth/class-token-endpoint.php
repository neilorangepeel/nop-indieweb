<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\IndieAuth;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * IndieAuth token endpoint.
 *
 * POST /wp-json/nop-indieweb/v1/token
 *   grant_type=authorization_code  — exchange auth code for a Bearer token
 *   action=revoke                  — revoke an existing token (RFC 7009)
 *
 * GET /wp-json/nop-indieweb/v1/token
 *   Authorization: Bearer <token>  — verify a token (used by some clients)
 *
 * Discovery: <link rel="token_endpoint" href="...">
 */
class Token_Endpoint {

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( 'nop-indieweb/v1', '/token', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_post' ],
				'permission_callback' => '__return_true',
			],
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_get' ],
				'permission_callback' => '__return_true',
			],
		] );
	}

	// ── POST ──────────────────────────────────────────────────────────────────

	public function handle_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( 'revoke' === $request->get_param( 'action' ) ) {
			return $this->revoke_token( $request );
		}
		return $this->exchange_code( $request );
	}

	private function exchange_code( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( 'authorization_code' !== $request->get_param( 'grant_type' ) ) {
			return new WP_Error(
				'unsupported_grant_type',
				'Only grant_type=authorization_code is supported.',
				[ 'status' => 400 ]
			);
		}

		$code         = sanitize_text_field( $request->get_param( 'code' )         ?? '' );
		$client_id    = esc_url_raw(         $request->get_param( 'client_id' )    ?? '' );
		$redirect_uri = esc_url_raw(         $request->get_param( 'redirect_uri' ) ?? '' );
		$verifier     = sanitize_text_field( $request->get_param( 'code_verifier' ) ?? '' );

		if ( ! $code || ! $client_id || ! $redirect_uri ) {
			return new WP_Error(
				'missing_params',
				'code, client_id, and redirect_uri are all required.',
				[ 'status' => 400 ]
			);
		}

		// Look up and immediately consume the auth code (codes are single-use).
		$stored = get_transient( 'nop_ia_code_' . $code );
		delete_transient( 'nop_ia_code_' . $code );

		if ( ! $stored ) {
			return new WP_Error( 'invalid_grant', 'Authorization code is invalid or expired.', [ 'status' => 400 ] );
		}

		if ( $stored['client_id'] !== $client_id || $stored['redirect_uri'] !== $redirect_uri ) {
			return new WP_Error( 'invalid_grant', 'client_id or redirect_uri does not match.', [ 'status' => 400 ] );
		}

		// Validate PKCE if the authorization request included a code_challenge.
		// NOP: needs review — PKCE is only enforced when a challenge was present at
		// the authorize step, so a client that never sends one is exempt. Mandating
		// PKCE for all clients would close a downgrade gap but may break legacy ones.
		if ( $stored['challenge'] ) {
			if ( ! $verifier ) {
				return new WP_Error( 'invalid_request', 'code_verifier is required.', [ 'status' => 400 ] );
			}
			if ( ! $this->verify_pkce( $verifier, $stored['challenge'] ) ) {
				return new WP_Error( 'invalid_grant', 'PKCE code_verifier does not match code_challenge.', [ 'status' => 400 ] );
			}
		}

		$raw_token = bin2hex( random_bytes( 32 ) );

		Token_Store::insert(
			$raw_token,
			$client_id,
			$stored['client_name'] ?? ( wp_parse_url( $client_id, PHP_URL_HOST ) ?? $client_id ),
			$stored['scope'],
			(int) $stored['user_id']
		);

		return new WP_REST_Response( [
			'access_token' => $raw_token,
			'token_type'   => 'Bearer',
			'scope'        => $stored['scope'],
			'me'           => $stored['me'],
		], 200 );
	}

	private function revoke_token( WP_REST_Request $request ): WP_REST_Response {
		$token = sanitize_text_field( $request->get_param( 'token' ) ?? '' );
		if ( $token ) {
			// Per RFC 7009 §2.2, revocation always returns 200 even for unknown tokens.
			Token_Store::revoke_by_raw( $token );
		}
		return new WP_REST_Response( null, 200 );
	}

	// ── GET — token verification ───────────────────────────────────────────────

	public function handle_get( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$auth   = $request->get_header( 'Authorization' );
		$token  = ( $auth && str_starts_with( $auth, 'Bearer ' ) )
			? trim( substr( $auth, 7 ) )
			: '';

		if ( ! $token ) {
			return new WP_Error( 'missing_token', 'Send Authorization: Bearer <token>.', [ 'status' => 401 ] );
		}

		$row = Token_Store::find_by_token( $token );
		if ( ! $row ) {
			return new WP_Error( 'invalid_token', 'Token not found or revoked.', [ 'status' => 401 ] );
		}

		Token_Store::touch( $row['token_hash'] );

		return new WP_REST_Response( [
			'me'        => home_url( '/' ),
			'client_id' => $row['client_id'],
			'scope'     => $row['scope'],
		], 200 );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function verify_pkce( string $verifier, string $challenge ): bool {
		// S256: BASE64URL(SHA256(ASCII(code_verifier))) must equal code_challenge.
		$derived = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		return hash_equals( $challenge, $derived );
	}
}
