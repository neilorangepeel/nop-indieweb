<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Admin;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use NOP\IndieWeb\IndieAuth\Token_Store;

/**
 * Settings page at Settings > IndieWeb.
 *
 * Renders a React app (build/settings/index.js) that loads settings via
 * GET /nop-indieweb/v1/settings and saves via POST. See Settings_API for
 * the REST endpoints and sanitisation logic.
 */
class Settings {

	private const OPTION_KEY = 'nop_indieweb_settings';
	private const PAGE_SLUG  = 'nop-indieweb-settings';

	public function register(): void {
		add_action( 'admin_menu',            [ $this, 'add_page' ] );
		add_action( 'admin_init',            [ $this, 'maybe_handle_revoke' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		// Keep the AJAX handler for one release so any open browser tabs with
		// the old Test Connection button still work.
		// NOP: needs review — this was a one-release backward-compat shim (also
		// ajax_test_connection() below). Safe to delete once that release has
		// shipped; left in place because the cutover date is your call.
		add_action( 'wp_ajax_nop_test_connection', [ $this, 'ajax_test_connection' ] );
	}

	public function add_page(): void {
		add_options_page(
			__( 'IndieWeb Settings', 'nop-indieweb' ),
			__( 'IndieWeb', 'nop-indieweb' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap nop-indieweb-settings">
			<h1><?php esc_html_e( 'IndieWeb', 'nop-indieweb' ); ?></h1>
			<div id="nop-settings-root"></div>
		</div>
		<?php
	}

	// ——— Token revocation (GET link + nonce, kept for backward compat) ————————

	public function maybe_handle_revoke(): void {
		if ( ! isset( $_GET['nop_revoke_token'] ) ) {
			return;
		}

		$token_id = absint( $_GET['nop_revoke_token'] );
		check_admin_referer( 'nop_revoke_token_' . $token_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'nop-indieweb' ), '', [ 'response' => 403 ] );
		}

		Token_Store::delete_by_id( $token_id );

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '#advanced' ) );
		exit;
	}

	// ——— AJAX test connection (kept for backward compat, one release) —————————

	public function ajax_test_connection(): void {
		check_ajax_referer( 'nop_test_connection' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized', 'nop-indieweb' ), 403 );
		}

		$slug       = sanitize_key( $_POST['service'] ?? '' );
		$manager    = \NOP\IndieWeb\Plugin::get_instance()->syndication_manager();
		$syndicator = $manager ? $manager->get( $slug ) : null;

		if ( ! $syndicator ) {
			wp_send_json_error( __( 'Unknown service.', 'nop-indieweb' ) );
		}

		$result = $syndicator->test_connection();
		if ( $result['ok'] ) {
			wp_send_json_success( $result['message'] );
		}
		wp_send_json_error( $result['message'] );
	}

	// ——— Asset enqueue ————————————————————————————————————————————————————————

	public function enqueue_assets( string $hook ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		$asset_file = NOP_INDIEWEB_DIR . 'build/settings/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'nop-indieweb-settings',
			NOP_INDIEWEB_URL . 'build/settings/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'nop-indieweb-settings',
			NOP_INDIEWEB_URL . 'build/settings/style-index.css',
			[ 'wp-components' ],
			$asset['version']
		);

		wp_localize_script( 'nop-indieweb-settings', 'nopIndieWebSettings', [
			'restUrl'    => rest_url( 'nop-indieweb/v1/settings' ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'adminUrl'   => admin_url(),
			'categories' => array_values( array_map( fn( $c ) => $c->name, get_categories( [ 'hide_empty' => false ] ) ) ),
			'tags'       => array_values( array_map( fn( $t ) => $t->name, get_tags( [ 'hide_empty' => false ] ) ) ),
			'syncNonces' => [
				'mastodon'   => wp_create_nonce( 'nop_indieweb_sync_mastodon' ),
				'bluesky'    => wp_create_nonce( 'nop_indieweb_sync_bluesky' ),
				'pixelfed'   => wp_create_nonce( 'nop_indieweb_sync_pixelfed' ),
				'letterboxd' => wp_create_nonce( 'nop_indieweb_sync_letterboxd' ),
			],
		] );
	}
}
