<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Semantic;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emits Open Graph + fediverse:creator meta tags on singular posts.
 *
 * These tags control how the post renders when its URL is unfurled by
 * Mastodon, Bluesky chat, Slack, etc. Mastodon in particular builds its
 * link-preview card entirely from these tags, and shows a "More from
 * <author>" follow byline when fediverse:creator is present (Mastodon 4.3+).
 *
 * Image priority: featured image → first inline photo → check-in map →
 * site icon. For a check-in without a photo this resolves to the map, which
 * becomes the card's hero image.
 *
 * Output can be disabled with the nop_indieweb_output_open_graph filter —
 * useful if an SEO plugin (Yoast etc.) takes over Open Graph output.
 */
class Open_Graph {

	public function register(): void {
		add_action( 'wp_head', [ $this, 'output' ], 5 );
	}

	public function output(): void {
		if ( ! is_singular( 'post' ) ) {
			return;
		}
		if ( ! apply_filters( 'nop_indieweb_output_open_graph', true ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		$title       = $this->title( $post_id );
		$description = $this->description( $post_id );
		$image       = $this->image( $post_id );
		$url         = (string) get_permalink( $post_id );
		$site_name   = (string) get_bloginfo( 'name' );

		$tags = [
			[ 'property', 'og:type',        'article' ],
			[ 'property', 'og:title',       $title ],
			[ 'property', 'og:url',         $url ],
			[ 'property', 'og:site_name',   $site_name ],
		];
		if ( '' !== $description ) {
			$tags[] = [ 'property', 'og:description', $description ];
		}
		if ( '' !== $image ) {
			$tags[] = [ 'property', 'og:image',  $image ];
			$tags[] = [ 'name',     'twitter:card', 'summary_large_image' ];
		} else {
			$tags[] = [ 'name', 'twitter:card', 'summary' ];
		}

		$creator = $this->fediverse_creator();
		if ( '' !== $creator ) {
			$tags[] = [ 'name', 'fediverse:creator', $creator ];
		}

		$out = '';
		foreach ( $tags as [ $attr, $key, $value ] ) {
			$out .= sprintf(
				"<meta %s=\"%s\" content=\"%s\">\n",
				esc_attr( $attr ),
				esc_attr( $key ),
				esc_attr( $value )
			);
		}
		// Already escaped per-attribute above.
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function title( int $post_id ): string {
		$title = trim( wp_strip_all_tags( (string) get_the_title( $post_id ) ) );
		return '' !== $title ? $title : (string) get_bloginfo( 'name' );
	}

	private function description( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$text = \NOP\IndieWeb\nop_indieweb_block_text( (string) $post->post_content );
		$text = trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $text ) {
			$text = trim( (string) get_the_excerpt( $post ) );
		}
		return '' !== $text ? wp_trim_words( $text, 40, '…' ) : '';
	}

	/**
	 * Image priority: featured image → first inline photo → check-in map →
	 * site icon. Returns '' when none resolve.
	 */
	private function image( int $post_id ): string {
		$featured = get_the_post_thumbnail_url( $post_id, 'large' );
		if ( $featured ) {
			return (string) $featured;
		}

		$post = get_post( $post_id );
		if ( $post ) {
			$images = \NOP\IndieWeb\nop_indieweb_block_images( (string) $post->post_content, 1 );
			if ( ! empty( $images[0]['url'] ) ) {
				return (string) $images[0]['url'];
			}
		}

		$map = (string) get_post_meta( $post_id, 'nop_indieweb_map_url', true );
		if ( '' !== $map ) {
			return $map;
		}

		return (string) get_site_icon_url( 512 );
	}

	/**
	 * Builds @handle@instance from the cached Mastodon account, or '' when
	 * Mastodon isn't configured / the handle hasn't been cached yet (caching
	 * happens on a successful connection test).
	 */
	private function fediverse_creator(): string {
		$acct     = (string) \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.mastodon.acct', '' );
		$instance = (string) \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.mastodon.instance', '' );
		if ( '' === $acct || '' === $instance ) {
			return '';
		}
		$host = (string) wp_parse_url( $instance, PHP_URL_HOST );
		if ( '' === $host ) {
			return '';
		}
		return '@' . $acct . '@' . $host;
	}
}
