<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Registrars;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's blocks, shared block scripts/styles, and block categories.
 */
class Block_Registrar {

	public function register(): void {
		add_action( 'init', [ $this, 'register_blocks' ] );
		add_filter( 'block_categories_all', [ $this, 'register_block_categories' ] );
	}

	public function register_blocks(): void {
		// Shared editor helper used by SSR blocks. Registered before the blocks
		// so editor.asset.php files can list 'nop-indieweb-ssr-block-helper' as a dep.
		wp_register_script(
			'nop-indieweb-ssr-block-helper',
			NOP_INDIEWEB_URL . 'assets/js/ssr-block-helper.js',
			[ 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-server-side-render', 'wp-data' ],
			NOP_INDIEWEB_VERSION,
			true
		);

		// Shared front-end stylesheet used by every block. Registered before the
		// blocks so block.json `style` arrays can list 'nop-blocks-shared' as a dep.
		// WordPress only enqueues it when at least one of those blocks renders.
		wp_register_style(
			'nop-blocks-shared',
			NOP_INDIEWEB_URL . 'assets/css/blocks-shared.css',
			[],
			NOP_INDIEWEB_VERSION
		);

		// Shared like-action handler used by both the like-button view.js and the
		// post-footer view.js. Avoids shipping the same fetch/animation logic twice.
		// Depends on wp-i18n so its user-facing strings (the like count label and
		// the save-failed message) resolve through wp.i18n.__(); the script falls
		// back to English if wp-i18n is somehow absent.
		wp_register_script(
			'nop-like-action',
			NOP_INDIEWEB_URL . 'assets/js/nop-like-action.js',
			[ 'wp-i18n' ],
			NOP_INDIEWEB_VERSION,
			true
		);
		wp_set_script_translations( 'nop-like-action', 'nop-indieweb' );

		register_block_type( NOP_INDIEWEB_DIR . 'blocks/checkin-map' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/exercise-map' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/weather-icon' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/weather-temp' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/replies' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/comment-form' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/like-button' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/cite-card' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/post-source' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/post-footer' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/film-meta' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/rsvp-meta' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/film-card' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/syndication-panel' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/exercise-type-icon' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/kind-icon' );
	}

	public function register_block_categories( array $categories ): array {
		return array_merge( $categories, [
			[
				'slug'  => 'nop-indieweb-conversations',
				'title' => __( 'NOP · Conversations', 'nop-indieweb' ),
			],
			[
				'slug'  => 'nop-indieweb-meta',
				'title' => __( 'NOP · Kind meta', 'nop-indieweb' ),
			],
		] );
	}
}
