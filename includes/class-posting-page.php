<?php
declare( strict_types=1 );

namespace NOP\IndieWeb;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Standalone mobile Micropub client at /post.
 *
 * Accessible to logged-in users with publish_posts capability. Supports
 * nine post kinds — Note, Photo, Reply, Like, Bookmark, Repost, Quote, Story,
 * RSVP — and routes all posts through the Micropub endpoint using WordPress
 * cookie + nonce auth. After posting the success screen links to both the
 * published permalink and the WordPress block editor.
 *
 * The page is its own bold object: a flat Bauhaus / Paul Rand poster — paper
 * field, primary-colour blocks, hard offset shadows, knockout figure-ground —
 * deliberately separate from the website. Time-of-day is grounded by a
 * sun/moon dot tracking an arc across the masthead (no per-second repaint).
 */
class Posting_Page {

	private const QUERY_VAR = 'nop_post_page';
	private const REWRITE   = '^post/?$';

	public function register(): void {
		add_action( 'init',          [ $this, 'add_rewrite_rule' ] );
		add_filter( 'query_vars',    [ $this, 'add_query_var' ] );
		// Render on parse_request (before the main WP_Query runs and before the
		// template loader is reached) so this standalone page never pays for the
		// posts query or the theme template hierarchy it doesn't use.
		add_action( 'parse_request', [ $this, 'maybe_render' ] );
	}

