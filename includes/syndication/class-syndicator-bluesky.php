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
		$embed   = $this->build_link_card( $post_id, $url, $session );

		$record = [
			'$type'     => 'app.bsky.feed.post',
			'text'      => $text,
			'createdAt' => gmdate( 'c' ),
		];

		if ( $facets ) {
			$record['facets'] = $facets;
		}

		if ( $embed ) {
			$record['embed'] = $embed;
		}

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
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$uri  = $body['uri'] ?? null;

		if ( ! $uri ) {
			return null;
		}

		$parts = explode( '/', $uri );
		$rkey  = end( $parts );
		return "https://bsky.app/profile/{$session['did']}/post/{$rkey}";
	}

	private function build_link_card( int $post_id, string $url, array $session ): ?array {
		$post        = get_post( $post_id );
		$title       = $post->post_title ?: '';
		$description = get_the_excerpt( $post );

		if ( ! $title ) {
			return null;
		}

		$external = [
			'uri'         => $url,
			'title'       => $title,
			'description' => $description ?: '',
		];

		// Upload featured image as thumb blob.
		$thumb = $this->upload_thumb( $post_id, $session );
		if ( $thumb ) {
			$external['thumb'] = $thumb;
		}

		return [
			'$type'    => 'app.bsky.embed.external',
			'external' => $external,
		];
	}

	private function upload_thumb( int $post_id, array $session ): ?array {
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( ! $thumbnail_id ) {
			return null;
		}

		$image = wp_get_attachment_image_src( $thumbnail_id, 'medium' );
		if ( ! $image ) {
			return null;
		}

		$response = wp_remote_get( $image[0], [ 'timeout' => 15 ] );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$mime = strtok( wp_remote_retrieve_header( $response, 'content-type' ), ';' );
		if ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ], true ) ) {
			return null;
		}

		$image_data = wp_remote_retrieve_body( $response );

		// Bluesky blob limit is 1MB.
		if ( strlen( $image_data ) > 1_000_000 ) {
			return null;
		}

		$upload = wp_remote_post(
			$this->pds() . '/xrpc/com.atproto.repo.uploadBlob',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $session['accessJwt'],
					'Content-Type'  => $mime,
				],
				'body'    => $image_data,
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $upload ) || 200 !== wp_remote_retrieve_response_code( $upload ) ) {
			return null;
		}

		$blob = json_decode( wp_remote_retrieve_body( $upload ), true );
		return $blob['blob'] ?? null;
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

		$byte_start = strlen( mb_substr( $text, 0, $pos ) );
		$byte_end   = $byte_start + strlen( $url );

		return [ [
			'index'    => [ 'byteStart' => $byte_start, 'byteEnd' => $byte_end ],
			'features' => [ [ '$type' => 'app.bsky.richtext.facet#link', 'uri' => $url ] ],
		] ];
	}
}
