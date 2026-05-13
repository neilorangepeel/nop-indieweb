<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Syndication;

abstract class Syndicator_Base {

	abstract public function slug(): string;

	abstract public function label(): string;

	abstract protected function is_configured(): bool;

	abstract protected function do_syndicate( int $post_id ): ?string;

	abstract protected function owns_url( string $url ): bool;

	public function matches_url( string $url ): bool {
		return $this->owns_url( $url );
	}

	public function enabled(): bool {
		return (bool) \NOP\IndieWeb\nop_indieweb_get_option( "syndicators.{$this->slug()}.enabled", false );
	}

	public function syndicate( int $post_id ): void {
		if ( ! $this->enabled() || ! $this->is_configured() ) {
			return;
		}

		// Dedup — skip if this platform already has a syndication URL on this post.
		$existing = get_post_meta( $post_id, 'nop_indieweb_syndication', true );
		$existing = is_array( $existing ) ? $existing : [];

		foreach ( $existing as $url ) {
			if ( $this->owns_url( $url ) ) {
				return;
			}
		}

		$url = $this->do_syndicate( $post_id );

		if ( $url ) {
			$existing[] = $url;
			update_post_meta( $post_id, 'nop_indieweb_syndication', $existing );
		}
	}

	protected function build_status_text( int $post_id, int $limit ): string {
		$post       = get_post( $post_id );
		$venue_name = get_post_meta( $post_id, 'nop_indieweb_venue_name', true );
		$permalink  = get_permalink( $post_id );

		if ( $venue_name ) {
			$text = sprintf( 'Checked in at %s', $venue_name );
		} elseif ( $post->post_title ) {
			$text = $post->post_title;
		} else {
			$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 );
			$text    = $excerpt ?: '';
		}

		// Reserve space for the URL + newlines.
		$url_part   = "\n\n" . $permalink;
		$max_text   = $limit - mb_strlen( $url_part );
		if ( mb_strlen( $text ) > $max_text ) {
			$text = mb_substr( $text, 0, $max_text - 1 ) . '…';
		}

		return $text . $url_part;
	}

	/**
	 * Uploads the post's featured image to a Mastodon-compatible /api/v2/media endpoint.
	 * Returns the media attachment ID string, or null if no thumbnail or upload fails.
	 */
	protected function upload_featured_image( int $post_id, string $instance, string $token ): ?string {
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( ! $thumbnail_id ) {
			return null;
		}

		$image = wp_get_attachment_image_src( $thumbnail_id, 'large' );
		if ( ! $image ) {
			return null;
		}

		$file_data = $this->fetch_image( $image[0] );
		if ( ! $file_data ) {
			return null;
		}

		$boundary = wp_generate_password( 24, false );
		$body     = "--{$boundary}\r\n"
			. "Content-Disposition: form-data; name=\"file\"; filename=\"image\"\r\n"
			. "Content-Type: {$file_data['mime']}\r\n\r\n"
			. $file_data['data'] . "\r\n"
			. "--{$boundary}--";

		$response = wp_remote_post(
			rtrim( $instance, '/' ) . '/api/v2/media',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
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

		// 200 = ready immediately, 202 = processing (still usable for the status).
		if ( in_array( $code, [ 200, 202 ], true ) && isset( $result['id'] ) ) {
			return (string) $result['id'];
		}

		return null;
	}

	protected function fetch_image( string $url ): ?array {
		$response = wp_remote_get( $url, [ 'timeout' => 15 ] );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$mime = strtok( wp_remote_retrieve_header( $response, 'content-type' ), ';' );

		if ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ], true ) ) {
			return null;
		}

		return [
			'mime' => $mime,
			'data' => wp_remote_retrieve_body( $response ),
		];
	}
}
