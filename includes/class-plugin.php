<?php
declare( strict_types=1 );

namespace NOP\IndieWeb;

use NOP\IndieWeb\Micropub\Endpoint;
use NOP\IndieWeb\Micropub\Media_Endpoint;
use NOP\IndieWeb\Post_Meta\Registry;
use NOP\IndieWeb\Post_Meta\Block_Bindings;
use NOP\IndieWeb\Services\Swarm;
use NOP\IndieWeb\Services\Note;
use NOP\IndieWeb\Services\Letterboxd;
use NOP\IndieWeb\Services\Bookmark;
use NOP\IndieWeb\Services\Reply;
use NOP\IndieWeb\Services\Like;
use NOP\IndieWeb\Services\Repost;
use NOP\IndieWeb\Services\RSVP;
use NOP\IndieWeb\Semantic\Semantic_Markup;
use NOP\IndieWeb\Semantic\MF2_Endpoint;
use NOP\IndieWeb\Admin\Settings;
use NOP\IndieWeb\Admin\Post_Filter;
use NOP\IndieWeb\Admin\Debug;
use NOP\IndieWeb\Admin\Checkin_Metabox;
use NOP\IndieWeb\Admin\Post_Kinds_Panel;
use NOP\IndieWeb\Admin\Syndication_Panel;
use NOP\IndieWeb\IndieAuth\Auth_Endpoint;
use NOP\IndieWeb\IndieAuth\Token_Endpoint;
use NOP\IndieWeb\Syndication\Syndication_Manager;
use NOP\IndieWeb\Importer\Feed_Importer;
use NOP\IndieWeb\Webmention\Webmention_Endpoint;
use NOP\IndieWeb\Webmention\Webmention_Sender;

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
		$note       = new Note();
		$letterboxd = new Letterboxd();
		// RSVP must appear before Reply — both match in-reply-to and RSVP is the more specific case.
		$services   = apply_filters( 'nop_indieweb_register_services', [
			new Swarm(),
			new Bookmark(),
			new RSVP(),
			new Reply(),
			new Like(),
			new Repost(),
			$note,
		] );

		$syndication_manager = new Syndication_Manager();
		$syndication_manager->register();
		( new Feed_Importer( $note, $letterboxd ) )->register();

		( new Registry() )->register();
		( new Block_Bindings() )->register();
		( new Endpoint( $services, $syndication_manager ) )->register();
		( new Media_Endpoint() )->register();
		( new Token_Endpoint() )->register();
		( new Webmention_Endpoint() )->register();
		( new Webmention_Sender() )->register();
		( new Post_Filter() )->register();
		( new Semantic_Markup() )->register();
		( new MF2_Endpoint() )->register();

		add_action( 'init', [ $this, 'register_blocks' ] );
		add_action( 'init', [ $this, 'register_patterns' ] );
		add_action( 'init', [ $this, 'register_templates' ] );
		add_action( 'after_setup_theme', [ $this, 'ensure_post_format_support' ] );

		if ( is_admin() ) {
			( new Settings() )->register();
			( new Auth_Endpoint() )->register();
			( new Debug( $services ) )->register();
			( new Checkin_Metabox() )->register();
			( new Post_Kinds_Panel() )->register();
			( new Syndication_Panel( $syndication_manager ) )->register();
		}

		add_action( 'wp_head',      [ $this, 'output_link_tags' ] );
		add_action( 'send_headers', [ $this, 'output_link_headers' ] );

		// Inject post-format templates into the block-theme template hierarchy.
		// get_single_template() doesn't include single-post-format-{format} by default,
		// so block templates with that slug are never matched during real requests.
		add_filter( 'single_template_hierarchy', [ $this, 'inject_post_format_template' ] );
	}

	public function ensure_post_format_support(): void {
		$settings = \NOP\IndieWeb\nop_indieweb_get_option( 'services', [] );

		$needed = [];
		if ( ! empty( $settings['swarm']['enabled'] ) ) {
			$needed[] = 'status';
		}

		$needed = apply_filters( 'nop_indieweb_required_post_formats', $needed );

		if ( ! $needed ) {
			return;
		}

		$supported = get_theme_support( 'post-formats' );
		$supported = is_array( $supported ) && isset( $supported[0] ) ? $supported[0] : [];

		$missing = array_diff( $needed, $supported );
		if ( $missing ) {
			add_theme_support( 'post-formats', array_unique( array_merge( $supported, $missing ) ) );
		}
	}

	public function inject_post_format_template( array $templates ): array {
		$post   = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return $templates;
		}

		$format = get_post_format( $post );

		// Standard-format posts with a specific post kind get their own template slug.
		if ( ! $format ) {
			$kind = get_post_meta( $post->ID, 'nop_indieweb_post_kind', true );
			if ( $kind ) {
				array_unshift( $templates, "single-{$kind}" );
			}
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

		$templates = [
			'nop-indieweb//single-post-format-status' => [
				'title'       => __( 'Single – Status Post', 'nop-indieweb' ),
				'description' => __( 'Displays a single status-format post. Themes can override this template.', 'nop-indieweb' ),
				'file'        => 'single-post-format-status.html',
			],
			'nop-indieweb//single-post-format-status-checkin' => [
				'title'       => __( 'Single – Status: Checkin', 'nop-indieweb' ),
				'description' => __( 'Displays a checkin post with venue, map, and syndication metadata. Themes can override this template.', 'nop-indieweb' ),
				'file'        => 'single-post-format-status-checkin.html',
			],
			'nop-indieweb//single-post-format-status-note' => [
				'title'       => __( 'Single – Status: Note', 'nop-indieweb' ),
				'description' => __( 'Displays an imported social post (Mastodon, Bluesky) with platform attribution and source link.', 'nop-indieweb' ),
				'file'        => 'single-post-format-status-note.html',
			],
			'nop-indieweb//single-post-format-status-bookmark' => [
				'title'       => __( 'Single – Status: Bookmark', 'nop-indieweb' ),
				'description' => __( 'Displays a bookmark post with the bookmarked URL.', 'nop-indieweb' ),
				'file'        => 'single-post-format-status-bookmark.html',
			],
			'nop-indieweb//single-post-format-status-reply' => [
				'title'       => __( 'Single – Status: Reply', 'nop-indieweb' ),
				'description' => __( 'Displays a reply post with the URL being replied to.', 'nop-indieweb' ),
				'file'        => 'single-post-format-status-reply.html',
			],
			'nop-indieweb//single-post-format-status-like' => [
				'title'       => __( 'Single – Status: Like', 'nop-indieweb' ),
				'description' => __( 'Displays a like post with the liked URL.', 'nop-indieweb' ),
				'file'        => 'single-post-format-status-like.html',
			],
			'nop-indieweb//single-post-format-status-repost' => [
				'title'       => __( 'Single – Status: Repost', 'nop-indieweb' ),
				'description' => __( 'Displays a repost with the reposted URL.', 'nop-indieweb' ),
				'file'        => 'single-post-format-status-repost.html',
			],
			'nop-indieweb//single-post-format-status-rsvp' => [
				'title'       => __( 'Single – Status: RSVP', 'nop-indieweb' ),
				'description' => __( 'Displays an RSVP post with the event URL and response.', 'nop-indieweb' ),
				'file'        => 'single-post-format-status-rsvp.html',
			],
			'nop-indieweb//single-watch' => [
				'title'       => __( 'Single – Film Diary Entry', 'nop-indieweb' ),
				'description' => __( 'Displays a Letterboxd film diary entry with star rating, poster, watch date, and review.', 'nop-indieweb' ),
				'file'        => 'single-watch.html',
			],
		];

		foreach ( $templates as $id => $template ) {
			$path = $dir . $template['file'];
			if ( ! is_readable( $path ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
				trigger_error( "NOP IndieWeb: template file missing: {$path}", E_USER_WARNING );
				continue;
			}
			register_block_template( $id, [
				'title'       => $template['title'],
				'description' => $template['description'],
				'content'     => file_get_contents( $path ),
			] );
		}
	}

	public function register_blocks(): void {
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/checkin-meta' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/webmentions' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/post-source' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/film-meta' );
	}

	public function register_patterns(): void {
		register_block_pattern_category(
			'nop-indieweb',
			[ 'label' => __( 'IndieWeb', 'nop-indieweb' ) ]
		);

		register_block_pattern( 'nop-indieweb/checkin-post', [
			'title'         => __( 'Checkin Post', 'nop-indieweb' ),
			'description'   => __( 'Metadata-list layout: venue name and street address are bound to post meta and fully styleable. Categories, map, and syndication are handled by the checkin-meta block below.', 'nop-indieweb' ),
			'categories'    => [ 'nop-indieweb' ],
			'keywords'      => [ 'checkin', 'swarm', 'venue', 'location', 'indieweb' ],
			'viewportWidth' => 800,
			'content'       => <<<'HTML'
<!-- wp:group {"style":{"spacing":{"blockGap":"1rem"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group">

<!-- wp:group {"style":{"spacing":{"blockGap":"0.25rem"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group">
<!-- wp:paragraph {"style":{"color":{"text":"#6b7280"},"typography":{"fontSize":"0.6875rem","fontWeight":"600","letterSpacing":"0.08em","textTransform":"uppercase"}}} -->
<p class="has-text-color" style="color:#6b7280;font-size:0.6875rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase">Venue</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":2,"style":{"typography":{"fontWeight":"700","lineHeight":"1.2"}},"metadata":{"bindings":{"content":{"source":"core/post-meta","args":{"key":"nop_indieweb_venue_name"}}}}} -->
<h2 class="wp-block-heading" style="font-weight:700;line-height:1.2">The Crown Bar</h2>
<!-- /wp:heading -->
</div>
<!-- /wp:group -->

<!-- wp:group {"style":{"spacing":{"blockGap":"0.25rem"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group">
<!-- wp:paragraph {"style":{"color":{"text":"#6b7280"},"typography":{"fontSize":"0.6875rem","fontWeight":"600","letterSpacing":"0.08em","textTransform":"uppercase"}}} -->
<p class="has-text-color" style="color:#6b7280;font-size:0.6875rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase">Address</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"core/post-meta","args":{"key":"nop_indieweb_venue_address"}}}}} -->
<p>46 Great Victoria Street</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->

<!-- wp:group {"style":{"spacing":{"blockGap":"0.25rem"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group">
<!-- wp:paragraph {"style":{"color":{"text":"#6b7280"},"typography":{"fontSize":"0.6875rem","fontWeight":"600","letterSpacing":"0.08em","textTransform":"uppercase"}}} -->
<p class="has-text-color" style="color:#6b7280;font-size:0.6875rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase">Date</p>
<!-- /wp:paragraph -->
<!-- wp:post-date {"format":"j F Y, g:i a","isLink":false} /-->
</div>
<!-- /wp:group -->

<!-- wp:separator {"opacity":"css"} -->
<hr class="wp-block-separator has-css-opacity"/>
<!-- /wp:separator -->

<!-- wp:nop-indieweb/checkin-meta /-->

</div>
<!-- /wp:group -->
HTML,
		] );
	}

	public function output_link_tags(): void {
		printf( "<link rel=\"micropub\" href=\"%s\">\n",               esc_url( rest_url( 'nop-indieweb/v1/micropub' ) ) );
		printf( "<link rel=\"webmention\" href=\"%s\">\n",             esc_url( rest_url( 'nop-indieweb/v1/webmention' ) ) );
		printf( "<link rel=\"authorization_endpoint\" href=\"%s\">\n", esc_url( Auth_Endpoint::url() ) );
		printf( "<link rel=\"token_endpoint\" href=\"%s\">\n",         esc_url( rest_url( 'nop-indieweb/v1/token' ) ) );

		foreach ( $this->get_me_urls() as $url ) {
			printf( "<link rel=\"me\" href=\"%s\">\n", esc_url( $url ) );
		}
	}

	public function output_link_headers(): void {
		header( sprintf( 'Link: <%s>; rel="micropub"',               rest_url( 'nop-indieweb/v1/micropub' ) ), false );
		header( sprintf( 'Link: <%s>; rel="webmention"',             rest_url( 'nop-indieweb/v1/webmention' ) ), false );
		header( sprintf( 'Link: <%s>; rel="authorization_endpoint"', Auth_Endpoint::url() ),                  false );
		header( sprintf( 'Link: <%s>; rel="token_endpoint"',         rest_url( 'nop-indieweb/v1/token' ) ),  false );

		foreach ( $this->get_me_urls() as $url ) {
			header( sprintf( 'Link: <%s>; rel="me"', $url ), false );
		}
	}

	private function get_me_urls(): array {
		$urls = [];

		// Custom profile URLs from Settings → General → Identity.
		$custom = nop_indieweb_get_option( 'me_urls', '' );
		foreach ( array_filter( array_map( 'trim', explode( "\n", $custom ) ) ) as $url ) {
			$urls[] = esc_url_raw( $url );
		}

		$mastodon_url = (string) nop_indieweb_get_option( 'syndicators.mastodon.profile_url', '' );
		if ( $mastodon_url ) {
			$urls[] = $mastodon_url;
		}

		$bluesky_handle = (string) nop_indieweb_get_option( 'syndicators.bluesky.handle', '' );
		if ( $bluesky_handle ) {
			$urls[] = 'https://bsky.app/profile/' . $bluesky_handle;
		}

		$pixelfed_url = (string) nop_indieweb_get_option( 'syndicators.pixelfed.profile_url', '' );
		if ( $pixelfed_url ) {
			$urls[] = $pixelfed_url;
		}

		return array_values( array_unique( array_filter( $urls ) ) );
	}
}
