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
		add_action( 'init', [ $this, 'register_templates' ] );

		if ( is_admin() ) {
			( new Settings() )->register();
			( new Auth_Endpoint() )->register();
			( new Debug( $services ) )->register();
			( new Checkin_Metabox() )->register();
		}

		add_action( 'wp_head',      [ $this, 'output_link_tags' ] );
		add_action( 'send_headers', [ $this, 'output_link_headers' ] );

		// Inject post-format templates into the block-theme template hierarchy.
		// get_single_template() doesn't include single-post-format-{format} by default,
		// so block templates with that slug are never matched during real requests.
		add_filter( 'single_template_hierarchy', [ $this, 'inject_post_format_template' ] );
	}

	public function inject_post_format_template( array $templates ): array {
		$post   = get_queried_object();
		$format = $post instanceof \WP_Post ? get_post_format( $post ) : '';
		if ( ! $format ) {
			return $templates;
		}

		// Base format template — fallback for all status posts.
		array_unshift( $templates, "single-post-format-{$format}" );

		// More-specific subtype template prepended at the front.
		// Themes only need to create the file if they want a distinct layout.
		$subtype = $this->resolve_status_subtype( $post );
		if ( $subtype ) {
			array_unshift( $templates, "single-post-format-{$format}-{$subtype}" );
		}

		return $templates;
	}

	/**
	 * Identifies the display subtype of a status post from its stored meta.
	 * Returns an empty string when no specific subtype is detected.
	 */
	private function resolve_status_subtype( \WP_Post $post ): string {
		// Explicit kind set at creation time — always authoritative.
		$kind = get_post_meta( $post->ID, 'nop_indieweb_post_kind', true );
		if ( $kind ) {
			return $kind;
		}
		// Inference fallback for posts predating the post_kind field.
		if ( get_post_meta( $post->ID, 'nop_indieweb_venue_name', true ) ) {
			return 'checkin';
		}
		return '';
	}


	public function register_templates(): void {
		$dir = NOP_INDIEWEB_DIR . 'templates/';

		register_block_template( 'nop-indieweb//single-post-format-status', [
			'title'       => __( 'Single – Status Post', 'nop-indieweb' ),
			'description' => __( 'Displays a single status-format post. Themes can override this template.', 'nop-indieweb' ),
			'content'     => file_get_contents( $dir . 'single-post-format-status.html' ),
		] );

		register_block_template( 'nop-indieweb//single-post-format-status-checkin', [
			'title'       => __( 'Single – Status: Checkin', 'nop-indieweb' ),
			'description' => __( 'Displays a checkin post with venue, map, and syndication metadata. Themes can override this template.', 'nop-indieweb' ),
			'content'     => file_get_contents( $dir . 'single-post-format-status-checkin.html' ),
		] );
	}

	public function register_blocks(): void {
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/checkin-meta' );
	}

	public function register_patterns(): void {
		register_block_pattern( 'nop-indieweb/checkin-post', [
			'title'       => 'Checkin Post',
			'description' => 'Post content followed by checkin metadata — venue, map, syndication, and service attribution.',
			'categories'  => [ 'featured' ],
			'content'     => '<!-- wp:post-content {"lock":{"move":false,"remove":false}} /-->
<!-- wp:nop-indieweb/checkin-meta /-->',
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
