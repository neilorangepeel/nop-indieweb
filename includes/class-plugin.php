<?php
declare( strict_types=1 );

namespace NOP\IndieWeb;

use NOP\IndieWeb\Micropub\Endpoint;
use NOP\IndieWeb\Post_Meta\Registry;
use NOP\IndieWeb\Post_Meta\Block_Bindings;
use NOP\IndieWeb\Services\Swarm;
use NOP\IndieWeb\Admin\Settings;
use NOP\IndieWeb\Admin\Post_Filter;
use NOP\IndieWeb\Admin\Debug;
use NOP\IndieWeb\Admin\Checkin_Metabox;
use NOP\IndieWeb\IndieAuth\Auth_Endpoint;
use NOP\IndieWeb\IndieAuth\Token_Endpoint;

/**
 * Bootstraps the plugin. Single entry point — everything is wired here.
 *
 * Adding a new service: add it to the $services array below (or via the
 * nop_indieweb_register_services filter from another plugin/theme).
 */
class Plugin {

	private static ?Plugin $instance = null;

	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		$services = apply_filters( 'nop_indieweb_register_services', [
			new Swarm(),
		] );

		( new Registry() )->register();
		( new Block_Bindings() )->register();
		( new Endpoint( $services ) )->register();
		( new Token_Endpoint() )->register();
		( new Post_Filter() )->register();

		add_action( 'init', [ $this, 'register_blocks' ] );
		add_action( 'init', [ $this, 'register_patterns' ] );

		if ( is_admin() ) {
			( new Settings() )->register();
			( new Auth_Endpoint() )->register();
			( new Debug( $services ) )->register();
			( new Checkin_Metabox() )->register();
		}

		add_action( 'wp_head',      [ $this, 'output_link_tags' ] );
		add_action( 'send_headers', [ $this, 'output_link_headers' ] );
	}

	public function register_blocks(): void {
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/checkin-card' );
	}

	public function register_patterns(): void {
		register_block_pattern( 'nop-indieweb/checkin-post', [
			'title'       => 'Checkin Post',
			'description' => 'Post content followed by the checkin card — venue, map, syndication, and photos.',
			'categories'  => [ 'featured' ],
			'content'     => '<!-- wp:post-content {"lock":{"move":false,"remove":false}} /-->
<!-- wp:nop-indieweb/checkin-card /-->',
		] );
	}

	public function output_link_tags(): void {
		printf( "<link rel=\"micropub\" href=\"%s\">\n",               esc_url( rest_url( 'nop-indieweb/v1/micropub' ) ) );
		printf( "<link rel=\"authorization_endpoint\" href=\"%s\">\n", esc_url( Auth_Endpoint::url() ) );
		printf( "<link rel=\"token_endpoint\" href=\"%s\">\n",         esc_url( rest_url( 'nop-indieweb/v1/token' ) ) );
	}

	public function output_link_headers(): void {
		header( sprintf( 'Link: <%s>; rel="micropub"',               rest_url( 'nop-indieweb/v1/micropub' ) ), false );
		header( sprintf( 'Link: <%s>; rel="authorization_endpoint"', Auth_Endpoint::url() ),                  false );
		header( sprintf( 'Link: <%s>; rel="token_endpoint"',         rest_url( 'nop-indieweb/v1/token' ) ),  false );
	}
}
