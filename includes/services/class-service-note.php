<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Services;

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

		$content_raw = $props['content'][0] ?? '';
		$content     = is_array( $content_raw )
			? ( $content_raw['value'] ?? '' )
			: (string) $content_raw;

		$syndication = array_map(
			'esc_url_raw',
			array_values( array_filter( (array) ( $props['syndication'] ?? [] ) ) )
		);

		$source_url = esc_url_raw( $props['url'][0] ?? $syndication[0] ?? '' );

		$content_trimmed = sanitize_textarea_field( $content );
		$name            = sanitize_text_field( $props['name'][0] ?? '' );
		// Micropub spec: name present and distinct from content = article.
		$is_article = $name !== '' && $name !== $content_trimmed;

		return [
			'content'     => $content_trimmed,
			'name'        => $is_article ? $name : '',
			'is_article'  => $is_article,
			'published'   => sanitize_text_field( $props['published'][0] ?? '' ),
			'source_url'  => $source_url,
			'platform'    => $this->detect_platform( $source_url ),
			'syndication' => $syndication,
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
		$blocks  = $content
			? "<!-- wp:paragraph -->\n<p>" . wp_kses_post( $content ) . "</p>\n<!-- /wp:paragraph -->"
			: '';

		$category_ids = $this->category_ids_from_setting( $settings['post_category'] ?? '' );
		$tags         = $this->tags_from_setting( $settings['post_tags'] ?? '' );

		$args = [
			'post_title'   => $parsed['is_article'] ? $parsed['name'] : $this->generate_title( $content, $post_date ),
			'post_content' => $blocks,
			'post_status'  => $settings['post_status'] ?? 'publish',
			'post_type'    => 'post',
		];

		if ( $tags ) {
			$args['tags_input'] = $tags;
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
		return ! empty( $parsed['is_article'] ) ? 'article' : 'note';
	}

	public function get_meta( array $parsed ): array {
		return [
			'nop_indieweb_platform'    => $parsed['platform'],
			'nop_indieweb_source_url'  => $parsed['source_url'],
			'nop_indieweb_syndication' => $parsed['syndication'],
			'nop_indieweb_photos'      => $this->media_urls_for_meta( $parsed['photos'] ),
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

	protected function after_insert( int $post_id, array $parsed ): void {
		$settings = $this->get_inbound_settings( $parsed['platform'] );

		$photo_ids = [];
		if ( $parsed['photos'] && ! empty( $settings['sideload_photos'] ) ) {
			$photo_ids = $this->sideload_photos( $parsed['photos'], $post_id );
			if ( $photo_ids ) {
				update_post_meta( $post_id, 'nop_indieweb_photo_ids', $photo_ids );
			}
		}

		$video_ids = [];
		if ( $parsed['videos'] && ! empty( $settings['sideload_photos'] ) ) {
			$video_ids = $this->sideload_videos( $parsed['videos'], $post_id );
			if ( $video_ids ) {
				update_post_meta( $post_id, 'nop_indieweb_video_ids', $video_ids );
			}
		}

		$photo_blocks = $parsed['photos']
			? $this->build_photo_blocks( $photo_ids, $photo_ids ? [] : $this->media_urls_for_meta( $parsed['photos'] ) )
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

		return 'entries';
	}

	/**
	 * Returns inbound post settings for the detected platform.
	 * Mastodon and Bluesky read from their own syndicator config;
	 * anything else falls back to the Entries service settings.
	 */
	private function get_inbound_settings( string $platform ): array {
		if ( in_array( $platform, [ 'mastodon', 'bluesky', 'pixelfed' ], true ) ) {
			return \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators', [] )[ $platform ] ?? [];
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
