<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Admin;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use NOP\IndieWeb\Syndication\Syndication_Manager;

class Syndication_Panel {

	private Syndication_Manager $manager;

	public function __construct( Syndication_Manager $manager ) {
		$this->manager = $manager;
	}

	public function register(): void {
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue' ] );
	}

	public function enqueue(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->post_type ) {
			return;
		}

		$syndicators = $this->manager->get_panel_data();
		if ( ! $syndicators ) {
			return;
		}

		$base = NOP_INDIEWEB_URL . 'admin/';
		$ver  = NOP_INDIEWEB_VERSION;

		wp_enqueue_script(
			'nop-indieweb-syndication-panel',
			$base . 'syndication-panel.js',
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

		wp_localize_script( 'nop-indieweb-syndication-panel', 'nopIndiewebSyndication', [
			'syndicators' => $syndicators,
		] );
	}
}
