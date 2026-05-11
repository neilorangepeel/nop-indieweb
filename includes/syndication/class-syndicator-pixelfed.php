<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Syndication;

class Syndicator_Pixelfed extends Syndicator_Base {

	public function slug(): string  { return 'pixelfed'; }
	public function label(): string { return 'Pixelfed'; }

	private function instance(): string {
		return rtrim( (string) \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.pixelfed.instance', '' ), '/' );
	}

	private function access_token(): string {
		return (string) \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.pixelfed.access_token', '' );
	}

	protected function is_configured(): bool {
		return $this->instance() && $this->access_token();
	}

	protected function owns_url( string $url ): bool {
		$instance = $this->instance();
		return $instance && str_starts_with( $url, $instance );
	}

	protected function do_syndicate( int $post_id ): ?string {
		$status = $this->build_status_text( $post_id, 2000 );
		$body   = [ 'status' => $status ];

		$media_id = $this->upload_featured_image( $post_id, $this->instance(), $this->access_token() );
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
}
