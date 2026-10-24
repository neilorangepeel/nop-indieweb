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

	/** Phosphor Regular icons offered to the core Icon block (file name => label), so a template's fact rows pick them in the editor. */
	private const ICONS = [
		'arrow-square-out'      => 'Open',
		'arrows-clockwise'      => 'Repost',
		'buildings'             => 'Buildings',
		'calendar-blank'        => 'Calendar',
		'chat-circle'           => 'Chat',
		'clock'                 => 'Clock',
		'cloud-sun'             => 'Weather',
		'crosshair'             => 'Coordinates',
		'film-strip'            => 'Film',
		'gauge'                 => 'Speed',
		'globe-hemisphere-west' => 'Globe',
		'hash'                  => 'Number',
		'heart'                 => 'Heart',
		'link'                  => 'Link',
		'map-pin'               => 'Map pin',
		'microphone-stage'      => 'Stage',
		'mountains'             => 'Climb',
		'path'                  => 'Route',
		'repeat'                => 'Reshare',
		'person-simple-run'     => 'Run',
		'ruler'                 => 'Distance',
		'share-network'         => 'Share',
		'storefront'            => 'Venue',
		'tag'                   => 'Tag',
		'ticket'                => 'Ticket',
		'timer'                 => 'Timer',
		'user'                  => 'Person',
		'vinyl-record'          => 'Record',
	];

	public function register(): void {
		add_action( 'init', [ $this, 'register_blocks' ] );
		add_action( 'init', [ $this, 'register_icons' ] );
		add_filter( 'block_categories_all', [ $this, 'register_block_categories' ] );
	}

	/** The `phosphor` icon collection (WordPress 7.1+; earlier the Icon block does not exist and this does nothing). */
	public function register_icons(): void {
		if ( ! function_exists( 'wp_register_icon_collection' ) || ! function_exists( 'wp_register_icon' ) ) {
			return;
		}
		wp_register_icon_collection( 'phosphor', [
			'label'       => 'Phosphor',
			'description' => __( 'Phosphor Regular, the icon family of the plugin\'s own blocks.', 'nop-indieweb' ),
		] );
		foreach ( self::ICONS as $name => $label ) {
			wp_register_icon( 'phosphor/' . $name, [
				'label'     => $label,
				'file_path' => NOP_INDIEWEB_DIR . 'assets/icons/phosphor/' . $name . '.svg',
			] );
		}
		// The marks the post kinds, exercise types and weather conditions bind to (kind-*, exercise-*, weather-*): every other
		// file in the folder, so adding one is adding the SVG.
		foreach ( (array) glob( NOP_INDIEWEB_DIR . 'assets/icons/phosphor/*.svg' ) as $file ) {
			$name = basename( $file, '.svg' );
			if ( ! isset( self::ICONS[ $name ] ) ) {
				wp_register_icon( 'phosphor/' . $name, [
					'label'     => ucwords( str_replace( '-', ' ', $name ) ),
					'file_path' => $file,
				] );
			}
		}
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

		// The shared likes Interactivity store, used by the like-button and
		// post-footer blocks (their block.json viewScriptModule lists this id),
		// so a post's like state is one value however many controls show it.
		wp_register_script_module(
			'nop-indieweb-likes',
			NOP_INDIEWEB_URL . 'assets/js/likes.js',
			[ [ 'id' => '@wordpress/interactivity' ] ],
			NOP_INDIEWEB_VERSION
		);

		// The reply form's store: posts in place, threads replies, offers new
		// responses. Enqueued by nop_wm_render_comment_form() when it renders.
		wp_register_script_module(
			'nop-indieweb-responses',
			NOP_INDIEWEB_URL . 'assets/js/responses.js',
			[ [ 'id' => '@wordpress/interactivity' ] ],
			NOP_INDIEWEB_VERSION
		);
		// Not attached to a block, so say it outright: the router should load it
		// when a client-side navigation brings a reply form onto the page.
		wp_interactivity()->add_client_navigation_support_to_script_module( 'nop-indieweb-responses' );

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
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/photos' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/share-button' );
		// These blocks reach a wall inside an entry opened over it, so the router must load their stores then.
		foreach ( [ 'photos', 'share-button' ] as $name ) {
			wp_interactivity()->add_client_navigation_support_to_script_module( 'nop-indieweb-' . $name . '-view-script-module' );
		}
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
