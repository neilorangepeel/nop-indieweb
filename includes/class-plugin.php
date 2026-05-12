<?php
declare( strict_types=1 );

namespace NOP\IndieWeb;

use NOP\IndieWeb\Kind\Kind_Taxonomy;
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
use NOP\IndieWeb\Webmention\Like_Endpoint;
use NOP\IndieWeb\Webmention\Social_Backfeed;

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

		( new Kind_Taxonomy() )->register();
		( new Registry() )->register();
		( new Block_Bindings() )->register();
		( new Endpoint( $services, $syndication_manager ) )->register();
		( new Media_Endpoint() )->register();
		( new Token_Endpoint() )->register();
		( new Webmention_Endpoint() )->register();
		( new Webmention_Sender() )->register();
		( new Like_Endpoint() )->register();
		( new Social_Backfeed() )->register();
		( new Post_Filter() )->register();
		( new Semantic_Markup() )->register();
		( new MF2_Endpoint() )->register();

		add_action( 'init', [ $this, 'register_blocks' ] );
		add_action( 'init', [ $this, 'register_patterns' ] );
		add_action( 'init', [ $this, 'register_templates' ] );

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

		// Inject kind-based templates into the block-theme single-post hierarchy.
		// Also injects post-format slugs as a lower-priority fallback so themes
		// that style format-status posts still work without changes.
		add_filter( 'single_template_hierarchy', [ $this, 'inject_kind_template' ] );

		// Keep webmentions out of the standard WordPress comments block.
		// The webmentions block fetches them explicitly with type='webmention',
		// so it is unaffected. Every other query (comment-template, comment counts,
		// feeds) will silently exclude them — matching the established IndieWeb pattern
		// used by the WordPress Webmention plugin and Semantic Linkbacks.
		add_filter( 'pre_get_comments', [ $this, 'exclude_webmentions_from_default_query' ] );
		add_filter( 'get_comments_number', [ $this, 'exclude_webmentions_from_count' ], 10, 2 );
	}

	public function exclude_webmentions_from_default_query( \WP_Comment_Query $query ): void {
		// Leave admin screens and explicit REST queries alone.
		if ( is_admin() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		// If the caller already asked for a specific type, respect that.
		if ( ! empty( $query->query_vars['type'] ) ) {
			return;
		}
		$not_in = (array) ( $query->query_vars['type__not_in'] ?? [] );
		if ( ! in_array( 'webmention', $not_in, true ) ) {
			$query->query_vars['type__not_in'] = array_merge( $not_in, [ 'webmention' ] );
		}
	}

	public function exclude_webmentions_from_count( int|string $count, int $post_id ): int {
		if ( is_admin() ) {
			return (int) $count;
		}
		return (int) get_comments( [
			'post_id'      => $post_id,
			'type__not_in' => [ 'webmention' ],
			'status'       => 'approve',
			'count'        => true,
		] );
	}

	/**
	 * Injects kind-based templates into the single-post hierarchy.
	 *
	 * Priority (highest first):
	 *   1. single-nop_kind-{slug}  — plugin's primary block template
	 *   2. single-post-format-{format}-{kind}  — theme compat if format is set
	 *   3. single-post-format-{format}          — theme compat fallback
	 *
	 * The plugin no longer sets post formats itself. If a theme or legacy data
	 * has set a format, those slugs are still injected below the kind template
	 * so existing theme templates continue to work unchanged.
	 */
	public function inject_kind_template( array $templates ): array {
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return $templates;
		}

		$kind   = get_post_meta( $post->ID, 'nop_indieweb_post_kind', true );
		$format = get_post_format( $post );

		// Format-based slugs first (lower priority — unshifted before kind).
		if ( $format && 'standard' !== $format ) {
			array_unshift( $templates, "single-post-format-{$format}" );
			if ( $kind ) {
				array_unshift( $templates, "single-post-format-{$format}-{$kind}" );
			}
		}

		// Kind template last (highest priority — ends up at front of array).
		if ( $kind ) {
			array_unshift( $templates, "single-nop_kind-{$kind}" );
		}

		return $templates;
	}


	public function register_templates(): void {
		$dir = NOP_INDIEWEB_DIR . 'templates/';

		$templates = [
			// ── Kind single-post templates (single-nop_kind-{slug}) ─────────────────
			'nop-indieweb//single-nop_kind-checkin' => [
				'title'       => __( 'Single – Checkin', 'nop-indieweb' ),
				'description' => __( 'Displays a checkin post with venue, map, and syndication metadata.', 'nop-indieweb' ),
				'file'        => 'single-nop_kind-checkin.html',
			],
			'nop-indieweb//single-nop_kind-note' => [
				'title'       => __( 'Single – Note', 'nop-indieweb' ),
				'description' => __( 'Displays an imported social post (Mastodon, Bluesky) with platform attribution and source link.', 'nop-indieweb' ),
				'file'        => 'single-nop_kind-note.html',
			],
			'nop-indieweb//single-nop_kind-bookmark' => [
				'title'       => __( 'Single – Bookmark', 'nop-indieweb' ),
				'description' => __( 'Displays a bookmark post with the bookmarked URL.', 'nop-indieweb' ),
				'file'        => 'single-nop_kind-bookmark.html',
			],
			'nop-indieweb//single-nop_kind-reply' => [
				'title'       => __( 'Single – Reply', 'nop-indieweb' ),
				'description' => __( 'Displays a reply post with the URL being replied to.', 'nop-indieweb' ),
				'file'        => 'single-nop_kind-reply.html',
			],
			'nop-indieweb//single-nop_kind-like' => [
				'title'       => __( 'Single – Like', 'nop-indieweb' ),
				'description' => __( 'Displays a like post with the liked URL.', 'nop-indieweb' ),
				'file'        => 'single-nop_kind-like.html',
			],
			'nop-indieweb//single-nop_kind-repost' => [
				'title'       => __( 'Single – Repost', 'nop-indieweb' ),
				'description' => __( 'Displays a repost with the reposted URL.', 'nop-indieweb' ),
				'file'        => 'single-nop_kind-repost.html',
			],
			'nop-indieweb//single-nop_kind-rsvp' => [
				'title'       => __( 'Single – RSVP', 'nop-indieweb' ),
				'description' => __( 'Displays an RSVP post with the event URL and response.', 'nop-indieweb' ),
				'file'        => 'single-nop_kind-rsvp.html',
			],
			'nop-indieweb//single-nop_kind-watch' => [
				'title'       => __( 'Single – Film Diary Entry', 'nop-indieweb' ),
				'description' => __( 'Displays a Letterboxd film diary entry with star rating, poster, watch date, and review.', 'nop-indieweb' ),
				'file'        => 'single-nop_kind-watch.html',
			],

			// ── Post-format fallback (theme compat — not used by new posts) ──────────
			'nop-indieweb//single-post-format-status' => [
				'title'       => __( 'Single – Status Post (legacy)', 'nop-indieweb' ),
				'description' => __( 'Fallback for posts with post_format=status set by a theme. New posts use single-nop_kind-{slug} instead.', 'nop-indieweb' ),
				'file'        => 'single-post-format-status.html',
			],

			// ── Kind archive templates (taxonomy-nop_kind-{slug}) ───────────────────────
			'nop-indieweb//taxonomy-nop_kind-watch' => [
				'title'       => __( 'Archive – Film Diary', 'nop-indieweb' ),
				'description' => __( 'Three-column poster grid for the watch kind. Shows poster, star rating, title, and watch date.', 'nop-indieweb' ),
				'file'        => 'taxonomy-nop_kind-watch.html',
			],
			'nop-indieweb//taxonomy-nop_kind-note' => [
				'title'       => __( 'Archive – Notes', 'nop-indieweb' ),
				'description' => __( 'Dense chronological stream for imported social posts. Shows content and platform attribution.', 'nop-indieweb' ),
				'file'        => 'taxonomy-nop_kind-note.html',
			],
			'nop-indieweb//taxonomy-nop_kind-checkin' => [
				'title'       => __( 'Archive – Checkins', 'nop-indieweb' ),
				'description' => __( 'Venue list for Swarm checkin posts. Shows venue name, locality, and date.', 'nop-indieweb' ),
				'file'        => 'taxonomy-nop_kind-checkin.html',
			],
			'nop-indieweb//taxonomy-nop_kind-bookmark' => [
				'title'       => __( 'Archive – Bookmarks', 'nop-indieweb' ),
				'description' => __( 'Reading list of bookmarked URLs with optional notes.', 'nop-indieweb' ),
				'file'        => 'taxonomy-nop_kind-bookmark.html',
			],
			'nop-indieweb//taxonomy-nop_kind-like' => [
				'title'       => __( 'Archive – Likes', 'nop-indieweb' ),
				'description' => __( 'Compact list of liked posts across the web.', 'nop-indieweb' ),
				'file'        => 'taxonomy-nop_kind-like.html',
			],
			'nop-indieweb//taxonomy-nop_kind-repost' => [
				'title'       => __( 'Archive – Reposts', 'nop-indieweb' ),
				'description' => __( 'Compact list of reposted content from Mastodon and Bluesky.', 'nop-indieweb' ),
				'file'        => 'taxonomy-nop_kind-repost.html',
			],
			'nop-indieweb//taxonomy-nop_kind-reply' => [
				'title'       => __( 'Archive – Replies', 'nop-indieweb' ),
				'description' => __( 'Conversation list showing in-reply-to context and reply content.', 'nop-indieweb' ),
				'file'        => 'taxonomy-nop_kind-reply.html',
			],
			'nop-indieweb//taxonomy-nop_kind-rsvp' => [
				'title'       => __( 'Archive – RSVPs', 'nop-indieweb' ),
				'description' => __( 'Event response list showing RSVP status and event link.', 'nop-indieweb' ),
				'file'        => 'taxonomy-nop_kind-rsvp.html',
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
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/like-button' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/post-source' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/film-meta' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/rsvp-meta' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/film-card' );
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
