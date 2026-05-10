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
		$status = $this->build_status_text( $post_id, 500 );

		$response = wp_remote_post(
			$this->instance() . '/api/v1/statuses',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $this->access_token(),
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( [ 'status' => $status ] ),
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return $body['url'] ?? null;
	}
}
