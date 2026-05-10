<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Syndication;

class Syndicator_Mastodon extends Syndicator_Base {

	public function slug(): string  { return 'mastodon'; }
	public function label(): string { return 'Mastodon'; }

	private function instance(): string {
		return rtrim( (string) \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.mastodon.instance', '' ), '/' );
	}

	private function access_token(): string {
		return (string) \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.mastodon.access_token', '' );
	}

	protected function is_configured(): bool {
		return $this->instance() && $this->access_token();
	}

	protected function owns_url( string $url ): bool {
		$instance = $this->instance();
		return $instance && str_starts_with( $url, $instance );
	}

	protected function do_syndicate( int $post_id ): ?string {
		$status   = $this->build_status_text( $post_id, 500 );
		$body     = [ 'status' => $status ];

		$media_id = $this->upload_featured_image( $post_id );
		if ( $media_id ) {
			$body['media_ids'] = [ $media_id ];
		}

		$response = wp_remote_post(
			$this->instance() . '/api/v1/statuses',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $this->access_token(),
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return $data['url'] ?? null;
	}

	private function upload_featured_image( int $post_id ): ?string {
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( ! $thumbnail_id ) {
			return null;
		}

		// Use a web-sized image — full size can be huge.
		$image = wp_get_attachment_image_src( $thumbnail_id, 'large' );
		if ( ! $image ) {
			return null;
		}

		$image_url = $image[0];
		$file_data = $this->fetch_image( $image_url );
		if ( ! $file_data ) {
			return null;
		}

		$mime = $file_data['mime'];
		$data = $file_data['data'];

		// Mastodon v1 media upload uses multipart/form-data.
		$boundary = wp_generate_password( 24, false );
		$body     = "--{$boundary}\r\n"
			. "Content-Disposition: form-data; name=\"file\"; filename=\"image\"\r\n"
			. "Content-Type: {$mime}\r\n\r\n"
			. $data . "\r\n"
			. "--{$boundary}--";

		$response = wp_remote_post(
			$this->instance() . '/api/v2/media',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $this->access_token(),
					'Content-Type'  => "multipart/form-data; boundary={$boundary}",
				],
				'body'    => $body,
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code   = wp_remote_retrieve_response_code( $response );
		$result = json_decode( wp_remote_retrieve_body( $response ), true );

		// 200 = ready immediately, 202 = processing (still usable).
		if ( in_array( $code, [ 200, 202 ], true ) && isset( $result['id'] ) ) {
			return (string) $result['id'];
		}

		return null;
	}

	private function fetch_image( string $url ): ?array {
		$response = wp_remote_get( $url, [ 'timeout' => 15 ] );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$mime = wp_remote_retrieve_header( $response, 'content-type' );
		$mime = strtok( $mime, ';' ); // strip charset if present

		if ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ], true ) ) {
			return null;
		}

		return [
			'mime' => $mime,
			'data' => wp_remote_retrieve_body( $response ),
		];
	}
}
