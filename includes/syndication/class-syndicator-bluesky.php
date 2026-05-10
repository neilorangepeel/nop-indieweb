<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Syndication;

class Syndicator_Bluesky extends Syndicator_Base {

	public function slug(): string  { return 'bluesky'; }
	public function label(): string { return 'Bluesky'; }

	private function handle(): string {
		return (string) \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.bluesky.handle', '' );
	}

	private function app_password(): string {
		return (string) \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.bluesky.app_password', '' );
	}

	private function pds(): string {
		return 'https://bsky.social';
	}

	protected function is_configured(): bool {
		return $this->handle() && $this->app_password();
	}

	protected function owns_url( string $url ): bool {
		return str_contains( $url, 'bsky.app' );
	}

	protected function do_syndicate( int $post_id ): ?string {
		$session = $this->create_session();
		if ( ! $session ) {
			return null;
		}

		$text    = $this->build_status_text( $post_id, 300 );
		$url     = get_permalink( $post_id );
		$facets  = $this->build_link_facets( $text, $url );

		$record = array_filter( [
			'$type'     => 'app.bsky.feed.post',
			'text'      => $text,
			'createdAt' => gmdate( 'c' ),
			'facets'    => $facets ?: null,
		] );

		$response = wp_remote_post(
			$this->pds() . '/xrpc/com.atproto.repo.createRecord',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $session['accessJwt'],
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( [
					'repo'       => $session['did'],
					'collection' => 'app.bsky.feed.post',
					'record'     => $record,
				] ),
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$uri  = $body['uri'] ?? null; // at://did:plc:.../app.bsky.feed.post/rkey

		if ( ! $uri ) {
			return null;
		}

		// Convert AT URI to bsky.app URL.
		$parts = explode( '/', $uri );
		$rkey  = end( $parts );
		$did   = $session['did'];
		return "https://bsky.app/profile/{$did}/post/{$rkey}";
	}

	private function create_session(): ?array {
		$response = wp_remote_post(
			$this->pds() . '/xrpc/com.atproto.server.createSession',
			[
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [
					'identifier' => $this->handle(),
					'password'   => $this->app_password(),
				] ),
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}

	private function build_link_facets( string $text, string $url ): array {
		$pos = mb_strpos( $text, $url );
		if ( false === $pos ) {
			return [];
		}

		// Bluesky facets use byte offsets, not character offsets.
		$byte_start = strlen( mb_substr( $text, 0, $pos ) );
		$byte_end   = $byte_start + strlen( $url );

		return [ [
			'index'    => [ 'byteStart' => $byte_start, 'byteEnd' => $byte_end ],
			'features' => [ [ '$type' => 'app.bsky.richtext.facet#link', 'uri' => $url ] ],
		] ];
	}
}
