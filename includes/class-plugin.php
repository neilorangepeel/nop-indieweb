<?php
declare( strict_types=1 );

namespace NOP\IndieWeb;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use NOP\IndieWeb\Kind\Kind_Taxonomy;
use NOP\IndieWeb\Kind\Venue_Category_Taxonomy;
use NOP\IndieWeb\Kind\Exercise_Type_Taxonomy;
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
use NOP\IndieWeb\Services\Quote;
use NOP\IndieWeb\Services\RSVP;
use NOP\IndieWeb\Services\Exercise;
use NOP\IndieWeb\Semantic\Semantic_Markup;
use NOP\IndieWeb\Semantic\Open_Graph;
use NOP\IndieWeb\Semantic\MF2_Endpoint;
use NOP\IndieWeb\Admin\Settings;
use NOP\IndieWeb\Admin\Settings_API;
use NOP\IndieWeb\Posting_Page;
use NOP\IndieWeb\Admin\Post_Filter;
use NOP\IndieWeb\Admin\Debug;
use NOP\IndieWeb\Admin\Post_Kinds_Panel;
use NOP\IndieWeb\Lookup\Lookup_Provider_TMDB;
use NOP\IndieWeb\Admin\Syndication_Panel;
use NOP\IndieWeb\Admin\Syndication_Notice;
use NOP\IndieWeb\IndieAuth\Auth_Endpoint;
use NOP\IndieWeb\IndieAuth\Token_Endpoint;
use NOP\IndieWeb\Syndication\Syndication_Manager;
use NOP\IndieWeb\Importer\Feed_Importer;
use NOP\IndieWeb\WebSub;
use NOP\IndieWeb\AiPolicy\AI_Policy;
use NOP\IndieWeb\Exercise\Exercise_Endpoint;
use NOP\IndieWeb\Rsvp\Event_Endpoint;
use NOP\IndieWeb\Preview\Link_Endpoint;
use NOP\IndieWeb\Webmention\Webmention_Endpoint;
use NOP\IndieWeb\Webmention\Webmention_Sender;
use NOP\IndieWeb\Webmention\Like_Endpoint;
use NOP\IndieWeb\Webmention\Social_Backfeed;
use NOP\IndieWeb\Webmention\Comment_Filter;
use NOP\IndieWeb\Registrars\Block_Registrar;
use NOP\IndieWeb\Registrars\Pattern_Registrar;
use NOP\IndieWeb\Registrars\Template_Registrar;
use NOP\IndieWeb\Discovery\Link_Discovery;
use NOP\IndieWeb\Venue\Foursquare_OAuth;
use NOP\IndieWeb\Venue\Venue_Visit_Counter;
use NOP\IndieWeb\Rest\Authoring_Routes;

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

	/**
	 * Enriches a URL-response post with a captured cite of its target.
	 *
	 * Runs inline on the `nop_indieweb_before_post_insert` filter for any
	 * Url_Response_Service (like/bookmark/repost/reply/rsvp): fetches the target
	 * once, sets the post title to the real page title (falling back to today's
	 * domain when absent), and stores the cite_* meta. Best-effort — a failed or
	 * empty fetch leaves the post args untouched, so there's no regression.
	 *
	 * @param array $post_args Args bound for wp_insert_post (incl. meta_input).
	 * @param array $parsed    The service's parsed payload (carries 'url').
	 * @param mixed $service   The service handling the request.
	 */
	public function enrich_url_response_cite( array $post_args, array $parsed, $service ): array {
		if ( ! $service instanceof Services\Url_Response_Service ) {
			return $post_args;
		}
		$url = (string) ( $parsed['url'] ?? '' );
		if ( '' === $url ) {
			return $post_args;
		}

		$cite = ( new Webmention\Cite_Extractor() )->extract_from_url( $url );
		if ( empty( $cite ) ) {
			return $post_args;
		}

		// The Micropub post has only a domain title, so always override it.
		if ( ! empty( $cite['title'] ) ) {
			$post_args['post_title'] = $cite['title'];
		}

		// Shared mapper + source marker (see Cite_Enricher) so the editor hook
		// recognises this post as already enriched and skips re-fetching it.
		$post_args['meta_input'] = $post_args['meta_input'] ?? [];
		foreach ( Webmention\Cite_Enricher::meta_from_cite( $cite ) as $key => $value ) {
			$post_args['meta_input'][ $key ] = $value;
		}
		$post_args['meta_input']['_nop_indieweb_cite_source'] = $url;

		return $post_args;
	}

	public function boot(): void {
		// One-shot migration: drop autoload on the settings option so plaintext
		// syndication credentials aren't kept in memory on every request.
		nop_indieweb_maybe_disable_settings_autoload();

		$note       = new Note();
		$letterboxd = new Letterboxd();
		// RSVP must appear before Reply — both match in-reply-to and RSVP is the more specific case.
		// Exercise must appear before Note — both match generic h-entry.
		$services   = apply_filters( 'nop_indieweb_register_services', [
			new Swarm(),
			new Bookmark(),
			new RSVP(),
			new Reply(),
			new Like(),
			new Repost(),
			new Quote(),
			new Exercise(),
			$note,
		] );
		$this->services = $services;

		// Dispatch background after_insert jobs queued by Service_Base::handle().
		// The cron event carries the post_id and service slug; the parsed data
		// is retrieved from a short-lived transient set at insert time.
		add_action( 'nop_indieweb_run_after_insert', [ $this, 'dispatch_after_insert' ], 10, 2 );

		// Enrich URL-response posts (like/bookmark/repost/reply/rsvp) with a
		// captured cite of the target — title, author, excerpt, image — so they
		// carry real context instead of a bare link.
		add_filter( 'nop_indieweb_before_post_insert', [ $this, 'enrich_url_response_cite' ], 10, 3 );

		$syndication_manager       = new Syndication_Manager();
		$this->syndication_manager = $syndication_manager;
		$syndication_manager->register();
		( new Feed_Importer( $note, $letterboxd ) )->register();

		( new Kind_Taxonomy() )->register();
		( new Venue_Category_Taxonomy() )->register();
		( new Exercise_Type_Taxonomy() )->register();
		( new Registry() )->register();
		( new Block_Bindings() )->register();
		( new Endpoint( $services, $syndication_manager ) )->register();
		( new Media_Endpoint() )->register();
		( new Token_Endpoint() )->register();
		( new Auth_Endpoint() )->register();
		( new Webmention_Endpoint() )->register();
		( new Webmention_Sender() )->register();
		( new WebSub() )->register();
		( new Like_Endpoint() )->register();
		( new Social_Backfeed() )->register();
		( new Webmention\Cite_Enricher() )->register();
		( new Post_Filter() )->register();
		( new Semantic_Markup() )->register();
		( new Open_Graph() )->register();
		( new MF2_Endpoint() )->register();
		( new AI_Policy() )->register();
		( new Exercise_Endpoint() )->register();
		( new Event_Endpoint() )->register();
		( new Link_Endpoint() )->register();

		$lookup_providers = apply_filters( 'nop_indieweb_register_lookup_providers', [
			new Lookup_Provider_TMDB(),
		] );

		( new Block_Registrar() )->register();
		( new Pattern_Registrar() )->register();
		( new Template_Registrar() )->register();
		( new Link_Discovery() )->register();
		( new Foursquare_OAuth() )->register();
		( new \NOP\IndieWeb\Syndication\Tumblr_OAuth() )->register();
		( new Authoring_Routes( $lookup_providers ) )->register();
		( new Settings_API() )->register();
		( new Posting_Page() )->register();
		( new Health_Check() )->register();

		if ( is_admin() ) {
			( new Settings() )->register();
			( new Debug( $services ) )->register();
			( new Post_Kinds_Panel() )->register();
			( new Syndication_Panel( $syndication_manager ) )->register();
			( new Syndication_Notice( $syndication_manager ) )->register();
		}

		// Clear the personal-best distance transient whenever an exercise post's
		// type meta is written, so the next render re-queries and re-caches.
		add_action( 'added_post_meta', function( $meta_id, int $post_id, string $meta_key, $meta_value ) {
			if ( 'nop_indieweb_exercise_type' === $meta_key ) {
				delete_transient( 'nop_pb_dist_' . sanitize_key( (string) $meta_value ) );
			}
		}, 10, 4 );
		add_action( 'updated_post_meta', function( $meta_id, int $post_id, string $meta_key, $meta_value ) {
			if ( 'nop_indieweb_exercise_type' === $meta_key ) {
				$new = sanitize_key( (string) $meta_value );
				$old = sanitize_key( (string) get_post_meta( $post_id, $meta_key, true ) );
				if ( $new ) delete_transient( 'nop_pb_dist_' . $new );
				if ( $old && $old !== $new ) delete_transient( 'nop_pb_dist_' . $old );
			}
		}, 10, 4 );

		( new Map_Cleanup() )->register();
		( new Venue_Visit_Counter() )->register();
		( new Comment_Filter() )->register();
	}
}
