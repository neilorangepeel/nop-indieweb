<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Micropub;

use WP_REST_Request;
use WP_Error;
use NOP\IndieWeb\IndieAuth\Token_Store;

/**
 * Verifies incoming Micropub requests against IndieAuth-issued Bearer tokens.
 *
 * Tokens are issued by the IndieAuth token endpoint after the user completes
 * the authorization flow. Each token is stored as a SHA-256 hash in the
 * nop_indieweb_tokens table — the raw value is never stored.
 */
class Auth {

	public function verify( WP_REST_Request $request ): bool|WP_Error {
		$raw_token = $this->extract_token( $request );

		if ( ! $raw_token ) {
			return new WP_Error(
				'nop_indieweb_missing_token',
				'Missing Bearer token. Send Authorization: Bearer <token> or access_token in the request body.',
				[ 'status' => 401 ]
			);
		}

		$row = Token_Store::find_by_token( $raw_token );

		if ( ! $row ) {
			return new WP_Error(
				'nop_indieweb_invalid_token',
				'Invalid or revoked Bearer token.',
				[ 'status' => 403 ]
			);
		}

		Token_Store::touch( $row['token_hash'] );

		return true;
	}

	private function extract_token( WP_REST_Request $request ): string {
		$auth_header = $request->get_header( 'Authorization' );
		if ( $auth_header && str_starts_with( $auth_header, 'Bearer ' ) ) {
			return trim( substr( $auth_header, 7 ) );
		}

		$body_token = $request->get_param( 'access_token' );
		if ( $body_token ) {
			return sanitize_text_field( $body_token );
		}

		return '';
	}
}
