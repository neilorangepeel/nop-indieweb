<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Admin;

use WP_Query;

/**
 * Adds a Post Format filter dropdown to the Posts list screen.
 *
 * WordPress doesn't include this by default. Post formats are stored as
 * terms in the 'post_format' taxonomy, so filtering is a simple tax_query.
 */
class Post_Filter {

	public function register(): void {
		add_action( 'restrict_manage_posts',   [ $this, 'render_dropdown' ] );
		add_action( 'parse_query',             [ $this, 'apply_filter' ] );
		add_filter( 'manage_posts_columns',    [ $this, 'add_kind_column' ] );
		add_action( 'manage_posts_custom_column', [ $this, 'render_kind_column' ], 10, 2 );
	}

	public function render_dropdown( string $post_type ): void {
		if ( 'post' !== $post_type ) {
			return;
		}

		$theme_formats = get_theme_support( 'post-formats' );
		if ( ! is_array( $theme_formats ) ) {
			return;
		}

		$formats = $theme_formats[0] ?? [];
		$current = sanitize_key( $_GET['post_format'] ?? '' );

		echo "<select name='post_format' id='filter-by-post-format'>";
		echo "<option value=''>All formats</option>";
		foreach ( $formats as $format ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $format ),
				selected( $current, $format, false ),
				esc_html( ucfirst( $format ) )
			);
		}
		echo "</select>";
	}

	public function add_kind_column( array $columns ): array {
		$out = [];
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['nop_post_kind'] = 'Kind';
			}
		}
		return $out;
	}

	public function render_kind_column( string $column, int $post_id ): void {
		if ( 'nop_post_kind' !== $column ) {
			return;
		}
		static $labels = [
			'note'     => 'Note',
			'bookmark' => 'Bookmark',
			'like'     => 'Like',
			'reply'    => 'Reply',
			'repost'   => 'Repost',
			'rsvp'     => 'RSVP',
		];
		$kind = (string) get_post_meta( $post_id, 'nop_indieweb_post_kind', true );
		if ( $kind ) {
			printf( '<span class="nop-kind-badge">%s</span>', esc_html( $labels[ $kind ] ?? ucfirst( $kind ) ) );
		} else {
			echo '<span class="nop-kind-badge nop-kind-badge--none">—</span>';
		}
	}

	public function apply_filter( WP_Query $query ): void {
		global $pagenow;

		if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() ) {
			return;
		}

		$format = sanitize_key( $_GET['post_format'] ?? '' );
		if ( ! $format ) {
			return;
		}

		// Post formats are taxonomy terms with the prefix 'post-format-'.
		$query->set( 'tax_query', [
			[
				'taxonomy' => 'post_format',
				'field'    => 'slug',
				'terms'    => 'post-format-' . $format,
			],
		] );
	}
}
