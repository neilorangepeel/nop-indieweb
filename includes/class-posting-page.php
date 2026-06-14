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
					<span class="type-btn__label"><?php esc_html_e('Note', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="photo" aria-pressed="false" type="button">
					<span class="type-btn__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 256 256" fill="currentColor"><path d="M208,56H180.28L166.65,35.56A8,8,0,0,0,160,32H96a8,8,0,0,0-6.65,3.56L75.72,56H48A24,24,0,0,0,24,80V192a24,24,0,0,0,24,24H208a24,24,0,0,0,24-24V80A24,24,0,0,0,208,56Zm8,136a8,8,0,0,1-8,8H48a8,8,0,0,1-8-8V80a8,8,0,0,1,8-8H80a8,8,0,0,0,6.65-3.56L100.28,48h55.44l13.63,20.44A8,8,0,0,0,176,72h32a8,8,0,0,1,8,8ZM128,88a44,44,0,1,0,44,44A44.05,44.05,0,0,0,128,88Zm0,72a28,28,0,1,1,28-28A28,28,0,0,1,128,160Z"/></svg></span>
					<span class="type-btn__label"><?php esc_html_e('Photo', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="reply" aria-pressed="false" type="button">
					<span class="type-btn__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 256 256" fill="currentColor"><path d="M224,128a96,96,0,0,1-94.71,96H128A95.38,95.38,0,0,1,62.1,197.8a8,8,0,0,1,11-11.63A80,80,0,1,0,71.43,71.39a3.07,3.07,0,0,1-.26.25L44.59,96H72a8,8,0,0,1,0,16H24a8,8,0,0,1-8-8V56a8,8,0,0,1,16,0V85.8L60.25,60A96,96,0,0,1,224,128Z"/></svg></span>
					<span class="type-btn__label"><?php esc_html_e('Reply', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="like" aria-pressed="false" type="button">
					<span class="type-btn__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 256 256" fill="currentColor"><path d="M178,40c-20.65,0-38.73,8.88-50,23.89C116.73,48.88,98.65,40,78,40a62.07,62.07,0,0,0-62,62c0,70,103.79,126.66,108.21,129a8,8,0,0,0,7.58,0C136.21,228.66,240,172,240,102A62.07,62.07,0,0,0,178,40ZM128,214.8C109.74,204.16,32,155.69,32,102A46.06,46.06,0,0,1,78,56c19.45,0,35.78,10.36,42.6,27a8,8,0,0,0,14.8,0c6.82-16.67,23.15-27,42.6-27a46.06,46.06,0,0,1,46,46C224,155.61,146.24,204.15,128,214.8Z"/></svg></span>
					<span class="type-btn__label"><?php esc_html_e('Like', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="bookmark" aria-pressed="false" type="button">
					<span class="type-btn__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 256 256" fill="currentColor"><path d="M184,32H72A16,16,0,0,0,56,48V224a8,8,0,0,0,12.24,6.78L128,193.43l59.77,37.35A8,8,0,0,0,200,224V48A16,16,0,0,0,184,32Zm0,177.57-51.77-32.35a8,8,0,0,0-8.48,0L72,209.57V48H184Z"/></svg></span>
					<span class="type-btn__label"><?php esc_html_e('Bookmark', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="repost" aria-pressed="false" type="button">
					<span class="type-btn__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 256 256" fill="currentColor"><path d="M224,48V96a8,8,0,0,1-8,8H168a8,8,0,0,1,0-16h28.69L182.06,73.37a79.56,79.56,0,0,0-56.13-23.43h-.45A79.52,79.52,0,0,0,69.59,72.71,8,8,0,0,1,58.41,61.27a96,96,0,0,1,135,.79L208,76.69V48a8,8,0,0,1,16,0ZM186.41,183.29a80,80,0,0,1-112.47-.66L59.31,168H88a8,8,0,0,0,0-16H40a8,8,0,0,0-8,8v48a8,8,0,0,0,16,0V179.31l14.63,14.63A95.43,95.43,0,0,0,130,222.06h.53a95.36,95.36,0,0,0,67.07-27.33,8,8,0,0,0-11.18-11.44Z"/></svg></span>
					<span class="type-btn__label"><?php esc_html_e('Repost', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="rsvp" aria-pressed="false" type="button">
					<span class="type-btn__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 256 256" fill="currentColor"><path d="M208,32H184V24a8,8,0,0,0-16,0v8H88V24a8,8,0,0,0-16,0v8H48A16,16,0,0,0,32,48V208a16,16,0,0,0,16,16H208a16,16,0,0,0,16-16V48A16,16,0,0,0,208,32ZM72,48v8a8,8,0,0,0,16,0V48h80v8a8,8,0,0,0,16,0V48h24V80H48V48ZM208,208H48V96H208V208Zm-29.66-85.66a8,8,0,0,1,0,11.32l-48,48a8,8,0,0,1-11.32,0l-24-24a8,8,0,0,1,11.32-11.32L124,164.69l42.34-42.35A8,8,0,0,1,178.34,122.34Z"/></svg></span>
					<span class="type-btn__label"><?php esc_html_e('RSVP', 'nop-indieweb' ); ?></span>
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
window.NOP = {
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
</script>
<script src="<?php echo esc_url( $post_js_url ); ?>" defer></script>
</body>
</html>
		<?php
	}
}
