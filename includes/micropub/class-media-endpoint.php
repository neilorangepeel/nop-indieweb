<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Micropub;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
 *
 * Authorization model:
 *   • Bearer token required.
 *   • Token must carry the `media` scope (or `create`, which most Micropub
 *     clients request and which implies media uploads in common practice).
 *   • Uploaded MIME types are restricted to a fixed whitelist of safe image,
 *     audio, and video formats — independent of the site's upload_mimes filter.
 *   • Per-file size cap is enforced before the upload is moved.
 */
class Media_Endpoint {

	/**
	 * Default per-upload size cap in bytes (25 MB). Filterable via
	 * `nop_indieweb_media_max_bytes` for sites that need to allow larger video.
	 */
	private const DEFAULT_MAX_BYTES = 25 * 1024 * 1024;

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
		$token = ( new Auth() )->verify( $request );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		// Accept either 'media' or 'create'. Most Micropub clients request only
		// 'create' and expect the implicit ability to upload media to a post.
		if ( ! Auth::has_scope( $token, 'media' ) && ! Auth::has_scope( $token, 'create' ) ) {
			return new WP_Error(
				'nop_indieweb_insufficient_scope',
				'Token needs "media" or "create" scope to upload.',
				[ 'status' => 403 ]
			);
		}

		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || empty( $files['file']['tmp_name'] ) ) {
			return new WP_Error( 'nop_indieweb_missing_file', 'No file provided. Send a multipart/form-data request with a "file" field.', [ 'status' => 400 ] );
		}

		$max_bytes = (int) apply_filters( 'nop_indieweb_media_max_bytes', self::DEFAULT_MAX_BYTES );
		// Prefer the actual size of the uploaded temp file over the client-reported
		// 'size' field, which can be absent, zero, or spoofed.
		$tmp_size  = is_readable( $files['file']['tmp_name'] ) ? (int) filesize( $files['file']['tmp_name'] ) : 0;
		$size      = $tmp_size > 0 ? $tmp_size : (int) ( $files['file']['size'] ?? 0 );
		if ( $size > 0 && $size > $max_bytes ) {
			return new WP_Error(
				'nop_indieweb_file_too_large',
				sprintf( 'File exceeds maximum size of %d bytes.', $max_bytes ),
				[ 'status' => 413 ]
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$uploaded = wp_handle_upload(
			$files['file'],
			[
				'test_form' => false,
				// Restrict to a fixed safe-media whitelist; do NOT honour the
				// site's upload_mimes filter (which may include risky types
				// like SVG without sanitisation).
				'mimes'     => self::allowed_mimes(),
			]
		);

		if ( isset( $uploaded['error'] ) ) {
			return new WP_Error( 'nop_indieweb_upload_failed', $uploaded['error'], [ 'status' => 400 ] );
		}

		$attachment_id = wp_insert_attachment(
			wp_slash( [
				'post_mime_type' => $uploaded['type'],
				'post_title'     => sanitize_file_name( $files['file']['name'] ),
				'post_status'    => 'inherit',
				'post_author'    => (int) ( $token['user_id'] ?? 0 ),
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

	/**
	 * Whitelist of MIME types the Micropub media endpoint will accept.
	 *
	 * Notably excludes SVG, HTML, and any application/* — these can carry
	 * scripts and aren't appropriate as Micropub uploads. Filterable so a site
	 * with a sanitising SVG plugin can opt in.
	 */
	private static function allowed_mimes(): array {
		$mimes = [
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'webp'         => 'image/webp',
			'heic'         => 'image/heic',
			'mp4|m4v'      => 'video/mp4',
			'mov|qt'       => 'video/quicktime',
			'webm'         => 'video/webm',
			'mp3'          => 'audio/mpeg',
			'm4a'          => 'audio/mp4',
			'wav'          => 'audio/wav',
			'ogg|oga'      => 'audio/ogg',
		];
		return (array) apply_filters( 'nop_indieweb_media_allowed_mimes', $mimes );
	}
}
