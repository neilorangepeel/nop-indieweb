<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Admin;

/**
 * Enqueues the native block-editor Venue panel for checkin posts.
 *
 * The panel is a PluginDocumentSettingPanel component (JS) — it renders
 * directly in the document sidebar alongside Categories and Tags, matching
 * their styling exactly. It replaces the former classic PHP metabox, which
 * produced a styling mismatch and added the unwanted "Meta Boxes" compat tab.
 *
 * Saving is handled by the editor: editPost() marks the post dirty and the
 * REST API persists meta on Save. No save_post hook needed.
 */
class Checkin_Metabox {

	public function register(): void {
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue' ] );
	}

	public function enqueue(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->post_type ) {
			return;
		}

		// The panel itself self-gates on nop_indieweb_post_kind in JS, so we
		// always enqueue on Post edit screens — this covers brand-new posts
		// where no kind has been chosen yet, but the user might pick "checkin"
		// from the Post Kinds panel and expect the venue panel to appear.

		$base = NOP_INDIEWEB_URL . 'admin/';
		$ver  = NOP_INDIEWEB_VERSION;

		wp_enqueue_script(
			'nop-indieweb-checkin-panel',
			$base . 'checkin-panel.js',
			[ 'wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-element', 'wp-data', 'wp-components', 'wp-i18n' ],
			$ver,
			true
		);

		wp_enqueue_style(
			'nop-indieweb-editor-sidebar',
			$base . 'editor-sidebar.css',
			[],
			$ver
		);
	}
}
