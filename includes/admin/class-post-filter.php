<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Admin;

use NOP\IndieWeb\Kind\Kind_Taxonomy;
use WP_Query;

/**
 * Adds a Post Kind filter dropdown to the Posts list screen.
 *
 * The Kind column is provided automatically by show_admin_column: true on
 * the nop_kind taxonomy registration. This class adds the filter dropdown
 * and wires up the query.
 */
class Post_Filter {

	public function register(): void {
		add_action( 'restrict_manage_posts', [ $this, 'render_dropdown' ] );
		add_action( 'parse_query',           [ $this, 'apply_filter' ] );
	}

	public function render_dropdown( string $post_type ): void {
		if ( 'post' !== $post_type ) {
			return;
		}

		$terms   = get_terms( [ 'taxonomy' => Kind_Taxonomy::TAXONOMY, 'hide_empty' => true ] );
		$current = sanitize_key( $_GET['nop_kind'] ?? '' );

		echo "<select name='nop_kind' id='filter-by-post-kind'>";
		echo "<option value=''>All kinds</option>";
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $term->slug ),
					selected( $current, $term->slug, false ),
					esc_html( $term->name )
				);
			}
		}
		echo "</select>";
	}

	public function apply_filter( WP_Query $query ): void {
		global $pagenow;

		if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() ) {
			return;
		}

		$kind = sanitize_key( $_GET['nop_kind'] ?? '' );
		if ( ! $kind ) {
			return;
		}

		$query->set( 'tax_query', [
			[
				'taxonomy' => Kind_Taxonomy::TAXONOMY,
				'field'    => 'slug',
				'terms'    => $kind,
			],
		] );
	}
}
