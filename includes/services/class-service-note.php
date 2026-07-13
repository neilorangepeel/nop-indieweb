<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Services;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles generic h-entry Micropub posts — notes, short-form content, and
 * posts arriving via Bridgy from Mastodon or Bluesky (PESOS direction).
 *
 * Platform detection: source URL is matched against the configured Mastodon
 * instance and known Bluesky domains. Matched platforms use their own tab's
 * inbound settings; unknown sources fall back to the Entries catch-all.
 */
class Note extends Service_Base {

	public function get_name(): string { return 'Entries'; }
	public function get_slug(): string { return 'entries'; }

	/**
	 * Matches any h-entry that isn't a Swarm checkin.
	 * Swarm is registered first in the services array so it gets priority.
	 */
	public function can_handle( array $payload ): bool {
		if ( ! in_array( 'h-entry', $payload['type'] ?? [], true ) ) {
			return false;
		}
		$checkin = $payload['properties']['checkin'][0] ?? null;
		return ! ( is_array( $checkin ) && in_array( 'h-card', $checkin['type'] ?? [], true ) );
	}

	protected function get_dedup_key( array $parsed ): ?string {
		return $parsed['source_url'] ?: null;
	}

	protected function get_dedup_meta_key(): string {
		return 'nop_indieweb_source_url';
	}

	public function parse( array $payload ): array {
		$props = $payload['properties'] ?? [];

		$content_parts = \NOP\IndieWeb\nop_indieweb_micropub_content_parts( $props['content'][0] ?? '' );
		$content       = $content_parts['plain'];

		$syndication = array_map(
			'esc_url_raw',
			array_values( array_filter( (array) ( $props['syndication'] ?? [] ) ) )
		);

		$source_url = esc_url_raw( $props['url'][0] ?? $syndication[0] ?? '' );

		$content_trimmed = sanitize_textarea_field( $content );
		$name            = sanitize_text_field( $props['name'][0] ?? '' );
		// Micropub spec: name present and distinct from content = article.
		$is_article = $name !== '' && $name !== $content_trimmed;

		$tags = array_values( array_filter( array_map(
			'sanitize_text_field',
			(array) ( $props['category'] ?? [] )
		) ) );

		return [
			'content'      => $content_trimmed,
			'content_html' => $content_parts['html'],
			'name'        => $is_article ? $name : '',
			'is_article'  => $is_article,
			'kind'        => sanitize_key( $props['post-kind'][0] ?? '' ),
			'published'   => sanitize_text_field( $props['published'][0] ?? '' ),
			'source_url'  => $source_url,
			'platform'    => $this->detect_platform( $source_url ),
			'syndication' => $syndication,
			'tags'        => $tags,
			'categories'  => $this->categories_from_props( $props ),
			// Photo/video entries may be a plain URL string (Micropub spec) or
			// an array with { primary, fallback, size } shape from the Bluesky
			// importer. sideload_photos/sideload_videos handle both shapes.
			'photos'      => array_values( array_filter( (array) ( $props['photo'] ?? [] ) ) ),
			'videos'      => array_values( array_filter( (array) ( $props['video'] ?? [] ) ) ),
			'raw_payload' => $payload,
		];
	}

	public function map_to_post( array $parsed ): array {
		$settings                    = $this->get_inbound_settings( $parsed['platform'] );
		[ $post_date, $post_date_gmt ] = $this->parse_post_date( $parsed['published'], true );

		$content = trim( $parsed['content'] );
		$html    = trim( (string) ( $parsed['content_html'] ?? '' ) );
		// Prefer the sanitised HTML (bold/italic from the composer) so the blog
		// renders real formatting; socials strip it back to plain text on their own.
		$inner   = '' !== $html ? $html : ( '' !== $content ? wp_kses_post( $content ) : '' );
		$blocks  = '' !== $inner
			? "<!-- wp:paragraph -->\n<p>" . $inner . "</p>\n<!-- /wp:paragraph -->"
			: '';

		$category_ids = $this->resolve_category_ids( $parsed['categories'] ?? null, $settings['post_category'] ?? '' );
		$tags         = array_unique( array_merge(
			$this->tags_from_setting( $settings['post_tags'] ?? '' ),
			$parsed['tags'] ?? [],
		) );

		$args = [
			'post_title'   => $parsed['is_article'] ? $parsed['name'] : $this->generate_title( $content, $post_date ),
			'post_content' => $blocks,
			'post_status'  => $settings['post_status'] ?? 'publish',
			'post_type'    => 'post',
		];

		if ( $tags ) {
			$args['tags_input'] = array_values( $tags );
		}
		if ( $category_ids ) {
			$args['post_category'] = $category_ids;
		}
		if ( $post_date ) {
			$args['post_date']     = $post_date;
			$args['post_date_gmt'] = $post_date_gmt;
		}

		return $args;
	}

