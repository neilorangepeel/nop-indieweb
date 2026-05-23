<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Admin;

/**
 * Enqueues the Post Kinds sidebar panel in the block editor.
 *
 * The panel renders a Kind selector and the relevant URL/meta fields for
 * the selected kind. Selecting a kind also auto-sets the post format to
 * "status". Filling in a URL auto-fills the title if it is still empty.
 */
class Post_Kinds_Panel {

	public function register(): void {
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue' ] );
	}

	public function enqueue(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script(
			'nop-indieweb-post-kinds-panel',
			NOP_INDIEWEB_URL . 'admin/post-kinds-panel.js',
			[ 'wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-element', 'wp-data', 'wp-components', 'wp-i18n', 'wp-blocks', 'wp-api-fetch' ],
			NOP_INDIEWEB_VERSION,
			true
		);

		// Shared editor-sidebar stylesheet (defines .nop-panel-row, .nop-layout-offer, …).
		// Other panels enqueue the same handle — WP dedupes by handle so this is safe.
		wp_enqueue_style(
			'nop-indieweb-editor-sidebar',
			NOP_INDIEWEB_URL . 'admin/editor-sidebar.css',
			[],
			NOP_INDIEWEB_VERSION
		);

		$terms = get_terms( [
			'taxonomy'   => \NOP\IndieWeb\Kind\Kind_Taxonomy::TAXONOMY,
			'hide_empty' => false,
			'fields'     => 'all',
		] );
		$kind_terms = [];
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$kind_terms[] = [ 'id' => $term->term_id, 'slug' => $term->slug ];
			}
		}
		wp_localize_script( 'nop-indieweb-post-kinds-panel', 'nopIndieWebKindsPanel', [
			'terms'   => $kind_terms,
			'config'  => \NOP\IndieWeb\Kind\Kind_Taxonomy::get_editor_panel_config(),
			'restUrl' => rest_url( 'nop-indieweb/v1' ),
		] );
	}
}
