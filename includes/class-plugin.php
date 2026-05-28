<?php
declare( strict_types=1 );

namespace NOP\IndieWeb;

use NOP\IndieWeb\Kind\Kind_Taxonomy;
use NOP\IndieWeb\Kind\Venue_Category_Taxonomy;
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
use NOP\IndieWeb\Admin\Post_Kinds_Panel;
use NOP\IndieWeb\Lookup\Lookup_Provider_TMDB;
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

	private ?Syndication_Manager $syndication_manager = null;

	/** @var Services\Service_Base[] */
	private array $services = [];

	/** @var \NOP\IndieWeb\Lookup\Lookup_Provider_Base[] */
	private array $lookup_providers = [];

	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function syndication_manager(): ?Syndication_Manager {
		return $this->syndication_manager;
	}

	/**
	 * WP-Cron callback: runs a service's after_insert() in the background.
	 *
	 * Retrieves the parsed data from the transient set by Service_Base::handle(),
	 * finds the matching service by slug, and delegates. The transient is deleted
	 * whether or not a matching service is found so it doesn't persist indefinitely.
	 */
	public function dispatch_after_insert( int $post_id, string $service_slug ): void {
		$transient_key = 'nop_ia_parsed_' . $post_id;
		$parsed        = get_transient( $transient_key );
		delete_transient( $transient_key );

		if ( ! is_array( $parsed ) ) {
			\NOP\IndieWeb\nop_indieweb_log( "dispatch_after_insert: no parsed data for post {$post_id}" );
			return;
		}

		foreach ( $this->services as $service ) {
			if ( $service instanceof Services\Service_Base && $service->get_slug() === $service_slug ) {
				$service->run_after_insert( $post_id, $parsed );
				return;
			}
		}

		\NOP\IndieWeb\nop_indieweb_log( "dispatch_after_insert: no service found for slug '{$service_slug}'" );
	}

	public function boot(): void {
		// One-shot migration: drop autoload on the settings option so plaintext
		// syndication credentials aren't kept in memory on every request.
		nop_indieweb_maybe_disable_settings_autoload();

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
		$this->services = $services;

		// Dispatch background after_insert jobs queued by Service_Base::handle().
		// The cron event carries the post_id and service slug; the parsed data
		// is retrieved from a short-lived transient set at insert time.
		add_action( 'nop_indieweb_run_after_insert', [ $this, 'dispatch_after_insert' ], 10, 2 );

		$syndication_manager       = new Syndication_Manager();
		$this->syndication_manager = $syndication_manager;
		$syndication_manager->register();
		( new Feed_Importer( $note, $letterboxd ) )->register();

		( new Kind_Taxonomy() )->register();
		( new Venue_Category_Taxonomy() )->register();
		( new Registry() )->register();
		( new Block_Bindings() )->register();
		( new Endpoint( $services, $syndication_manager ) )->register();
		( new Media_Endpoint() )->register();
		( new Token_Endpoint() )->register();
		( new Auth_Endpoint() )->register();
		( new Webmention_Endpoint() )->register();
		( new Webmention_Sender() )->register();
		( new Like_Endpoint() )->register();
		( new Social_Backfeed() )->register();
		( new Post_Filter() )->register();
		( new Semantic_Markup() )->register();
		( new MF2_Endpoint() )->register();

		$this->lookup_providers = apply_filters( 'nop_indieweb_register_lookup_providers', [
			new Lookup_Provider_TMDB(),
		] );

		add_action( 'init', [ $this, 'register_blocks' ] );
		add_action( 'init', [ $this, 'register_patterns' ] );
		add_action( 'init', [ $this, 'register_templates' ] );
		add_filter( 'block_categories_all', [ $this, 'register_block_categories' ] );
		add_action( 'rest_api_init', [ $this, 'register_indieauth_metadata_route' ] );
		add_action( 'rest_api_init', [ $this, 'register_lookup_route' ] );
		add_action( 'rest_api_init', [ $this, 'register_foursquare_oauth_routes' ] );

		if ( is_admin() ) {
			( new Settings() )->register();
			( new Debug( $services ) )->register();
			( new Post_Kinds_Panel() )->register();
			( new Syndication_Panel( $syndication_manager ) )->register();
		}

		add_action( 'before_delete_post', [ $this, 'delete_map_image' ] );
		add_action( 'before_delete_post', [ $this, 'renumber_venue_visits_on_delete' ] );
		add_action( 'trashed_post',       [ $this, 'renumber_venue_visits_on_status_change' ] );
		add_action( 'untrashed_post',     [ $this, 'renumber_venue_visits_on_status_change' ] );
		add_action( 'wp_head',      [ $this, 'output_link_tags' ] );
		add_action( 'send_headers', [ $this, 'output_link_headers' ] );

		// Inject kind-based templates into the block-theme single-post hierarchy.
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

		// Subtract webmentions from the count WordPress already calculated, rather
		// than re-counting all non-webmention comments — one query, same result.
		// Memoise per request so a listing page that renders the same post twice
		// only hits the DB once.
		static $webmention_counts = [];
		if ( ! isset( $webmention_counts[ $post_id ] ) ) {
			$webmention_counts[ $post_id ] = (int) get_comments( [
				'post_id' => $post_id,
				'type'    => 'webmention',
				'status'  => 'approve',
				'count'   => true,
			] );
		}

		return max( 0, (int) $count - $webmention_counts[ $post_id ] );
	}

	public function inject_kind_template( array $templates ): array {
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return $templates;
		}

		$kind = get_post_meta( $post->ID, 'nop_indieweb_post_kind', true );
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
			'nop-indieweb//single-nop_kind-article' => [
				'title'       => __( 'Single – Article', 'nop-indieweb' ),
				'description' => __( 'Displays a long-form article with title, date, tags, and comments.', 'nop-indieweb' ),
				'file'        => 'single-nop_kind-article.html',
			],
			'nop-indieweb//single-nop_kind-photo' => [
				'title'       => __( 'Single – Photo', 'nop-indieweb' ),
				'description' => __( 'Displays a photo post with featured image, caption, and syndication source.', 'nop-indieweb' ),
				'file'        => 'single-nop_kind-photo.html',
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
			'nop-indieweb//taxonomy-nop_kind-article' => [
				'title'       => __( 'Archive – Articles', 'nop-indieweb' ),
				'description' => __( 'Chronological list of long-form articles with title, date, tags, and excerpt.', 'nop-indieweb' ),
				'file'        => 'taxonomy-nop_kind-article.html',
			],
			'nop-indieweb//taxonomy-nop_venue_category' => [
				'title'       => __( 'Archive – Venue Category', 'nop-indieweb' ),
				'description' => __( 'Lists every check-in in a Foursquare venue category (Yoga Studios, Parks, Bars, etc.).', 'nop-indieweb' ),
				'file'        => 'taxonomy-nop_venue_category.html',
			],
		];

		// register_block_template() takes content (not a path), so without a
		// cache we'd read 18 files on every request including REST/AJAX. The
		// transient is keyed on plugin version AND a hash of file mtimes so it
		// invalidates on upgrade or on any template edit (filemtime is a cheap
		// stat-cached call).
		$mtimes = [];
		foreach ( $templates as $id => $template ) {
			$path           = $dir . $template['file'];
			$mtimes[ $id ]  = is_readable( $path ) ? filemtime( $path ) : 0;
		}
		$cache_key = 'nop_indieweb_template_contents_' . NOP_INDIEWEB_VERSION
		           . '_' . substr( md5( implode( ',', $mtimes ) ), 0, 8 );
		$contents  = get_transient( $cache_key );

		if ( false === $contents ) {
			$contents = [];
			foreach ( $templates as $id => $template ) {
				$path = $dir . $template['file'];
				if ( ! is_readable( $path ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
					trigger_error( "NOP IndieWeb: template file missing: {$path}", E_USER_WARNING );
					continue;
				}
				$contents[ $id ] = file_get_contents( $path );
			}
			set_transient( $cache_key, $contents, DAY_IN_SECONDS );
		}

		foreach ( $templates as $id => $template ) {
			if ( ! isset( $contents[ $id ] ) ) {
				continue;
			}
			register_block_template( $id, [
				'title'       => $template['title'],
				'description' => $template['description'],
				'content'     => $contents[ $id ],
			] );
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

		// Shared like-action handler used by both the like-button view.js and the
		// post-footer view.js. Avoids shipping the same fetch/animation logic twice.
		wp_register_script(
			'nop-like-action',
			NOP_INDIEWEB_URL . 'assets/js/nop-like-action.js',
			[],
			NOP_INDIEWEB_VERSION,
			true
		);

		register_block_type( NOP_INDIEWEB_DIR . 'blocks/checkin-map' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/weather-icon' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/weather-temp' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/webmentions' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/like-button' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/post-source' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/post-footer' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/film-meta' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/rsvp-meta' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/film-card' );
		register_block_type( NOP_INDIEWEB_DIR . 'blocks/syndication-panel' );
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

	public function register_patterns(): void {
		register_block_pattern_category(
			'nop-indieweb',
			[ 'label' => __( 'IndieWeb', 'nop-indieweb' ) ]
		);

		register_block_pattern( 'nop-indieweb/checkin-post', [
			'title'         => __( 'Checkin Post', 'nop-indieweb' ),
			'description'   => __( 'Granular venue blocks (categories, name, address, coordinates, map, venue link) bound to checkin post meta. Each block is individually styleable.', 'nop-indieweb' ),
			'categories'    => [ 'nop-indieweb' ],
			'keywords'      => [ 'checkin', 'swarm', 'venue', 'location', 'indieweb' ],
			'viewportWidth' => 800,
			'content'       => <<<'HTML'
<!-- wp:group {"style":{"spacing":{"blockGap":"1rem"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group">

<!-- wp:post-terms {"term":"nop_venue_category","separator":" · "} /-->

<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"name"}}}}} -->
<p>Venue name</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"address"}}}}} -->
<p>Street address</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"locality_country"}}}}} -->
<p>Locality, country</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"venue_coordinates"}}}}} -->
<p>Coordinates</p>
<!-- /wp:paragraph -->