	public function get_kind( array $parsed = [] ): string {
		$explicit = $parsed['kind'] ?? '';
		if ( in_array( $explicit, [ 'photo', 'video', 'story', 'note', 'article' ], true ) ) {
			return $explicit;
		}
		return ! empty( $parsed['is_article'] ) ? 'article' : 'note';
	}

	public function get_meta( array $parsed ): array {
		return [
			'nop_indieweb_platform'    => $parsed['platform'],
			'nop_indieweb_source_url'  => $parsed['source_url'],
			'nop_indieweb_syndication' => $parsed['syndication'],
			'nop_indieweb_photos'      => $this->media_urls_for_meta( $parsed['photos'] ),
			'nop_indieweb_photo_alts'  => $this->media_alts_for_meta( $parsed['photos'] ),
			'nop_indieweb_videos'      => $this->media_urls_for_meta( $parsed['videos'] ),
			'nop_indieweb_raw_payload' => wp_json_encode( $parsed['raw_payload'] ),
		];
	}

	/**
	 * Flattens media entries to URL strings for meta storage. Plain strings pass
	 * through; array entries use the primary URL (or fallback when primary is
	 * missing). Empty entries are dropped.
	 */
	private function media_urls_for_meta( array $entries ): array {
		$urls = [];
		foreach ( $entries as $entry ) {
			if ( is_string( $entry ) ) {
				$url = esc_url_raw( $entry );
			} elseif ( is_array( $entry ) ) {
				$url = esc_url_raw( (string) ( $entry['primary'] ?? $entry['fallback'] ?? '' ) );
			} else {
				$url = '';
			}
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}
		return $urls;
	}

	/**
	 * Alt strings aligned 1:1 with media_urls_for_meta() — same entries, same skip
	 * rule (drop anything with no URL), so nop_indieweb_photos[i] ↔ photo_alts[i].
	 * Lets syndicators recover alt text during the after_insert race window, when
	 * the photo blocks (which normally carry the alt) aren't in post_content yet.
	 */
	private function media_alts_for_meta( array $entries ): array {
		$alts = [];
		foreach ( $entries as $entry ) {
			if ( is_string( $entry ) ) {
				$url = esc_url_raw( $entry );
				$alt = '';
			} elseif ( is_array( $entry ) ) {
				$url = esc_url_raw( (string) ( $entry['primary'] ?? $entry['fallback'] ?? '' ) );
				$alt = (string) ( $entry['alt'] ?? '' );
			} else {
				$url = '';
				$alt = '';
			}
			if ( '' !== $url ) {
				$alts[] = sanitize_text_field( $alt );
			}
		}
		return $alts;
	}

	protected function after_insert( int $post_id, array $parsed ): void {
		$settings         = $this->get_inbound_settings( $parsed['platform'] );
		$sideload_enabled = ! empty( $settings['sideload_photos'] );

		$photo_ids = [];
		if ( $parsed['photos'] && $sideload_enabled ) {
			$photo_ids = $this->sideload_photos( $parsed['photos'], $post_id );
			if ( $photo_ids ) {
				update_post_meta( $post_id, 'nop_indieweb_photo_ids', $photo_ids );
			}
		}

		$video_ids = [];
		// NOP: needs review — video sideloading is gated on the *photos* setting,
		// so enabling photos silently also enables (potentially large) video
		// downloads, with no way to enable one without the other. Decide whether
		// to add a dedicated `sideload_videos` setting.
		if ( $parsed['videos'] && $sideload_enabled ) {
			$video_ids = $this->sideload_videos( $parsed['videos'], $post_id );
			if ( $video_ids ) {
				update_post_meta( $post_id, 'nop_indieweb_video_ids', $video_ids );
			}
		}

		// When sideloading is enabled but all downloads fail, write nothing —
		// the post stays photo-free and `wp nop-indieweb repair-photo-sideloads`
		// can retry. Only fall back to remote URLs when sideloading is
		// intentionally off (the hotlink is then a deliberate choice, not a
		// transient failure left permanently in the content).
		$fallback_urls = $sideload_enabled ? [] : $this->media_urls_for_meta( $parsed['photos'] );

		$photo_blocks = $parsed['photos']
			? $this->build_photo_blocks( $photo_ids, $fallback_urls )
			: '';
		$video_blocks = $video_ids ? $this->build_video_blocks( $video_ids ) : '';

		$append = trim( implode( "\n\n", array_filter( [ $photo_blocks, $video_blocks ] ) ) );
		if ( '' === $append ) {
			return;
		}

		$post = get_post( $post_id );
		wp_update_post( [
			'ID'           => $post_id,
			'post_content' => rtrim( $post->post_content ) . "\n\n" . $append,
		] );
	}

