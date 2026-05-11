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
			[ 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-data', 'wp-components', 'wp-i18n' ],
			NOP_INDIEWEB_VERSION,
			true
		);
	}
}
