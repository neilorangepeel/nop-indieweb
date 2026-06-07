<?php
/**
 * Plugin Name: NOP IndieWeb
 * Plugin URI:  https://neilorangepeel.com
 * Description: POSSE/IndieWeb integration — Micropub endpoint, IndieAuth server, post meta, and syndication.
 * Version:     0.5.6
 * Requires at least: 6.7
 * Requires PHP:      8.0
 * Author:      Neil Hainsworth
 * Author URI:  https://neilorangepeel.com
 * License:     GPL-2.0-or-later
 * Text Domain: nop-indieweb
 */

declare( strict_types=1 );

namespace NOP\IndieWeb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NOP_INDIEWEB_VERSION', '0.5.6' );
define( 'NOP_INDIEWEB_DIR',     plugin_dir_path( __FILE__ ) );
define( 'NOP_INDIEWEB_URL',     plugin_dir_url( __FILE__ ) );
define( 'NOP_INDIEWEB_FILE',    __FILE__ );

// Load all files explicitly — no autoloader magic.
require_once NOP_INDIEWEB_DIR . 'includes/utils/functions.php';
require_once NOP_INDIEWEB_DIR . 'includes/utils/microformats.php';
require_once NOP_INDIEWEB_DIR . 'includes/utils/block-content.php';
require_once NOP_INDIEWEB_DIR . 'includes/indieauth/class-token-store.php';
require_once NOP_INDIEWEB_DIR . 'includes/indieauth/class-auth-endpoint.php';
require_once NOP_INDIEWEB_DIR . 'includes/indieauth/class-token-endpoint.php';
require_once NOP_INDIEWEB_DIR . 'includes/micropub/class-auth.php';
require_once NOP_INDIEWEB_DIR . 'includes/micropub/class-request.php';
require_once NOP_INDIEWEB_DIR . 'includes/micropub/class-endpoint.php';
require_once NOP_INDIEWEB_DIR . 'includes/micropub/class-media-endpoint.php';
require_once NOP_INDIEWEB_DIR . 'includes/weather/class-weather-fetcher.php';
require_once NOP_INDIEWEB_DIR . 'includes/services/class-service-base.php';
require_once NOP_INDIEWEB_DIR . 'includes/services/class-url-response-service.php';
require_once NOP_INDIEWEB_DIR . 'includes/services/class-service-swarm.php';
require_once NOP_INDIEWEB_DIR . 'includes/services/class-service-note.php';
require_once NOP_INDIEWEB_DIR . 'includes/services/class-service-letterboxd.php';
require_once NOP_INDIEWEB_DIR . 'includes/services/class-service-bookmark.php';
require_once NOP_INDIEWEB_DIR . 'includes/services/class-service-reply.php';
require_once NOP_INDIEWEB_DIR . 'includes/services/class-service-like.php';
require_once NOP_INDIEWEB_DIR . 'includes/services/class-service-repost.php';
require_once NOP_INDIEWEB_DIR . 'includes/services/class-service-rsvp.php';
require_once NOP_INDIEWEB_DIR . 'includes/kind/class-kind-taxonomy.php';
require_once NOP_INDIEWEB_DIR . 'includes/kind/class-venue-category-taxonomy.php';
require_once NOP_INDIEWEB_DIR . 'includes/post-meta/class-meta-registry.php';
require_once NOP_INDIEWEB_DIR . 'includes/post-meta/class-block-bindings.php';
require_once NOP_INDIEWEB_DIR . 'includes/semantic/class-semantic-markup.php';
require_once NOP_INDIEWEB_DIR . 'includes/semantic/class-open-graph.php';
require_once NOP_INDIEWEB_DIR . 'includes/semantic/class-mf2-endpoint.php';
require_once NOP_INDIEWEB_DIR . 'includes/syndication/class-syndicator-base.php';
require_once NOP_INDIEWEB_DIR . 'includes/syndication/class-mastodon-compatible-syndicator.php';
require_once NOP_INDIEWEB_DIR . 'includes/syndication/class-syndicator-mastodon.php';
require_once NOP_INDIEWEB_DIR . 'includes/syndication/class-syndicator-bluesky.php';
require_once NOP_INDIEWEB_DIR . 'includes/syndication/class-syndicator-pixelfed.php';
require_once NOP_INDIEWEB_DIR . 'includes/syndication/class-syndication-manager.php';
require_once NOP_INDIEWEB_DIR . 'includes/lookup/class-lookup-provider-base.php';
require_once NOP_INDIEWEB_DIR . 'includes/lookup/class-lookup-provider-tmdb.php';
require_once NOP_INDIEWEB_DIR . 'includes/admin/class-settings.php';
require_once NOP_INDIEWEB_DIR . 'includes/admin/class-settings-api.php';
require_once NOP_INDIEWEB_DIR . 'includes/admin/class-post-filter.php';
require_once NOP_INDIEWEB_DIR . 'includes/admin/class-debug.php';
require_once NOP_INDIEWEB_DIR . 'includes/admin/class-post-kinds-panel.php';
require_once NOP_INDIEWEB_DIR . 'includes/admin/class-syndication-panel.php';
require_once NOP_INDIEWEB_DIR . 'includes/importer/class-feed-importer.php';
require_once NOP_INDIEWEB_DIR . 'includes/webmention/class-mf2-parser.php';
require_once NOP_INDIEWEB_DIR . 'includes/webmention/class-cite-extractor.php';
require_once NOP_INDIEWEB_DIR . 'includes/webmention/class-cite-enricher.php';
require_once NOP_INDIEWEB_DIR . 'includes/webmention/class-webmention-endpoint.php';
require_once NOP_INDIEWEB_DIR . 'includes/webmention/class-webmention-sender.php';
require_once NOP_INDIEWEB_DIR . 'includes/webmention/class-like-endpoint.php';
require_once NOP_INDIEWEB_DIR . 'includes/webmention/class-social-backfeed.php';
require_once NOP_INDIEWEB_DIR . 'includes/venue/class-foursquare-enricher.php';
require_once NOP_INDIEWEB_DIR . 'includes/venue/class-geoapify-geocoder.php';
require_once NOP_INDIEWEB_DIR . 'includes/class-websub.php';
require_once NOP_INDIEWEB_DIR . 'includes/class-posting-page.php';
require_once NOP_INDIEWEB_DIR . 'includes/class-plugin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once NOP_INDIEWEB_DIR . 'includes/cli/class-backfill-venue-categories.php';
	require_once NOP_INDIEWEB_DIR . 'includes/cli/class-backfill-venue-details.php';
	require_once NOP_INDIEWEB_DIR . 'includes/cli/class-backfill-checkin-maps.php';
	require_once NOP_INDIEWEB_DIR . 'includes/cli/class-backfill-weather.php';
	require_once NOP_INDIEWEB_DIR . 'includes/cli/class-fix-facebook-venues.php';
	require_once NOP_INDIEWEB_DIR . 'includes/cli/class-import-facebook-checkins.php';
	require_once NOP_INDIEWEB_DIR . 'includes/cli/class-import-swarm-checkins.php';
	require_once NOP_INDIEWEB_DIR . 'includes/cli/class-fix-swarm-timestamps.php';
	require_once NOP_INDIEWEB_DIR . 'includes/cli/class-fix-micropub-timestamps.php';
	require_once NOP_INDIEWEB_DIR . 'includes/cli/class-backfill-venue-visits.php';
	\WP_CLI::add_command( 'nop-indieweb backfill-venue-categories',   \NOP\IndieWeb\Cli\Backfill_Venue_Categories::class );
	\WP_CLI::add_command( 'nop-indieweb backfill-venue-details',      \NOP\IndieWeb\Cli\Backfill_Venue_Details::class );
	\WP_CLI::add_command( 'nop-indieweb backfill-checkin-maps',       \NOP\IndieWeb\Cli\Backfill_Checkin_Maps::class );
	\WP_CLI::add_command( 'nop-indieweb backfill-weather',            \NOP\IndieWeb\Cli\Backfill_Weather::class );
	\WP_CLI::add_command( 'nop-indieweb fix-facebook-venues',         \NOP\IndieWeb\Cli\Fix_Facebook_Venues::class );
	\WP_CLI::add_command( 'nop-indieweb import-facebook-checkins',    \NOP\IndieWeb\Cli\Import_Facebook_Checkins::class );
	\WP_CLI::add_command( 'nop-indieweb import-swarm-checkins',       \NOP\IndieWeb\Cli\Import_Swarm_Checkins::class );
	\WP_CLI::add_command( 'nop-indieweb fix-swarm-timestamps',        \NOP\IndieWeb\Cli\Fix_Swarm_Timestamps::class );
	\WP_CLI::add_command( 'nop-indieweb fix-micropub-timestamps',     \NOP\IndieWeb\Cli\Fix_Micropub_Timestamps::class );
	\WP_CLI::add_command( 'nop-indieweb backfill-venue-visits',       \NOP\IndieWeb\Cli\Backfill_Venue_Visits::class );
}

