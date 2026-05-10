<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Services;

/**
 * Handles generic h-entry Micropub posts — notes, short-form content, and
 * posts arriving via Bridgy from Mastodon or Bluesky (PESOS direction).
 *
 * Bridgy payload shape:
 *   properties.url[0]         → canonical URL on the remote platform
 *   properties.content[0]     → post content (string or {'html':…,'value':…})
 *   properties.published[0]   → ISO 8601 timestamp
 *   properties.syndication[]  → syndication URLs (usually the same as url[0])
 *   properties.photo[]        → photo URLs (optional)
 */
class Note extends Service_Base {

	public function get_name(): string { return 'Note'; }
	public function get_slug(): string { return 'note'; }

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

		return [
			'content'     => sanitize_textarea_field( $content ),
			'published'   => sanitize_text_field( $props['published'][0] ?? '' ),
			'source_url'  => $source_url,
			'syndication' => $syndication,
			'photos'      => array_map(
				'esc_url_raw',
				array_values( array_filter( (array) ( $props['photo'] ?? [] ) ) )
			),
			'raw_payload' => $payload,
		];
	}

	public function map_to_post( array $parsed ): array {
		$settings    = $this->get_settings();
		$post_status = $settings['post_status'] ?? 'publish';

		$post_date     = '';
		$post_date_gmt = '';
		if ( $parsed['published'] ) {
			$timestamp = strtotime( $parsed['published'] );
			if ( $timestamp && $timestamp <= ( time() + 60 ) ) {
				$post_date     = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ) );
				$post_date_gmt = gmdate( 'Y-m-d H:i:s', $timestamp );
			}
		}

		$content = trim( $parsed['content'] );
		$blocks  = $content
			? "<!-- wp:paragraph -->\n<p>" . wp_kses_post( $content ) . "</p>\n<!-- /wp:paragraph -->"
			: '';

		$category_names = array_filter( array_map( 'trim', explode( ',', $settings['post_category'] ?? 'Notes' ) ) );
		$category_ids   = array_values( array_filter( array_map( [ $this, 'ensure_category' ], $category_names ) ) );

		$tags = array_filter( array_map( 'trim', explode( ',', $settings['post_tags'] ?? '' ) ) );

		$args = [
			'post_title'   => '',
			'post_content' => $blocks,
			'post_status'  => $post_status,
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

	public function get_meta( array $parsed ): array {
		return [
			'nop_indieweb_post_kind'   => 'note',
			'nop_indieweb_source_url'  => $parsed['source_url'],
			'nop_indieweb_syndication' => $parsed['syndication'],
			'nop_indieweb_photos'      => $parsed['photos'],
			'nop_indieweb_raw_payload' => wp_json_encode( $parsed['raw_payload'] ),
		];
	}

	public function get_post_format( array $parsed ): string {
		return 'status';
	}

	protected function after_insert( int $post_id, array $parsed ): void {
		if ( ! $parsed['photos'] ) {
			return;
		}

		$ids = $this->sideload_photos( $parsed['photos'], $post_id );
		if ( $ids ) {
			update_post_meta( $post_id, 'nop_indieweb_photo_ids', $ids );
		}

		$photo_blocks = $this->build_photo_blocks( $ids, $ids ? [] : $parsed['photos'] );
		if ( $photo_blocks ) {
			$post = get_post( $post_id );
			wp_update_post( [
				'ID'           => $post_id,
				'post_content' => rtrim( $post->post_content ) . "\n\n" . $photo_blocks,
			] );
		}
	}

	private function ensure_category( string $name ): int {
		$slug = sanitize_title( $name );
		$term = get_term_by( 'slug', $slug, 'category' );
		if ( $term instanceof \WP_Term ) {
			return $term->term_id;
		}
		$result = wp_insert_term( $name, 'category' );
		return is_wp_error( $result ) ? 0 : (int) $result['term_id'];
	}
}
