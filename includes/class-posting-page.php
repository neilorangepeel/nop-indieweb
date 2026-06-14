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
 * eight post types — Note, Photo, Reply, Like, Bookmark, Repost, Article,
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
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/manifest+json; charset=utf-8' );
		}
		$path  = wp_parse_url( home_url( '/post' ), PHP_URL_PATH ) ?: '/post';
		// Bundled app icon (the target mark on the ink tile) — a dedicated, consistent
		// install icon rather than whatever the WordPress site icon happens to be. The
		// full-bleed tile keeps the glyph inside the maskable safe zone.
		$icons = [
			[ 'src' => esc_url_raw( NOP_INDIEWEB_URL . 'assets/icons/app-192.png' ), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable' ],
			[ 'src' => esc_url_raw( NOP_INDIEWEB_URL . 'assets/icons/app-512.png' ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable' ],
		];

		echo wp_json_encode( [
			'name'             => get_bloginfo( 'name' ) . ' · Post',
			'short_name'       => __( 'Post', 'nop-indieweb' ),
			'start_url'        => $path,
			'scope'            => $path,
			'display'          => 'standalone',
			'background_color' => '#F4EFE6',
			'theme_color'      => '#20713A',
			'icons'            => $icons,
			// Register as a share target (Android/desktop) — sharing a page/text opens
			// /post?title=&text=&url=, which the client maps to a bookmark/note. iOS has
			// no Web Share Target, but the same params drive an iOS Shortcut.
			'share_target'     => [
				'action' => $path,
				'method' => 'GET',
				'params' => [ 'title' => 'title', 'text' => 'text', 'url' => 'url' ],
			],
		] );
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
		$font_dir = get_theme_file_uri( 'assets/fonts/brandon-text' );
		$cond_dir = get_theme_file_uri( 'assets/fonts/brandon-text-condensed' );
		$page     = home_url( '/post' );
		$sw_asset = file_exists( NOP_INDIEWEB_DIR . 'build/post/index.asset.php' ) ? ( include NOP_INDIEWEB_DIR . 'build/post/index.asset.php' ) : [];
		$sw_ver   = is_array( $sw_asset ) && ! empty( $sw_asset['version'] ) ? $sw_asset['version'] : '1';
		$shell    = [
			$page,
			NOP_INDIEWEB_URL . 'build/post/style-index.css?ver=' . rawurlencode( $sw_ver ),
			NOP_INDIEWEB_URL . 'build/post/index.js?ver=' . rawurlencode( $sw_ver ),
			$font_dir . '/brandon-text_normal_400.woff2',
			$font_dir . '/brandon-text_normal_500.woff2',
			$font_dir . '/brandon-text_normal_700.woff2',
			$font_dir . '/brandon-text_normal_800.woff2',
			$cond_dir . '/brandon-text-condensed_normal_700.woff2',
			$cond_dir . '/brandon-text-condensed_normal_800.woff2',
		];
		?>
'use strict';
var CACHE = 'nop-post-v3';
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

	// The /post page: network-first so the nonce refreshes online; cached shell offline.
	if ( req.mode === 'navigate' ) {
		e.respondWith(
			fetch( req ).then( function ( res ) {
				var copy = res.clone();
				caches.open( CACHE ).then( function ( c ) { c.put( PAGE, copy ); } );
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
	if ( /\.woff2($|\?)/.test( url.pathname ) || url.pathname.indexOf( '/build/post/' ) !== -1 ) {
		e.respondWith(
			caches.match( req ).then( function ( m ) {
				return m || fetch( req ).then( function ( res ) {
					if ( res && res.ok ) { var copy = res.clone(); caches.open( CACHE ).then( function ( c ) { c.put( req, copy ); } ); }
					return res;
				} );
			} ).catch( function () { return caches.match( req ); } )
		);
	}
} );
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
		$media_url    = esc_url( rest_url( 'wp/v2/media' ) );
		$micropub_url = esc_url( rest_url( 'nop-indieweb/v1/micropub' ) );
		$now_url      = esc_url( rest_url( 'nop-indieweb/v1/now' ) );
		// Escaped at the point of output below (PHPCS can't track escaping through assignment).
		$site_name    = get_bloginfo( 'name' );
		$font_dir     = get_theme_file_uri( 'assets/fonts/brandon-text' );
		$cond_dir     = get_theme_file_uri( 'assets/fonts/brandon-text-condensed' );

		// Built app assets (CSS now, the app script next) — version-busted from the
		// build's asset file so a new build invalidates the URL.
		$post_asset   = file_exists( NOP_INDIEWEB_DIR . 'build/post/index.asset.php' ) ? ( include NOP_INDIEWEB_DIR . 'build/post/index.asset.php' ) : [];
		$post_ver     = is_array( $post_asset ) && ! empty( $post_asset['version'] ) ? $post_asset['version'] : '1';
		$post_css_url = NOP_INDIEWEB_URL . 'build/post/style-index.css?ver=' . rawurlencode( $post_ver );
		$post_js_url  = NOP_INDIEWEB_URL . 'build/post/index.js?ver=' . rawurlencode( $post_ver );

		$user      = wp_get_current_user();
		$user_name = $user->first_name ?: $user->display_name;

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

		// Time-of-day greetings, translated here so the JS device stays i18n-safe.
		$greetings = [
			'morning'   => __( 'Good morning', 'nop-indieweb' ),
			'afternoon' => __( 'Good afternoon', 'nop-indieweb' ),
			'evening'   => __( 'Good evening', 'nop-indieweb' ),
			'night'     => __( 'Up late', 'nop-indieweb' ),
		];

		// Compute syndication targets here so the page can inline them — saves a
		// second round-trip (the old ?q=config fetch booted all of WordPress again
		// just to list these). Shape mirrors the Micropub config endpoint.
		$syndicate_to = [];
		$manager      = Plugin::get_instance()->syndication_manager();
		if ( $manager ) {
			$syndicate_to = array_map(
				// Pixelfed is a photo-only network — flag it so the client only
				// offers it on photo posts (nothing else can syndicate there).
				fn( $s ) => [ 'uid' => $s['slug'], 'name' => $s['label'], 'photoOnly' => ( 'pixelfed' === $s['slug'] ) ],
				$manager->get_panel_data()
			);
		}
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
<meta name="theme-color" id="themeColor" content="#00787F">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url( NOP_INDIEWEB_URL . 'assets/icons/app-192.png' ); ?>">
<link rel="manifest" href="<?php echo esc_url( home_url( '/post?manifest=1' ) ); ?>">
<link rel="preload" href="<?php echo esc_url( $font_dir . '/brandon-text_normal_400.woff2' ); ?>" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?php echo esc_url( $cond_dir . '/brandon-text-condensed_normal_800.woff2' ); ?>" as="font" type="font/woff2" crossorigin>
<title><?php echo esc_html( $site_name ); ?></title>
<style>
<?php
foreach ( [ '400', '500', '700', '800' ] as $weight ) {
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
<div class="app" id="app" data-type="note">

	<span id="inkNow" aria-hidden="true" style="position:absolute;width:0;height:0;overflow:hidden;color:var(--ink)"></span>

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
		<div class="masthead__bar">
			<span class="brand__mark" aria-hidden="true">
				<svg viewBox="0 0 60 60" fill="none"><path fill-rule="evenodd" clip-rule="evenodd" d="M30 5.45455C16.4439 5.45455 5.45455 16.4439 5.45455 30V35.4545C5.45455 45.9982 14.0018 54.5455 24.5455 54.5455H30C43.5561 54.5455 54.5455 43.5561 54.5455 30C54.5455 16.4439 43.5561 5.45455 30 5.45455ZM0 30C0 13.4315 13.4315 0 30 0C46.5685 0 60 13.4315 60 30C60 46.5685 46.5685 60 30 60H24.5455C10.9893 60 0 49.0107 0 35.4545V30ZM30 16.3636C22.4688 16.3636 16.3636 22.4688 16.3636 30C16.3636 37.5312 22.4688 43.6364 30 43.6364C37.5312 43.6364 43.6364 37.5312 43.6364 30C43.6364 22.4688 37.5312 16.3636 30 16.3636ZM10.9091 30C10.9091 19.4564 19.4564 10.9091 30 10.9091C40.5436 10.9091 49.0909 19.4564 49.0909 30C49.0909 40.5436 40.5436 49.0909 30 49.0909C19.4564 49.0909 10.9091 40.5436 10.9091 30ZM30.0775 27.3502C28.5713 27.3502 27.3502 28.5713 27.3502 30.0775C27.3502 31.5837 26.1292 32.8048 24.623 32.8048C23.1167 32.8048 21.8957 31.5837 21.8957 30.0775C21.8957 25.5589 25.5589 21.8957 30.0775 21.8957C34.5963 21.8957 38.2593 25.5589 38.2593 30.0775C38.2593 31.5837 37.0383 32.8048 35.5321 32.8048C34.0258 32.8048 32.8048 31.5837 32.8048 30.0775C32.8048 28.5713 31.5837 27.3502 30.0775 27.3502Z"/></svg>
			</span>
			<!-- Metadata ticker — serial · date · place · temp · sky on one crawling
			     line, masked into the logo on the left. JS fills #tickerTrack. -->
			<div class="ticker" aria-hidden="true">
				<div class="ticker__track" id="tickerTrack"></div>
			</div>
		</div>
	</header>

	<!-- View container -->
	<div class="view-container">

		<!-- Compose view -->
		<div id="view-compose">
			<!-- Scroll region: type selector + fields scroll as one;
			     masthead and Post button stay pinned. -->
			<div class="compose-scroll">
			<div class="scroll-fade scroll-fade-top" aria-hidden="true"></div>

			<div class="type-grid-wrap">
				<div class="type-grid" id="typeBar" role="group" aria-label="<?php esc_attr_e( 'Post type', 'nop-indieweb' ); ?>">
				<button class="type-btn is-active" data-type="note" aria-pressed="true" type="button">
					<span class="type-btn__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 256 256" fill="currentColor"><path d="M229.66,58.34l-32-32a8,8,0,0,0-11.32,0l-96,96A8,8,0,0,0,88,128v32a8,8,0,0,0,8,8h32a8,8,0,0,0,5.66-2.34l96-96A8,8,0,0,0,229.66,58.34ZM124.69,152H104V131.31l64-64L188.69,88ZM200,76.69,179.31,56,192,43.31,212.69,64ZM224,128v80a16,16,0,0,1-16,16H48a16,16,0,0,1-16-16V48A16,16,0,0,1,48,32h80a8,8,0,0,1,0,16H48V208H208V128a8,8,0,0,1,16,0Z"/></svg></span>
					<span><?php esc_html_e( 'Note', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="photo" aria-pressed="false" type="button">
					<span class="type-btn__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 256 256" fill="currentColor"><path d="M208,56H180.28L166.65,35.56A8,8,0,0,0,160,32H96a8,8,0,0,0-6.65,3.56L75.72,56H48A24,24,0,0,0,24,80V192a24,24,0,0,0,24,24H208a24,24,0,0,0,24-24V80A24,24,0,0,0,208,56Zm8,136a8,8,0,0,1-8,8H48a8,8,0,0,1-8-8V80a8,8,0,0,1,8-8H80a8,8,0,0,0,6.65-3.56L100.28,48h55.44l13.63,20.44A8,8,0,0,0,176,72h32a8,8,0,0,1,8,8ZM128,88a44,44,0,1,0,44,44A44.05,44.05,0,0,0,128,88Zm0,72a28,28,0,1,1,28-28A28,28,0,0,1,128,160Z"/></svg></span>
					<span><?php esc_html_e( 'Photo', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="reply" aria-pressed="false" type="button">
					<span class="type-btn__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 256 256" fill="currentColor"><path d="M224,128a96,96,0,0,1-94.71,96H128A95.38,95.38,0,0,1,62.1,197.8a8,8,0,0,1,11-11.63A80,80,0,1,0,71.43,71.39a3.07,3.07,0,0,1-.26.25L44.59,96H72a8,8,0,0,1,0,16H24a8,8,0,0,1-8-8V56a8,8,0,0,1,16,0V85.8L60.25,60A96,96,0,0,1,224,128Z"/></svg></span>
					<span><?php esc_html_e( 'Reply', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="like" aria-pressed="false" type="button">
					<span class="type-btn__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 256 256" fill="currentColor"><path d="M178,40c-20.65,0-38.73,8.88-50,23.89C116.73,48.88,98.65,40,78,40a62.07,62.07,0,0,0-62,62c0,70,103.79,126.66,108.21,129a8,8,0,0,0,7.58,0C136.21,228.66,240,172,240,102A62.07,62.07,0,0,0,178,40ZM128,214.8C109.74,204.16,32,155.69,32,102A46.06,46.06,0,0,1,78,56c19.45,0,35.78,10.36,42.6,27a8,8,0,0,0,14.8,0c6.82-16.67,23.15-27,42.6-27a46.06,46.06,0,0,1,46,46C224,155.61,146.24,204.15,128,214.8Z"/></svg></span>
					<span><?php esc_html_e( 'Like', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="bookmark" aria-pressed="false" type="button">
					<span class="type-btn__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 256 256" fill="currentColor"><path d="M184,32H72A16,16,0,0,0,56,48V224a8,8,0,0,0,12.24,6.78L128,193.43l59.77,37.35A8,8,0,0,0,200,224V48A16,16,0,0,0,184,32Zm0,177.57-51.77-32.35a8,8,0,0,0-8.48,0L72,209.57V48H184Z"/></svg></span>
					<span><?php esc_html_e( 'Bookmark', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="repost" aria-pressed="false" type="button">
					<span class="type-btn__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 256 256" fill="currentColor"><path d="M224,48V96a8,8,0,0,1-8,8H168a8,8,0,0,1,0-16h28.69L182.06,73.37a79.56,79.56,0,0,0-56.13-23.43h-.45A79.52,79.52,0,0,0,69.59,72.71,8,8,0,0,1,58.41,61.27a96,96,0,0,1,135,.79L208,76.69V48a8,8,0,0,1,16,0ZM186.41,183.29a80,80,0,0,1-112.47-.66L59.31,168H88a8,8,0,0,0,0-16H40a8,8,0,0,0-8,8v48a8,8,0,0,0,16,0V179.31l14.63,14.63A95.43,95.43,0,0,0,130,222.06h.53a95.36,95.36,0,0,0,67.07-27.33,8,8,0,0,0-11.18-11.44Z"/></svg></span>
					<span><?php esc_html_e( 'Repost', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="rsvp" aria-pressed="false" type="button">
					<span class="type-btn__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 256 256" fill="currentColor"><path d="M208,32H184V24a8,8,0,0,0-16,0v8H88V24a8,8,0,0,0-16,0v8H48A16,16,0,0,0,32,48V208a16,16,0,0,0,16,16H208a16,16,0,0,0,16-16V48A16,16,0,0,0,208,32ZM72,48v8a8,8,0,0,0,16,0V48h80v8a8,8,0,0,0,16,0V48h24V80H48V48ZM208,208H48V96H208V208Zm-29.66-85.66a8,8,0,0,1,0,11.32l-48,48a8,8,0,0,1-11.32,0l-24-24a8,8,0,0,1,11.32-11.32L124,164.69l42.34-42.35A8,8,0,0,1,178.34,122.34Z"/></svg></span>
					<span><?php esc_html_e( 'RSVP', 'nop-indieweb' ); ?></span>
				</button>
				</div><!-- .type-grid -->
				<div class="type-fade type-fade-left" aria-hidden="true"></div>
				<div class="type-fade type-fade-right" aria-hidden="true"></div>
			</div><!-- .type-grid-wrap -->

			<div class="compose-fields">

				<!-- URL field (reply, like, bookmark, repost, rsvp) -->
				<div class="field-group is-conditional" id="fieldUrl" hidden>
					<label class="field-label" id="urlLabel" for="typeUrl"><?php esc_html_e( 'URL', 'nop-indieweb' ); ?></label>
					<input type="url" id="typeUrl" class="text-field" placeholder="https://…" autocomplete="off">
				</div>

				<!-- RSVP response (rsvp) -->
				<div class="field-group is-conditional" id="fieldRsvp" hidden>
					<span class="field-label"><?php esc_html_e( 'Going?', 'nop-indieweb' ); ?></span>
					<div class="rsvp-toggle" id="rsvpToggle" role="group" aria-label="<?php esc_attr_e( 'RSVP response', 'nop-indieweb' ); ?>">
						<button type="button" class="rsvp-btn is-active" data-rsvp="yes" aria-pressed="true"><?php esc_html_e( 'Yes', 'nop-indieweb' ); ?></button>
						<button type="button" class="rsvp-btn" data-rsvp="maybe" aria-pressed="false"><?php esc_html_e( 'Maybe', 'nop-indieweb' ); ?></button>
						<button type="button" class="rsvp-btn" data-rsvp="no" aria-pressed="false"><?php esc_html_e( 'No', 'nop-indieweb' ); ?></button>
					</div>
				</div>

				<!-- URL specimen (like, repost) — watermark glyph when empty, big
				     hostname specimen once a URL parses -->
				<div class="url-specimen is-conditional" id="urlSpecimen" hidden>
					<span class="url-specimen__glyph" id="specimenGlyph" aria-hidden="true"></span>
					<p class="url-specimen__hint" id="specimenHint"></p>
					<p class="url-specimen__host" id="specimenHost" hidden></p>
					<p class="url-specimen__path" id="specimenPath" hidden></p>
				</div>

				<!-- Photo picker -->
				<div class="field-group is-conditional" id="fieldPhoto" hidden>
					<div class="photo-picker" id="photoPicker">
						<input type="file" id="photoInput" accept="image/*" multiple>
						<span class="photo-picker-icon" aria-hidden="true"><svg width="32" height="32" viewBox="0 0 256 256" fill="currentColor"><path d="M208,56H180.28L166.65,35.56A8,8,0,0,0,160,32H96a8,8,0,0,0-6.65,3.56L75.72,56H48A24,24,0,0,0,24,80V192a24,24,0,0,0,24,24H208a24,24,0,0,0,24-24V80A24,24,0,0,0,208,56Zm8,136a8,8,0,0,1-8,8H48a8,8,0,0,1-8-8V80a8,8,0,0,1,8-8H80a8,8,0,0,0,6.65-3.56L100.28,48h55.44l13.63,20.44A8,8,0,0,0,176,72h32a8,8,0,0,1,8,8ZM128,88a44,44,0,1,0,44,44A44.05,44.05,0,0,0,128,88Zm0,72a28,28,0,1,1,28-28A28,28,0,0,1,128,160Z"/></svg></span>
						<p><?php esc_html_e( 'Add photos', 'nop-indieweb' ); ?></p>
						<small><?php esc_html_e( 'Tap to select · up to 10', 'nop-indieweb' ); ?></small>
					</div>
					<div class="thumbnails" id="thumbnails"></div>
					<div class="alt-texts" id="altTexts"></div>
				</div>

				<!-- Content -->
				<div class="field-group" id="fieldContent">
					<label class="sr-only" for="content"><?php esc_html_e( 'Content', 'nop-indieweb' ); ?></label>
					<div class="compose-wrap">
						<textarea class="compose-field" id="content" rows="4"></textarea>
						<span class="compose-prompt" id="composePrompt" aria-hidden="true"></span>
					</div>
					<div class="char-count" id="charCount" aria-live="polite" hidden></div>
				</div>

				<!-- Tags (note, photo) -->
				<div class="field-group" id="fieldTags">
					<label class="field-label" for="tagInput"><?php esc_html_e( 'Tags', 'nop-indieweb' ); ?></label>
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
				</div>

				<!-- Syndicators -->
				<details class="syndicate-details" id="syndicateDetails" hidden>
					<summary class="syndicate-summary"><?php esc_html_e( 'Syndicate to', 'nop-indieweb' ); ?></summary>
					<div class="syndicators" id="syndicators"></div>
				</details>

				</div><!-- .compose-fields -->
				<div class="scroll-fade scroll-fade-bottom" aria-hidden="true"></div>
			</div><!-- .compose-scroll -->

			<div class="bottom-bar">
				<button class="btn btn-primary" id="postBtn" disabled type="button">
					<svg class="btn-primary__icon" aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M3.4 20.4l17.45-7.48a1 1 0 0 0 0-1.84L3.4 3.6a.993.993 0 0 0-1.39.91L2 9.12c0 .5.37.93.87.99L17 12 2.87 13.88c-.5.07-.87.5-.87 1l.01 4.61c0 .71.73 1.2 1.39.91z"/></svg>
					<?php esc_html_e( 'Post', 'nop-indieweb' ); ?>
				</button>
			</div>
		</div><!-- #view-compose -->

		<!-- Progress view -->
		<div id="view-progress" hidden>
			<div class="progress-view">
				<div class="progress-spinner" aria-hidden="true"></div>
				<p class="progress-status" id="progressStatus" aria-live="polite"><?php esc_html_e( 'Posting…', 'nop-indieweb' ); ?></p>
				<div class="progress-bar-track" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
					<div class="progress-bar-fill" id="progressFill"></div>
				</div>
			</div>
		</div>

		<!-- Success view -->
		<div id="view-success" hidden>
			<div class="success-scroll">
				<div class="success-hero">
					<span class="burst" aria-hidden="true"><i style="--a:0deg"></i><i style="--a:36deg"></i><i style="--a:72deg"></i><i style="--a:108deg"></i><i style="--a:144deg"></i><i style="--a:180deg"></i><i style="--a:216deg"></i><i style="--a:252deg"></i><i style="--a:288deg"></i><i style="--a:324deg"></i></span>
					<div class="success-banner">
						<span class="success-check" aria-hidden="true"><svg width="28" height="28" viewBox="0 0 256 256" fill="currentColor"><path d="M229.66,77.66l-128,128a8,8,0,0,1-11.32,0l-56-56a8,8,0,0,1,11.32-11.32L96,188.69,218.34,66.34a8,8,0,0,1,11.32,11.32Z"/></svg></span>
						<h2><?php esc_html_e( 'Posted', 'nop-indieweb' ); ?></h2>
					</div>
					<p class="success-streak" id="successStreak" hidden></p>
				</div>
				<div class="success-photos" id="successPhotos"></div>
				<a class="success-permalink" id="successLink" href="#" target="_blank" rel="noopener noreferrer"></a>
				<div class="success-actions">
					<a class="btn btn-accent" id="editBtn" href="#" target="_blank" rel="noopener noreferrer" hidden>
						<?php esc_html_e( 'Open in editor →', 'nop-indieweb' ); ?>
					</a>
					<button class="btn btn-instagram" id="instagramBtn" type="button" hidden>
						<?php esc_html_e( 'Share to Instagram', 'nop-indieweb' ); ?>
					</button>
				</div>
			</div>
			<div class="bottom-bar">
				<button class="btn btn-secondary" id="anotherBtn" type="button">
					<?php esc_html_e( 'Post another', 'nop-indieweb' ); ?>
				</button>
			</div>
		</div>

	</div><!-- .view-container -->

	<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>

</div><!-- .app -->

<script>
(function () {
	'use strict';

	var NOP = {
		nonce:       <?php echo wp_json_encode( $nonce ); ?>,
		mediaUrl:    <?php echo wp_json_encode( $media_url ); ?>,
		micropubUrl: <?php echo wp_json_encode( $micropub_url ); ?>,
		nowUrl:      <?php echo wp_json_encode( $now_url ); ?>,
		syndicateTo: <?php echo wp_json_encode( $syndicate_to ); ?>,
		userName:    <?php echo wp_json_encode( $user_name ); ?>,
		greetings:   <?php echo wp_json_encode( $greetings ); ?>,
		nextId:      <?php echo wp_json_encode( $next_id ); ?>,
		postsToday:  <?php echo wp_json_encode( $posts_today ); ?>,
		lastPostTs:  <?php echo wp_json_encode( $last_post_ts ); ?>,
		swUrl:       <?php echo wp_json_encode( home_url( '/post?sw=1' ) ); ?>,
		swScope:     <?php echo wp_json_encode( wp_parse_url( home_url( '/post' ), PHP_URL_PATH ) ?: '/post' ); ?>,
		nonceUrl:    <?php echo wp_json_encode( home_url( '/post?nonce=1' ) ); ?>,
	};

	// Register the service worker — installs the app shell so /post opens offline.
	if ( 'serviceWorker' in navigator ) {
		navigator.serviceWorker.register( NOP.swUrl, { scope: NOP.swScope } ).catch( function () {} );
	}

	var DRAFT_KEY    = 'nop_post_draft';
	var CHAR_LIMITS  = { bluesky: 300, mastodon: 500, pixelfed: 500 };
	var NOTE_PROMPTS = [ "What's happening?", "Seen anything good?", "A thought…", "What's on your mind?", "Share something…" ];
	var notePrompt   = NOTE_PROMPTS[ Math.floor( Math.random() * NOTE_PROMPTS.length ) ];
	var restoring    = false;

	// Trailing debounce — coalesces high-frequency work (draft writes on keystroke)
	// to one call after the user pauses, keeping the typing path off localStorage.
	function debounce( fn, ms ) {
		var t;
		return function () { clearTimeout( t ); t = setTimeout( fn, ms ); };
	}

	var app = document.getElementById( 'app' );

	// ── Clock + time-of-day device ──────────────────────────────────────────────

	var DAYS   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
	var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

	var deviceTimeEl = document.getElementById( 'deviceTime' );
	// The masthead serial — shown in the ticker; bumped by one each time a post is
	// sent so "post another" shows the next likely id.
	var nextSerial   = NOP.nextId;

	// iOS 26 ignores theme-color and tints the status bar by SAMPLING the page (the
	// html accent on the tab, the .device-chrome band on the standalone app). It
	// samples at render time only, so a JS-driven --ink change (the OKLCH re-ink)
	// doesn't re-trigger it — on the standalone app's band, briefly dropping the band
	// from the render forces iOS to re-sample the new accent. Gated to standalone +
	// dark + real device (light re-samples; the desktop mock band must not flash).
	var deviceChrome = document.querySelector( '.device-chrome' );
	var isMockFrame  = window.matchMedia( '(min-width: 600px) and (min-height: 600px)' );
	var prefersDark  = window.matchMedia( '(prefers-color-scheme: dark)' );
	function nudgeStatusBar() {
		// Standalone only: the band exists there (it's hidden in a Safari tab, where
		// theme-color drives the bar and updates per kind on its own).
		if ( ! deviceChrome || ! window.navigator.standalone || isMockFrame.matches || ! prefersDark.matches ) { return; }
		deviceChrome.style.display = 'none';
		requestAnimationFrame( function () { deviceChrome.style.display = 'flex'; } );
	}

	// ── Re-ink: a colour-picker-style hue rotation through OKLCH ──────────────────
	// Drive --ink in JS so the kind switch sweeps through the hue wheel (teal →
	// green → orange) rather than a flat RGB crossfade. Every element reads
	// var(--ink), so they all rotate together — one uniform sweep.
	var inkNow    = document.getElementById( 'inkNow' );
	var KIND_VAR  = { note: '--teal', photo: '--blue', reply: '--orange', like: '--red', bookmark: '--green', repost: '--violet', rsvp: '--magenta' };
	var INK       = {};
	function buildInkMap() {
		var probe = document.createElement( 'span' );
		probe.style.cssText = 'position:absolute;width:0;height:0;overflow:hidden';
		app.appendChild( probe );
		Object.keys( KIND_VAR ).forEach( function ( k ) {
			probe.style.color = 'var(' + KIND_VAR[ k ] + ')';
			INK[ k ] = getComputedStyle( probe ).color;
		} );
		app.removeChild( probe );
	}
	var OKLCH_OK = !! ( window.CSS && CSS.supports && CSS.supports( 'color', 'color-mix(in oklch, red, blue)' ) );
	var inkRAF;
	// The body's field tints from --ink too, but body sits outside .app, so mirror
	// the ink onto :root — app keeps its own (its CSS rule beats the inherited value),
	// while body finally has a hue to read. Driven here so both sweep in lockstep.
	var root = document.documentElement;
	function animateInk( from, to ) {
		cancelAnimationFrame( inkRAF );
		if ( ! to ) { return; }
		// Initial load (no-anim), unsupported engine, or no change → settle to the
		// scheme-aware CSS value instantly.
		if ( ! OKLCH_OK || app.classList.contains( 'no-anim' ) || ! from || from === to ) {
			app.style.removeProperty( '--ink' );
			root.style.setProperty( '--ink', to );
			return;
		}
		var start = 0;
		function step( now ) {
			if ( ! start ) { start = now; }
			var t = Math.min( ( now - start ) / 400, 1 );                       // ≈ --fade
			var e = t < 0.5 ? 2 * t * t : 1 - Math.pow( -2 * t + 2, 2 ) / 2;     // easeInOut
			var mix = 'color-mix(in oklch, ' + from + ', ' + to + ' ' + ( e * 100 ).toFixed( 2 ) + '%)';
			app.style.setProperty( '--ink', mix );
			root.style.setProperty( '--ink', mix );
			if ( t < 1 ) { inkRAF = requestAnimationFrame( step ); }
			else { app.style.removeProperty( '--ink' ); root.style.setProperty( '--ink', to ); }  // app → CSS value; root holds the steady ink
		}
		inkRAF = requestAnimationFrame( step );
	}
	var lastTime = '';

	function greetingFor( hour ) {
		if ( hour < 5 )  return NOP.greetings.night;
		if ( hour < 12 ) return NOP.greetings.morning;
		if ( hour < 18 ) return NOP.greetings.afternoon;
		if ( hour < 22 ) return NOP.greetings.evening;
		return NOP.greetings.night;
	}

	function updateClock() {
		var now  = new Date();
		var time = String( now.getHours() ).padStart( 2, '0' ) + ':' + String( now.getMinutes() ).padStart( 2, '0' );
		var date = DAYS[ now.getDay() ] + ' ' + now.getDate() + ' ' + MONTHS[ now.getMonth() ];
		if ( time !== lastTime ) {
			if ( deviceTimeEl ) { deviceTimeEl.textContent = time; }
			tkTime = time;   // separate ticker items: date · time
			tkDate = date;
			setTk( 'tk-time', tkTime );
			setTk( 'tk-date', tkDate );
			setTk( 'tk-golden', tkGolden() );                          // countdown ticks down
			if ( lastPostTs ) { setTk( 'tk-last', 'Last posted ' + tkAgo( lastPostTs ) ); }
			lastTime = time;
		}
	}
	updateClock();
	setInterval( updateClock, 1000 );

	// ── Metadata ticker ───────────────────────────────────────────────────────
	// One crawling line of the current moment (serial · date · place · temp · sky)
	// beside the logo. The track holds two identical sequences and animates -50%
	// for a seamless loop. Items with no datum yet are simply omitted; the time
	// updates in place each minute (setTk) so the crawl never restarts, while a
	// resolved /now rebuilds once (renderTicker).
	var tickerTrack = document.getElementById( 'tickerTrack' );
	var TK_SPEED    = 24;                          // px/sec crawl (slow, ambient)
	var TK_ID_PRE   = 'Post No. ';            // spelled out — Brandon has no № glyph
	var tkTime = '', tkDate = '', tkPlace = '', tkTemp = '', tkSky = '';
	var tkSunset   = 0;                            // Unix sunset (from /now) → golden hour
	var postsToday = NOP.postsToday || 0;          // server count; bumped on each post
	var lastPostTs = NOP.lastPostTs || 0;          // Unix of the last published post
	var queueCount = 0;                            // offline posts waiting to send

	// Golden hour ≈ the last hour before sunset: counts down to it, reads "now"
	// during, then falls back to the sunset time once it's passed.
	function tkGolden() {
		if ( ! tkSunset ) { return ''; }
		var now = Math.floor( Date.now() / 1000 ), start = tkSunset - 3600;
		if ( now < start )    { return 'Golden hour in ' + tkDur( start - now ); }
		if ( now < tkSunset ) { return 'Golden hour now'; }
		return 'Sunset ' + tkClock( tkSunset );
	}
	function tkDur( s ) {
		var m = Math.round( s / 60 );
		return m < 60 ? m + 'm' : Math.floor( m / 60 ) + 'h ' + ( m % 60 ) + 'm';
	}
	function tkClock( ts ) {
		var d = new Date( ts * 1000 );
		return String( d.getHours() ).padStart( 2, '0' ) + ':' + String( d.getMinutes() ).padStart( 2, '0' );
	}
	function tkAgo( ts ) {
		var s = Math.floor( Date.now() / 1000 ) - ts;
		if ( s < 90 ) { return 'just now'; }
		var m = Math.round( s / 60 ); if ( m < 60 ) { return m + 'm ago'; }
		var h = Math.round( m / 60 ); if ( h < 24 ) { return h + 'h ago'; }
		var d = Math.round( h / 24 ); if ( d < 7 )  { return d + 'd ago'; }
		return Math.round( d / 7 ) + 'w ago';
	}
	function tkCadence() { return ordinal( postsToday + 1 ) + ' today'; }

	function tkItems() {
		var out = [ { c: 'tk-id', h: TK_ID_PRE + nextSerial } ];
		if ( queueCount > 0 ) { out.push( { c: 'tk-queue', h: queueCount + ' to send' } ); }
		out.push( { c: 'tk-cadence', h: tkCadence() } );
		if ( lastPostTs ) { out.push( { c: 'tk-last',   h: 'Last posted ' + tkAgo( lastPostTs ) } ); }
		if ( tkDate )     { out.push( { c: 'tk-date',   h: tkDate } ); }
		if ( tkTime )     { out.push( { c: 'tk-time',   h: tkTime } ); }
		if ( tkSunset )   { out.push( { c: 'tk-golden', h: tkGolden() } ); }
		if ( tkPlace )    { out.push( { c: 'tk-place',  h: tkPlace } ); }
		if ( tkTemp )     { out.push( { c: 'tk-temp',   h: tkTemp } ); }
		if ( tkSky )      { out.push( { c: 'tk-sky',    h: tkSky } ); }
		return out;
	}
	function tkSeqHTML() {
		return tkItems().map( function ( it ) {
			return '<span class="ticker__item ' + it.c + '">' + it.h + '</span>'
				+ '<span class="ticker__sep" aria-hidden="true">·</span>';
		} ).join( '' );
	}
	function renderTicker() {
		if ( ! tickerTrack ) { return; }
		var seq = tkSeqHTML();
		tickerTrack.innerHTML = '<span class="ticker__seq">' + seq + '</span>'
			+ '<span class="ticker__seq">' + seq + '</span>';
		var w = tickerTrack.firstChild.getBoundingClientRect().width;
		if ( w ) { tickerTrack.style.animationDuration = ( w / TK_SPEED ).toFixed( 1 ) + 's'; }
	}
	// In-place text swap for a recurring item (time, id) — avoids rebuilding the
	// track, which would restart the crawl.
	function setTk( cls, text ) {
		if ( ! tickerTrack ) { return; }
		var els = tickerTrack.getElementsByClassName( cls ), i;
		for ( i = 0; i < els.length; i++ ) { els[ i ].textContent = text; }
	}
	lastTime = '';        // re-seed: populate tkTime/tkDate before the first build
	updateClock();
	renderTicker();

	// ── Current-moment data (place · temp · sky) ─────────────────────────────────
	// Device GPS → the /now endpoint (the server reverse-geocodes + fetches current
	// weather with the plugin's existing keys, so nothing leaks client-side). Both
	// the coordinates and the resolved payload are cached in localStorage: coords for
	// 6h so iOS isn't re-prompted every visit, the payload for 30 min so the ticker
	// paints instantly from cache then refreshes. Every failure path is silent — the
	// item is simply omitted (permission denied / offline / no API keys configured).
	var GEO_KEY = 'nop_post_geo', NOW_KEY = 'nop_post_now';
	var GEO_TTL = 6 * 60 * 60 * 1000, NOW_TTL = 30 * 60 * 1000;

	// Pirate Weather icon keyword → riso glyph (sun/moon reuse the flight-path art).
	var WX_SUN  = '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="5"/><g stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="1.5" x2="12" y2="4.5"/><line x1="12" y1="19.5" x2="12" y2="22.5"/><line x1="1.5" y1="12" x2="4.5" y2="12"/><line x1="19.5" y1="12" x2="22.5" y2="12"/><line x1="4.4" y1="4.4" x2="6.5" y2="6.5"/><line x1="17.5" y1="17.5" x2="19.6" y2="19.6"/><line x1="4.4" y1="19.6" x2="6.5" y2="17.5"/><line x1="17.5" y1="6.5" x2="19.6" y2="4.4"/></g></svg>';
	var WX_MOON = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z"/></svg>';
	var WX_CLOUD = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6.5 18a4.5 4.5 0 0 1-.5-8.97 6 6 0 0 1 11.64-1.2A4 4 0 0 1 17.5 18h-11Z"/></svg>';
	var WX_RAIN  = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6.5 15a4.5 4.5 0 0 1-.5-8.97 6 6 0 0 1 11.64-1.2A4 4 0 0 1 17.5 15h-11Z"/><g stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="8" y1="18.5" x2="7" y2="21.5"/><line x1="12" y1="18.5" x2="11" y2="21.5"/><line x1="16" y1="18.5" x2="15" y2="21.5"/></g></svg>';
	var WX_SNOW  = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6.5 15a4.5 4.5 0 0 1-.5-8.97 6 6 0 0 1 11.64-1.2A4 4 0 0 1 17.5 15h-11Z"/><circle cx="8" cy="20" r="1"/><circle cx="12" cy="21.3" r="1"/><circle cx="16" cy="20" r="1"/></svg>';
	var WX_FOG   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="4" y1="8" x2="20" y2="8"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="6" y1="16" x2="18" y2="16"/></svg>';
	var WX_WIND  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 9h10.5a2.5 2.5 0 1 0-2.5-2.5"/><path d="M3 14h14a2.5 2.5 0 1 1-2.5 2.5"/></svg>';
	var WX_PCD   = '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="8" cy="7.5" r="3"/><g stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><line x1="8" y1="1.6" x2="8" y2="2.9"/><line x1="2.1" y1="7.5" x2="3.4" y2="7.5"/><line x1="3.4" y1="2.9" x2="4.4" y2="3.9"/><line x1="12.6" y1="2.9" x2="11.6" y2="3.9"/></g><path d="M9 19a4 4 0 0 1-.4-7.98 5.3 5.3 0 0 1 10.3-1A3.6 3.6 0 0 1 18.6 19H9Z"/></svg>';
	var WX_PCN   = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M13.5 3.4A4.4 4.4 0 1 0 9 10.3 3.7 3.7 0 0 1 13.5 3.4Z"/><path d="M9 19a4 4 0 0 1-.4-7.98 5.3 5.3 0 0 1 10.3-1A3.6 3.6 0 0 1 18.6 19H9Z"/></svg>';
	var WX_ICON = {
		'clear-day': WX_SUN, 'clear-night': WX_MOON, 'cloudy': WX_CLOUD,
		'partly-cloudy-day': WX_PCD, 'partly-cloudy-night': WX_PCN,
		'rain': WX_RAIN, 'snow': WX_SNOW, 'sleet': WX_RAIN, 'wind': WX_WIND, 'fog': WX_FOG
	};

	function readJSON( k ) { try { return JSON.parse( localStorage.getItem( k ) ); } catch ( e ) { return null; } }
	function writeJSON( k, v ) { try { localStorage.setItem( k, JSON.stringify( v ) ); } catch ( e ) {} }

	function renderNow( d ) {
		if ( ! d ) { return; }
		tkPlace = d.place ? ( d.country ? d.place + ', ' + d.country : d.place ) : '';
		// Show both units — Belfast thinks in °C, but the °F is a free, useful glance.
		// Derive °F from the rounded °C so the two never disagree at a rounding edge
		// (a raw 11.7° would otherwise print "12°C / 53°F", which reads as a bug).
		tkTemp = '';
		if ( d.temp_c != null && d.temp_c !== '' ) {
			var c = Math.round( d.temp_c );
			tkTemp = c + '°C / ' + Math.round( c * 9 / 5 + 32 ) + '°F';
		}
		tkSky = '';
		if ( d.summary || d.icon ) {
			var icon = WX_ICON[ d.icon ] || '';
			tkSky = ( icon ? '<span class="ticker__icon" aria-hidden="true">' + icon + '</span>' : '' ) + ( d.summary || '' );
		}
		tkSunset = d.sunset ? Number( d.sunset ) : 0;   // golden hour derives from this
		renderTicker();   // rebuild once now that place/temp/sky/sunset have resolved
	}

	function fetchNow( lat, lon ) {
		var sep = NOP.nowUrl.indexOf( '?' ) >= 0 ? '&' : '?';
		fetch( NOP.nowUrl + sep + 'lat=' + encodeURIComponent( lat ) + '&lon=' + encodeURIComponent( lon ), {
			headers: { 'X-WP-Nonce': NOP.nonce }
		} )
			.then( function ( r ) { return r.ok ? r.json() : null; } )
			.then( function ( d ) { if ( d ) { renderNow( d ); writeJSON( NOW_KEY, { data: d, ts: Date.now() } ); } } )
			.catch( function () {} );
	}

	function withCoords( cb ) {
		var g = readJSON( GEO_KEY );
		if ( g && g.lat != null && ( Date.now() - g.ts ) < GEO_TTL ) { cb( g.lat, g.lon ); return; }
		if ( ! navigator.geolocation ) { return; }
		navigator.geolocation.getCurrentPosition(
			function ( pos ) {
				var lat = pos.coords.latitude, lon = pos.coords.longitude;
				writeJSON( GEO_KEY, { lat: lat, lon: lon, ts: Date.now() } );
				cb( lat, lon );
			},
			function () {},
			{ enableHighAccuracy: false, timeout: 8000, maximumAge: GEO_TTL }
		);
	}

	function loadNow() {
		var cached = readJSON( NOW_KEY );
		if ( cached && cached.data ) { renderNow( cached.data ); }        // paint instantly
		if ( cached && ( Date.now() - cached.ts ) < NOW_TTL ) { return; }  // still fresh
		withCoords( fetchNow );
	}
	loadNow();
	// Retry when the tab becomes visible again. iOS Safari's site-settings panel
	// (aA → Location → Allow) does NOT re-fire a load-time geolocation request, so
	// without this the grid stays empty until a manual reload after granting. Also
	// covers pull-to-refresh and bfcache restores. Cheap on repeat — loadNow() early
	// -returns while the cached payload is fresh, and a denied permission no longer
	// prompts, so this can't spam requests.
	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'visible' ) { loadNow(); }
	} );
	window.addEventListener( 'pageshow', loadNow );

	// ── Type configuration ────────────────────────────────────────────────────

	var TYPE_CONFIG = {
		note:     { urlProp: null,           hasContent: true,  hasTags: true,  contentPlaceholder: 'Write a note…' },
		photo:    { urlProp: null,           hasContent: true,  hasTags: true,  contentPlaceholder: 'Write a caption…' },
		reply:    { urlProp: 'in-reply-to',  hasContent: true,  hasTags: false, urlLabel: 'Reply to URL', contentPlaceholder: 'Your reply…' },
		like:     { urlProp: 'like-of',      hasContent: false, hasTags: false, urlLabel: 'Like URL', urlHint: "Paste the URL you're liking" },
		bookmark: { urlProp: 'bookmark-of',  hasContent: true,  hasTags: false, urlLabel: 'Bookmark URL', contentPlaceholder: 'Notes…' },
		repost:   { urlProp: 'repost-of',    hasContent: false, hasTags: false, urlLabel: 'Repost URL', urlHint: "Paste the URL you're reposting" },
		rsvp:     { urlProp: 'in-reply-to',  hasContent: true,  hasTags: false, urlLabel: 'Event URL', contentPlaceholder: 'Add a note (optional)…', hasRsvp: true },
	};

	var currentType   = 'note';
	var selectedFiles = [];
	var photoAlts     = [];
	var currentTags   = [];
	var currentRsvp   = 'yes';

	// ── DOM refs ──────────────────────────────────────────────────────────────

	var postBtn      = document.getElementById( 'postBtn' );
	var fieldUrl     = document.getElementById( 'fieldUrl' );
	var fieldRsvp    = document.getElementById( 'fieldRsvp' );
	var fieldPhoto   = document.getElementById( 'fieldPhoto' );
	var fieldContent = document.getElementById( 'fieldContent' );
	var fieldTags    = document.getElementById( 'fieldTags' );
	var urlInput     = document.getElementById( 'typeUrl' );
	var urlLabel     = document.getElementById( 'urlLabel' );
	var contentInput  = document.getElementById( 'content' );
	var composePrompt = document.getElementById( 'composePrompt' );
	var picker       = document.getElementById( 'photoPicker' );

	// Big rotating prompt overlay — set its text, and fade it once typing starts.
	function setPrompt( text ) { composePrompt.textContent = text; syncPrompt(); }
	function syncPrompt() { composePrompt.classList.toggle( 'is-hidden', contentInput.value.length > 0 ); }
	// The note placeholder, led by the time-of-day greeting — the old standalone
	// greeting line now lives here, in front of the rotating prompt.
	function notePlaceholder() {
		var greet = greetingFor( new Date().getHours() );
		return ( NOP.userName ? greet + ', ' + NOP.userName : greet ) + ' — ' + notePrompt;
	}
	function autoGrowContent() {
		contentInput.style.height = 'auto';
		contentInput.style.height = contentInput.scrollHeight + 'px';
		updateScrollFades();
	}
	var composeScroll = document.querySelector( '.compose-scroll' );
	var fadeTop       = document.querySelector( '.scroll-fade-top' );
	var fadeBottom    = document.querySelector( '.scroll-fade-bottom' );
	// Reveal by opacity over the static mask. Safe from the old haloing because the
	// shadow dot is the same size as the base dot (--ht-dot = --grain-dot): fading it
	// in just darkens each dot in place — no ring, and the mask never moves.
	function updateScrollFades() {
		if ( ! composeScroll ) return;
		var RAMP  = 56;
		var top   = composeScroll.scrollTop;
		var below = composeScroll.scrollHeight - composeScroll.clientHeight - top;
		if ( fadeTop )    fadeTop.style.opacity    = Math.min( Math.max( top, 0 ) / RAMP, 1 );
		if ( fadeBottom ) fadeBottom.style.opacity = Math.min( Math.max( below, 0 ) / RAMP, 1 );
	}
	// ONE master dot grid: every dot layer is anchored to the VIEWPORT origin (0,0),
	// so the surround, the phone interior and the swell-shadows all share the same
	// fixed grid and register exactly — the dots never move, scrolling just slides
	// content over them and the masks/opacity reveal the shadow dots in place.
	// background-position is per-box, so cancel each box's viewport offset mod pitch.
	function gridPitch() { return parseFloat( getComputedStyle( root ).getPropertyValue( '--grain-pitch' ) ) || 4; }
	function lockXY( left, top, pitch ) {
		function m( v ) { return ( ( v % pitch ) + pitch ) % pitch; }
		return ( -m( left ) ).toFixed( 2 ) + 'px ' + ( -m( top ) ).toFixed( 2 ) + 'px';
	}
	function lockEl( el, pitch ) {
		if ( ! el ) { return; }
		var r = el.getBoundingClientRect();
		el.style.backgroundPosition = lockXY( r.left, r.top, pitch );
	}
	// The kind-row edge fades scroll vertically with the strip, so re-anchor them on
	// every compose scroll to keep their dots pinned to the fixed grid.
	function lockTypeFades() {
		var pitch = gridPitch();
		lockEl( typeFadeLeft, pitch );
		lockEl( typeFadeRight, pitch );
	}
	function alignHalftone() {
		if ( ! composeScroll ) { return; }
		var pitch = gridPitch();
		// Phone interior grain — mobile: .app carries it; desktop: the .app::before
		// card, fed a CSS var (a pseudo-element has no JS box of its own).
		lockEl( app, pitch );
		var ar = app.getBoundingClientRect();
		var cs = getComputedStyle( app );
		var bl = parseFloat( cs.borderLeftWidth ) || 0;
		var bt = parseFloat( cs.borderTopWidth )  || 0;
		var st = parseFloat( cs.getPropertyValue( '--safe-top' ) ) || 0;
		app.style.setProperty( '--cardpos', lockXY( ar.left + bl, ar.top + bt + st, pitch ) );
		// Vertical scroll-fades — lock to the composeScroll edges (stable while stuck).
		var sr = composeScroll.getBoundingClientRect();
		var h  = ( fadeBottom && fadeBottom.offsetHeight ) || 120;
		if ( fadeTop )    { fadeTop.style.backgroundPosition    = lockXY( sr.left, sr.top, pitch ); }
		if ( fadeBottom ) { fadeBottom.style.backgroundPosition = lockXY( sr.left, sr.bottom - h, pitch ); }
		lockTypeFades();
	}
	composeScroll.addEventListener( 'scroll', function () { updateScrollFades(); lockTypeFades(); }, { passive: true } );

	// Horizontal scroll-fades for the kind row — same ramp-by-distance as the
	// vertical ones, so the left/right halftone edges fade in by how far there is
	// left to scroll. The right one is visible on load (Repost/RSVP sit off-screen).
	var typeBar       = document.getElementById( 'typeBar' );
	var typeFadeLeft  = document.querySelector( '.type-fade-left' );
	var typeFadeRight = document.querySelector( '.type-fade-right' );
	function updateTypeFades() {
		if ( ! typeBar ) { return; }
		// Ramp the reveal over ~one badge of scroll (matching the longer gradient's
		// reach) so the fade unfurls with the drag rather than snapping to full after
		// a few pixels. Opacity is read straight off scrollLeft, so it tracks the
		// finger (and momentum) 1:1.
		var RAMP  = 96;
		var left  = typeBar.scrollLeft;
		var right = typeBar.scrollWidth - typeBar.clientWidth - left;
		if ( typeFadeLeft )  { typeFadeLeft.style.opacity  = Math.min( Math.max( left, 0 ) / RAMP, 1 ); }
		if ( typeFadeRight ) { typeFadeRight.style.opacity = Math.min( Math.max( right, 0 ) / RAMP, 1 ); }
	}
	typeBar.addEventListener( 'scroll', updateTypeFades, { passive: true } );

	var resizeRAF;
	window.addEventListener( 'resize', function () {
		cancelAnimationFrame( resizeRAF );
		resizeRAF = requestAnimationFrame( function () { updateScrollFades(); alignHalftone(); updateTypeFades(); } );
	} );

	var photoInput   = document.getElementById( 'photoInput' );
	var thumbs       = document.getElementById( 'thumbnails' );
	var altTexts     = document.getElementById( 'altTexts' );
	var specimen      = document.getElementById( 'urlSpecimen' );
	var specimenGlyph = document.getElementById( 'specimenGlyph' );
	var specimenHint  = document.getElementById( 'specimenHint' );
	var specimenHost  = document.getElementById( 'specimenHost' );
	var specimenPath  = document.getElementById( 'specimenPath' );

	// URL specimen — on URL-only kinds the empty space below the field shows the
	// kind glyph as a watermark; once the URL parses it becomes a type specimen
	// of the target's hostname, confirming what you're about to act on.
	function updateSpecimen() {
		var cfg  = TYPE_CONFIG[ currentType ];
		var show = !! cfg.urlProp && ! cfg.hasContent;
		specimen.hidden = ! show;
		if ( ! show ) return;

		var parsed = null;
		var raw    = urlInput.value.trim();
		if ( raw ) {
			try { parsed = new URL( raw ); } catch ( e ) {}
		}
		var filled = !! ( parsed && parsed.hostname );

		specimenGlyph.hidden = filled;
		specimenHint.hidden  = filled;
		specimenHost.hidden  = ! filled;
		if ( filled ) {
			specimenHost.textContent = parsed.hostname.replace( /^www\./, '' );
			var path = parsed.pathname + parsed.search;
			specimenPath.textContent = path;
			specimenPath.hidden = path === '/';
		} else {
			specimenPath.hidden = true;
			specimenHint.textContent = cfg.urlHint || 'Paste a URL';
			specimenGlyph.innerHTML = '';
			var icon = document.querySelector( '.type-btn[data-type="' + currentType + '"] .type-btn__icon svg' );
			if ( icon ) {
				var big = icon.cloneNode( true );
				big.setAttribute( 'width', '96' );
				big.setAttribute( 'height', '96' );
				specimenGlyph.appendChild( big );
			}
		}
	}

	// ── Syndicators ───────────────────────────────────────────────────────────
	// Targets are inlined server-side (NOP.syndicateTo) — no fetch needed.

	// Re-rendered whenever the kind changes: photo-only targets (Pixelfed) only
	// appear on photo posts. Preserves the visitor's tick state across switches.
	function renderSyndicators() {
		var box  = document.getElementById( 'syndicators' );
		var prev = {};
		box.querySelectorAll( 'input[type=checkbox]' ).forEach( function (cb) { prev[ cb.value ] = cb.checked; } );

		var synTo = ( NOP.syndicateTo || [] ).filter( function (s) {
			return ! s.photoOnly || currentType === 'photo';
		} );
		if ( ! synTo.length ) {
			box.innerHTML = '';
			document.getElementById( 'syndicateDetails' ).hidden = true;
			return;
		}
		box.innerHTML = synTo.map( function (s) {
			var limit   = CHAR_LIMITS[ s.uid ];
			var checked = ( s.uid in prev ) ? prev[ s.uid ] : true;
			return '<label class="syndicator-item">'
				+ '<input type="checkbox" class="sr-only" value="' + escAttr( s.uid ) + '"' + ( checked ? ' checked' : '' ) + '>'
				+ '<span class="syndicator-box" aria-hidden="true"><svg width="12" height="12" viewBox="0 0 256 256" fill="currentColor"><path d="M229.66,77.66l-128,128a8,8,0,0,1-11.32,0l-56-56a8,8,0,0,1,11.32-11.32L96,188.69,218.34,66.34a8,8,0,0,1,11.32,11.32Z"/></svg></span>'
				+ ' ' + escHtml( s.name )
				+ ( limit ? '<span class="syndicator-item__limit">' + limit + '</span>' : '' )
				+ '</label>';
		} ).join( '' );
		document.getElementById( 'syndicateDetails' ).hidden = false;
		updateCounter();
	}
	renderSyndicators();

	// ── Tags ─────────────────────────────────────────────────────────────────

	var tagInput  = document.getElementById( 'tagInput' );
	var tagsField = document.getElementById( 'tagsField' );

	tagsField.addEventListener( 'click', function () { tagInput.focus(); } );

	tagInput.addEventListener( 'keydown', function (e) {
		if ( e.key === 'Enter' || e.key === ',' ) {
			e.preventDefault();
			addTag( tagInput.value );
		} else if ( e.key === 'Backspace' && tagInput.value === '' && currentTags.length ) {
			currentTags.pop();
			renderTags();
			saveDraft();
		}
	} );

	tagInput.addEventListener( 'blur', function () {
		addTag( tagInput.value );
	} );

	function addTag( raw ) {
		var tag = raw.trim().replace( /^,+|,+$/g, '' ).trim();
		if ( tag && ! currentTags.includes( tag ) ) {
			currentTags.push( tag );
			renderTags();
			saveDraft();
		}
		tagInput.value = '';
	}

	function renderTags() {
		document.getElementById( 'tagChips' ).innerHTML = currentTags.map( function (tag, i) {
			return '<span class="tag-chip">'
				+ escHtml( tag )
				+ '<button class="tag-chip__remove" type="button" data-index="' + i + '" aria-label="Remove ' + escAttr( tag ) + '">×</button>'
				+ '</span>';
		} ).join( '' );
	}

	tagsField.addEventListener( 'click', function (e) {
		var btn = e.target.closest( '.tag-chip__remove' );
		if ( btn ) {
			currentTags.splice( parseInt( btn.dataset.index, 10 ), 1 );
			renderTags();
			saveDraft();
		}
	} );

	// ── Type switching ────────────────────────────────────────────────────────

	document.getElementById( 'typeBar' ).addEventListener( 'click', function (e) {
		var btn = e.target.closest( '.type-btn' );
		if ( ! btn ) { return; }
		// A short tick on an actual kind change — best-effort (iOS Safari ignores it),
		// the same nicety the Post button already gives on send.
		if ( btn.dataset.type !== currentType && navigator.vibrate ) { navigator.vibrate( 8 ); }
		switchType( btn.dataset.type );
	} );

	document.getElementById( 'rsvpToggle' ).addEventListener( 'click', function (e) {
		var btn = e.target.closest( '.rsvp-btn' );
		if ( ! btn || btn.dataset.rsvp === currentRsvp ) { return; }
		currentRsvp = btn.dataset.rsvp;
		if ( navigator.vibrate ) { navigator.vibrate( 8 ); }
		document.querySelectorAll( '.rsvp-btn' ).forEach( function (b) {
			var on = b.dataset.rsvp === currentRsvp;
			b.classList.toggle( 'is-active', on );
			b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		} );
		saveDraft();
	} );

	function switchType( type ) {
		if ( ! TYPE_CONFIG[ type ] ) return;
		currentType = type;
		var cfg = TYPE_CONFIG[ type ];

		var prevInk = inkNow ? getComputedStyle( inkNow ).color : '';
		app.dataset.type = type;
		animateInk( prevInk, INK[ type ] );

		document.querySelectorAll( '.type-btn' ).forEach( function (b) {
			var active = b.dataset.type === type;
			b.classList.toggle( 'is-active', active );
			b.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
		} );

		fieldUrl.hidden     = ! cfg.urlProp;
		fieldRsvp.hidden    = ! cfg.hasRsvp;
		fieldPhoto.hidden   = type !== 'photo';
		fieldContent.hidden = ! cfg.hasContent;
		fieldTags.hidden    = ! cfg.hasTags;

		if ( cfg.urlProp ) urlLabel.textContent = cfg.urlLabel || 'URL';
		if ( cfg.hasContent ) {
			setPrompt( ( type === 'note' ) ? notePlaceholder() : ( cfg.contentPlaceholder || 'Write…' ) );
		}

		updateSpecimen();
		renderSyndicators();
		updateCounter();
		saveDraft();
		updatePostBtn();
		autoGrowContent();
		setTimeout( nudgeStatusBar, 450 );
	}

	// ── Share-to-app ──────────────────────────────────────────────────────────
	// Prefill from URL params, so a share (Android share_target → ?title/text/url)
	// or an iOS Shortcut (?reply=/?bookmark=/?text= …) opens the right kind, filled.
	function applyShareParams() {
		var qs = new URLSearchParams( location.search );
		var explicit = [ 'reply', 'like', 'repost', 'bookmark', 'rsvp' ];
		var kind = '', url = '', text = '', i, v;
		for ( i = 0; i < explicit.length; i++ ) {
			v = qs.get( explicit[ i ] );
			if ( v ) { kind = explicit[ i ]; url = v; break; }
		}
		if ( kind ) {
			text = qs.get( 'text' ) || qs.get( 'note' ) || '';
		} else {
			var u = qs.get( 'url' ) || '';
			text = qs.get( 'text' ) || qs.get( 'note' ) || qs.get( 'title' ) || '';
			if ( u )        { kind = 'bookmark'; url = u; }   // a shared link → bookmark it
			else if ( text ) { kind = 'note'; }                // shared text → a note
		}
		if ( ! kind ) { return false; }

		clearDraft();
		restoring = true;
		switchType( kind );
		var cfg = TYPE_CONFIG[ kind ];
		urlInput.value     = cfg.urlProp   ? url  : '';
		contentInput.value = cfg.hasContent ? text : '';
		restoring = false;

		updateSpecimen(); updatePostBtn(); updateCounter(); autoGrowContent(); syncPrompt();
		saveDraft();
		// Strip the params so a reload/relaunch doesn't re-prefill the same share.
		if ( window.history && history.replaceState ) { history.replaceState( {}, '', location.pathname ); }
		return true;
	}

	// ── Post button state ─────────────────────────────────────────────────────

	function updatePostBtn() {
		var cfg     = TYPE_CONFIG[ currentType ];
		var enabled = false;
		if ( currentType === 'photo' ) {
			enabled = selectedFiles.length > 0;
		} else if ( cfg.urlProp ) {
			enabled = urlInput.value.trim().length > 0;
		} else {
			enabled = contentInput.value.trim().length > 0;
		}
		postBtn.disabled = ! enabled;
	}

	urlInput.addEventListener( 'input', function () { updateSpecimen(); updatePostBtn(); saveDraftSoon(); } );
	contentInput.addEventListener( 'input', function () { updatePostBtn(); updateCounter(); saveDraftSoon(); syncPrompt(); autoGrowContent(); } );
	document.getElementById( 'syndicators' ).addEventListener( 'change', updateCounter );

	// ── Photo picker ──────────────────────────────────────────────────────────

	picker.addEventListener( 'click', function () { photoInput.click(); } );
	picker.addEventListener( 'dragover', function (e) { e.preventDefault(); picker.classList.add( 'drag-over' ); } );
	picker.addEventListener( 'dragleave', function () { picker.classList.remove( 'drag-over' ); } );
	picker.addEventListener( 'drop', function (e) {
		e.preventDefault();
		picker.classList.remove( 'drag-over' );
		handleFiles( Array.from( e.dataTransfer.files ).filter( function (f) { return f.type.startsWith( 'image/' ); } ) );
	} );
	photoInput.addEventListener( 'change', function () { handleFiles( Array.from( photoInput.files ) ); } );

	function handleFiles( files ) {
		selectedFiles     = files.slice( 0, 10 );
		photoAlts         = [];
		thumbs.innerHTML  = '';
		altTexts.innerHTML = '';
		selectedFiles.forEach( function (file, i) {
			var cell       = document.createElement( 'figure' );
			cell.className = 'thumb';
			var img = document.createElement( 'img' );
			img.src = URL.createObjectURL( file );
			img.alt = '';
			cell.appendChild( img );
			thumbs.appendChild( cell );

			var row   = document.createElement( 'div' );
			row.className = 'alt-text-row';
			var lbl   = document.createElement( 'span' );
			lbl.className = 'alt-text-label';
			lbl.textContent = selectedFiles.length > 1
				? 'Photo ' + ( i + 1 ) + ' — alt text'
				: 'Alt text';
			var alt           = document.createElement( 'input' );
			alt.type          = 'text';
			alt.className     = 'thumb__alt';
			alt.placeholder   = 'Describe the photo…';
			alt.autocomplete  = 'off';
			alt.dataset.index = i;
			alt.setAttribute( 'aria-label', 'Alt text for photo ' + ( i + 1 ) );
			row.appendChild( lbl );
			row.appendChild( alt );
			altTexts.appendChild( row );
		} );
		picker.querySelector( 'p' ).textContent = selectedFiles.length
			? selectedFiles.length + ' photo' + ( selectedFiles.length > 1 ? 's' : '' ) + ' selected'
			: 'Add photos';
		updatePostBtn();
	}

	altTexts.addEventListener( 'input', function (e) {
		if ( e.target.classList.contains( 'thumb__alt' ) ) {
			photoAlts[ parseInt( e.target.dataset.index, 10 ) ] = e.target.value;
		}
	} );

	// ── Post ──────────────────────────────────────────────────────────────────

	// The write nonce — refreshed from the server before a queue replay (the page
	// nonce can expire before connectivity returns). Live posts use it as-is.
	var nonce = NOP.nonce;

	postBtn.addEventListener( 'click', async function () {
		var post = formToPost();

		// Offline up front → straight to the queue (no point attempting the network).
		if ( ! navigator.onLine && window.indexedDB ) { await queueAndAck( post ); return; }

		try {
			showView( 'progress' );
			var result = await sendPost( post, setProgress );
			recordKindUse( post.type );   // float this kind to the front next time
			setProgress( 'Syndicating…', 0.97 );
			await delay( 600 );
			if ( post.type === 'photo' && post.content ) {
				await navigator.clipboard.writeText( post.content ).catch( function () {} );
			}
			showSuccess( result.permalink, result.editUrl, result.photoUrls );
		} catch ( err ) {
			// A dropped connection mid-send → queue it rather than lose the post.
			if ( window.indexedDB && ( ! navigator.onLine || err instanceof TypeError ) ) {
				await queueAndAck( post );
			} else {
				showView( 'compose' );
				showToast( 'Something went wrong: ' + err.message, 'error' );
			}
		}
	} );

	// Snapshot the form into a plain, storable post — photo Files ride along as
	// blobs (structured-clone keeps them through IndexedDB).
	function formToPost() {
		var files = [];
		if ( currentType === 'photo' ) {
			for ( var i = 0; i < selectedFiles.length; i++ ) {
				files.push( { blob: selectedFiles[ i ], name: selectedFiles[ i ].name || 'photo.jpg', type: selectedFiles[ i ].type || 'image/jpeg', alt: ( photoAlts[ i ] || '' ) } );
			}
		}
		return {
			id:          'p' + Date.now() + '-' + Math.random().toString( 36 ).slice( 2, 7 ),
			type:        currentType,
			content:     contentInput.value.trim(),
			url:         urlInput.value.trim(),
			rsvp:        currentRsvp,
			tags:        currentTags.slice(),
			syndicateTo: Array.prototype.map.call( document.querySelectorAll( '#syndicators input[type="checkbox"]:checked' ), function ( cb ) { return cb.value; } ),
			files:       files,
		};
	}

	// The single place a post is actually sent — shared by the live path and the
	// queue replay. Uploads photos, then creates the post via Micropub.
	async function sendPost( post, onProgress ) {
		var photoUrls = [];
		if ( post.type === 'photo' && post.files.length ) {
			for ( var i = 0; i < post.files.length; i++ ) {
				if ( onProgress ) { onProgress( 'Uploading ' + ( i + 1 ) + ' of ' + post.files.length + '…', ( i / post.files.length ) * 0.75 ); }
				var up = await uploadPhoto( post.files[ i ].blob, post.files[ i ].name, post.files[ i ].type );
				photoUrls.push( up.source_url );
			}
		}
		if ( onProgress ) { onProgress( 'Posting…', 0.88 ); }
		var response = await fetch( NOP.micropubUrl, {
			method:  'POST',
			headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
			body:    JSON.stringify( buildPayload( post, photoUrls ) ),
		} );
		if ( response.status !== 201 ) {
			var errBody = await response.json().catch( function () { return {}; } );
			throw new Error( errBody.message || 'Posting failed (' + response.status + ')' );
		}
		return {
			permalink: response.headers.get( 'Location' ) || '',
			editUrl:   response.headers.get( 'X-Edit-URL' ) || '',
			photoUrls: photoUrls,
		};
	}

	function buildPayload( post, photoUrls ) {
		var cfg   = TYPE_CONFIG[ post.type ];
		var props = {};

		props[ 'post-kind' ] = [ post.type ];

		if ( post.content && cfg.hasContent ) { props.content = [ post.content ]; }

		// RSVP rides on in-reply-to (the event URL); the rsvp property is what makes
		// the server resolve it as an RSVP rather than a plain reply.
		if ( cfg.urlProp && post.url ) { props[ cfg.urlProp ] = [ post.url ]; }
		if ( cfg.hasRsvp ) { props.rsvp = [ post.rsvp ]; }

		// Alt text rides along as the server's array photo shape ({primary, alt});
		// sideload_photos copies it onto the attachment, where both the rendered
		// post and the Mastodon/Bluesky syndicators read it.
		if ( photoUrls && photoUrls.length ) {
			props.photo = photoUrls.map( function ( url, i ) {
				var alt = ( ( post.files[ i ] && post.files[ i ].alt ) || '' ).trim();
				return alt ? { primary: url, alt: alt } : url;
			} );
		}
		if ( cfg.hasTags && post.tags.length ) { props.category = post.tags.slice(); }

		// Always sent, even when empty — an explicitly empty selection means
		// "this site only"; omitting the property would fall back to the
		// server's default of syndicating to every enabled platform.
		props[ 'syndicate-to' ] = post.syndicateTo.slice();

		return { type: [ 'h-entry' ], properties: props };
	}

	async function uploadPhoto( blob, name, type ) {
		var res = await fetch( NOP.mediaUrl, {
			method:  'POST',
			headers: {
				'X-WP-Nonce':          nonce,
				'Content-Disposition': 'attachment; filename="' + ( name || 'photo.jpg' ) + '"',
				'Content-Type':        type || 'image/jpeg',
			},
			body: blob,
		} );
		if ( ! res.ok ) {
			var err = await res.json().catch( function () { return {}; } );
			throw new Error( err.message || 'Upload failed (' + res.status + ')' );
		}
		return res.json();
	}

	// ── Offline queue (IndexedDB) + replay ───────────────────────────────────────
	// When offline, a post is stored whole (incl. photo blobs) and replayed when the
	// app is next open and back online. iOS Safari has no Background Sync, so replay
	// is page-driven: on the `online` event and on launch.
	var DB_NAME = 'nop_post_queue', STORE = 'posts', replaying = false;

	function qOpen() {
		return new Promise( function ( resolve, reject ) {
			var req = indexedDB.open( DB_NAME, 1 );
			req.onupgradeneeded = function () { req.result.createObjectStore( STORE, { keyPath: 'id' } ); };
			req.onsuccess = function () { resolve( req.result ); };
			req.onerror   = function () { reject( req.error ); };
		} );
	}
	function qAdd( post ) {
		return qOpen().then( function ( db ) { return new Promise( function ( res, rej ) {
			var tx = db.transaction( STORE, 'readwrite' ); tx.objectStore( STORE ).put( post );
			tx.oncomplete = function () { res(); }; tx.onerror = function () { rej( tx.error ); };
		} ); } );
	}
	function qAll() {
		return qOpen().then( function ( db ) { return new Promise( function ( res, rej ) {
			var out = [], cur = db.transaction( STORE, 'readonly' ).objectStore( STORE ).openCursor();
			cur.onsuccess = function ( e ) { var c = e.target.result; if ( c ) { out.push( c.value ); c.continue(); } else { res( out ); } };
			cur.onerror = function () { rej( cur.error ); };
		} ); } );
	}
	function qDelete( id ) {
		return qOpen().then( function ( db ) { return new Promise( function ( res ) {
			var tx = db.transaction( STORE, 'readwrite' ); tx.objectStore( STORE ).delete( id );
			tx.oncomplete = function () { res(); }; tx.onerror = function () { res(); };
		} ); } );
	}
	// Count without loading the stored blobs into memory.
	function qCount() {
		return qOpen().then( function ( db ) { return new Promise( function ( res ) {
			var r = db.transaction( STORE, 'readonly' ).objectStore( STORE ).count();
			r.onsuccess = function () { res( r.result ); }; r.onerror = function () { res( 0 ); };
		} ); } );
	}

	// Reflect the pending count in the ticker's "N to send" item. Rebuild only when
	// the item appears/disappears (0↔N); otherwise just swap the number in place.
	function setQueueCount( n ) {
		var toggled = ( n > 0 ) !== ( queueCount > 0 );
		queueCount = n;
		if ( toggled ) { renderTicker(); }
		else if ( n > 0 ) { setTk( 'tk-queue', n + ' to send' ); }
		// Mirror the count on the home-screen app icon (iOS 16.4+, Android, desktop).
		if ( 'setAppBadge' in navigator ) {
			if ( n > 0 ) { navigator.setAppBadge( n ).catch( function () {} ); }
			else if ( navigator.clearAppBadge ) { navigator.clearAppBadge().catch( function () {} ); }
		}
	}
	function refreshQueueCount() {
		if ( ! window.indexedDB ) { return Promise.resolve(); }
		return qCount().then( setQueueCount ).catch( function () {} );
	}

	function refreshNonce() {
		return fetch( NOP.nonceUrl, { credentials: 'same-origin' } )
			.then( function ( r ) { return r.ok ? r.json() : null; } )
			.then( function ( d ) { if ( d && d.nonce ) { nonce = d.nonce; } } )
			.catch( function () {} );
	}

	async function queueAndAck( post ) {
		try { await qAdd( post ); }
		catch ( e ) { showView( 'compose' ); showToast( "Couldn't save offline: " + e.message, 'error' ); return; }
		setQueueCount( queueCount + 1 );        // show "N to send" in the ticker
		recordKindUse( post.type );
		showToast( 'Saved — will post when you’re back online.', 'info' );
		resetForm();
	}

	async function replayQueue() {
		if ( replaying || ! window.indexedDB || ! navigator.onLine ) { return; }
		replaying = true;
		try {
			var items = await qAll();
			if ( ! items.length ) { return; }
			await refreshNonce();                       // the page nonce may have expired
			for ( var i = 0; i < items.length; i++ ) {
				try {
					var result = await sendPost( items[ i ], null );
					await qDelete( items[ i ].id );       // delete right after the 201 — minimise the double-post window
					queueCount   = Math.max( 0, queueCount - 1 );
					postsToday  += 1;
					lastPostTs   = Math.floor( Date.now() / 1000 );
					renderTicker();
					showToast( 'Queued post published.', 'info' );
				} catch ( e ) {
					if ( ! navigator.onLine || e instanceof TypeError ) { break; }  // still offline — keep the queue intact
					showToast( 'A queued post failed: ' + e.message, 'error' );       // server error — stop, leave it for inspection
					break;
				}
			}
		} catch ( e ) {} finally { replaying = false; refreshQueueCount(); }
	}

	// Ask the browser not to evict the queue under storage pressure (best-effort).
	if ( navigator.storage && navigator.storage.persist ) { navigator.storage.persist().catch( function () {} ); }

	window.addEventListener( 'online', replayQueue );
	replayQueue();           // flush anything stored from a previous, offline session
	refreshQueueCount();     // and surface the count if we're offline with posts waiting

	// ── Success ───────────────────────────────────────────────────────────────

	function showSuccess( permalink, editUrl, photoUrls ) {
		showView( 'success' );
		clearDraft();
		if ( navigator.vibrate ) navigator.vibrate( 10 );

		var streakEl = document.getElementById( 'successStreak' );
		var count    = bumpStreak();
		if ( count > 0 ) {
			streakEl.innerHTML = '<span class="success-streak__num">' + ordinal( count ) + '</span>'
				+ '<span class="success-streak__label">post today</span>';
			streakEl.hidden = false;
		} else {
			streakEl.hidden = true;
		}

		// Keep the ticker's cadence + last-posted live as you post through the session.
		postsToday  += 1;
		lastPostTs   = Math.floor( Date.now() / 1000 );
		renderTicker();

		document.getElementById( 'successPhotos' ).innerHTML = photoUrls.map( function (url) {
			return '<img src="' + escAttr( url ) + '" alt="">';
		} ).join( '' );

		var link = document.getElementById( 'successLink' );
		link.href = permalink; link.textContent = permalink;

		var editBtn = document.getElementById( 'editBtn' );
		editBtn.href = editUrl; editBtn.hidden = ! editUrl;

		var igBtn = document.getElementById( 'instagramBtn' );
		igBtn.hidden = ! ( currentType === 'photo' && selectedFiles.length );
		igBtn.onclick = async function () {
			if ( navigator.canShare && navigator.canShare( { files: selectedFiles } ) ) {
				try { await navigator.share( { files: selectedFiles } ); }
				catch ( e ) { if ( e.name !== 'AbortError' ) showToast( 'Share from your Photos app instead.', 'error' ); }
			} else {
				showToast( "Web sharing isn't supported on this browser.", 'info' );
			}
		};

		document.getElementById( 'anotherBtn' ).onclick = resetForm;
	}

	function resetForm() {
		selectedFiles = []; photoAlts = []; currentTags = [];
		contentInput.value = ''; urlInput.value = '';
		thumbs.innerHTML = ''; altTexts.innerHTML = ''; photoInput.value = '';
		picker.querySelector( 'p' ).textContent = 'Add photos';
		renderTags();
		clearDraft();
		notePrompt = NOTE_PROMPTS[ Math.floor( Math.random() * NOTE_PROMPTS.length ) ];
		if ( nextSerial ) { setTk( 'tk-id', TK_ID_PRE + ( ++nextSerial ) ); }
		switchType( 'note' );
		updateCounter();
		showView( 'compose' );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	function showView( name ) {
		document.getElementById( 'view-compose'  ).hidden = name !== 'compose';
		document.getElementById( 'view-progress' ).hidden = name !== 'progress';
		document.getElementById( 'view-success'  ).hidden = name !== 'success';
	}

	function setProgress( message, fraction ) {
		document.getElementById( 'progressStatus' ).textContent = message;
		var fill = document.getElementById( 'progressFill' );
		fill.style.width = Math.round( fraction * 100 ) + '%';
		fill.parentElement.setAttribute( 'aria-valuenow', Math.round( fraction * 100 ) );
	}

	function delay( ms ) { return new Promise( function (resolve) { setTimeout( resolve, ms ); } ); }

	function escHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	}
	function escAttr( str ) { return String( str ).replace( /"/g, '&quot;' ); }

	// ── Draft persistence ──────────────────────────────────────────────────────

	function saveDraft() {
		if ( restoring ) return;
		try {
			localStorage.setItem( DRAFT_KEY, JSON.stringify( {
				type:    currentType,
				content: contentInput.value,
				url:     urlInput.value,
				tags:    currentTags,
				rsvp:    currentRsvp,
			} ) );
		} catch ( e ) {}
	}
	// Keystroke path uses the debounced save (discrete actions still call saveDraft).
	var saveDraftSoon = debounce( saveDraft, 400 );

	function loadDraft() {
		var raw;
		try { raw = localStorage.getItem( DRAFT_KEY ); } catch ( e ) { return; }
		if ( ! raw ) return;
		var d;
		try { d = JSON.parse( raw ); } catch ( e ) { return; }
		if ( ! d ) return;
		restoring = true;
		if ( d.type && TYPE_CONFIG[ d.type ] ) switchType( d.type );
		if ( typeof d.content === 'string' ) contentInput.value = d.content;
		if ( typeof d.url === 'string' ) urlInput.value = d.url;
		if ( Array.isArray( d.tags ) ) { currentTags = d.tags.slice(); renderTags(); }
		if ( d.rsvp ) {
			currentRsvp = d.rsvp;
			document.querySelectorAll( '.rsvp-btn' ).forEach( function (b) {
				var on = b.dataset.rsvp === currentRsvp;
				b.classList.toggle( 'is-active', on );
				b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			} );
		}
		restoring = false;
		updateSpecimen();
		updateCounter();
		updatePostBtn();
		saveDraft();
	}

	function clearDraft() { try { localStorage.removeItem( DRAFT_KEY ); } catch ( e ) {} }

	// ── Character counter ──────────────────────────────────────────────────────

	function currentLimit() {
		var lim = 0;
		Array.prototype.forEach.call(
			document.querySelectorAll( '#syndicators input[type="checkbox"]:checked' ),
			function ( cb ) {
				var l = CHAR_LIMITS[ cb.value ];
				if ( l ) lim = lim ? Math.min( lim, l ) : l;
			}
		);
		return lim;
	}

	function updateCounter() {
		var el  = document.getElementById( 'charCount' );
		var len = contentInput.value.length;
		var lim = currentLimit();
		// Only surface the counter when a syndication limit applies and you're
		// within 50 of it (or over) — otherwise it's noise, so stay hidden.
		if ( ! TYPE_CONFIG[ currentType ].hasContent || ! lim || ( lim - len ) > 50 ) {
			el.hidden = true;
			return;
		}
		el.hidden = false;
		el.textContent = String( lim - len );
		el.classList.toggle( 'is-over', len > lim );
	}

	// ── Streak ─────────────────────────────────────────────────────────────────

	function bumpStreak() {
		var key = 'nop_post_count_' + new Date().toISOString().slice( 0, 10 );
		try {
			var n = parseInt( localStorage.getItem( key ) || '0', 10 ) + 1;
			localStorage.setItem( key, String( n ) );
			return n;
		} catch ( e ) { return 0; }
	}

	function ordinal( n ) {
		var s = [ 'th', 'st', 'nd', 'rd' ], v = n % 100;
		return n + ( s[ ( v - 20 ) % 10 ] || s[ v ] || s[ 0 ] );
	}

	// ── Toast ──────────────────────────────────────────────────────────────────

	var toastTimer;
	function showToast( message, kind ) {
		var el = document.getElementById( 'toast' );
		el.textContent = message;
		el.className   = 'toast' + ( kind === 'error' ? ' toast--error' : '' );
		el.hidden      = false;
		void el.offsetWidth;
		el.classList.add( 'is-visible' );
		clearTimeout( toastTimer );
		toastTimer = setTimeout( function () {
			el.classList.remove( 'is-visible' );
			setTimeout( function () { el.hidden = true; }, 250 );
		}, 3500 );
	}

	// ── Kind order — most-recently-used first (localStorage) ─────────────────────
	var KIND_MRU_KEY = 'nop_kind_mru';
	function readKindMru() {
		try { var a = JSON.parse( localStorage.getItem( KIND_MRU_KEY ) || '[]' ); return Array.isArray( a ) ? a : []; }
		catch ( e ) { return []; }
	}
	function recordKindUse( kind ) {
		try {
			var mru = readKindMru().filter( function ( k ) { return k !== kind; } );
			mru.unshift( kind );
			localStorage.setItem( KIND_MRU_KEY, JSON.stringify( mru ) );
		} catch ( e ) {}
	}
	function applyKindOrder() {
		var grid = document.getElementById( 'typeBar' );
		var mru  = readKindMru();
		if ( ! grid || ! mru.length ) { return; }
		Array.prototype.slice.call( grid.querySelectorAll( '.type-btn' ) ).sort( function ( a, b ) {
			var ia = mru.indexOf( a.dataset.type ); if ( ia < 0 ) { ia = 99; }
			var ib = mru.indexOf( b.dataset.type ); if ( ib < 0 ) { ib = 99; }
			return ia - ib;
		} ).forEach( function ( btn ) { grid.appendChild( btn ); } );
	}
	function mruDefaultKind() {
		var mru = readKindMru();
		return ( mru[0] && TYPE_CONFIG[ mru[0] ] ) ? mru[0] : 'note';
	}

	// ── Init ───────────────────────────────────────────────────────────────────

	buildInkMap();                            // resolve each kind's ink for OKLCH tweening
	if ( window.matchMedia ) {
		try { window.matchMedia( '(prefers-color-scheme: dark)' ).addEventListener( 'change', buildInkMap ); } catch ( e ) {}
	}
	applyKindOrder();                         // tiles in most-recently-used order
	app.classList.add( 'no-anim' );           // suppress the re-ink flash for the initial kind
	setPrompt( notePlaceholder() );
	var hadDraft = false;
	try { hadDraft = !! localStorage.getItem( DRAFT_KEY ); } catch ( e ) {}
	loadDraft();
	if ( ! hadDraft ) { switchType( mruDefaultKind() ); }   // no draft → open on the last-used kind
	applyShareParams();                                     // a share/Shortcut overrides the above
	updateCounter();
	syncPrompt();
	autoGrowContent();
	app.offsetHeight;                         // flush, then re-enable transitions
	app.classList.remove( 'no-anim' );
	alignHalftone();
	requestAnimationFrame( alignHalftone );   // re-align once layout (safe-area) settles
	if ( document.fonts && document.fonts.ready ) { document.fonts.ready.then( alignHalftone ); }  // and after the web font reflows the masthead
	updateTypeFades();
	requestAnimationFrame( updateTypeFades );  // after the MRU reorder + layout settles

} )();
</script>
</body>
</html>
		<?php
	}
}