// Create the tokens table on activation and on every load if the schema is stale.
register_activation_hook( __FILE__, function () {
	\NOP\IndieWeb\IndieAuth\Token_Store::maybe_create_table();
} );

add_action( 'plugins_loaded', function () {
	\NOP\IndieWeb\IndieAuth\Token_Store::maybe_create_table();
	\NOP\IndieWeb\maybe_migrate_profile_urls();
	\NOP\IndieWeb\maybe_migrate_swarm_source_url();
	\NOP\IndieWeb\maybe_flush_kind_rewrite_rules();
	\NOP\IndieWeb\Plugin::get_instance()->boot();
} );

// Load translations. On `init` (not plugins_loaded) per WP 6.7+, which warns
// when a text domain is loaded before init. Lets the strings wrapped in __()
// across the plugin resolve from a .mo file in wp-content/languages/plugins/
// or this plugin's own languages/ directory once translations exist.
add_action( 'init', function () {
	// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- explicit load supports distribution outside the wp.org directory
	load_plugin_textdomain( 'nop-indieweb', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

/**
 * One-time migration: moves nop_indieweb_mastodon_profile_url and
 * nop_indieweb_pixelfed_profile_url standalone options into the plugin
 * settings array so all data lives in one place.
 */
function maybe_migrate_profile_urls(): void {
	foreach ( [ 'mastodon', 'pixelfed' ] as $platform ) {
		$legacy_key = "nop_indieweb_{$platform}_profile_url";
		$url        = (string) get_option( $legacy_key, '' );
		if ( $url ) {
			\NOP\IndieWeb\nop_indieweb_update_option( "syndicators.{$platform}.profile_url", $url );
			delete_option( $legacy_key );
		}
	}
}

/**
 * One-time migration: backfills source_url + platform on existing Swarm
 * checkin posts so the syndication-panel and post-source blocks render
 * correctly. Pre-existing data put the Swarm permalink only in
 * nop_indieweb_syndication and nop_indieweb_checkin_url; these posts need
 * the source-url/platform pair too.
 */
function maybe_migrate_swarm_source_url(): void {
	if ( get_option( 'nop_indieweb_swarm_source_migrated' ) ) {
		return;
	}

	$query = new \WP_Query( [
		'post_type'      => 'post',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- low-frequency meta/taxonomy lookup (import, admin, or per-post render cache), not a hot path
		'meta_query'     => [
			[ 'key' => 'nop_indieweb_service', 'value' => 'swarm' ],
		],
		'no_found_rows'  => true,
	] );

	foreach ( $query->posts as $post_id ) {
		if ( ! get_post_meta( $post_id, 'nop_indieweb_platform', true ) ) {
			update_post_meta( $post_id, 'nop_indieweb_platform', 'swarm' );
		}
		if ( ! get_post_meta( $post_id, 'nop_indieweb_source_url', true ) ) {
			$checkin_url = (string) get_post_meta( $post_id, 'nop_indieweb_checkin_url', true );
			if ( $checkin_url ) {
				update_post_meta( $post_id, 'nop_indieweb_source_url', $checkin_url );
			}
		}
	}

	update_option( 'nop_indieweb_swarm_source_migrated', 1, false );
}

/**
 * One-time migration: flush WordPress rewrite rules so taxonomy-archive URLs
 * for the nop_kind taxonomy (e.g. /kind/checkin/) start resolving. The
 * taxonomy is registered with rewrite slug 'kind', but rewrite rules are
 * cached in an option and don't regenerate until something calls
 * flush_rewrite_rules(). Defers the flush to the `init` hook at priority 99
 * so the taxonomy is already registered when the rules regenerate.
 */
function maybe_flush_kind_rewrite_rules(): void {
	if ( get_option( 'nop_indieweb_kind_rewrite_flushed' ) ) {
		return;
	}
	add_action( 'init', function () {
		flush_rewrite_rules();
		update_option( 'nop_indieweb_kind_rewrite_flushed', 1, false );
	}, 99 );
}
