<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Webmention;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps webmention authors' photos on this site.
 *
 * A remote avatar URL in the page makes every visitor's browser contact the
 * author's host (a Mastodon or Bluesky CDN, say), which tells that host who is
 * reading this site. So the photo is fetched once, by the server, saved under
 * uploads/nop-avatars/, and only the local copy is ever shown.
 */
class Avatar_Cache {

	private const DIR       = 'nop-avatars';
	private const MAX_BYTES = 1048576; // 1 MB.
	private const TYPES     = [
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
	];

	/**
	 * Returns the local URL for a remote avatar, fetching it on first use.
	 * Returns '' when it cannot be fetched, so the page shows the fallback icon
	 * rather than hotlinking.
	 */
	public static function cache( string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		if ( self::is_local( $url ) ) {
			return $url;
		}
		if ( ! preg_match( '#^https?://#i', $url ) || ! \NOP\IndieWeb\nop_indieweb_is_safe_url( $url ) ) {
			return '';
		}

		$upload = wp_upload_dir();
		$name   = hash( 'sha256', $url );
		foreach ( self::TYPES as $ext ) {
			if ( file_exists( "{$upload['basedir']}/" . self::DIR . "/{$name}.{$ext}" ) ) {
				return "{$upload['baseurl']}/" . self::DIR . "/{$name}.{$ext}";
			}
		}

		$response = \NOP\IndieWeb\nop_indieweb_strict_remote_get( $url, [ 'timeout' => 10, 'limit_response_size' => self::MAX_BYTES ] );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}
		$body = wp_remote_retrieve_body( $response );
		$type = strtolower( trim( explode( ';', (string) wp_remote_retrieve_header( $response, 'content-type' ) )[0] ) );
		if ( '' === $body || strlen( $body ) > self::MAX_BYTES || ! isset( self::TYPES[ $type ] ) ) {
			return '';
		}

		$dir = $upload['basedir'] . '/' . self::DIR;
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		$file = "{$dir}/{$name}." . self::TYPES[ $type ];
		if ( false === file_put_contents( $file, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- small cache write.
			return '';
		}
		return "{$upload['baseurl']}/" . self::DIR . '/' . basename( $file );
	}

	public static function is_local( string $url ): bool {
		return str_starts_with( $url, wp_upload_dir()['baseurl'] . '/' . self::DIR . '/' );
	}

	/** Deletes a cached copy, used when someone asks for their data to be erased. */
	public static function delete( string $url ): void {
		if ( self::is_local( $url ) ) {
			wp_delete_file( wp_upload_dir()['basedir'] . '/' . self::DIR . '/' . basename( $url ) );
		}
	}
}
