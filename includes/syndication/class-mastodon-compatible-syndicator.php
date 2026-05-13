<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Syndication;

/**
 * Shared base for syndicators that speak the Mastodon HTTP API:
 *   POST /api/v1/statuses     — create a post
 *   POST /api/v2/media        — upload media
 *   GET  /api/v1/accounts/verify_credentials — connection test
 *
 * Pixelfed re-uses the same API, so both syndicators share this implementation.
 * Subclasses just declare their slug, label, and character limit.
 */
abstract class Mastodon_Compatible_Syndicator extends Syndicator_Base {

	abstract protected function char_limit(): int;

	protected function instance(): string {
		return rtrim( (string) \NOP\IndieWeb\nop_indieweb_get_option( "syndicators.{$this->slug()}.instance", '' ), '/' );
	}

	protected function access_token(): string {
		return (string) \NOP\IndieWeb\nop_indieweb_get_option( "syndicators.{$this->slug()}.access_token", '' );
	}

	protected function is_configured(): bool {
		return $this->instance() && $this->access_token();
	}

	protected function owns_url( string $url ): bool {
		$instance = $this->instance();
		return $instance && str_starts_with( $url, $instance );
	}

	protected function do_syndicate( int $post_id ): ?string {
		$status = $this->build_status_text( $post_id, $this->char_limit() );
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

	public function test_connection(): array {
		if ( ! $this->is_configured() ) {
			return [ 'ok' => false, 'message' => __( 'Not configured.', 'nop-indieweb' ) ];
		}

		$response = wp_remote_get(
			$this->instance() . '/api/v1/accounts/verify_credentials',
			[
				'headers' => [ 'Authorization' => 'Bearer ' . $this->access_token() ],
				'timeout' => 10,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'message' => $response->get_error_message() ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code && isset( $body['acct'] ) ) {
			if ( ! empty( $body['url'] ) ) {
				\NOP\IndieWeb\nop_indieweb_update_option( "syndicators.{$this->slug()}.profile_url", esc_url_raw( $body['url'] ) );
			}
			return [ 'ok' => true, 'message' => 'Connected as @' . $body['acct'] ];
		}

		return [
			'ok'      => false,
			'message' => 'Error ' . $code . ': ' . ( $body['error'] ?? __( 'Unknown error', 'nop-indieweb' ) ),
		];
	}
}
