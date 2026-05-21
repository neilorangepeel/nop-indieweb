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
 *
 * verify() returns the token row on success so callers can enforce per-token
 * scope and authorship. Use require_scope() and can_edit_post() on every
 * state-changing path.
 */
class Auth {

	/**
	 * Validates the incoming Bearer token and returns the matched token row.
	 *
	 * @return array{id:int,token_hash:string,client_id:string,client_name:string,scope:string,user_id:int}|WP_Error
	 */
	public function verify( WP_REST_Request $request ): array|WP_Error {
		$raw_token = $this->extract_token( $request );

		if ( ! $raw_token ) {
			return new WP_Error(
				'nop_indieweb_missing_token',
				'Missing Bearer token. Send Authorization: Bearer <token>.',
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

		if ( time() - (int) strtotime( $row['last_used_at'] ) > HOUR_IN_SECONDS ) {
			Token_Store::touch( $row['token_hash'] );
		}

		return $row;
	}

	/**
	 * Returns true if the token grants the given Micropub scope.
	 *
	 * Legacy tokens stored before scope enforcement may have an empty scope
	 * string — those are treated as 'create'-only (the Micropub default),
	 * which fails closed for update/delete/media. The user can re-authorize
	 * to grant additional scopes.
	 */
	public static function has_scope( array $token, string $required ): bool {
		$required = strtolower( trim( $required ) );
		$scopes   = preg_split( '/\s+/', strtolower( (string) ( $token['scope'] ?? '' ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		if ( ! $scopes ) {
			$scopes = [ 'create' ];
		}
		return in_array( $required, $scopes, true );
	}

	/**
	 * Returns a WP_Error if the token lacks the required scope, otherwise null.
	 */
	public static function require_scope( array $token, string $required ): ?WP_Error {
		if ( self::has_scope( $token, $required ) ) {
			return null;
		}
		return new WP_Error(
			'nop_indieweb_insufficient_scope',
			sprintf( 'Token does not have the required "%s" scope.', $required ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Returns true if the token's owning user can edit the target post.
	 *
	 * Honours WP's capability layer (post author, editor role, custom caps).
	 */
	public static function can_edit_post( array $token, int $post_id ): bool {
		$user_id = (int) ( $token['user_id'] ?? 0 );
		return $user_id > 0 && user_can( $user_id, 'edit_post', $post_id );
	}

	/**
	 * Returns true if the token's owning user can delete the target post.
	 */
	public static function can_delete_post( array $token, int $post_id ): bool {
		$user_id = (int) ( $token['user_id'] ?? 0 );
		return $user_id > 0 && user_can( $user_id, 'delete_post', $post_id );
	}

	/**
	 * Extracts the Bearer token from the request.
	 *
	 * Prefers the Authorization header. Falls back to an access_token field in
	 * the request body (form or JSON) for spec-compliant Micropub clients.
	 *
	 * Tokens are explicitly NOT accepted from the URL query string — query
	 * params leak into webserver access logs and browser history.
	 */
	private function extract_token( WP_REST_Request $request ): string {
		$auth_header = $request->get_header( 'Authorization' );
		if ( $auth_header && str_starts_with( $auth_header, 'Bearer ' ) ) {
			return trim( substr( $auth_header, 7 ) );
		}

		$body_params = $request->get_body_params();
		if ( is_array( $body_params ) && ! empty( $body_params['access_token'] ) && is_string( $body_params['access_token'] ) ) {
			return trim( sanitize_text_field( $body_params['access_token'] ) );
		}

		$json_params = $request->get_json_params();
		if ( is_array( $json_params ) && ! empty( $json_params['access_token'] ) && is_string( $json_params['access_token'] ) ) {
			return trim( sanitize_text_field( $json_params['access_token'] ) );
		}

		return '';
	}
}
