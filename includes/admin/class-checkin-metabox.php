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

		// Only load for checkin posts — no point registering the plugin on unrelated posts.
		// Default to not enqueuing when post ID is undetermined (e.g. new post screen).
		global $post;
		$post_id = $post->ID ?? 0;
		if ( ! $post_id ) {
			return;
		}
		$kind      = get_post_meta( $post_id, 'nop_indieweb_post_kind', true );
		$has_venue = get_post_meta( $post_id, 'nop_indieweb_venue_name', true );
		if ( 'checkin' !== $kind && ! $has_venue ) {
			return;
		}

		$base = NOP_INDIEWEB_URL . 'admin/';
		$ver  = NOP_INDIEWEB_VERSION;

		wp_enqueue_script(
			'nop-indieweb-checkin-panel',
			$base . 'checkin-panel.js',
			[ 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-data', 'wp-components', 'wp-i18n' ],
			$ver,
			true
		);

		wp_enqueue_style(
			'nop-indieweb-checkin-panel',
			$base . 'checkin-panel.css',
			[],
			$ver
		);
	}
}