	/**
	 * Re-sideloads photos for a post where sideloading previously failed.
	 *
	 * Reads nop_indieweb_photos (stored remote URLs), downloads and attaches
	 * them, writes nop_indieweb_photo_ids, strips any remote-URL image blocks
	 * previously written as fallback, and appends proper local wp:image blocks.
	 *
	 * Called by `wp nop-indieweb repair-photo-sideloads`.
	 */
	public function repair_photo_sideloads( int $post_id ): bool {
		$photos = get_post_meta( $post_id, 'nop_indieweb_photos', true );
		if ( ! is_array( $photos ) || ! $photos ) {
			return false;
		}

		$ids = $this->sideload_photos( $photos, $post_id );
		if ( ! $ids ) {
			return false;
		}

		update_post_meta( $post_id, 'nop_indieweb_photo_ids', $ids );

		$blocks = $this->build_photo_blocks( $ids, [] );
		if ( ! $blocks ) {
			return true;
		}

		$post    = get_post( $post_id );
		$content = $this->strip_remote_image_blocks( (string) $post->post_content );

		wp_update_post( [
			'ID'           => $post_id,
			'post_content' => rtrim( $content ) . "\n\n" . $blocks,
		] );

		return true;
	}

	/**
	 * Strips <!-- wp:image --> blocks in the no-id/remote-URL form (no JSON
	 * between "wp:image" and "-->") and any empty gallery wrappers left behind.
	 * Local image blocks ("wp:image {"id":N,...}") are not touched.
	 */
	private function strip_remote_image_blocks( string $content ): string {
		$content = (string) preg_replace(
			'/\n*<!--\s*wp:image\s*-->\n<figure[^>]*>.*?<\/figure>\n<!--\s*\/wp:image\s*-->/s',
			'',
			$content
		);
		$content = (string) preg_replace(
			'/\n*<!--\s*wp:gallery[^>]*-->\n<figure[^>]*>\s*<\/figure>\n<!--\s*\/wp:gallery\s*-->/s',
			'',
			$content
		);
		return $content;
	}

	/**
	 * Identifies which platform a source URL belongs to so inbound settings
	 * can be read from that platform's tab rather than the Entries catch-all.
	 */
	private function detect_platform( string $source_url ): string {
		if ( ! $source_url ) {
			return 'entries';
		}

		if ( str_contains( $source_url, 'bsky.app' ) || str_contains( $source_url, 'bsky.social' ) ) {
			return 'bluesky';
		}

		$mastodon_instance = rtrim( (string) \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.mastodon.instance', '' ), '/' );
		if ( $mastodon_instance && str_starts_with( $source_url, $mastodon_instance ) ) {
			return 'mastodon';
		}

		$pixelfed_instance = rtrim( (string) \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.pixelfed.instance', '' ), '/' );
		if ( $pixelfed_instance && str_starts_with( $source_url, $pixelfed_instance ) ) {
			return 'pixelfed';
		}

		if ( str_contains( $source_url, '.tumblr.com' ) || str_contains( $source_url, 'tmblr.co' ) ) {
			return 'tumblr';
		}
		$tumblr_blog = (string) \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.tumblr.blog_identifier', '' );
		if ( '' !== $tumblr_blog && str_contains( $source_url, $tumblr_blog ) ) {
			return 'tumblr';
		}

		return 'entries';
	}

	/**
	 * Returns inbound post settings for the detected platform.
	 * Mastodon and Bluesky read from their own syndicator config;
	 * anything else falls back to the Entries service settings.
	 */
	private function get_inbound_settings( string $platform ): array {
		if ( in_array( $platform, [ 'mastodon', 'bluesky', 'pixelfed', 'tumblr' ], true ) ) {
			$settings = \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators', [] )[ $platform ] ?? [];
			// Imported posts default to sideloading their media — owning the copy
			// is the point of PESOS, and the platform sections have no UI toggle
			// for this. Absence of the key means "yes", not "no".
			$settings['sideload_photos'] = $settings['sideload_photos'] ?? true;
			return $settings;
		}
		return $this->get_settings();
	}

	private function generate_title( string $content, string $post_date ): string {
		$text  = preg_replace( '/<!--.*?-->/s', '', $content );
		$text  = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
		$words = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );

		$url_words  = array_filter( $words, fn( $w ) => str_starts_with( $w, 'http' ) );
		$text_words = array_values( array_filter( $words, fn( $w ) => ! str_starts_with( $w, 'http' ) ) );

		if ( empty( $text_words ) && ! empty( $url_words ) ) {
			return wp_parse_url( reset( $url_words ), PHP_URL_HOST ) ?: reset( $url_words );
		}

		$pool = $text_words ?: $words;
		if ( $pool ) {
			return implode( ' ', array_slice( $pool, 0, 6 ) ) . ( count( $pool ) > 6 ? '…' : '' );
		}

		return $post_date ? wp_date( 'j M Y', strtotime( $post_date ) ) : wp_date( 'j M Y' );
	}

}