<!-- wp:nop-indieweb/checkin-map /-->

<!-- wp:buttons -->
<div class="wp-block-buttons">
<!-- wp:button {"metadata":{"bindings":{"url":{"source":"nop-indieweb/post-meta","args":{"field":"url"}},"text":{"source":"nop-indieweb/post-meta","args":{"field":"venue_url_host_label"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#" target="_blank" rel="noopener noreferrer">View venue</a></div>
<!-- /wp:button -->

<!-- wp:button {"metadata":{"bindings":{"url":{"source":"nop-indieweb/post-meta","args":{"key":"nop_indieweb_checkin_url"}},"text":{"source":"nop-indieweb/post-meta","args":{"field":"checkin_url_host_label"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#" target="_blank" rel="noopener noreferrer">View checkin</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->

<!-- wp:post-date {"format":"j F Y, g:i a","isLink":false} /-->

</div>
<!-- /wp:group -->
HTML,
		] );

		register_block_pattern( 'nop-indieweb/venue-address', [
			'title'         => __( 'Venue Address', 'nop-indieweb' ),
			'description'   => __( 'Single paragraph bound to the full venue address (street, locality, country). Emits p-adr microformat class.', 'nop-indieweb' ),
			'categories'    => [ 'nop-indieweb' ],
			'keywords'      => [ 'venue', 'address', 'checkin', 'indieweb' ],
			'viewportWidth' => 600,
			'content'       => <<<'HTML'
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"full_address"}}}},"fontSize":"small"} -->
<p class="has-small-font-size">Address</p>
<!-- /wp:paragraph -->
HTML,
		] );

		register_block_pattern( 'nop-indieweb/venue-link-button', [
			'title'         => __( 'Venue Link Button', 'nop-indieweb' ),
			'description'   => __( 'Button linking to the Foursquare venue page. URL and label text both bind to post meta. Emits u-url on the anchor.', 'nop-indieweb' ),
			'categories'    => [ 'nop-indieweb' ],
			'keywords'      => [ 'venue', 'link', 'button', 'foursquare', 'checkin' ],
			'viewportWidth' => 400,
			'content'       => <<<'HTML'
<!-- wp:buttons -->
<div class="wp-block-buttons">
<!-- wp:button {"metadata":{"bindings":{"url":{"source":"nop-indieweb/post-meta","args":{"field":"url"}},"text":{"source":"nop-indieweb/post-meta","args":{"field":"venue_url_host_label"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#" target="_blank" rel="noopener noreferrer">View venue</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
HTML,
		] );

		register_block_pattern( 'nop-indieweb/checkin-meta-strip', [
			'title'         => __( 'Checkin Meta Strip', 'nop-indieweb' ),
			'description'   => __( 'Horizontal header row for a checkin: location pin, "Check-in" label, venue categories, and the post date. Designed to sit above the venue title.', 'nop-indieweb' ),
			'categories'    => [ 'nop-indieweb' ],
			'keywords'      => [ 'checkin', 'header', 'meta', 'categories' ],
			'viewportWidth' => 800,
			'content'       => <<<'HTML'
<!-- wp:group {"style":{"typography":{"fontStyle":"normal","fontWeight":"700","textTransform":"uppercase","letterSpacing":"2px"},"spacing":{"blockGap":"6px"}},"fontSize":"small","layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->
<div class="wp-block-group has-small-font-size" style="font-style:normal;font-weight:700;letter-spacing:2px;text-transform:uppercase">
<!-- wp:group {"style":{"spacing":{"blockGap":"0.5em"}},"layout":{"type":"flex","flexWrap":"wrap"}} -->
<div class="wp-block-group">
<!-- wp:icon {"icon":"core/map-marker","style":{"dimensions":{"width":"1em"}}} /-->
<!-- wp:paragraph -->
<p>Check-in</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p>·</p>
<!-- /wp:paragraph -->
<!-- wp:post-terms {"term":"nop_venue_category","separator":" "} /-->
</div>
<!-- /wp:group -->
<!-- wp:post-date {"format":"G:i · d.m.Y"} /-->
</div>
<!-- /wp:group -->
HTML,
		] );

		register_block_pattern( 'nop-indieweb/weather-row', [
			'title'         => __( 'Weather Row', 'nop-indieweb' ),
			'description'   => __( 'Weather icon + temperature for a checkin, sourced from the snapshotted weather meta. Use beside the venue address.', 'nop-indieweb' ),
			'categories'    => [ 'nop-indieweb' ],
			'keywords'      => [ 'weather', 'checkin', 'temperature' ],
			'viewportWidth' => 400,
			'content'       => <<<'HTML'
<!-- wp:group {"style":{"spacing":{"blockGap":"6px"}},"fontSize":"small","layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
<div class="wp-block-group has-small-font-size">
<!-- wp:nop-indieweb/weather-icon {"fontSize":"small"} /-->
<!-- wp:nop-indieweb/weather-temp {"fontSize":"small"} /-->
</div>
<!-- /wp:group -->
HTML,
		] );

		register_block_pattern( 'nop-indieweb/checkin-data-palette', [
			'title'         => __( 'Checkin Data Palette', 'nop-indieweb' ),
			'description'   => __( 'Every meaningful piece of data a check-in post carries — bindings + custom blocks, each labelled. Insert this into a checkin post, then copy whichever pieces you want into your real layout. Not for production use.', 'nop-indieweb' ),
			'categories'    => [ 'nop-indieweb' ],
			'keywords'      => [ 'checkin', 'data', 'palette', 'reference', 'design' ],
			'viewportWidth' => 900,
			'content'       => <<<'HTML'
<!-- wp:group {"metadata":{"name":"Checkin Data Palette"},"style":{"spacing":{"blockGap":"2.5rem","padding":{"top":"2rem","bottom":"2rem","left":"2rem","right":"2rem"}},"border":{"width":"1px","color":"#e5e7eb"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group has-border-color" style="border-color:#e5e7eb;border-width:1px;padding-top:2rem;padding-right:2rem;padding-bottom:2rem;padding-left:2rem">

<!-- wp:heading {"level":2,"fontSize":"x-large"} -->
<h2 class="wp-block-heading has-x-large-font-size">Checkin Data Palette</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"style":{"color":{"text":"#6b7280"}}} -->
<p class="has-text-color" style="color:#6b7280">Every piece of data this checkin carries, grouped by section. Copy any block into your real layout.</p>
<!-- /wp:paragraph -->

<!-- wp:separator {"backgroundColor":"accent-6"} --><hr class="wp-block-separator has-text-color has-accent-6-color has-alpha-channel-opacity has-accent-6-background-color has-background"/><!-- /wp:separator -->

<!-- wp:heading {"level":4,"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.1em","fontSize":"0.75rem"},"color":{"text":"#6b7280"}}} -->
<h4 class="wp-block-heading has-text-color" style="color:#6b7280;font-size:0.75rem;letter-spacing:0.1em;text-transform:uppercase">Identity</h4>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-title (core)</p><!-- /wp:paragraph -->
<!-- wp:post-title /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-date (core)</p><!-- /wp:paragraph -->
<!-- wp:post-date {"format":"j F Y, G:i"} /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-author (core)</p><!-- /wp:paragraph -->
<!-- wp:post-author {"showAvatar":false} /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-terms · nop_kind (core)</p><!-- /wp:paragraph -->
<!-- wp:post-terms {"term":"nop_kind"} /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-terms · category (core)</p><!-- /wp:paragraph -->
<!-- wp:post-terms {"term":"category"} /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-terms · post_tag (core)</p><!-- /wp:paragraph -->
<!-- wp:post-terms {"term":"post_tag"} /-->

<!-- wp:separator {"backgroundColor":"accent-6"} --><hr class="wp-block-separator has-text-color has-accent-6-color has-alpha-channel-opacity has-accent-6-background-color has-background"/><!-- /wp:separator -->

<!-- wp:heading {"level":4,"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.1em","fontSize":"0.75rem"},"color":{"text":"#6b7280"}}} -->
<h4 class="wp-block-heading has-text-color" style="color:#6b7280;font-size:0.75rem;letter-spacing:0.1em;text-transform:uppercase">Venue</h4>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">venue name (binding: field=name) — adds p-name</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"name"}}}}} --><p>The Crown Bar</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">full address (binding: field=full_address, derived) — adds p-adr</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"full_address"}}}}} --><p>46 Great Victoria Street, Belfast, United Kingdom</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">street address (binding: field=address) — adds p-street-address</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"address"}}}}} --><p>46 Great Victoria Street</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">locality (binding: field=locality) — adds p-locality</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"locality"}}}}} --><p>Belfast</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">region (binding: field=region) — adds p-region</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"region"}}}}} --><p>County Antrim</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">country (binding: field=country) — adds p-country-name</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"country"}}}}} --><p>United Kingdom</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">postcode (binding: field=postcode) — adds p-postal-code</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"postcode"}}}}} --><p>BT2 7BA</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">locality + country (binding: field=locality_country, derived)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"locality_country"}}}}} --><p>Belfast, United Kingdom</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">coordinates (binding: field=venue_coordinates, derived)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"venue_coordinates"}}}}} --><p>54.597 ° N · 5.935 ° W</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">latitude (binding: field=lat)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"lat"}}}}} --><p>54.5967</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">longitude (binding: field=lng)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"lng"}}}}} --><p>-5.9347</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">visit number (binding: field=venue_visit_number, derived) — "1st visit", "2nd visit", etc.</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"venue_visit_number"}}}}} --><p>1st Visit</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-terms · nop_venue_category (core) — adds p-category</p><!-- /wp:paragraph -->
<!-- wp:post-terms {"term":"nop_venue_category","separator":" · "} /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">venue link button (button bindings: url=venue_url, text=venue_url_host_label) — adds u-url</p><!-- /wp:paragraph -->
<!-- wp:buttons --><div class="wp-block-buttons">
<!-- wp:button {"metadata":{"bindings":{"url":{"source":"nop-indieweb/post-meta","args":{"field":"url"}},"text":{"source":"nop-indieweb/post-meta","args":{"field":"venue_url_host_label"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#" target="_blank" rel="noopener noreferrer">View on foursquare.com</a></div>
<!-- /wp:button -->
</div><!-- /wp:buttons -->

<!-- wp:separator {"backgroundColor":"accent-6"} --><hr class="wp-block-separator has-text-color has-accent-6-color has-alpha-channel-opacity has-accent-6-background-color has-background"/><!-- /wp:separator -->

<!-- wp:heading {"level":4,"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.1em","fontSize":"0.75rem"},"color":{"text":"#6b7280"}}} -->
<h4 class="wp-block-heading has-text-color" style="color:#6b7280;font-size:0.75rem;letter-spacing:0.1em;text-transform:uppercase">Check-in source</h4>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">checkin link button (button bindings: url=nop_indieweb_checkin_url, text=checkin_url_host_label) — adds u-url</p><!-- /wp:paragraph -->
<!-- wp:buttons --><div class="wp-block-buttons">
<!-- wp:button {"metadata":{"bindings":{"url":{"source":"nop-indieweb/post-meta","args":{"key":"nop_indieweb_checkin_url"}},"text":{"source":"nop-indieweb/post-meta","args":{"field":"checkin_url_host_label"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#" target="_blank" rel="noopener noreferrer">View on swarmapp.com</a></div>
<!-- /wp:button -->
</div><!-- /wp:buttons -->

<!-- wp:separator {"backgroundColor":"accent-6"} --><hr class="wp-block-separator has-text-color has-accent-6-color has-alpha-channel-opacity has-accent-6-background-color has-background"/><!-- /wp:separator -->

<!-- wp:heading {"level":4,"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.1em","fontSize":"0.75rem"},"color":{"text":"#6b7280"}}} -->
<h4 class="wp-block-heading has-text-color" style="color:#6b7280;font-size:0.75rem;letter-spacing:0.1em;text-transform:uppercase">Weather</h4>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">weather-icon (custom block — inlines SVG from slug)</p><!-- /wp:paragraph -->
<!-- wp:nop-indieweb/weather-icon /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">weather-temp (custom block)</p><!-- /wp:paragraph -->
<!-- wp:nop-indieweb/weather-temp /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">weather summary (binding: key=nop_indieweb_weather_summary)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"key":"nop_indieweb_weather_summary"}}}}} --><p>Light Rain</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">weather temp °C (binding: key=nop_indieweb_weather_temp_c)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"key":"nop_indieweb_weather_temp_c"}}}}} --><p>9.3</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">weather temp °F (binding: key=nop_indieweb_weather_temp_f)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"key":"nop_indieweb_weather_temp_f"}}}}} --><p>48.7</p><!-- /wp:paragraph -->

<!-- wp:separator {"backgroundColor":"accent-6"} --><hr class="wp-block-separator has-text-color has-accent-6-color has-alpha-channel-opacity has-accent-6-background-color has-background"/><!-- /wp:separator -->

<!-- wp:heading {"level":4,"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.1em","fontSize":"0.75rem"},"color":{"text":"#6b7280"}}} -->
<h4 class="wp-block-heading has-text-color" style="color:#6b7280;font-size:0.75rem;letter-spacing:0.1em;text-transform:uppercase">Visual</h4>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">checkin-map (custom block — Geoapify static map with marker + attribution overlay)</p><!-- /wp:paragraph -->
<!-- wp:nop-indieweb/checkin-map /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-featured-image (core)</p><!-- /wp:paragraph -->
<!-- wp:post-featured-image /-->