	public function add_rewrite_rule(): void {
		add_rewrite_rule( self::REWRITE, 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	public function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public function maybe_render( \WP $wp ): void {
		// At parse_request the main query hasn't run yet, so read the matched query
		// var off the WP object directly rather than via get_query_var().
		if ( empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}
		// The service worker + manifest carry no secrets and must be reachable for
		// registration/install regardless of auth state — serve them before the gate.
		if ( isset( $_GET['sw'] ) ) {        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public static asset, no state change
			$this->render_service_worker();
			exit;
		}
		if ( isset( $_GET['manifest'] ) ) {  // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public static asset, no state change
			$this->render_manifest();
			exit;
		}
		// A fresh wp_rest nonce for the offline queue's replay (the page nonce can
		// expire before connectivity returns). Owner-only, via the login cookie.
		if ( isset( $_GET['nonce'] ) ) {  // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- mints a nonce, gated by the login cookie
			nocache_headers();
			if ( ! headers_sent() ) {
				header( 'Content-Type: application/json; charset=utf-8' );
			}
			if ( ! is_user_logged_in() ) {
				status_header( 401 );
				echo wp_json_encode( [ 'nonce' => '' ] );
				exit;
			}
			echo wp_json_encode( [ 'nonce' => wp_create_nonce( 'wp_rest' ) ] );
			exit;
		}
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( home_url( '/post' ) ) );
			exit;
		}
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to post.', 'nop-indieweb' ) );
		}
		$this->render_page();
		exit;
	}

	// ——— PWA: manifest + service worker ————————————————————————————————————————

	/**
	 * Web app manifest (served at /post?manifest=1) — makes the authoring app
	 * installable. Paths derive from home_url so it works in a subdirectory.
	 */
	private function render_manifest(): void {
		// The manifest payload is effectively static (site name, plugin asset URLs,
		// /post paths). Long-cache it so install / launch doesn't re-hit PHP.
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/manifest+json; charset=utf-8' );
			header( 'Cache-Control: public, max-age=86400' );
		}
		$path  = wp_parse_url( home_url( '/post' ), PHP_URL_PATH ) ?: '/post';
		// Bundled app icon (the target mark on the ink tile) — a dedicated, consistent
		// install icon rather than whatever the WordPress site icon happens to be. The
		// full-bleed tile keeps the glyph inside the maskable safe zone.
		$ver   = self::icon_version();
		$icons = [
			[ 'src' => esc_url_raw( NOP_INDIEWEB_URL . 'assets/icons/app-192.png?v=' . $ver ), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable' ],
			[ 'src' => esc_url_raw( NOP_INDIEWEB_URL . 'assets/icons/app-512.png?v=' . $ver ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable' ],
		];

		echo wp_json_encode( [
			'name'             => get_bloginfo( 'name' ) . ' · Post',
			'short_name'       => __( 'Post', 'nop-indieweb' ),
			'start_url'        => $path,
			'scope'            => $path,
			'display'          => 'standalone',
			'background_color' => '#F4EFE6',
			'theme_color'      => '#00777F',
			'icons'            => $icons,
			// Register as a share target (Android/desktop) — sharing a page/text opens
			// /post?title=&text=&url=, which the client maps to a bookmark/note. iOS has
			// no Web Share Target, but the same params drive an iOS Shortcut.
			// enctype is required when method is POST and silently warned-about by
			// Chrome on GET shares too — set it explicitly so DevTools stays quiet.
			'share_target'     => [
				'action'  => $path,
				'method'  => 'GET',
				'enctype' => 'application/x-www-form-urlencoded',
				'params'  => [ 'title' => 'title', 'text' => 'text', 'url' => 'url' ],
			],
		] );
	}

	/**
	 * Cache-buster for the app icons. iOS fetches the apple-touch-icon through
	 * Safari's HTTP cache, and the PNGs are served with a one-year max-age — a
	 * recolored icon at the same URL never reaches the home screen. The file's
	 * mtime changes on every git-pull deploy that touches it, minting a new URL.
	 */
	private static function icon_version(): string {
		$mtime = @filemtime( NOP_INDIEWEB_DIR . 'assets/icons/app-192.png' );
		return $mtime ? (string) $mtime : NOP_INDIEWEB_VERSION;
	}

	/**
	 * Cache-busting version for the built /post assets. The webpack asset file
	 * hashes only the JS entry, so a style-only rebuild leaves it unchanged —
	 * fold in the extracted CSS so any rebuild busts the ?ver (and with it the
	 * service-worker shell and the browser's HTTP cache).
	 */
	private function asset_version(): string {
		$asset = file_exists( NOP_INDIEWEB_DIR . 'build/post/index.asset.php' ) ? ( include NOP_INDIEWEB_DIR . 'build/post/index.asset.php' ) : [];
		$ver   = is_array( $asset ) && ! empty( $asset['version'] ) ? $asset['version'] : '1';
		$css   = NOP_INDIEWEB_DIR . 'build/post/style-index.css';
		if ( file_exists( $css ) ) {
			$ver = substr( md5( $ver . md5_file( $css ) ), 0, 20 );
		}
		return $ver;
	}

	/**
	 * Where /post loads its typefaces from.
	 *
	 * The plugin's own copies, deliberately. /post is a plugin feature and must
	 * not stop working because someone switched theme — it used to read these
	 * from `get_theme_file_uri()`, so activating any other theme 404'd every
	 * face and dropped the authoring app to system fonts.
	 *
	 * Filterable for the one legitimate case: a theme that already serves the
	 * same faces and would rather not have them fetched twice.
	 */
	/**
	 * The composer's client-side copy, translated here and handed to the script as
	 * NOP.l10n (English source => translation). Inline rather than via wp-i18n so
	 * the offline app shell carries its strings with it. Keys must match the
	 * __( '…' ) literals in src/post/index.js exactly — src/post/l10n.test.js
	 * fails the build when one is missing.
	 *
	 * @return array<string,string>
	 */
	private function js_strings(): array {
		return [
			'What\'s happening?' => __( 'What\'s happening?', 'nop-indieweb' ),
			'Seen anything good?' => __( 'Seen anything good?', 'nop-indieweb' ),
			'A thought…' => __( 'A thought…', 'nop-indieweb' ),
			'What\'s on your mind?' => __( 'What\'s on your mind?', 'nop-indieweb' ),
			'Share something…' => __( 'Share something…', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'Last: %s' => __( 'Last: %s', 'nop-indieweb' ),
			'No.' => __( 'No.', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'Golden: %s' => __( 'Golden: %s', 'nop-indieweb' ),
			'Golden: now' => __( 'Golden: now', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'Sunset: %s' => __( 'Sunset: %s', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'Daylight: %s' => __( 'Daylight: %s', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'Sunrise: %s' => __( 'Sunrise: %s', 'nop-indieweb' ),
			'Blue: now' => __( 'Blue: now', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'Blue: %s' => __( 'Blue: %s', 'nop-indieweb' ),
			'new moon' => __( 'new moon', 'nop-indieweb' ),
			'waxing crescent' => __( 'waxing crescent', 'nop-indieweb' ),
			'first quarter' => __( 'first quarter', 'nop-indieweb' ),
			'waxing gibbous' => __( 'waxing gibbous', 'nop-indieweb' ),
			'full moon' => __( 'full moon', 'nop-indieweb' ),
			'waning gibbous' => __( 'waning gibbous', 'nop-indieweb' ),
			'last quarter' => __( 'last quarter', 'nop-indieweb' ),
			'waning crescent' => __( 'waning crescent', 'nop-indieweb' ),
			'just now' => __( 'just now', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'%sm ago' => __( '%sm ago', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'%sh ago' => __( '%sh ago', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'%sd ago' => __( '%sd ago', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'%sw ago' => __( '%sw ago', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'%s today' => __( '%s today', 'nop-indieweb' ),
			'Spr Equinox' => __( 'Spr Equinox', 'nop-indieweb' ),
			'Sum Solstice' => __( 'Sum Solstice', 'nop-indieweb' ),
			'Aut Equinox' => __( 'Aut Equinox', 'nop-indieweb' ),
			'Win Solstice' => __( 'Win Solstice', 'nop-indieweb' ),
			'today' => __( 'today', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'%sd' => __( '%sd', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'Wk %s' => __( 'Wk %s', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'Day %s' => __( 'Day %s', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'Moon: %s' => __( 'Moon: %s', 'nop-indieweb' ),
			'Offline' => __( 'Offline', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'Queue: %s' => __( 'Queue: %s', 'nop-indieweb' ),
			'NOW' => __( 'NOW', 'nop-indieweb' ),
			'HERE' => __( 'HERE', 'nop-indieweb' ),
			'LIGHT' => __( 'LIGHT', 'nop-indieweb' ),
			'LOG' => __( 'LOG', 'nop-indieweb' ),
			'Write a note…' => __( 'Write a note…', 'nop-indieweb' ),
			'Write a note, or add a photo.' => __( 'Write a note, or add a photo.', 'nop-indieweb' ),
			'What are we looking at?' => __( 'What are we looking at?', 'nop-indieweb' ),
			'In reply to' => __( 'In reply to', 'nop-indieweb' ),
			'Say your piece…' => __( 'Say your piece…', 'nop-indieweb' ),
			'Paste the URL you\'re replying to' => __( 'Paste the URL you\'re replying to', 'nop-indieweb' ),
			'Liking' => __( 'Liking', 'nop-indieweb' ),
			'Paste the URL you\'re liking' => __( 'Paste the URL you\'re liking', 'nop-indieweb' ),
			'Bookmarking' => __( 'Bookmarking', 'nop-indieweb' ),
			'A note to future you…' => __( 'A note to future you…', 'nop-indieweb' ),
			'Paste the URL to bookmark' => __( 'Paste the URL to bookmark', 'nop-indieweb' ),
			'Reposting' => __( 'Reposting', 'nop-indieweb' ),
			'Paste the URL you\'re reposting' => __( 'Paste the URL you\'re reposting', 'nop-indieweb' ),
			'The quote itself…' => __( 'The quote itself…', 'nop-indieweb' ),
			'Add the quote itself.' => __( 'Add the quote itself.', 'nop-indieweb' ),
			'Add a caption (optional)…' => __( 'Add a caption (optional)…', 'nop-indieweb' ),
			'Event' => __( 'Event', 'nop-indieweb' ),
			'Add a word (optional)…' => __( 'Add a word (optional)…', 'nop-indieweb' ),
			'Paste the event\'s URL' => __( 'Paste the event\'s URL', 'nop-indieweb' ),
			'Post' => __( 'Post', 'nop-indieweb' ),
			'Schedule' => __( 'Schedule', 'nop-indieweb' ),
			'Paste a URL' => __( 'Paste a URL', 'nop-indieweb' ),
			'Fetching event details…' => __( 'Fetching event details…', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'Found via %s.' => __( 'Found via %s.', 'nop-indieweb' ),
			'Found event details.' => __( 'Found event details.', 'nop-indieweb' ),
			'Only the page title was readable — please fill in the details.' => __( 'Only the page title was readable — please fill in the details.', 'nop-indieweb' ),
			'Couldn’t find event data — please fill in manually.' => __( 'Couldn’t find event data — please fill in manually.', 'nop-indieweb' ),
			'Locating…' => __( 'Locating…', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'Remove %s' => __( 'Remove %s', 'nop-indieweb' ),
			'URL' => __( 'URL', 'nop-indieweb' ),
			'Write…' => __( 'Write…', 'nop-indieweb' ),
			'Pick a photo or a short clip.' => __( 'Pick a photo or a short clip.', 'nop-indieweb' ),
			'Add at least one photo.' => __( 'Add at least one photo.', 'nop-indieweb' ),
			'Paste a link first.' => __( 'Paste a link first.', 'nop-indieweb' ),
			'Write something first.' => __( 'Write something first.', 'nop-indieweb' ),
			'Cleared' => __( 'Cleared', 'nop-indieweb' ),
			'Undo' => __( 'Undo', 'nop-indieweb' ),
			'ALT?' => __( 'ALT?', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'Remove photo %s' => __( 'Remove photo %s', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'Alt text %s' => __( 'Alt text %s', 'nop-indieweb' ),
			'Alt text' => __( 'Alt text', 'nop-indieweb' ),
			'Describe it…' => __( 'Describe it…', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'Alt text for photo %s' => __( 'Alt text for photo %s', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'%s selected' => __( '%s selected', 'nop-indieweb' ),
			'Add photos' => __( 'Add photos', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'%s photo needs alt text' => __( '%s photo needs alt text', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'%s photos need alt text' => __( '%s photos need alt text', 'nop-indieweb' ),
			'Post anyway' => __( 'Post anyway', 'nop-indieweb' ),
			'Sharing…' => __( 'Sharing…', 'nop-indieweb' ),
			'Caption copied to clipboard' => __( 'Caption copied to clipboard', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'Something went wrong: %s' => __( 'Something went wrong: %s', 'nop-indieweb' ),
			'Try again' => __( 'Try again', 'nop-indieweb' ),
			/* translators: 1: photo number, 2: total photos */
			'Uploading %1$s of %2$s…' => __( 'Uploading %1$s of %2$s…', 'nop-indieweb' ),
			'Uploading video…' => __( 'Uploading video…', 'nop-indieweb' ),
			'Posting…' => __( 'Posting…', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'Posting failed (%s)' => __( 'Posting failed (%s)', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'Upload failed (%s)' => __( 'Upload failed (%s)', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'Couldn\'t save offline: %s' => __( 'Couldn\'t save offline: %s', 'nop-indieweb' ),
			'Couldn\'t reach the server — saved and retrying…' => __( 'Couldn\'t reach the server — saved and retrying…', 'nop-indieweb' ),
			'Saved — will post when you’re back online.' => __( 'Saved — will post when you’re back online.', 'nop-indieweb' ),
			'Queued post published.' => __( 'Queued post published.', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'A queued post failed: %s' => __( 'A queued post failed: %s', 'nop-indieweb' ),
			'Why?' => __( 'Why?', 'nop-indieweb' ),
			'(opens in a new tab)' => __( '(opens in a new tab)', 'nop-indieweb' ),
			'sent' => __( 'sent', 'nop-indieweb' ),
			'failed' => __( 'failed', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'Retry %s' => __( 'Retry %s', 'nop-indieweb' ),
			'skipped' => __( 'skipped', 'nop-indieweb' ),
			'sending' => __( 'sending', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'Scheduled for %s' => __( 'Scheduled for %s', 'nop-indieweb' ),
			'post today' => __( 'post today', 'nop-indieweb' ),
			'Share from your Photos app instead.' => __( 'Share from your Photos app instead.', 'nop-indieweb' ),
			'Web sharing isn\'t supported on this browser.' => __( 'Web sharing isn\'t supported on this browser.', 'nop-indieweb' ),
			'Link copied' => __( 'Link copied', 'nop-indieweb' ),
			'Couldn\'t copy — long-press the link above.' => __( 'Couldn\'t copy — long-press the link above.', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'Posting in %s…' => __( 'Posting in %s…', 'nop-indieweb' ),
			'Drafts aren\'t supported here.' => __( 'Drafts aren\'t supported here.', 'nop-indieweb' ),
			'Nothing to save yet.' => __( 'Nothing to save yet.', 'nop-indieweb' ),
			'Couldn\'t save the draft.' => __( 'Couldn\'t save the draft.', 'nop-indieweb' ),
			'Draft saved.' => __( 'Draft saved.', 'nop-indieweb' ),
			'No drafts yet.' => __( 'No drafts yet.', 'nop-indieweb' ),
			'(untitled)' => __( '(untitled)', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'Delete draft: %s' => __( 'Delete draft: %s', 'nop-indieweb' ),
			'Loading…' => __( 'Loading…', 'nop-indieweb' ),
			'post' => __( 'post', 'nop-indieweb' ),
			'Nothing sent yet.' => __( 'Nothing sent yet.', 'nop-indieweb' ),
			'Retrying…' => __( 'Retrying…', 'nop-indieweb' ),
			'Couldn\'t retry that one.' => __( 'Couldn\'t retry that one.', 'nop-indieweb' ),
			'Couldn\'t open that draft.' => __( 'Couldn\'t open that draft.', 'nop-indieweb' ),
			'Draft deleted' => __( 'Draft deleted', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'%s character' => __( '%s character', 'nop-indieweb' ),
			/* translators: %s: a number, name or time filled in by the composer */
			'%s characters' => __( '%s characters', 'nop-indieweb' ),
			'Dismiss' => __( 'Dismiss', 'nop-indieweb' ),
		];
	}

	private function font_base_uri(): string {
		return (string) apply_filters( 'nop_indieweb_post_font_uri', NOP_INDIEWEB_URL . 'assets/fonts' );
	}

	/**
	 * Service worker (served at /post?sw=1) — precaches the app shell (the page +
	 * Brandon fonts) so /post installs and opens offline. Network-first for the
	 * page so the nonce stays fresh online; cache-first for the static fonts; the
	 * Micropub/now REST routes are never cached.
	 */
	private function render_service_worker(): void {
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/javascript; charset=utf-8' );
			header( 'Service-Worker-Allowed: /' );
		}
		$font_dir = $this->font_base_uri() . '/brandon-text';
		$cond_dir = $this->font_base_uri() . '/brandon-text-condensed';
		$page     = home_url( '/post' );
		$sw_ver   = $this->asset_version();
		$shell    = [
			$page,
			NOP_INDIEWEB_URL . 'build/post/style-index.css?ver=' . rawurlencode( $sw_ver ),
			NOP_INDIEWEB_URL . 'build/post/index.js?ver=' . rawurlencode( $sw_ver ),
			$font_dir . '/brandon-text_normal_500.woff2',
			$font_dir . '/brandon-text_normal_700.woff2',
			$font_dir . '/brandon-text_normal_800.woff2',
			$cond_dir . '/brandon-text-condensed_normal_700.woff2',
			$cond_dir . '/brandon-text-condensed_normal_800.woff2',
		];
		?>
'use strict';
// Bump on any change that demands a clean shell refresh on every device. The
// activate handler deletes every cache whose name doesn't equal CACHE — so
// changing this string is the textbook way to nuke a stuck precached shell.
var CACHE = 'nop-post-v14';
var PAGE  = <?php echo wp_json_encode( $page ); ?>;
var SHELL = <?php echo wp_json_encode( $shell ); ?>;

self.addEventListener( 'install', function ( e ) {
	e.waitUntil(
		caches.open( CACHE ).then( function ( c ) {
			// Add resiliently — one 404 shouldn't fail the whole install.
			return Promise.all( SHELL.map( function ( u ) {
				return c.add( new Request( u, { credentials: 'same-origin' } ) ).catch( function () {} );
			} ) );
		} ).then( function () { return self.skipWaiting(); } )
	);
} );

self.addEventListener( 'activate', function ( e ) {
	e.waitUntil(
		caches.keys().then( function ( keys ) {
			return Promise.all( keys.filter( function ( k ) { return k !== CACHE; } ).map( function ( k ) { return caches.delete( k ); } ) );
		} ).then( function () { return self.clients.claim(); } )
	);
} );

self.addEventListener( 'fetch', function ( e ) {
	var req = e.request;
	if ( req.method !== 'GET' ) { return; }                         // never touch POST (Micropub/media)
	var url = new URL( req.url );
	if ( url.pathname.indexOf( '/wp-json/' ) !== -1 ) { return; }    // never cache the API

	// The /post page: network-first so the nonce refreshes online; cached shell
	// offline. Gate the cache write on res.ok — without this, a 503/504 served
	// by SiteGround during a deploy gets pinned as the offline fallback and
	// served indefinitely until the next successful navigation.
	if ( req.mode === 'navigate' ) {
		e.respondWith(
			fetch( req ).then( function ( res ) {
				if ( res && res.ok ) {
					var copy = res.clone();
					caches.open( CACHE ).then( function ( c ) { c.put( PAGE, copy ); } );
				}
				return res;
			} ).catch( function () {
				return caches.match( PAGE ).then( function ( m ) { return m || caches.match( req ); } );
			} )
		);
		return;
	}

	// Cache-first only for content-stable assets: the fonts (filename-versioned)
	// and the built app CSS/JS (?ver-busted, so a new build is a new URL). Icons,
	// the manifest, etc. fall through to the network so they're never pinned stale.
	//
	// Resilient to transient 5xx on the built app: if the new build URL comes
	// back as 503/504 (SiteGround WAF or edge cache hiccup), retry once after a
	// short pause, then fall back to ANY previously-cached version of the same
	// path. Better to run a slightly older build than to ship a broken page.
	if ( /\.woff2($|\?)/.test( url.pathname ) || url.pathname.indexOf( '/build/post/' ) !== -1 ) {
		e.respondWith(
			caches.match( req ).then( function ( hit ) {
				if ( hit ) { return hit; }
				return fetchResilient( req ).then( function ( res ) {
					if ( res && res.ok ) {
						var copy = res.clone();
						caches.open( CACHE ).then( function ( c ) {
							return c.put( req, copy ).then( function () { return pruneOtherVersions( c, url ); } );
						} );
						return res;
					}
					// Non-OK response — try the previous build's cached asset.
					return matchAnyVersion( url ).then( function ( fb ) { return fb || res; } );
				} ).catch( function () {
					return matchAnyVersion( url ).then( function ( fb ) {
						return fb || new Response( '', { status: 504, statusText: 'No cached fallback' } );
					} );
				} );
			} ).catch( function () {
				// caches.match can itself reject on a corrupted CacheStorage or a
				// private-mode quota error. Fall back to a fresh network fetch (with
				// the same retry behaviour) rather than letting respondWith reject —
				// browser network-error pages are worse than one un-cached request.
				return fetchResilient( req );
			} )
		);
	}
} );

// One retry after 400ms on a 5xx — covers SiteGround's brief unavailability
// windows during deploy / WAF rate-limit blips without making the page wait
// forever on a genuinely-down origin.
function fetchResilient( req ) {
	return fetch( req.clone() ).then( function ( res ) {
		if ( ! res || res.status < 500 ) { return res; }
		return new Promise( function ( r ) { setTimeout( r, 400 ); } )
			.then( function () { return fetch( req.clone() ); } );
	} );
}

// Once a new build's asset is safely cached, drop every other ?ver= of the same
// path — otherwise each deploy leaves another full copy of the app in the cache,
// and the fallback below could hand back the oldest one instead of the last.
function pruneOtherVersions( c, url ) {
	return c.keys().then( function ( keys ) {
		return Promise.all( keys.filter( function ( k ) {
			try { var u = new URL( k.url ); return u.pathname === url.pathname && u.href !== url.href; } catch ( e ) { return false; }
		} ).map( function ( k ) { return c.delete( k ); } ) );
	} );
}

// Search the cache for any entry whose pathname matches — same asset, any
// `?ver=` value — so a 503 on a freshly-deployed URL falls back to the
// previously-cached version of the same asset (pruning keeps that to one).
function matchAnyVersion( url ) {
	return caches.open( CACHE ).then( function ( c ) {
		return c.keys().then( function ( keys ) {
			for ( var i = 0; i < keys.length; i++ ) {
				try {
					var k = new URL( keys[ i ].url );
					if ( k.pathname === url.pathname ) {
						return c.match( keys[ i ] );
					}
				} catch ( e ) {}
			}
			return null;
		} );
	} );
}

// End of service worker.
		<?php
	}

	// ——— Page render ——————————————————————————————————————————————————————————

	private function render_page(): void {
		// We bypass the template loader (rendered on parse_request), so WordPress
		// never runs send_headers for this request — set the headers ourselves.
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		}

		$nonce        = wp_create_nonce( 'wp_rest' );
		$media_url    = esc_url_raw( rest_url( 'wp/v2/media' ) );
		$micropub_url = esc_url_raw( rest_url( 'nop-indieweb/v1/micropub' ) );
		$now_url      = esc_url_raw( rest_url( 'nop-indieweb/v1/now' ) );
		$drafts_url   = esc_url_raw( rest_url( 'nop-indieweb/v1/drafts' ) );
		$fetch_event_url = esc_url_raw( rest_url( 'nop-indieweb/v1/fetch-event' ) );
		$fetch_context_url = esc_url_raw( rest_url( 'nop-indieweb/v1/fetch-context' ) );
		$syndication_status_url = esc_url_raw( rest_url( 'nop-indieweb/v1/syndication/status' ) );
		$syndication_sent_url   = esc_url_raw( rest_url( 'nop-indieweb/v1/syndication/sent' ) );
		$syndication_retry_url  = esc_url_raw( rest_url( 'nop-indieweb/v1/syndication/retry' ) );
		$tags_url          = esc_url_raw( rest_url( 'wp/v2/tags' ) );
		$cats_url          = esc_url_raw( rest_url( 'wp/v2/categories' ) );
		// The REST URLs above are esc_url_raw, not esc_url: they're emitted through
		// wp_json_encode into a script, where esc_url's &#038; would break a
		// plain-permalink (?rest_route=) site. The site name is escaped at output.
		$site_name    = get_bloginfo( 'name' );
		$font_dir     = $this->font_base_uri() . '/brandon-text';
		$cond_dir     = $this->font_base_uri() . '/brandon-text-condensed';

		// One-line "what it is" per kind, surfaced inline when the kind title is
		// tapped (see the docket filing line). Plain and short — just the gist.
		$kind_info = [
			'note'     => __( 'A quick thought in your own words — no link.', 'nop-indieweb' ),
			'photo'    => __( 'Pictures with a caption, for your grid.', 'nop-indieweb' ),
			'reply'    => __( 'Reply to a post by its link.', 'nop-indieweb' ),
			'like'     => __( '♥ a post by its link — no words.', 'nop-indieweb' ),
			'bookmark' => __( 'Save a link for later, just for you.', 'nop-indieweb' ),
			'repost'   => __( 'Boost a post as-is — no comment.', 'nop-indieweb' ),
			'quote'    => __( 'A passage plus your take on it.', 'nop-indieweb' ),
			'story'    => __( 'A vertical photo or clip — gone in 24h.', 'nop-indieweb' ),
			'rsvp'     => __( 'Yes, no or maybe to an event link.', 'nop-indieweb' ),
		];

		// Built app assets (CSS now, the app script next) — version-busted from the
		// build's asset file so a new build invalidates the URL.
		$post_ver     = $this->asset_version();
		$post_css_url = NOP_INDIEWEB_URL . 'build/post/style-index.css?ver=' . rawurlencode( $post_ver );
		$post_js_url  = NOP_INDIEWEB_URL . 'build/post/index.js?ver=' . rawurlencode( $post_ver );

		$user = wp_get_current_user();

		// The serial shown in the masthead — the id the next post will most likely
		// take (the table's high-water mark + 1). A decorative stamp: real gaps
		// (deleted rows / concurrent inserts) mean it can differ from the assigned id.
		global $wpdb;
		$next_id = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ) + 1; // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// Posting cadence for the ticker: how many posts the author has published
		// today (local day) and when they last posted. Both are small, indexed
		// queries; the client bumps them live as you post in the session.
		$today_q = new \WP_Query( [
			'author'         => $user->ID,
			'post_status'    => 'publish',
			'post_type'      => 'post',
			'date_query'     => [ [ 'after' => current_time( 'Y-m-d' ) . ' 00:00:00', 'inclusive' => true ] ],
			'fields'         => 'ids',
			'posts_per_page' => 100,
			'no_found_rows'  => true,
		] );
		$posts_today = count( $today_q->posts );

		$last_ids     = get_posts( [ 'author' => $user->ID, 'post_status' => 'publish', 'post_type' => 'post', 'numberposts' => 1, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids' ] );
		$last_post_ts = $last_ids ? (int) get_post_timestamp( $last_ids[0] ) : 0;

		// Compute syndication targets here so the page can inline them — saves a
		// second round-trip (the old ?q=config fetch booted all of WordPress again
		// just to list these). Shape mirrors the Micropub config endpoint.
		$syndicate_to = [];
		$manager      = Plugin::get_instance()->syndication_manager();
		if ( $manager ) {
			$syndicate_to = array_map(
				// Pixelfed only takes photo posts (→ grid) and story posts (→ Stories
				// tray); `kinds` restricts which kinds the client offers it on. A null
				// `kinds` (Mastodon/Bluesky) means every kind.
				fn( $s ) => [
					'uid'   => $s['slug'],
					'name'  => $s['label'],
					'kinds' => ( 'pixelfed' === $s['slug'] ) ? [ 'photo', 'story' ] : null,
				],
				$manager->get_panel_data()
			);
		}

		// Most-used post tags → one-tap chips beneath the tag field. Display
		// convenience only; tapping seeds the existing client tag list (posted
		// as `category`). Names keep their original case — tags are case-sensitive.
		$top_tags  = [];
		$tag_terms = get_terms( [
			'taxonomy'   => 'post_tag',
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => 10,
			'hide_empty' => true,
		] );
		if ( is_array( $tag_terms ) ) {
			$top_tags = array_map( static fn( $t ) => $t->name, $tag_terms );
		}

		// Most-used categories → the same one-tap chips beneath the categories field.
		$top_cats  = [];
		$cat_terms = get_terms( [
			'taxonomy'   => 'category',
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => 10,
			'hide_empty' => true,
		] );
		if ( is_array( $cat_terms ) ) {
			$top_cats = array_map( static fn( $t ) => $t->name, $cat_terms );
		}

		// Per-kind default categories — the same service settings the server falls
		// back to for a payload with no explicit categories. The composer pre-stamps
		// them as removable chips, so what's shown is exactly what the post gets.
		$services_opt = nop_indieweb_get_option( 'services', [] );
		$cats_setting = static fn( string $slug ): array => array_values( array_filter( array_map(
			'trim',
			explode( ',', (string) ( $services_opt[ $slug ]['post_category'] ?? '' ) )
		) ) );
		$entry_cats   = $cats_setting( 'entries' );
		$kind_cats    = [
			'note'     => $entry_cats,
			'photo'    => $entry_cats,
			'story'    => $entry_cats,
			'reply'    => $cats_setting( 'reply' ),
			'like'     => $cats_setting( 'like' ),
			'bookmark' => $cats_setting( 'bookmark' ),
			'repost'   => $cats_setting( 'repost' ),
			'quote'    => $cats_setting( 'quote' ),
			'rsvp'     => $cats_setting( 'rsvp' ),
		];
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<script>
/* Branch the two iOS use cases BEFORE first layout (head script, runs early):
   • Standalone (Home Screen app) — keep viewport-fit=cover for full-bleed; mark
     <html class="standalone"> so CSS uses 100vh (the full screen; dvh is short
     and mis-initialises in a PWA) and the real safe-area insets.
   • Safari tab — DROP viewport-fit=cover so content stays inside the browser
     chrome (no logo under the status bar, no Post button under the toolbar), and
     CSS uses 100dvh (the visible viewport). navigator.standalone is the reliable
     signal on iOS (display-mode:standalone reports false even in a real PWA). */
( function () {
	if ( window.navigator.standalone ) {
		document.documentElement.classList.add( 'standalone' );
	} else {
		var v = document.querySelector( 'meta[name="viewport"]' );
		if ( v ) { v.setAttribute( 'content', 'width=device-width, initial-scale=1' ); }
	}
} )();
</script>
<meta name="theme-color" id="themeColor" content="#00777F">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
<meta name="robots" content="noindex, nofollow">
<link rel="apple-touch-icon" href="<?php echo esc_url( NOP_INDIEWEB_URL . 'assets/icons/app-192.png?v=' . self::icon_version() ); ?>">
<link rel="manifest" href="<?php echo esc_url( home_url( '/post?manifest=1' ) ); ?>">
<link rel="preload" href="<?php echo esc_url( $font_dir . '/brandon-text_normal_500.woff2' ); ?>" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?php echo esc_url( $cond_dir . '/brandon-text-condensed_normal_700.woff2' ); ?>" as="font" type="font/woff2" crossorigin>
<title><?php echo esc_html( $site_name ); ?></title>
<style>
<?php
foreach ( [ '500', '700', '800' ] as $weight ) {
	printf(
		'@font-face{font-family:"Brandon Text";font-weight:%1$d;font-style:normal;font-display:swap;src:url("%2$s/brandon-text_normal_%1$d.woff2") format("woff2")}' . "\n",
		absint( $weight ), esc_url( $font_dir )
	);
}
foreach ( [ '700', '800' ] as $weight ) {
	printf(
		'@font-face{font-family:"Brandon Text Condensed";font-weight:%1$d;font-style:normal;font-display:swap;src:url("%2$s/brandon-text-condensed_normal_%1$d.woff2") format("woff2")}' . "\n",
		absint( $weight ), esc_url( $cond_dir )
	);
}
?>
</style>
<link rel="stylesheet" href="<?php echo esc_url( $post_css_url ); ?>">
</head>
<body>
<!-- Inline icon sprite — every <use href="#…"> below pulls from these. Hidden so
     it occupies no layout; currentColor still inherits through <use>. -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
	<symbol id="nop-x" viewBox="0 0 24 24"><path d="M7 7 17 17 M17 7 7 17" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></symbol>
	<symbol id="nop-check" viewBox="0 0 256 256"><path fill="currentColor" d="M229.66,77.66l-128,128a8,8,0,0,1-11.32,0l-56-56a8,8,0,0,1,11.32-11.32L96,188.69,218.34,66.34a8,8,0,0,1,11.32,11.32Z"/></symbol>
	<?php
	// Post-kind marks for the Sent list's rail, from the same source the
	// kind-icon block draws from. A kind with no mark here simply isn't in the
	// sprite, and the list falls back to printing its name.
	foreach ( \NOP\IndieWeb\Kind\Kind_Icons::paths() as $kind_slug => $kind_path ) :
		?>
		<symbol id="nop-kind-<?php echo esc_attr( $kind_slug ); ?>" viewBox="0 0 256 256"><path fill="currentColor" d="<?php echo esc_attr( $kind_path ); ?>"/></symbol>
		<?php
	endforeach;
	?>
</svg>
<div class="app" id="app" data-type="note">

	<!-- Faux iOS chrome — desktop floating-phone mock only -->
	<div class="device-chrome" aria-hidden="true">
		<span class="device-chrome__time" id="deviceTime">19:07</span>
		<span class="device-chrome__island"></span>
		<span class="device-chrome__icons">
			<svg width="18" height="12" viewBox="0 0 18 12" fill="currentColor"><rect x="0" y="8.5" width="3" height="3.5" rx="1"/><rect x="4.9" y="5.7" width="3" height="6.3" rx="1"/><rect x="9.8" y="2.8" width="3" height="9.2" rx="1"/><rect x="14.7" y="0" width="3" height="12" rx="1"/></svg>
			<svg width="16" height="12" viewBox="0 0 16 12" fill="currentColor"><path d="M8 11.8.7 4.5a10.4 10.4 0 0 1 14.6 0L8 11.8Z"/></svg>
			<span class="device-chrome__battery"><i></i></span>
		</span>
	</div>

	<!-- Masthead -->
	<header class="masthead">
		<h1 class="sr-only"><?php echo esc_html( sprintf( /* translators: %s: site name */ __( 'Post to %s', 'nop-indieweb' ), $site_name ) ); ?></h1>
		<div class="masthead__bar">
			<button type="button" class="brand__mark" id="nowBtn" aria-label="<?php esc_attr_e( 'Show local place and weather', 'nop-indieweb' ); ?>">
				<svg viewBox="0 0 60 60" fill="none" aria-hidden="true" focusable="false"><path fill-rule="evenodd" clip-rule="evenodd" d="M30 5.45455C16.4439 5.45455 5.45455 16.4439 5.45455 30V35.4545C5.45455 45.9982 14.0018 54.5455 24.5455 54.5455H30C43.5561 54.5455 54.5455 43.5561 54.5455 30C54.5455 16.4439 43.5561 5.45455 30 5.45455ZM0 30C0 13.4315 13.4315 0 30 0C46.5685 0 60 13.4315 60 30C60 46.5685 46.5685 60 30 60H24.5455C10.9893 60 0 49.0107 0 35.4545V30ZM30 16.3636C22.4688 16.3636 16.3636 22.4688 16.3636 30C16.3636 37.5312 22.4688 43.6364 30 43.6364C37.5312 43.6364 43.6364 37.5312 43.6364 30C43.6364 22.4688 37.5312 16.3636 30 16.3636ZM10.9091 30C10.9091 19.4564 19.4564 10.9091 30 10.9091C40.5436 10.9091 49.0909 19.4564 49.0909 30C49.0909 40.5436 40.5436 49.0909 30 49.0909C19.4564 49.0909 10.9091 40.5436 10.9091 30ZM30.0775 27.3502C28.5713 27.3502 27.3502 28.5713 27.3502 30.0775C27.3502 31.5837 26.1292 32.8048 24.623 32.8048C23.1167 32.8048 21.8957 31.5837 21.8957 30.0775C21.8957 25.5589 25.5589 21.8957 30.0775 21.8957C34.5963 21.8957 38.2593 25.5589 38.2593 30.0775C38.2593 31.5837 37.0383 32.8048 35.5321 32.8048C34.0258 32.8048 32.8048 31.5837 32.8048 30.0775C32.8048 28.5713 31.5837 27.3502 30.0775 27.3502Z"/></svg>
			</button>
			<!-- Metadata ticker — serial · date · place · temp · sky on one crawling
			     line, masked into the logo on the left. JS fills #tickerTrack. -->
			<div class="ticker" aria-hidden="true">
				<div class="ticker__track" id="tickerTrack"></div>
			</div>
		</div>
	</header>

	<!-- View container -->
	<main class="view-container">

		<!-- Compose view -->
		<div id="view-compose">
			<!-- Scroll region: type selector + fields scroll as one;
			     masthead and Post button stay pinned. -->
			<div class="compose-scroll">
			<div class="scroll-fade scroll-fade-top" aria-hidden="true"></div>

			<div class="type-grid-wrap">
				<div class="type-grid" id="typeBar" role="group" aria-label="<?php esc_attr_e( 'Post type', 'nop-indieweb' ); ?>">
				<?php
				// One tile per kind, its mark drawn from the same Kind_Icons sprite as the Sent
				// list and the site's kind-icon block, so a kind looks the same everywhere.
				$kind_labels = [
					'note'     => __( 'Note', 'nop-indieweb' ),
					'photo'    => __( 'Photo', 'nop-indieweb' ),
					'reply'    => __( 'Reply', 'nop-indieweb' ),
					'like'     => __( 'Like', 'nop-indieweb' ),
					'bookmark' => __( 'Bookmark', 'nop-indieweb' ),
					'repost'   => __( 'Repost', 'nop-indieweb' ),
					'quote'    => __( 'Quote', 'nop-indieweb' ),
					'story'    => __( 'Story', 'nop-indieweb' ),
					'rsvp'     => __( 'RSVP', 'nop-indieweb' ),
				];
				foreach ( $kind_labels as $kind_slug => $kind_label ) :
					$is_note = 'note' === $kind_slug;
					?>
				<button class="type-btn<?php echo $is_note ? ' is-active' : ''; ?>" data-type="<?php echo esc_attr( $kind_slug ); ?>" aria-pressed="<?php echo $is_note ? 'true' : 'false'; ?>" type="button">
					<span class="type-btn__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 256 256" focusable="false"><use href="#nop-kind-<?php echo esc_attr( $kind_slug ); ?>"/></svg></span>
					<span class="type-btn__label"><?php echo esc_html( $kind_label ); ?></span>
					<svg class="type-btn__arc" viewBox="0 0 75 75" aria-hidden="true"><path id="arc-<?php echo esc_attr( $kind_slug ); ?>-t" fill="none" d="M14.5,37.5 A23,23 0 0 1 60.5,37.5"/><path id="arc-<?php echo esc_attr( $kind_slug ); ?>-b" fill="none" d="M60.5,37.5 A23,23 0 0 1 14.5,37.5"/><circle class="type-btn__seal" cx="14.5" cy="37.5" r="2"/><circle class="type-btn__seal" cx="60.5" cy="37.5" r="2"/><text text-anchor="middle"><textPath href="#arc-<?php echo esc_attr( $kind_slug ); ?>-t" startOffset="50%"><?php echo esc_html( $kind_label ); ?></textPath></text><text text-anchor="middle"><textPath href="#arc-<?php echo esc_attr( $kind_slug ); ?>-b" startOffset="50%"><?php echo esc_html( $kind_label ); ?></textPath></text></svg>
				</button>
				<?php endforeach; ?>
				</div><!-- .type-grid -->
			</div><!-- .type-grid-wrap -->

			<div class="compose-fields">
				<div class="docket" id="docket">

				<!-- Filing line — kind (+ Clear, kept well away from Save) on the left;
				     Sent · Drafts · Save on the right. Serial and date live in the
				     masthead ticker, so they aren't repeated here. -->
				<div class="docket__header" id="docketHeader">
					<!-- The kind label doubles as the explainer toggle: tap it to reveal
					     a one-line "what & when" for the current kind (#kindInfo). -->
					<button type="button" class="docket__kind" id="kindInfoToggle" aria-expanded="false" aria-controls="kindInfo">
						<span class="docket__kind-name" id="docketKind"></span>
						<svg class="docket__kind-i" width="11" height="11" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true" focusable="false"><path d="M128,24A104,104,0,1,0,232,128,104.11,104.11,0,0,0,128,24Zm-4,48a12,12,0,1,1-12,12A12,12,0,0,1,124,72Zm12,112a16,16,0,0,1-16-16V128a8,8,0,0,1,0-16,16,16,0,0,1,16,16v40a8,8,0,0,1,0,16Z"/></svg>
						<span class="sr-only"><?php esc_html_e( '— what this kind is for', 'nop-indieweb' ); ?></span>
					</button>
					<button type="button" class="docket__clear" id="clearBtn" hidden>
						<?php esc_html_e( 'Clear', 'nop-indieweb' ); ?>
					</button>
					<button type="button" class="docket__action" id="sentBtn">
						<?php esc_html_e( 'Sent', 'nop-indieweb' ); ?><span class="docket__badge" id="sentCount" hidden></span>
					</button>
					<button type="button" class="docket__action" id="draftsBtn">
						<?php esc_html_e( 'Drafts', 'nop-indieweb' ); ?><span class="docket__badge" id="draftsCount" hidden></span>
					</button>
					<button type="button" class="docket__action" id="saveDraftBtn">
						<?php esc_html_e( 'Save', 'nop-indieweb' ); ?>
					</button>
				</div>
				<!-- Inline kind explainer — populated/toggled from index.js. -->
				<p class="docket__kind-info" id="kindInfo" role="note" hidden></p>

				<!-- Printed catalog fields (per kind) -->
				<div class="docket__fields" id="docketFields">

				<!-- URL field (reply, like, bookmark, repost, rsvp) -->
				<div class="field-row is-conditional docket-slip" id="fieldUrl" hidden>
					<span class="field-row__label"><?php esc_html_e( 'URL', 'nop-indieweb' ); ?></span>
					<div class="field-row__field">
						<input type="url" id="typeUrl" class="text-field" placeholder="<?php esc_attr_e( 'Paste a link…', 'nop-indieweb' ); ?>" autocomplete="off" aria-label="<?php esc_attr_e( 'URL', 'nop-indieweb' ); ?>">
						<!-- Printed reference line: hostname/path now; title/excerpt slot in later (server fetch). -->
						<div class="slip-ref" id="slipRef" hidden>
							<span class="slip-ref__host" id="slipHost"></span>
							<span class="slip-ref__path" id="slipPath"></span>
							<span class="slip-ref__title" id="slipTitle" hidden></span>
							<p class="slip-ref__excerpt" id="slipExcerpt" hidden></p>
						</div>
					</div>
				</div>

				<!-- RSVP response (rsvp) -->
				<div class="field-row is-conditional" id="fieldRsvp" hidden>
					<span class="field-row__label"><?php esc_html_e( 'Going', 'nop-indieweb' ); ?></span>
					<div class="rsvp-toggle" id="rsvpToggle" role="group" aria-label="<?php esc_attr_e( 'RSVP response', 'nop-indieweb' ); ?>">
						<button type="button" class="rsvp-btn is-active" data-rsvp="yes" aria-pressed="true"><?php esc_html_e( 'Yes', 'nop-indieweb' ); ?></button>
						<button type="button" class="rsvp-btn" data-rsvp="maybe" aria-pressed="false"><?php esc_html_e( 'Maybe', 'nop-indieweb' ); ?></button>
						<button type="button" class="rsvp-btn" data-rsvp="no" aria-pressed="false"><?php esc_html_e( 'No', 'nop-indieweb' ); ?></button>
					</div>
				</div>

				<!-- RSVP event details (rsvp) — a labelled cover sheet, pre-filled from
				     the pasted event URL, every field hand-editable. Records the single
				     date+time the author is attending: maps to h-event's dt-start, with
				     dt-end intentionally omitted (an RSVP is "I'm going on this day",
				     not "the show runs Jun 13–Jul 10"). Date and time are split so a
				     date-only source fills the date and leaves the time blank rather
				     than inventing midnight. -->
				<div class="field-group is-conditional event-fields" id="fieldEvent" hidden>
					<p class="event-status" id="eventStatus" aria-live="polite" hidden></p>
					<!-- Event poster — hot-linked, hidden field carries the URL through to
					     the Micropub payload, the thumbnail is just author confirmation
					     ("yep, that's the show"). Tap the ✕ to dismiss when the parser
					     pulls the wrong image (a venue-page og:image often resolves to
					     a site-wide logo, not the event's own poster). -->
					<figure class="event-poster preview-halftone" id="eventPoster" hidden>
						<img id="eventPosterImg" alt="" referrerpolicy="no-referrer" loading="lazy" decoding="async">
						<button type="button" class="event-poster__remove" id="eventPosterRemove" aria-label="<?php esc_attr_e( 'Remove poster', 'nop-indieweb' ); ?>">
							<svg width="14" height="14" aria-hidden="true" focusable="false"><use href="#nop-x"/></svg>
						</button>
					</figure>
					<input type="hidden" id="eventImage" value="">
					<div class="field-row">
						<span class="field-row__label"><?php esc_html_e( 'Title', 'nop-indieweb' ); ?></span>
						<textarea id="eventName" class="text-field text-field--grow" rows="1" placeholder="<?php esc_attr_e( 'Event title', 'nop-indieweb' ); ?>" autocomplete="off" aria-label="<?php esc_attr_e( 'Event title', 'nop-indieweb' ); ?>"></textarea>
					</div>
					<div class="field-row">
						<span class="field-row__label"><?php esc_html_e( 'Where', 'nop-indieweb' ); ?></span>
						<textarea id="eventLocation" class="text-field text-field--grow" rows="1" placeholder="<?php esc_attr_e( 'Location (optional)', 'nop-indieweb' ); ?>" autocomplete="off" aria-label="<?php esc_attr_e( 'Event location', 'nop-indieweb' ); ?>"></textarea>
					</div>
					<div class="field-row field-row--when">
						<span class="field-row__label"><?php esc_html_e( 'When', 'nop-indieweb' ); ?></span>
						<!-- Plain native <input type="date"> and <input type="time">.
						     Empty-state rendering is browser-dependent (Chrome shows the
						     dd/mm/yyyy pattern, Safari shows today's date as a hint) —
						     that's a platform inconsistency the HTML spec leaves to UA
						     discretion, not something we override. input.value is "" when
						     the user hasn't picked, which is what every consumer (form
						     validation, draft persistence, Micropub payload, the Clear
						     button visibility check) reads. -->
						<div class="field-row__pair">
							<input type="date" id="eventStartDate" class="text-field text-field--date" autocomplete="off" aria-label="<?php esc_attr_e( 'Event date', 'nop-indieweb' ); ?>">
							<input type="time" id="eventStartTime" class="text-field text-field--time" autocomplete="off" aria-label="<?php esc_attr_e( 'Event time', 'nop-indieweb' ); ?>">
						</div>
					</div>
				</div>

				<!-- URL specimen (like, repost) — watermark glyph when empty, big
				     hostname specimen once a URL parses -->
				<div class="url-specimen is-conditional" id="urlSpecimen" hidden>
					<span class="url-specimen__glyph" id="specimenGlyph" aria-hidden="true"></span>
					<p class="url-specimen__hint" id="specimenHint"></p>
					<p class="url-specimen__host" id="specimenHost" hidden></p>
					<p class="url-specimen__path" id="specimenPath" hidden></p>
					<p class="url-specimen__title" id="specimenTitle" hidden></p>
				</div>

				</div><!-- .docket__fields -->

				<!-- Ruled writing area -->
				<div class="docket__body" id="docketBody">

				<!-- Story media (story) — pick one photo or one short clip; the writing
				     area below is the optional caption. For a clip, a poster frame is
				     captured client-side. -->
				<div class="field-group" id="fieldStory" hidden>
					<div class="story-picker" id="storyPicker">
						<input type="file" id="storyInput" accept="image/*,video/*">
						<button type="button" class="story-picker__prompt" id="storyPrompt">
							<span class="story-picker__icon" aria-hidden="true"><svg width="32" height="32" viewBox="0 0 256 256" focusable="false"><use href="#nop-kind-story"/></svg></span>
							<p><?php esc_html_e( 'Pick a photo or short clip', 'nop-indieweb' ); ?></p>
							<small><?php esc_html_e( 'A photo or short vertical video', 'nop-indieweb' ); ?></small>
						</button>
						<video class="story-picker__preview" id="storyPreview" playsinline muted controls hidden></video>
						<img class="story-picker__preview" id="storyPhotoPreview" alt="" hidden>
						<button type="button" class="story-picker__remove" id="storyRemove" hidden aria-label="<?php esc_attr_e( 'Remove media', 'nop-indieweb' ); ?>">
							<svg width="14" height="14" aria-hidden="true" focusable="false"><use href="#nop-x"/></svg>
						</button>
						<span class="thumb__altflag" aria-hidden="true"><?php esc_html_e( 'ALT?', 'nop-indieweb' ); ?></span>
					</div>
					<!-- Alt text for a photo Story. Kept separate from the caption on
					     purpose — the caption renders as a visible figcaption, so copying
					     it into alt would announce the same sentence twice. "Same as
					     caption" is there for when it genuinely does describe the photo. -->
					<div class="alt-texts" id="storyAltTexts" hidden>
						<div class="alt-text-row">
							<div class="alt-text-head">
								<span class="alt-text-label"><?php esc_html_e( 'Alt text', 'nop-indieweb' ); ?></span>
								<button type="button" class="alt-same" id="storyAltSame" disabled><?php esc_html_e( 'Same as caption', 'nop-indieweb' ); ?></button>
							</div>
							<input type="text" id="storyAlt" class="thumb__alt" placeholder="<?php esc_attr_e( 'Describe it…', 'nop-indieweb' ); ?>" autocomplete="off" aria-label="<?php esc_attr_e( 'Alt text for the story photo', 'nop-indieweb' ); ?>">
						</div>
					</div>
				</div>

				<!-- Content -->
				<div class="field-group" id="fieldContent">
					<label class="sr-only" for="content"><?php esc_html_e( 'Content', 'nop-indieweb' ); ?></label>
					<textarea class="compose-field" id="content" rows="4"></textarea>
					<div class="compose-meta">
						<span class="char-count" id="charCount" aria-live="polite" hidden></span>
					</div>
				</div>

				<!-- Photo picker — sits below the writing pad on text-first kinds (note,
				     reply) as a quiet "Add photos" link; the photo kind lifts it back above
				     the pad as the full hero dropzone (CSS order). -->
				<div class="field-group is-conditional" id="fieldPhoto" hidden>
					<input type="file" id="photoInput" accept="image/*" multiple hidden>
					<button type="button" class="photo-picker" id="photoPicker">
						<span class="photo-picker-icon" aria-hidden="true"><svg width="32" height="32" viewBox="0 0 256 256" fill="currentColor"><path d="M208,56H180.28L166.65,35.56A8,8,0,0,0,160,32H96a8,8,0,0,0-6.65,3.56L75.72,56H48A24,24,0,0,0,24,80V192a24,24,0,0,0,24,24H208a24,24,0,0,0,24-24V80A24,24,0,0,0,208,56Zm8,136a8,8,0,0,1-8,8H48a8,8,0,0,1-8-8V80a8,8,0,0,1,8-8H80a8,8,0,0,0,6.65-3.56L100.28,48h55.44l13.63,20.44A8,8,0,0,0,176,72h32a8,8,0,0,1,8,8ZM128,88a44,44,0,1,0,44,44A44.05,44.05,0,0,0,128,88Zm0,72a28,28,0,1,1,28-28A28,28,0,0,1,128,160Z"/></svg></span>
						<span class="photo-picker__label"><?php esc_html_e( 'Add photos', 'nop-indieweb' ); ?></span>
						<span class="photo-picker__hint"><?php esc_html_e( 'Up to 10', 'nop-indieweb' ); ?></span>
					</button>
					<div class="thumbnails" id="thumbnails"></div>
					<div class="alt-texts" id="altTexts"></div>
				</div>

				<!-- Quote attribution (quote) — who said it. The writing area above holds
				     the quote itself; the source link below is optional. -->
				<div class="field-row" id="fieldCite" hidden>
					<label class="field-row__label" for="citeAuthor"><?php esc_html_e( 'Cite', 'nop-indieweb' ); ?></label>
					<div class="field-row__field">
						<textarea id="citeAuthor" class="text-field text-field--grow" rows="1" placeholder="<?php esc_attr_e( 'e.g. Maya Angelou', 'nop-indieweb' ); ?>" autocomplete="off"></textarea>
					</div>
				</div>

				<!-- Quote source link (quote) — optional; we can't pull the passage from
				     a URL, so this is just a link to where it came from, if anywhere. -->
				<div class="field-row" id="fieldQuoteLink" hidden>
					<label class="field-row__label" for="quoteLink"><?php esc_html_e( 'URL', 'nop-indieweb' ); ?></label>
					<div class="field-row__field">
						<input type="url" id="quoteLink" class="text-field" placeholder="<?php esc_attr_e( 'Link (optional)', 'nop-indieweb' ); ?>" autocomplete="off" inputmode="url">
					</div>
				</div>

				<!-- Quote commentary (quote) — optional note shown under the quoted
				     passage; the writing area above holds the passage itself. -->
				<div class="field-row" id="fieldQuoteComment" hidden>
					<label class="field-row__label" for="quoteComment"><?php esc_html_e( 'Thoughts', 'nop-indieweb' ); ?></label>
					<div class="field-row__field">
						<textarea id="quoteComment" class="text-field text-field--note" rows="2" placeholder="<?php esc_attr_e( 'Add your thoughts (optional)…', 'nop-indieweb' ); ?>" autocomplete="off"></textarea>
					</div>
				</div>

				</div><!-- .docket__body -->
				</div><!-- .docket -->

				<!-- Metadata ledger — tags + delivery options ruled as one index
				     section. The vertical margin rule (drawn on .ledger) runs unbroken
				     down the gutter; each .field-row--ledger lays a hairline rule, and
				     the two cross in faint registration marks. -->
				<div class="ledger" id="metaLedger">

				<!-- Tags (note, photo) -->
				<div class="field-row field-row--ledger" id="fieldTags">
					<label class="field-row__label" for="tagInput"><?php esc_html_e( 'Tags', 'nop-indieweb' ); ?></label>
					<div class="field-row__field">
						<div class="tags-field" id="tagsField">
							<span id="tagChips"></span>
							<input
								type="text"
								id="tagInput"
								class="tag-input"
								placeholder="<?php esc_attr_e( 'Add a tag…', 'nop-indieweb' ); ?>"
								autocomplete="off"
								autocorrect="off"
								autocapitalize="off"
							>
						</div>
						<?php if ( $top_tags ) : ?>
						<div class="quick-tags" id="quickTags" aria-label="<?php esc_attr_e( 'Most used tags', 'nop-indieweb' ); ?>">
							<?php foreach ( $top_tags as $quick_tag ) : ?>
							<button type="button" class="quick-tag" data-tag="<?php echo esc_attr( $quick_tag ); ?>" aria-pressed="false"><?php echo esc_html( $quick_tag ); ?></button>
							<?php endforeach; ?>
						</div>
						<?php endif; ?>
					</div>
				</div>

				<!-- Categories (all kinds) — pre-stamped with the kind's default
				     categories from settings, fully editable before posting. Shares
				     the tag field's chip UI wholesale. -->
				<div class="field-row field-row--ledger" id="fieldCats">
					<label class="field-row__label" for="catInput"><?php esc_html_e( 'Categories', 'nop-indieweb' ); ?></label>
					<div class="field-row__field">
						<div class="tags-field" id="catsField">
							<span id="catChips"></span>
							<input
								type="text"
								id="catInput"
								class="tag-input"
								placeholder="<?php esc_attr_e( 'Add a category…', 'nop-indieweb' ); ?>"
								autocomplete="off"
								autocorrect="off"
								autocapitalize="off"
							>
						</div>
						<?php if ( $top_cats ) : ?>
						<div class="quick-tags" id="quickCats" aria-label="<?php esc_attr_e( 'Most used categories', 'nop-indieweb' ); ?>">
							<?php foreach ( $top_cats as $quick_cat ) : ?>
							<button type="button" class="quick-tag" data-tag="<?php echo esc_attr( $quick_cat ); ?>" aria-pressed="false"><?php echo esc_html( $quick_cat ); ?></button>
							<?php endforeach; ?>
						</div>
						<?php endif; ?>
					</div>
				</div>

				<!-- Post options — where/how this goes out: opt-in geotag + cross-post
				     targets. One divided block of consistent toggle rows; the :has()
				     rule in style.scss collapses it (divider and all) when no group shows. -->
				<div class="post-options" id="fieldOptions">

					<!-- Order: Options · Post to (Tags/Categories live in their own rows
					     above this group). The :has()/~ ledger rules below adapt to this
					     DOM order, so the last visible group always closes the ledger. -->

					<!-- Options (all kinds; Place only where the kind carries location) —
					     the one-tap commitments as STAMPS: inked = it's happening, outline
					     = it isn't. An active stamp unfolds its single detail line below
					     (resolved place / privacy note / date+time). The checkbox inputs
					     keep their ids so drafts, payload and switchType stay untouched;
					     #fieldLocation now names the Place stamp itself. -->
					<div class="post-options__group field-row field-row--ledger" id="fieldOptionsRow" role="group" aria-labelledby="optOptionsLabel">
						<span class="field-row__label" id="optOptionsLabel"><?php esc_html_e( 'Options', 'nop-indieweb' ); ?></span>
						<div class="field-row__field option-stamps">
							<div class="stamp-row">
								<label class="stamp" id="fieldLocation" hidden>
									<input type="checkbox" id="locationCheck" class="sr-only">
									<span class="stamp__face"><?php esc_html_e( 'Place', 'nop-indieweb' ); ?></span>
								</label>
								<label class="stamp">
									<input type="checkbox" id="privateCheck" class="sr-only">
									<span class="stamp__face"><?php esc_html_e( 'Private', 'nop-indieweb' ); ?></span>
								</label>
								<label class="stamp">
									<input type="checkbox" id="scheduleCheck" class="sr-only">
									<span class="stamp__face"><?php esc_html_e( 'Later', 'nop-indieweb' ); ?></span>
								</label>
							</div>
							<span class="opt-detail" id="locationPlace" aria-live="polite" hidden></span>
							<span class="opt-detail opt-detail--private"><?php esc_html_e( 'Only you — kept out of feeds, syndication and webmentions.', 'nop-indieweb' ); ?></span>
							<div class="field-row__pair schedule-fields opt-detail" id="scheduleFields" hidden>
								<input type="date" id="scheduleDate" class="text-field text-field--date" autocomplete="off" aria-label="<?php esc_attr_e( 'Schedule date', 'nop-indieweb' ); ?>">
								<input type="time" id="scheduleTime" class="text-field text-field--time" autocomplete="off" aria-label="<?php esc_attr_e( 'Schedule time', 'nop-indieweb' ); ?>">
							</div>
							<p class="schedule-note" id="scheduleNote" role="note" aria-live="polite" hidden><?php esc_html_e( "That time's passed — this will post now.", 'nop-indieweb' ); ?></p>
						</div>
					</div>

					<!-- Syndication targets (all kinds; Pixelfed photo-only). Rendered by
					     renderSyndicators() into #syndicators; the group is shown/hidden there. -->
					<div class="post-options__group field-row field-row--ledger" id="fieldSyndicate" role="group" aria-labelledby="optSyndicateLabel" hidden>
						<span class="field-row__label" id="optSyndicateLabel"><?php esc_html_e( 'Post to', 'nop-indieweb' ); ?></span>
						<div class="syndicators" id="syndicators"></div>
					</div>

				</div>

				</div><!-- .ledger -->

				</div><!-- .compose-fields -->
				<div class="scroll-fade scroll-fade-bottom" aria-hidden="true"></div>
			</div><!-- .compose-scroll -->

			<!-- Kind-strip edge-shadows live OUTSIDE the scroller (in #view-compose,
			     which never moves) so their halftone dots stay grid-locked and never
			     swim; only their reveal/clip follows the strip. See .type-shadow CSS. -->
			<div class="type-shadow type-shadow-left" aria-hidden="true"></div>
			<div class="type-shadow type-shadow-right" aria-hidden="true"></div>

			<div class="bottom-bar">
				<button class="btn btn-primary is-incomplete" id="postBtn" type="button">
					<svg class="btn-primary__icon" aria-hidden="true" width="18" height="18" viewBox="0 0 256 256" fill="currentColor"><path d="M231.87,114l-168-95.89A16,16,0,0,0,40.92,37.34L71.55,128,40.92,218.67A16,16,0,0,0,56,240a16.15,16.15,0,0,0,7.93-2.1l167.92-96.05a16,16,0,0,0,.05-27.89ZM56,224a.56.56,0,0,0,0-.12L85.74,136H144a8,8,0,0,0,0-16H85.74L56.06,32.16A.46.46,0,0,0,56,32l168,95.83Z"/></svg>
					<span class="btn-primary__label"><?php esc_html_e( 'Post', 'nop-indieweb' ); ?></span>
				</button>
			</div>
		</div><!-- #view-compose -->

		<!-- Progress view -->
		<div id="view-progress" hidden>
			<div class="progress-view">
				<div class="progress-spinner" aria-hidden="true"></div>
				<p class="progress-status" id="progressStatus" aria-live="polite" tabindex="-1"><?php esc_html_e( 'Posting…', 'nop-indieweb' ); ?></p>
				<div class="progress-bar-track" role="progressbar" aria-label="<?php esc_attr_e( 'Posting progress', 'nop-indieweb' ); ?>" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
					<div class="progress-bar-fill" id="progressFill"></div>
				</div>
				<button type="button" class="progress-undo" id="progressUndo" data-view-focus hidden><?php esc_html_e( 'Undo', 'nop-indieweb' ); ?></button>
			</div>
		</div>

		<!-- Success view -->
		<div id="view-success" hidden>
			<div class="success-scroll">
				<div class="success-hero">
					<div class="success-banner">
						<span class="success-check" aria-hidden="true"><svg width="28" height="28" aria-hidden="true" focusable="false"><use href="#nop-check"/></svg></span>
						<h2 tabindex="-1" data-view-focus><?php esc_html_e( 'Posted', 'nop-indieweb' ); ?></h2>
					</div>
					<p class="success-streak" id="successStreak" hidden></p>
				</div>
				<div class="success-photos" id="successPhotos"></div>
				<a class="success-permalink" id="successLink" href="#" target="_blank" rel="noopener noreferrer"></a>
				<ul class="delivery" id="successDelivery" aria-live="polite" hidden></ul>
				<!-- Syndication outlives this screen (cron delivery runs 1–2 min behind
				     publish), so once the short poll window is spent we stop pretending
				     and point at the Sent view rather than sit on a frozen "…". -->
				<button type="button" class="delivery__handoff" id="deliveryHandoff" hidden>
					<?php esc_html_e( 'Still sending — check Sent →', 'nop-indieweb' ); ?>
				</button>
				<div class="success-actions">
					<a class="btn btn-accent" id="editBtn" href="#" target="_blank" rel="noopener noreferrer" hidden>
						<?php esc_html_e( 'Open in editor →', 'nop-indieweb' ); ?>
					</a>
					<button class="btn btn-share" id="shareBtn" type="button" hidden>
						<span class="btn-share__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 60 60" fill="currentColor"><path fill-rule="evenodd" clip-rule="evenodd" d="M30 5.45455C16.4439 5.45455 5.45455 16.4439 5.45455 30V35.4545C5.45455 45.9982 14.0018 54.5455 24.5455 54.5455H30C43.5561 54.5455 54.5455 43.5561 54.5455 30C54.5455 16.4439 43.5561 5.45455 30 5.45455ZM0 30C0 13.4315 13.4315 0 30 0C46.5685 0 60 13.4315 60 30C60 46.5685 46.5685 60 30 60H24.5455C10.9893 60 0 49.0107 0 35.4545V30ZM30 16.3636C22.4688 16.3636 16.3636 22.4688 16.3636 30C16.3636 37.5312 22.4688 43.6364 30 43.6364C37.5312 43.6364 43.6364 37.5312 43.6364 30C43.6364 22.4688 37.5312 16.3636 30 16.3636ZM10.9091 30C10.9091 19.4564 19.4564 10.9091 30 10.9091C40.5436 10.9091 49.0909 19.4564 49.0909 30C49.0909 40.5436 40.5436 49.0909 30 49.0909C19.4564 49.0909 10.9091 40.5436 10.9091 30ZM30.0775 27.3502C28.5713 27.3502 27.3502 28.5713 27.3502 30.0775C27.3502 31.5837 26.1292 32.8048 24.623 32.8048C23.1167 32.8048 21.8957 31.5837 21.8957 30.0775C21.8957 25.5589 25.5589 21.8957 30.0775 21.8957C34.5963 21.8957 38.2593 25.5589 38.2593 30.0775C38.2593 31.5837 37.0383 32.8048 35.5321 32.8048C34.0258 32.8048 32.8048 31.5837 32.8048 30.0775C32.8048 28.5713 31.5837 27.3502 30.0775 27.3502Z"/></svg></span>
						<?php esc_html_e( 'Share', 'nop-indieweb' ); ?>
					</button>
					<button class="btn btn-copy" id="copyLinkBtn" type="button" hidden>
						<span class="btn-share__icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 256 256" fill="currentColor"><path d="M137.54,186.36a8,8,0,0,1,0,11.31l-9.94,10A56,56,0,0,1,48.38,128.4l24.71-24.71a56,56,0,0,1,79.32,0,8,8,0,0,1-11.32,11.31,40,40,0,0,0-56.68,0L59.7,139.72a40,40,0,0,0,56.58,56.58l9.94-9.94A8,8,0,0,1,137.54,186.36Zm70.08-138a56.08,56.08,0,0,0-79.22,0l-9.94,9.95a8,8,0,0,0,11.32,11.31l9.94-9.94a40,40,0,0,1,56.58,56.58L172.19,148a40,40,0,0,1-56.68,0,8,8,0,0,0-11.32,11.31,56,56,0,0,0,79.32,0l24.71-24.71A56.08,56.08,0,0,0,207.62,48.38Z"/></svg></span>
						<?php esc_html_e( 'Copy link', 'nop-indieweb' ); ?>
					</button>
				</div>
			</div>
			<div class="bottom-bar">
				<button class="btn btn-secondary" id="anotherBtn" type="button">
					<?php esc_html_e( 'Post another', 'nop-indieweb' ); ?>
				</button>
			</div>
		</div>

		<!-- Drafts view — the saved-drafts library (local + cross-device server drafts).
		     A first-class screen on the same paper as compose/success, not a floating
		     overlay; index.js swaps to it via showView('drafts'). -->
		<div id="view-drafts" hidden>
			<div class="drafts-view__scroll">
				<header class="drafts-view__head">
					<h2 class="drafts-view__title" tabindex="-1" data-view-focus><?php esc_html_e( 'Drafts', 'nop-indieweb' ); ?></h2>
				</header>
				<div class="drafts-view__list" id="draftsList"></div>
			</div>
			<div class="bottom-bar">
				<button class="btn btn-secondary" id="draftsClose" type="button"><?php esc_html_e( 'Back', 'nop-indieweb' ); ?></button>
			</div>
		</div>

		<!-- Sent view — where a post's delivery lands once the success screen has
		     moved on. Same paper as drafts; index.js swaps to it via showView('sent'). -->
		<div id="view-sent" hidden>
			<div class="drafts-view__scroll sent-view__scroll">
				<header class="drafts-view__head">
					<h2 class="drafts-view__title" tabindex="-1" data-view-focus><?php esc_html_e( 'Sent', 'nop-indieweb' ); ?></h2>
				</header>
				<div class="drafts-view__list sent-view__list" id="sentList" aria-live="polite"></div>
			</div>
			<div class="bottom-bar">
				<button class="btn btn-secondary" id="sentClose" type="button"><?php esc_html_e( 'Back', 'nop-indieweb' ); ?></button>
			</div>
		</div>

	</main><!-- .view-container -->

	<div class="toast" id="toast" role="status" aria-live="polite"></div>

</div><!-- .app -->

<script>
window.NOP = {
		nonce:       <?php echo wp_json_encode( $nonce ); ?>,
		mediaUrl:    <?php echo wp_json_encode( $media_url ); ?>,
		micropubUrl: <?php echo wp_json_encode( $micropub_url ); ?>,
		nowUrl:      <?php echo wp_json_encode( $now_url ); ?>,
		draftsUrl:   <?php echo wp_json_encode( $drafts_url ); ?>,
		fetchEventUrl: <?php echo wp_json_encode( $fetch_event_url ); ?>,
		fetchContextUrl: <?php echo wp_json_encode( $fetch_context_url ); ?>,
		syndicationStatusUrl: <?php echo wp_json_encode( $syndication_status_url ); ?>,
		syndicationSentUrl: <?php echo wp_json_encode( $syndication_sent_url ); ?>,
		syndicationRetryUrl: <?php echo wp_json_encode( $syndication_retry_url ); ?>,
		tagsUrl:     <?php echo wp_json_encode( $tags_url ); ?>,
		catsUrl:     <?php echo wp_json_encode( $cats_url ); ?>,
		kindCats:    <?php echo wp_json_encode( $kind_cats ); ?>,
		syndicateTo: <?php echo wp_json_encode( $syndicate_to ); ?>,
		kindInfo:    <?php echo wp_json_encode( $kind_info ); ?>,
		nextId:      <?php echo wp_json_encode( $next_id ); ?>,
		postsToday:  <?php echo wp_json_encode( $posts_today ); ?>,
		lastPostTs:  <?php echo wp_json_encode( $last_post_ts ); ?>,
		swUrl:       <?php echo wp_json_encode( home_url( '/post?sw=1' ) ); ?>,
		swScope:     <?php echo wp_json_encode( wp_parse_url( home_url( '/post' ), PHP_URL_PATH ) ?: '/post' ); ?>,
		nonceUrl:    <?php echo wp_json_encode( home_url( '/post?nonce=1' ) ); ?>,
		l10n:        <?php echo wp_json_encode( $this->js_strings() ); ?>,
};
</script>
<script src="<?php echo esc_url( $post_js_url ); ?>" defer></script>
</body>
</html>
		<?php
	}
}
