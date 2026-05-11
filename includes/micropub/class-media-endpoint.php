<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Micropub;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Micropub media endpoint.
 *
 * Accepts multipart file uploads from Micropub clients, stores them in the
 * WordPress media library, and returns the URL via a Location header.
 *
 * Spec: https://micropub.spec.indieweb.org/#media-endpoint
 */
class Media_Endpoint {

	public static function url(): string {
		return rest_url( 'nop-indieweb/v1/media' );
	}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );
	}

	public function register_route(): void {
		register_rest_route( 'nop-indieweb/v1', '/media', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$auth_result = ( new Auth() )->verify( $request );
		if ( is_wp_error( $auth_result ) ) {
			return $auth_result;
		}

		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || empty( $files['file']['tmp_name'] ) ) {
			return new WP_Error( 'nop_indieweb_missing_file', 'No file provided. Send a multipart/form-data request with a "file" field.', [ 'status' => 400 ] );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$uploaded = wp_handle_upload( $files['file'], [ 'test_form' => false ] );

		if ( isset( $uploaded['error'] ) ) {
			return new WP_Error( 'nop_indieweb_upload_failed', $uploaded['error'], [ 'status' => 500 ] );
		}

		$attachment_id = wp_insert_attachment(
			wp_slash( [
				'post_mime_type' => $uploaded['type'],
				'post_title'     => sanitize_file_name( $files['file']['name'] ),
				'post_status'    => 'inherit',
			] ),
			$uploaded['file']
		);

		if ( is_wp_error( $attachment_id ) ) {
			return new WP_Error( 'nop_indieweb_attachment_failed', $attachment_id->get_error_message(), [ 'status' => 500 ] );
		}

		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $uploaded['file'] ) );

		$url = wp_get_attachment_url( $attachment_id );

		\NOP\IndieWeb\nop_indieweb_log( "Micropub media upload: attachment {$attachment_id}", $url );

		$response = new WP_REST_Response( null, 201 );
		$response->header( 'Location', $url );
		return $response;
	}
}