<!-- wp:separator {"backgroundColor":"accent-6"} --><hr class="wp-block-separator has-text-color has-accent-6-color has-alpha-channel-opacity has-accent-6-background-color has-background"/><!-- /wp:separator -->

<!-- wp:heading {"level":4,"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.1em","fontSize":"0.75rem"},"color":{"text":"#6b7280"}}} -->
<h4 class="wp-block-heading has-text-color" style="color:#6b7280;font-size:0.75rem;letter-spacing:0.1em;text-transform:uppercase">Content</h4>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-content (core)</p><!-- /wp:paragraph -->
<!-- wp:post-content /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-excerpt (core)</p><!-- /wp:paragraph -->
<!-- wp:post-excerpt /-->

<!-- wp:separator {"backgroundColor":"accent-6"} --><hr class="wp-block-separator has-text-color has-accent-6-color has-alpha-channel-opacity has-accent-6-background-color has-background"/><!-- /wp:separator -->

<!-- wp:heading {"level":4,"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.1em","fontSize":"0.75rem"},"color":{"text":"#6b7280"}}} -->
<h4 class="wp-block-heading has-text-color" style="color:#6b7280;font-size:0.75rem;letter-spacing:0.1em;text-transform:uppercase">Interactions</h4>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">like-button (custom block — site likes + aggregated webmention likes)</p><!-- /wp:paragraph -->
<!-- wp:nop-indieweb/like-button /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">webmentions (custom block — facepile + threaded replies)</p><!-- /wp:paragraph -->
<!-- wp:nop-indieweb/webmentions /-->

