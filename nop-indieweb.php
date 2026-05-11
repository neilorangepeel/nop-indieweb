<?php
/**
 * Plugin Name: NOP IndieWeb
 * Plugin URI:  https://neilorangepeel.com
 * Description: POSSE/IndieWeb integration — Micropub endpoint, IndieAuth server, post meta, and syndication for neilorangepeel.com.
 * Version:     0.2.0
 * Author:      Neil Hainworth
 * Author URI:  https://neilorangepeel.com
 * License:     GPL-2.0-or-later
 * Text Domain: nop-indieweb
 */

declare( strict_types=1 );

namespace NOP\IndieWeb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NOP_INDIEWEB_VERSION', '0.2.0' );
define( 'NOP_INDIEWEB_DIR',     plugin_dir_path( __FILE__ ) );
define( 'NOP_INDIEWEB_URL',     plugin_dir_url( __FILE__ ) );
define( 'NOP_INDIEWEB_FILE',    __FILE__ );

// Load all files explicitly — no autoloader magic.
require_once NOP_INDIEWEB_DIR . 'includes/utils/functions.php';
require_once NOP_INDIEWEB_DIR . 'includes/utils/microformats.php';
require_once NOP_INDIEWEB_DIR . 'includes/indieauth/class-token-store.php';
require_once NOP_INDIEWEB_DIR . 'includes/indieauth/class-auth-endpoint.php';
require_once NOP_INDIEWEB_DIR . 'includes/indieauth/class-token-endpoint.php';
require_once NOP_INDIEWEB_DIR . 'includes/micropub/class-auth.php';
require_once NOP_INDIEWEB_DIR . 'includes/micropub/class-request.php';
require_once NOP_INDIEWEB_DIR . 'includes/micropub/class-endpoint.php';
require_once NOP_INDIEWEB_DIR . 'includes/services/class-service-base.php';
require_once NOP_INDIEWEB_DIR . 'includes/services/class-service-swarm.php';
require_once NOP_INDIEWEB_DIR . 'includes/services/class-service-note.php';
require_once NOP_INDIEWEB_DIR . 'includes/post-meta/class-meta-registry.php';
require_once NOP_INDIEWEB_DIR . 'includes/post-meta/class-block-bindings.php';
require_once NOP_INDIEWEB_DIR . 'includes/semantic/class-semantic-markup.php';
require_once NOP_INDIEWEB_DIR . 'includes/semantic/class-mf2-endpoint.php';
require_once NOP_INDIEWEB_DIR . 'includes/syndication/class-syndicator-base.php';
require_once NOP_INDIEWEB_DIR . 'includes/syndication/class-syndicator-mastodon.php';
require_once NOP_INDIEWEB_DIR . 'includes/syndication/class-syndicator-bluesky.php';
require_once NOP_INDIEWEB_DIR . 'includes/syndication/class-syndicator-pixelfed.php';
require_once NOP_INDIEWEB_DIR . 'includes/syndication/class-syndication-manager.php';
require_once NOP_INDIEWEB_DIR . 'includes/admin/class-settings.php';
require_once NOP_INDIEWEB_DIR . 'includes/admin/class-post-filter.php';
require_once NOP_INDIEWEB_DIR . 'includes/admin/class-debug.php';
require_once NOP_INDIEWEB_DIR . 'includes/admin/class-checkin-metabox.php';
require_once NOP_INDIEWEB_DIR . 'includes/admin/class-syndication-panel.php';
require_once NOP_INDIEWEB_DIR . 'includes/importer/class-feed-importer.php';
require_once NOP_INDIEWEB_DIR . 'includes/webmention/class-mf2-parser.php';
require_once NOP_INDIEWEB_DIR . 'includes/webmention/class-webmention-endpoint.php';
require_once NOP_INDIEWEB_DIR . 'includes/webmention/class-webmention-sender.php';
require_once NOP_INDIEWEB_DIR . 'includes/class-plugin.php';

// Create the tokens table on activation and on every load if the schema is stale.
register_activation_hook( __FILE__, function () {
	\NOP\IndieWeb\IndieAuth\Token_Store::maybe_create_table();
} );

add_action( 'plugins_loaded', function () {
	\NOP\IndieWeb\IndieAuth\Token_Store::maybe_create_table();
	\NOP\IndieWeb\Plugin::get_instance()->boot();
} );
