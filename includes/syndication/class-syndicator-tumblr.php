<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Syndication;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Syndicates posts to Tumblr via the Neue Post Format (NPF). Tumblr is blog-like,
 * so it takes every kind that has a natural mapping — text/photo/link/quote —
 * skipping only the reaction kinds (like/rsvp/listen) that have no Tumblr form.
 * The OAuth2 token plumbing and the NPF assembly live in Tumblr_Client.
 */
class Syndicator_Tumblr extends Syndicator_Base {

	public function slug(): string  { return 'tumblr'; }
	public function label(): string { return 'Tumblr'; }

	protected function is_configured(): bool {
		$opt = fn( string $k ): string => (string) \NOP\IndieWeb\nop_indieweb_get_option( "syndicators.tumblr.{$k}", '' );
		return '' !== $opt( 'consumer_key' )
			&& '' !== $opt( 'consumer_secret' )
			&& '' !== $opt( 'refresh_token' )
			&& '' !== $opt( 'blog_identifier' );
	}

	// Reaction kinds have no NPF equivalent worth posting; everything else maps to
	// a text/photo/link/quote post.
	protected function supports_post( int $post_id ): bool {
		$kind = (string) get_post_meta( $post_id, 'nop_indieweb_post_kind', true );
		return ! in_array( $kind, [ 'like', 'rsvp', 'listen' ], true );
	}

	protected function owns_url( string $url ): bool {
		$host = (string) parse_url( $url, PHP_URL_HOST );
		if ( '' === $host ) {
			return false;
		}
		$blog = (string) \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.tumblr.blog_identifier', '' );
		return ( '' !== $blog && str_contains( $host, $blog ) )
			|| str_ends_with( $host, '.tumblr.com' )
			|| 'tmblr.co' === $host;
	}

	protected function do_syndicate( int $post_id ): string|\WP_Error {
		$kind = (string) get_post_meta( $post_id, 'nop_indieweb_post_kind', true );
		$text = $this->build_full_text( $post_id );

		// Inline images, then the photo-meta fallback (photo blocks aren't injected
		// into post_content yet when syndication fires — same race the others hit).
		$images = $this->collect_inline_images( $post_id, 10 );
		if ( ! $images ) {
			$cdn = get_post_meta( $post_id, 'nop_indieweb_photos', true );
			if ( is_array( $cdn ) ) {
				foreach ( array_filter( $cdn ) as $url ) {
					$images[] = [ 'url' => (string) $url, 'alt' => '' ];
				}
			}
		}

		$tags = wp_get_post_tags( $post_id, [ 'fields' => 'names' ] );
		$ctx  = [
			'permalink'    => (string) get_permalink( $post_id ),
			'tags'         => is_array( $tags ) ? $tags : [],
			'cite'         => (string) get_post_meta( $post_id, 'nop_indieweb_cite', true ),
			'target_url'   => $this->target_url( $post_id, $kind ),
			'target_title' => (string) get_post_meta( $post_id, 'nop_indieweb_cite_title', true ),
		];

		$npf    = Tumblr_Client::build_npf( $text, $images, $kind, $ctx );
		$result = ( new Tumblr_Client() )->create_post( $npf, $images );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( empty( $result['url'] ) ) {
			return new \WP_Error( 'nop_syndication_failed', __( 'Tumblr accepted the post but returned no URL.', 'nop-indieweb' ) );
		}
		return (string) $result['url'];
	}

	/** The target URL a reply/bookmark/repost points at, from its kind meta. */
	private function target_url( int $post_id, string $kind ): string {
		$meta = [
			'reply'    => 'nop_indieweb_in_reply_to',
			'bookmark' => 'nop_indieweb_bookmark_of',
			'repost'   => 'nop_indieweb_repost_of',
		][ $kind ] ?? '';
		return '' === $meta ? '' : (string) get_post_meta( $post_id, $meta, true );
	}

	public function test_connection(): array {
		if ( ! $this->is_configured() ) {
			return [ 'ok' => false, 'message' => __( 'Not connected — click Connect Tumblr.', 'nop-indieweb' ) ];
		}
		return ( new Tumblr_Client() )->verify();
	}
}