<!-- wp:separator {"backgroundColor":"accent-6"} --><hr class="wp-block-separator has-text-color has-accent-6-color has-alpha-channel-opacity has-accent-6-background-color has-background"/><!-- /wp:separator -->

<!-- wp:heading {"level":4,"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.1em","fontSize":"0.75rem"},"color":{"text":"#6b7280"}}} -->
<h4 class="wp-block-heading has-text-color" style="color:#6b7280;font-size:0.75rem;letter-spacing:0.1em;text-transform:uppercase">Provenance</h4>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">syndication-panel (custom block — "Also on Mastodon · Bluesky · Swarm")</p><!-- /wp:paragraph -->
<!-- wp:nop-indieweb/syndication-panel /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-source (custom block — originating platform link)</p><!-- /wp:paragraph -->
<!-- wp:nop-indieweb/post-source /-->

</div>
<!-- /wp:group -->
HTML,
		] );

		register_block_pattern( 'nop-indieweb/note-post', [
			'title'         => __( 'Note Post', 'nop-indieweb' ),
			'description'   => __( 'Short-form note layout: inline kind/date header, featured image, content, and a compact interaction row (like · comments · reposts · source).', 'nop-indieweb' ),
			'categories'    => [ 'nop-indieweb' ],
			'keywords'      => [ 'note', 'social', 'indieweb', 'like', 'webmention' ],
			'viewportWidth' => 800,
			'content'       => <<<'HTML'
<!-- wp:group {"style":{"spacing":{"blockGap":"0"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group">

<!-- wp:group {"style":{"spacing":{"padding":{"top":"2rem","bottom":"1.5rem"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="padding-top:2rem;padding-bottom:1.5rem">
<!-- wp:group {"style":{"spacing":{"blockGap":"0.5rem"}},"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
<div class="wp-block-group">
<!-- wp:paragraph {"style":{"color":{"text":"#6b7280"},"typography":{"fontSize":"0.6875rem","fontWeight":"600","letterSpacing":"0.1em","textTransform":"uppercase"}}} -->
<p class="has-text-color" style="color:#6b7280;font-size:0.6875rem;font-weight:600;letter-spacing:0.1em;text-transform:uppercase">Note</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.6875rem"}}} -->
<p class="has-text-color" style="color:#9ca3af;font-size:0.6875rem" aria-hidden="true">·</p>
<!-- /wp:paragraph -->
<!-- wp:post-date {"format":"j M Y, H:i","style":{"color":{"text":"#6b7280"},"typography":{"fontSize":"0.6875rem"}}} /-->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->

<!-- wp:post-featured-image {"align":"wide","style":{"spacing":{"margin":{"top":"0","bottom":"0"}}}} /-->

<!-- wp:group {"style":{"spacing":{"padding":{"top":"1.5rem","bottom":"1.25rem"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="padding-top:1.5rem;padding-bottom:1.25rem">
<!-- wp:post-content {"layout":{"type":"constrained"}} /-->
</div>
<!-- /wp:group -->

<!-- wp:group {"style":{"spacing":{"padding":{"top":"0","bottom":"2.5rem"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="padding-top:0;padding-bottom:2.5rem">
<!-- wp:nop-indieweb/post-footer /-->
</div>
<!-- /wp:group -->

</div>
<!-- /wp:group -->
HTML,
		] );
	}

	public function register_indieauth_metadata_route(): void {
		register_rest_route( 'nop-indieweb/v1', '/indieauth-metadata', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'indieauth_metadata_response' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function register_lookup_route(): void {
		register_rest_route( 'nop-indieweb/v1', '/lookup', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'lookup_route_handler' ],
			'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			'args'                => [
				'provider' => [ 'required' => true,  'sanitize_callback' => 'sanitize_key' ],
				'q'        => [ 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );
	}

	public function lookup_route_handler( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$slug     = $request->get_param( 'provider' );
		$query    = $request->get_param( 'q' );
		$provider = null;

		foreach ( $this->lookup_providers as $p ) {
			if ( $p->get_slug() === $slug ) {
				$provider = $p;
				break;
			}
		}

		if ( ! $provider ) {
			return new \WP_Error( 'unknown_provider', 'Unknown lookup provider.', [ 'status' => 400 ] );
		}

		$results = $provider->search( $query );
		if ( is_wp_error( $results ) ) {
			return $results;
		}

		return new \WP_REST_Response( [ 'results' => $results ], 200 );
	}

	public function register_foursquare_oauth_routes(): void {
		register_rest_route( 'nop-indieweb/v1', '/foursquare-auth', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'foursquare_auth_redirect' ],
			'permission_callback' => '__return_true',
		] );
		register_rest_route( 'nop-indieweb/v1', '/foursquare-callback', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'foursquare_oauth_callback' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function foursquare_auth_redirect(): void {
		$opts          = get_option( 'nop_indieweb_settings', [] );
		$client_id     = $opts['venue']['foursquare_client_id'] ?? '';
		$callback_url  = rest_url( 'nop-indieweb/v1/foursquare-callback' );

		if ( ! $client_id ) {
			wp_die( esc_html__( 'Foursquare Client ID not configured in plugin settings.', 'nop-indieweb' ) );
		}

		// CSRF protection. This route and its callback are OAuth redirect targets
		// reached by plain browser navigation, so a WordPress nonce can't guard
		// them (REST treats cookie-only requests as anonymous, and Foursquare
		// can't echo a nonce). Instead we issue a one-time random state, stash it
		// server-side, and require it back on the callback before exchanging the
		// code — the standard OAuth defence against forged callbacks.
		$state = wp_generate_password( 32, false );
		set_transient( 'nop_indieweb_fsq_oauth_state', $state, 15 * MINUTE_IN_SECONDS );

		$url = add_query_arg( [
			'client_id'     => $client_id,
			'response_type' => 'code',
			'redirect_uri'  => $callback_url,
			'state'         => $state,
		], 'https://foursquare.com/oauth2/authenticate' );

		wp_redirect( $url );
		exit;
	}

	public function foursquare_oauth_callback( \WP_REST_Request $request ): void {
		$code  = sanitize_text_field( $request->get_param( 'code' ) ?? '' );
		$error = sanitize_text_field( $request->get_param( 'error' ) ?? '' );
		$state = sanitize_text_field( $request->get_param( 'state' ) ?? '' );

		// Verify the state issued by foursquare_auth_redirect(). One-time use —
		// delete it whether or not it matches so a leaked value can't be replayed.
		$expected = get_transient( 'nop_indieweb_fsq_oauth_state' );
		delete_transient( 'nop_indieweb_fsq_oauth_state' );
		if ( ! $expected || '' === $state || ! hash_equals( (string) $expected, $state ) ) {
			wp_die( esc_html__( 'Invalid or expired authorisation request. Please start the Foursquare connection again.', 'nop-indieweb' ) );
		}

		if ( $error || ! $code ) {
			wp_die( esc_html( sprintf(
				/* translators: %s: error message returned by Foursquare */
				__( 'Foursquare authorisation denied or failed: %s', 'nop-indieweb' ),
				$error ?: __( 'no code returned', 'nop-indieweb' )
			) ) );
		}

		$opts          = get_option( 'nop_indieweb_settings', [] );
		$client_id     = $opts['venue']['foursquare_client_id'] ?? '';
		$client_secret = $opts['venue']['foursquare_client_secret'] ?? '';
		$callback_url  = rest_url( 'nop-indieweb/v1/foursquare-callback' );

		$response = wp_remote_post( 'https://foursquare.com/oauth2/access_token', [
			'timeout' => 15,
			'body'    => [
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'grant_type'    => 'authorization_code',
				'redirect_uri'  => $callback_url,
				'code'          => $code,
			],
		] );

		if ( is_wp_error( $response ) ) {
			wp_die( esc_html( sprintf(
				/* translators: %s: HTTP error message */
				__( 'Token exchange failed: %s', 'nop-indieweb' ),
				$response->get_error_message()
			) ) );
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$token = $body['access_token'] ?? '';

		if ( ! $token ) {
			// Don't echo the raw response body — an error payload can include the
			// client_secret we just sent.
			wp_die( esc_html__( 'No access token in Foursquare response.', 'nop-indieweb' ) );
		}

		// Write via the helper so the secrets-bearing settings option keeps
		// autoload=false (a raw update_option here would re-enable autoload and
		// put credentials in memory on every request).
		\NOP\IndieWeb\nop_indieweb_update_option( 'venue.foursquare_user_token', $token );

		wp_die(
			'<h2>' . esc_html__( 'Foursquare connected!', 'nop-indieweb' ) . '</h2><p>'
			. esc_html__( 'Your personal access token has been saved. You can close this tab and run the importer.', 'nop-indieweb' )
			. '</p>',
			esc_html__( 'Foursquare connected', 'nop-indieweb' ),
			[ 'response' => 200 ]
		);
	}

	public function indieauth_metadata_response(): \WP_REST_Response {
		return new \WP_REST_Response( [
			'issuer'                                => home_url( '/' ),
			'authorization_endpoint'                => Auth_Endpoint::url(),
			'token_endpoint'                        => rest_url( 'nop-indieweb/v1/token' ),
			'code_challenge_methods_supported'      => [ 'S256' ],
			'scopes_supported'                      => [ 'create', 'update', 'delete', 'undelete', 'media', 'draft' ],
			'response_types_supported'              => [ 'code' ],
			'grant_types_supported'                 => [ 'authorization_code' ],
		], 200 );
	}

	public function output_link_tags(): void {
		printf( "<link rel=\"micropub\" href=\"%s\">\n",               esc_url( rest_url( 'nop-indieweb/v1/micropub' ) ) );
		printf( "<link rel=\"webmention\" href=\"%s\">\n",             esc_url( rest_url( 'nop-indieweb/v1/webmention' ) ) );
		printf( "<link rel=\"authorization_endpoint\" href=\"%s\">\n", esc_url( Auth_Endpoint::url() ) );
		printf( "<link rel=\"token_endpoint\" href=\"%s\">\n",         esc_url( rest_url( 'nop-indieweb/v1/token' ) ) );
		printf( "<link rel=\"indieauth-metadata\" href=\"%s\">\n",     esc_url( rest_url( 'nop-indieweb/v1/indieauth-metadata' ) ) );

		foreach ( $this->get_me_urls() as $url ) {
			printf( "<link rel=\"me\" href=\"%s\">\n", esc_url( $url ) );
		}
	}

	public function output_link_headers(): void {
		// Discovery headers are only useful on page responses — skip REST/AJAX/admin
		// where they add bytes nobody reads.
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		header( sprintf( 'Link: <%s>; rel="micropub"',               rest_url( 'nop-indieweb/v1/micropub' ) ), false );
		header( sprintf( 'Link: <%s>; rel="webmention"',             rest_url( 'nop-indieweb/v1/webmention' ) ), false );
		header( sprintf( 'Link: <%s>; rel="authorization_endpoint"', Auth_Endpoint::url() ),                  false );
		header( sprintf( 'Link: <%s>; rel="token_endpoint"',         rest_url( 'nop-indieweb/v1/token' ) ),  false );
		header( sprintf( 'Link: <%s>; rel="indieauth-metadata"',     rest_url( 'nop-indieweb/v1/indieauth-metadata' ) ), false );

		foreach ( $this->get_me_urls() as $url ) {
			header( sprintf( 'Link: <%s>; rel="me"', $url ), false );
		}
	}

	public function renumber_venue_visits_on_delete( int $post_id ): void {
		if ( 'post' !== get_post_type( $post_id ) ) {
			return;
		}
		$venue_id = $this->get_checkin_venue_id( $post_id );
		if ( $venue_id ) {
			$this->renumber_checkins_for_venue( $venue_id, $post_id );
		}
	}

	public function renumber_venue_visits_on_status_change( int $post_id ): void {
		if ( 'post' !== get_post_type( $post_id ) ) {
			return;
		}
		$venue_id = $this->get_checkin_venue_id( $post_id );
		if ( $venue_id ) {
			$this->renumber_checkins_for_venue( $venue_id );
		}
	}

	private function get_checkin_venue_id( int $post_id ): string {
		return (string) ( get_post_meta( $post_id, 'nop_indieweb_venue_uid', true )
			?: get_post_meta( $post_id, 'nop_indieweb_venue_fsq_id', true ) );
	}

	private function renumber_checkins_for_venue( string $venue_id, int $exclude_id = 0 ): void {
		global $wpdb;
		$exclude = $exclude_id ? $wpdb->prepare( 'AND p.ID != %d', $exclude_id ) : '';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$post_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT p.ID
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			 WHERE m.meta_key IN ('nop_indieweb_venue_uid', 'nop_indieweb_venue_fsq_id')
			 AND m.meta_value = %s
			 AND p.post_type = 'post'
			 AND p.post_status IN ('publish', 'draft', 'private')
			 {$exclude}
			 ORDER BY p.post_date ASC",
			$venue_id
		) );
		// phpcs:enable
		foreach ( $post_ids as $i => $post_id ) {
			update_post_meta( (int) $post_id, 'nop_indieweb_venue_visit_number', $i + 1 );
		}
	}

	public function delete_map_image( int $post_id ): void {
		if ( ! get_post_meta( $post_id, 'nop_indieweb_map_url', true ) ) {
			return;
		}
		$file = wp_upload_dir()['basedir'] . "/checkin-maps/checkin-map-{$post_id}.png";
		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
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
