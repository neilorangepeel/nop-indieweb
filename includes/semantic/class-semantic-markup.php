<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Semantic;

/**
 * Injects microformats2 classes into front-end HTML at render time.
 *
 * Nothing is written to the database — all classes are added server-side
 * only. Deactivating the plugin removes them automatically.
 *
 * Covers the HTML layer:
 *   h-entry    → body class on IndieWeb posts
 *   dt-published → <time> element inside core/post-date block
 *   e-content  → wrapper div inside core/post-content block
 *
 * The checkin-meta block handles the venue h-card (p-checkin, u-url,
 * u-syndication) directly in its server-side render.
 */
class Semantic_Markup {

	private ?bool $is_active = null;

	public function register(): void {
		if ( ! \NOP\IndieWeb\nop_indieweb_get_option( 'mf2_enabled', true ) ) {
			return;
		}
		add_filter( 'body_class',   [ $this, 'add_hentry_class' ] );
		add_filter( 'render_block', [ $this, 'inject_block_classes' ], 10, 2 );
		add_action( 'wp_head',      [ $this, 'output_alternate_link' ] );
		add_action( 'wp_footer',    [ $this, 'output_syndication_links' ] );
	}

	public function add_hentry_class( array $classes ): array {
		if ( $this->is_active() ) {
			$classes[] = 'h-entry';
		}
		return $classes;
	}

	public function inject_block_classes( string $html, array $block ): string {
		if ( ! $this->is_active() ) {
			return $html;
		}
		return match ( $block['blockName'] ) {
			'core/post-date'    => $this->add_class_to_tag( $html, 'time', 'dt-published' ),
			'core/post-content' => $this->add_class_to_tag( $html, 'div',  'e-content' ),
			default             => $html,
		};
	}

	public function output_alternate_link(): void {
		if ( ! $this->is_active() ) {
			return;
		}
		$post_id = get_queried_object_id();
		printf(
			"<link rel=\"alternate\" type=\"application/mf2+json\" href=\"%s\">\n",
			esc_url( rest_url( "nop-indieweb/v1/mf2/{$post_id}" ) )
		);
	}

	public function output_syndication_links(): void {
		if ( ! $this->is_active() ) {
			return;
		}
		$urls = get_post_meta( get_queried_object_id(), 'nop_indieweb_syndication', true );
		if ( ! is_array( $urls ) || empty( $urls ) ) {
			return;
		}
		foreach ( $urls as $url ) {
			printf( "<a class=\"u-syndication\" href=\"%s\" hidden></a>\n", esc_url( $url ) );
		}
	}

	private function is_active(): bool {
		if ( null === $this->is_active ) {
			$this->is_active = is_singular( 'post' )
				&& (bool) get_post_meta( get_queried_object_id(), 'nop_indieweb_service', true );
		}
		return $this->is_active;
	}

	/**
	 * Adds a CSS class to the first occurrence of a given HTML tag in a string.
	 * Handles both existing class attributes and tags with no class at all.
	 */
	private function add_class_to_tag( string $html, string $tag, string $class ): string {
		return preg_replace_callback(
			'/<' . preg_quote( $tag, '/' ) . '\b([^>]*)>/i',
			static function ( array $m ) use ( $tag, $class ): string {
				$attrs = $m[1];
				if ( preg_match( '/\bclass="([^"]*)"/', $attrs, $c ) ) {
					$attrs = str_replace(
						$c[0],
						'class="' . trim( $c[1] . ' ' . $class ) . '"',
						$attrs
					);
				} else {
					$attrs .= ' class="' . $class . '"';
				}
				return '<' . $tag . $attrs . '>';
			},
			$html,
			1
		) ?? $html;
	}
}
