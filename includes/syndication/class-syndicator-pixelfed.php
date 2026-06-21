<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Syndication;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Syndicator_Pixelfed extends Mastodon_Compatible_Syndicator {

	public function slug(): string  { return 'pixelfed'; }
	public function label(): string { return 'Pixelfed'; }

	/** Pixelfed Stories upload ceiling — the API caps it at 15000 KB; stay under. */
	private const STORY_MAX_BYTES = 14 * 1024 * 1024;

	/** Stories play for 15 s, so cap the encoded clip there (and size to fit). */
	private const STORY_MAX_SECONDS = 15;

	protected function char_limit(): int { return 2000; }

	// Pixelfed has two surfaces we use: the photo grid (timeline) and the 24h
	// Stories tray. Photo posts go to the grid; Story posts go to Stories.
	protected function supports_post( int $post_id ): bool {
		$kind = get_post_meta( $post_id, 'nop_indieweb_post_kind', true );
		return 'photo' === $kind || 'story' === $kind;
	}

	protected function do_syndicate( int $post_id ): string|\WP_Error {
		if ( 'story' === get_post_meta( $post_id, 'nop_indieweb_post_kind', true ) ) {
			return $this->syndicate_story( $post_id );
		}
		return parent::do_syndicate( $post_id );
	}

	/**
	 * Posts one photo or one short video into the Pixelfed Stories tray via the
	 * two-step stories API (add → publish). Unlike the grid path this creates no
	 * timeline status — a Story lives only in the ephemeral 24h tray.
	 */
	private function syndicate_story( int $post_id ): string|\WP_Error {
		$instance = $this->instance();
		$token    = $this->access_token();

		$media = $this->resolve_story_media( $post_id );
		if ( ! $media ) {
			return new \WP_Error( 'nop_syndication_failed', __( 'Story has no JPEG/PNG photo or MP4 video to syndicate.', 'nop-indieweb' ) );
		}

		$media_id = $this->story_add( $instance, $token, $media, $post_id );
		if ( is_wp_error( $media_id ) ) {
			return $media_id;
		}

		$published = $this->story_publish( $instance, $token, $media_id, $media['duration'], $post_id );
		if ( is_wp_error( $published ) ) {
			return $published;
		}

		// Stories carry no stable public permalink (the media_url expires in 24h),
		// so record the profile URL as the syndication marker — owns_url() matches
		// it by instance prefix, and it's where the live story actually appears.
		$profile = (string) \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.pixelfed.profile_url', '' );
		return $profile ?: ( $instance . '/' );
	}

	/**
	 * Resolves the single Story media item to upload. Pixelfed Stories accept
	 * JPEG/PNG or MP4 only; anything else (gif/webp/webm) yields null so the
	 * caller fails cleanly. Mirrors the grid path's video-first, then image,
	 * then nop_indieweb_photos-meta fallback (photos land in meta before the
	 * block is injected into post_content).
	 *
	 * @return array{data:string,mime:string,filename:string,duration:int}|null
	 */
	private function resolve_story_media( int $post_id ): ?array {
		$video = $this->collect_inline_video( $post_id );
		if ( $video ) {
			// Pixelfed Stories reject anything over ~14.6 MB (15000 KB), far below
			// Mastodon/Bluesky — so ask the transcoder for a story-sized derivative.
			$fetched = $this->fetch_upload_video( $video, self::STORY_MAX_BYTES, self::STORY_MAX_SECONDS );
			if ( $fetched && 'video/mp4' === $fetched['mime'] ) {
				return [ 'data' => $fetched['data'], 'mime' => 'video/mp4', 'filename' => 'story.mp4', 'duration' => 15 ];
			}
			return null;
		}

		$inline = $this->collect_inline_images( $post_id, 1 );
		$url    = $inline[0]['url'] ?? '';

		if ( ! $url ) {
			$cdn_photos = get_post_meta( $post_id, 'nop_indieweb_photos', true );
			if ( is_array( $cdn_photos ) ) {
				$filtered = array_values( array_filter( $cdn_photos ) );
				$url      = (string) ( $filtered[0] ?? '' );
			}
		}

		if ( $url ) {
			$fetched = $this->fetch_image( $url );
			if ( $fetched && in_array( $fetched['mime'], [ 'image/jpeg', 'image/png' ], true ) ) {
				$ext = 'image/png' === $fetched['mime'] ? 'png' : 'jpg';
				return [ 'data' => $fetched['data'], 'mime' => $fetched['mime'], 'filename' => "story.{$ext}", 'duration' => 10 ];
			}
		}

		return null;
	}

	/**
	 * POST /api/v1.1/stories/add — multipart file upload. Returns the new story's
	 * media_id, or a WP_Error so the manager retries.
	 *
	 * @param array{data:string,mime:string,filename:string,duration:int} $media
	 */
	private function story_add( string $instance, string $token, array $media, int $post_id ): string|\WP_Error {
		$boundary = wp_generate_password( 24, false );
		$body     = "--{$boundary}\r\n"
			. "Content-Disposition: form-data; name=\"file\"; filename=\"{$media['filename']}\"\r\n"
			. "Content-Type: {$media['mime']}\r\n\r\n"
			. $media['data'] . "\r\n"
			. "--{$boundary}\r\n"
			. "Content-Disposition: form-data; name=\"duration\"\r\n\r\n"
			. $media['duration'] . "\r\n"
			. "--{$boundary}--";

		$response = wp_remote_post(
			$instance . '/api/v1.1/stories/add',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => "multipart/form-data; boundary={$boundary}",
					'Accept'        => 'application/json',
				],
				'body'    => $body,
				'timeout' => 120,
			]
		);

		if ( is_wp_error( $response ) ) {
			\NOP\IndieWeb\nop_indieweb_log( "Pixelfed stories/add failed for post {$post_id}", [ 'code' => $response->get_error_message() ] );
			return new \WP_Error( 'nop_syndication_failed', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $data['media_id'] ) ) {
			\NOP\IndieWeb\nop_indieweb_log( "Pixelfed stories/add failed for post {$post_id}", [ 'code' => $code, 'body' => $data ] );
			return new \WP_Error( 'nop_syndication_failed', sprintf(
				/* translators: 1: HTTP status code, 2: error detail from the platform API */
				__( 'HTTP %1$d: %2$s', 'nop-indieweb' ),
				$code,
				$data['message'] ?? $data['error'] ?? __( 'story upload rejected', 'nop-indieweb' )
			) );
		}

		return (string) $data['media_id'];
	}

	/**
	 * POST /api/v1.1/stories/publish — flips the uploaded media live in the tray.
	 * Returns true, or a WP_Error so the manager retries.
	 */
	private function story_publish( string $instance, string $token, string $media_id, int $duration, int $post_id ): bool|\WP_Error {
		$response = wp_remote_post(
			$instance . '/api/v1.1/stories/publish',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				],
				'body'    => [
					'media_id'  => $media_id,
					'duration'  => $duration,
					'can_reply' => '1',
					'can_react' => '1',
				],
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			\NOP\IndieWeb\nop_indieweb_log( "Pixelfed stories/publish failed for post {$post_id}", [ 'code' => $response->get_error_message() ] );
			return new \WP_Error( 'nop_syndication_failed', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			\NOP\IndieWeb\nop_indieweb_log( "Pixelfed stories/publish failed for post {$post_id}", [ 'code' => $code, 'body' => $data ] );
			return new \WP_Error( 'nop_syndication_failed', sprintf(
				/* translators: 1: HTTP status code, 2: error detail from the platform API */
				__( 'HTTP %1$d: %2$s', 'nop-indieweb' ),
				$code,
				$data['message'] ?? $data['error'] ?? __( 'story publish rejected', 'nop-indieweb' )
			) );
		}

		return true;
	}
}
