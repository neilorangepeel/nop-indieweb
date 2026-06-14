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
		$icon_url     = get_site_icon_url( 192 );
		$font_dir     = get_theme_file_uri( 'assets/fonts/brandon-text' );
		$cond_dir     = get_theme_file_uri( 'assets/fonts/brandon-text-condensed' );

		$user      = wp_get_current_user();
		$user_name = $user->first_name ?: $user->display_name;

		// The serial shown in the masthead — the id the next post will most likely
		// take (the table's high-water mark + 1). A decorative stamp: real gaps
		// (deleted rows / concurrent inserts) mean it can differ from the assigned id.
		global $wpdb;
		$next_id = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ) + 1; // phpcs:ignore WordPress.DB.DirectDatabaseQuery

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
<?php if ( $icon_url ) : ?>
<link rel="apple-touch-icon" href="<?php echo esc_url( $icon_url ); ?>">
<?php endif; ?>
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
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

/* Text selection inverts (ink fill, paper text). The page itself never scrolls
   (html/body are overflow:hidden); only .compose-scroll does, and it sets its own
   scrollbar-color below. */
::selection      { background: var(--ink); color: var(--paper); }
::-moz-selection { background: var(--ink); color: var(--paper); }
[hidden] { display: none !important; }

/*
 * Bauhaus primaries. Three role tokens carry the light/dark flip:
 *   --field  page field      --line   rules / borders / hard shadows
 *   --text   body text
 * The three primaries stay vivid in both themes — they pop on paper and
 * punch even harder on ink (peak Rand / Bass). The active post type sets
 * --accent / --on-accent on .app[data-type], retinting the type tile, focus
 * rings, tag chips, progress and the Post button together.
 */
/* Register --ink as a real <color> so it has a valid type + an initial-value at
   the root — html { background: var(--ink) } (the status-bar accent) needs a value
   before JS runs and before any .app[data-type] rule applies. The per-kind sweep
   itself is driven in JS (animateInk), not a CSS transition. */
@property --ink {
	syntax: '<color>';
	inherits: true;
	initial-value: #D7331F;
}

:root {
	/* Dark: paper becomes near-black and the inks lift to stay legible — the
	   rest cascades, since every role token derives from --paper / --ink.
	   Six risograph inks, one per post kind. */
	color-scheme: light dark;
	--paper:    light-dark(#F4EFE6, #15140F);
	--red:      light-dark(#D7331F, #FF5A4D);
	--blue:     light-dark(#1E4FD6, #6E90FF);
	--teal:     light-dark(#00787F, #3CC1C9);
	--green:    light-dark(#20713A, #5BCB84);
	--violet:   light-dark(#6A4ACF, #A78FE8);
	--orange:   light-dark(#B4500A, #FF9A3C);
	--magenta:  light-dark(#C81E66, #FF6FA3);
	--charcoal: light-dark(#1A1A1A, #F4EFE6);

	/* Two-tone risograph: ONE ink per screen, printed on paper. The ink-derived
	   tokens live on .app (not here) so they re-resolve with the per-type --ink;
	   defining them on :root would bake in the default ink and never re-tint. */
	--field:     var(--paper);
	--on-accent: var(--field);

	--display: 'Brandon Text Condensed', 'Brandon Text', -apple-system, BlinkMacSystemFont, sans-serif;
	/* The data-readout face — date stamp + the current-moment grid (place/temp/sky).
	   Brandon Text, so the ambient data sits with the rest of the type. */
	--readout: 'Brandon Text', -apple-system, BlinkMacSystemFont, sans-serif;

	/* Corner radius via the shared --nop-radius system, so /post matches the
	   blocks. This page is a standalone document (no theme.json cascade), so it
	   carries the brand value here rather than inheriting it — keep in sync with
	   the theme's :root{--nop-radius} (currently 4px). --radius stays a local
	   alias so the existing rules below are untouched. */
	--nop-radius: 4px;
	--nop-radius-pill: 9999px;
	--radius: var( --nop-radius, 2px );

	/* The content sheet's rounded TOP corners, echoing the phone's physical screen
	   corners (CSS can't read the real device radius — this is a tuned approximation;
	   modern Face-ID iPhones are ~47–55px). The accent html shows through the cut
	   corners, so they re-tint per kind for free. Phones only — the desktop mock
	   carries its own 50px device frame. */
	--screen-radius: 30px;

	/* POST button sized to the iOS Safari address-bar pill (measured from-device:
	   ~52pt tall, ~50% of screen width, centred — it's a floating pill, not a
	   full-width bar). The button reads as the same class of control as Safari's
	   own toolbar pill. Both tunable from here. */
	--post-btn-h: 50px;
	--post-btn-w: min(54%, 320px);

	--safe-top:    env(safe-area-inset-top, 0px);
	--safe-bottom: env(safe-area-inset-bottom, 0px);

	/* ── Scale tokens — adjust the whole UI from here ──────────────────────── */
	/* Type scale — the recurring body sizes (poster/display one-offs like 24/32/40
	   stay literal). Value-named so the source reads true; change one to retune
	   every label/field/caption printed at that size. */
	--fs-10: 10px;  --fs-11: 11px;  --fs-12: 12px;  --fs-13: 13px;
	--fs-14: 14px;  --fs-16: 16px;  --fs-18: 18px;
	/* The page side gutter — the inset shared by the top-level sections (masthead,
	   greeting, compose, success). Sized to breathe inside the rounded top corners
	   (--screen-radius): a gutter smaller than the radius lets the corner arc crowd
	   the masthead, so it tracks a touch under the radius rather than the old flush
	   16px. The rest of the layout is bespoke poster geometry, kept literal so the
	   shorthands stay readable. */
	--pad-x: 20px;
	--bw: 2px;                       /* the single border weight used throughout */

	/* Grain / halftone — ONE shared, fixed dot grid (pitch). --grain-dot is the base
	   page speckle; --ht-dot is the SAME grid's dot swollen for "shadow". A shadow
	   isn't a second pattern — it's these dots, phase-locked (alignHalftone, x+y),
	   rendered bigger + darker (var(--ink)) so they appear to swell in place. The
	   gradient is written out per surface: a shared --grain-img token can't work
	   because its var(--grain-ink) would resolve at :root (undefined → empty). */
	--grain-pitch: 3px;
	--grain-dot:   0.8px;
	--ht-dot:      0.8px;   /* shadow dot — same size as --grain-dot, just full --ink:
	                          the identical grid dot, darkened in place by the reveal
	                          mask (no swell). Bump it for a swelled shadow if wanted. */
}

/* Per-type ink — selecting a tile re-inks the whole screen (two-tone).
   One ink per kind: heart = red, photo = blue, the default note screen =
   teal, bookmark files away in green, reply talks in warm orange, repost
   echoes in violet. */
.app[data-type="note"]     { --ink: var(--teal); }
.app[data-type="photo"]    { --ink: var(--blue); }
.app[data-type="reply"]    { --ink: var(--orange); }
.app[data-type="like"]     { --ink: var(--red); }
.app[data-type="bookmark"] { --ink: var(--green); }
.app[data-type="repost"]   { --ink: var(--violet); }
.app[data-type="rsvp"]     { --ink: var(--magenta); }

html {
	height: 100%;
	/* The ROOT carries the ACCENT, on purpose. In a Safari tab iOS 26 tints the
	   status bar from the BODY background-color and falls back to HTML when body
	   has none — so the transparent body below lets this accent become the bar
	   tint with NO sampling band (so it's iOS's default height, like Amazon/etc).
	   On-screen it's covered by the off-white app + ::before, so it only feeds the
	   bar. Deep accent in dark mode so the forced-white bar text stays legible. */
	background-color: var(--ink);
}
@media (prefers-color-scheme: dark) {
	html { background-color: color-mix( in srgb, var(--ink) 45%, #000 ); }
}
body {
	/* Per-context height (see the head script). Default = Safari TAB: 100dvh, the
	   visible viewport between the browser chrome, so nothing runs under the status
	   bar / toolbar. .standalone (Home Screen app) overrides to 100vh — the full
	   852 screen (dvh there is 59px short and mis-initialises on PWA launch). */
	height: 100dvh;
	overflow: hidden;
	/* NO background-color on phones — transparent so iOS falls back to the html
	   accent for the status bar (see html). The visible off-white page is the
	   fixed ::before backstop (16% grain) + the app on top. The desktop frame
	   media query restores the field on body for the floating-phone surround. */
	color: var(--charcoal);
	font-family: 'Brandon Text', -apple-system, BlinkMacSystemFont, sans-serif;
	-webkit-font-smoothing: antialiased;
}
/* Standalone (Home Screen app): full screen via 100vh; tab keeps 100dvh above. */
.standalone body, .standalone .app { height: 100vh; }
/* Standalone runs full-bleed UNDER the status bar (viewport-fit=cover), so the
   content's rounded top would hide up at y=0 beneath iOS's status-bar overlay.
   Drop the card (.app + the ::before backstop) below --safe-top so its top
   corners land just under the bar, with the accent html filling the bar zone
   above and showing through the corner cuts. The masthead then only needs its
   12px (it no longer clears the bar — the inset does). A Safari tab keeps the
   base rules (content already sits below the browser chrome). */
.standalone .app        { margin-top: var(--safe-top); height: calc(100vh - var(--safe-top)); }
.standalone body::before { top: var(--safe-top); }
.standalone .masthead   { padding-top: 12px; }
/* Grain that reaches the iOS safe areas. iOS paints the root/body background
   COLOUR into the home-indicator / overscroll zones but NOT the background-image,
   so a grain-less strip survives there however we size body/.app. A real fixed
   element whose box is the full layout viewport (inset:0 under viewport-fit=cover
   spans the safe areas) DOES paint its grain there. Sits above the body bg but
   below the app content (z-index 0 vs the app's 1). Poster's 16%, same top-origin
   grid → seamless. Desktop uses the body field + floating phone instead. */
body::before {
	content: "";
	position: fixed;
	inset: 0;
	z-index: 0;
	background-color: var(--field);
	background-image: radial-gradient(color-mix(in srgb, var(--ink) 16%, transparent) var(--grain-dot), transparent calc(var(--grain-dot) + 0.3px));
	background-size: var(--grain-pitch) var(--grain-pitch);
	/* Match the app's rounded top so the accent html shows in the cut corners. */
	border-top-left-radius: var(--screen-radius);
	border-top-right-radius: var(--screen-radius);
	pointer-events: none;
}
@media (min-width: 600px) and (min-height: 600px) {
	body::before { display: none; }
}

/* ── App shell ──────────────────────────────────────────────────────────── */

.app {
	/* Ink-derived tokens declared HERE so the per-type --ink (set on .app[data-type])
	   re-resolves them per screen. --ink defaults to red; each type overrides it. */
	--ink:      var(--red);
	--line:     var(--ink);
	--text:     var(--ink);
	--accent:   var(--ink);
	--surface:  color-mix(in srgb, var(--ink) 10%, var(--paper));
	--rule:     color-mix(in srgb, var(--ink) 36%, var(--paper));
	--grain:    color-mix(in srgb, var(--ink) 16%, transparent);
	/* Muted ink strokes/fills, named by their tone. Mixed toward PAPER (opaque),
	   NOT transparent — a transparent border lets the dot grid show through the
	   stroke and reads as visual noise; an opaque tone renders the stroke solid. */
	--ink-30:   color-mix(in srgb, var(--ink) 30%, var(--paper));  /* control borders */
	--ink-38:   color-mix(in srgb, var(--ink) 38%, var(--paper));  /* field borders, scrollbars */
	--ink-50:   color-mix(in srgb, var(--ink) 50%, var(--paper));
	--shadow:   4px 4px 0 var(--ink);
	/* The kind ink a shade deeper — tones the faux iOS chrome and the frame border
	   on the desktop mock / standalone band. */
	--device-ink: color-mix(in srgb, var(--ink) 80%, #000);
	/* The status-bar accent, light/dark-aware (full ink in light; a deep accent in
	   dark so forced-white bar text stays legible) — the same value html and the
	   band use. The desktop mock reveals it in the content card's rounded top cuts. */
	--bar-accent: light-dark( var(--ink), color-mix(in srgb, var(--ink) 45%, #000) );

	/* The kind-switch crossfade is driven in JS through OKLCH (a hue rotation,
	   like a colour-picker), not a CSS transition — see animateInk(). Every
	   element just reads var(--ink), so they all re-ink together in one sweep. */

	display: flex;
	flex-direction: column;
	height: 100dvh;            /* tab: visible viewport; .standalone overrides to 100vh below */
	overflow: hidden;
	position: relative;
	z-index: 1;                 /* above the fixed grain backstop (body::before) */
	margin: 0 auto;
	color: var(--text);
	/* Same off-white as the html/body field — NO tint fill. The poster's depth
	   comes only from its dots being a touch darker than the field's (see --grain
	   vs the body grain), so the background colour never changes, just the dots. */
	background-color: var(--field);
	/* Faint halftone grain — the organic "printed on paper" texture. */
	background-image: radial-gradient(var(--grain) var(--grain-dot), transparent calc(var(--grain-dot) + 0.3px));
	background-size: var(--grain-pitch) var(--grain-pitch);
	/* Rounded top corners that echo the phone's screen; overflow:hidden above keeps
	   the content clipped to them. The accent html behind shows in the cut. The
	   desktop frame (border-radius:50px) overrides this on ≥600px. */
	border-top-left-radius: var(--screen-radius);
	border-top-right-radius: var(--screen-radius);
}
/* While JS sets the initial kind on load, suppress all transitions/animations so
   it doesn't re-ink or pop into view. Removed on the next frame. */
.app.no-anim, .app.no-anim * { transition: none !important; animation: none !important; }

/* Progressive enhancement: where corner-shape is supported (Chromium today; Safari
   once WebKit ships it — likely, given Apple's own squircle icons), upgrade the
   rounded corners from circular arcs to Apple-style squircles (a superellipse), so
   they match the device curve more truthfully. corner-shape only affects corners
   that already have a radius, and is ignored where unsupported (falls back to the
   border-radius above), so it's harmless on iOS Safari now — purely a future upgrade
   that activates itself the day Safari adds it. */
@supports (corner-shape: squircle) {
	.app, body::before, .app::before { corner-shape: squircle; }
}

/* On phones the paper runs full-bleed — no side frame. Edge borders sit in
   the zone the rounded display corners and sub-pixel rounding clip, so they
   render ragged; the horizontal rules carry the structure instead. The full
   2px ink frame only appears when there's genuinely room to float the poster:
   wide AND tall, so landscape phones stay full-bleed too. */
@media (min-width: 600px) and (min-height: 600px) {
	body {
		display: flex;
		align-items: center;
		justify-content: center;
		/* The document's own dot field the floating phone sits on — post-hued (var(--ink)
		   via :root) and part of the ONE master grid: body has no margin so its grain
		   sits at the viewport origin (0,0), and every other dot layer (phone interior,
		   shadows) is JS-anchored to that same origin (alignHalftone), so the surround
		   dots register exactly with the phone's. Lighter than the 16% poster so the
		   phone still reads as denser. Restored here because body is transparent on
		   phones (status-bar trick). */
		background-color: var(--field);
		background-image: radial-gradient(color-mix(in srgb, var(--ink) 13%, transparent) var(--grain-dot), transparent calc(var(--grain-dot) + 0.3px));
		background-size: var(--grain-pitch) var(--grain-pitch);
	}
	.app {
		/* A fixed, phone-sized poster floating in the browser — iPhone point
		   dimensions (390x844, 19.5:9) with the display corner radius. The faux
		   safe-area insets reserve the real iOS status-bar / home-indicator zones
		   so we design with the device chrome in mind. --device-ink (the kind ink
		   a shade deeper) tones the faux chrome AND this outer border, so the
		   device dressing reads as one hue. */
		width: 390px;
		height: 844px;
		--safe-top: 59px;
		--safe-bottom: 34px;
		border: var(--bw) solid var(--device-ink);
		border-radius: 50px;
		/* Accent base: the field+grain becomes the ::before card below; the only
		   place this shows is the band zone (under the band) and the card's rounded
		   top-corner cuts — exactly like the html accent does in standalone. */
		background: var(--bar-accent);
	}
	/* The content card: field+grain dropped below the faux status bar with rounded
	   top corners, so the accent base shows through the cuts (z-index:-1 keeps it
	   behind the content but above the accent base). Mirrors the standalone look. */
	.app::before {
		content: "";
		position: absolute;
		inset: var(--safe-top) 0 0 0;
		z-index: -1;
		background-color: var(--field);
		background-image: radial-gradient(var(--grain) var(--grain-dot), transparent calc(var(--grain-dot) + 0.3px));
		background-size: var(--grain-pitch) var(--grain-pitch);
		/* Anchored to the viewport master grid (set in alignHalftone) so the card's
		   dots register exactly with the surround dots and the shadows. */
		background-position: var(--cardpos, 0 0);
		border-top-left-radius: var(--screen-radius);
		border-top-right-radius: var(--screen-radius);
	}
	/* Desktop mock: show the band, full height, anchored inside the floating phone. */
	.app .device-chrome { display: flex; top: 0; height: var(--safe-top); position: absolute; }
	.app .device-chrome > * { visibility: visible; }
}

/* ── iOS chrome band ───────────────────────────────────────────────────────
   The accent band iOS Safari samples to tint the status bar (it reads the
   background-color of a fixed element near the top edge; theme-color is ignored
   in iOS 26, so this element is the ONLY way to colour the bar). Its HEIGHT
   differs by context:
   • Standalone app (.standalone) — full safe-top: viewport-fit=cover runs the app
     under the status bar, so the band fills that zone behind the real clock.
   • Desktop mock — full safe-top: the faux time / island / icons.
   • Safari TAB (default) — content sits BELOW the status bar (no cover), so a
     full band would be an on-page strip — and unnecessary, since the html accent
     now tints the bar there. So it's HIDDEN in the tab (display:none) and shown
     only in the standalone app + desktop mock. */
.device-chrome {
	display: none;
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	height: var(--safe-top, 54px);
	padding: 16px 34px 0;
	align-items: center;
	justify-content: space-between;
	/* Light mode: the full accent (it's dark enough that iOS keeps it under the
	   forced-white status-bar text). Dark mode override below. */
	background: var(--ink);
	color: var(--on-accent);
	font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Text', system-ui, sans-serif;
	pointer-events: none;
	z-index: 5;
}
/* Dark mode: the dark-scheme accents are too BRIGHT for the status bar. iOS
   black-translucent forces WHITE bar text, so it only honours a background dark
   enough for white to read — a bright accent gets rejected and the bar falls
   back to the near-black body (only red was dark enough to survive). So in dark
   mode use a DEEP accent (≈45% ink on black): dark enough that iOS keeps it,
   still clearly the kind's hue, with light text. */
@media (prefers-color-scheme: dark) {
	.device-chrome {
		background: color-mix( in srgb, var(--ink) 45%, #000 );
		color: #f4f0e7;
	}
}
/* Standalone Home Screen app: show the band, full height behind the status bar. */
/* AUDIT TEST (2026-06-14): band hidden in standalone to see if the html accent
   alone tints the bar. Revert this line to the commented one if the bar goes
   wrong on the Home Screen app. */
.standalone .device-chrome { display: none; }
/* .standalone .device-chrome { display: flex; top: 0; height: var(--safe-top, 54px); } */
/* Faux status-bar content is the desktop mock's stand-in for the real iOS
   clock/battery — hidden on real devices (revealed in the frame media query),
   where iOS renders the genuine chrome over the band. */
.device-chrome > * { visibility: hidden; }
.device-chrome__time {
	font-size: var(--fs-16);
	font-weight: 600;
	letter-spacing: 0.01em;
	font-variant-numeric: tabular-nums;
}
.device-chrome__island {
	position: absolute;
	top: 11px;
	left: 50%;
	transform: translateX(-50%);
	width: 122px;
	height: 36px;
	border-radius: 18px;
	background: color-mix(in srgb, var(--device-ink) 45%, #000);
}
.device-chrome__icons { display: flex; align-items: center; gap: 7px; }
.device-chrome__icons svg { display: block; }
.device-chrome__battery {
	position: relative;
	width: 25px;
	height: 12px;
	border: 1px solid color-mix(in srgb, currentColor 38%, transparent);
	border-radius: 3.5px;
	padding: 1.5px;
}
.device-chrome__battery::after {
	content: "";
	position: absolute;
	right: -3px;
	top: 50%;
	transform: translateY(-50%);
	width: 1.8px;
	height: 4px;
	border-radius: 0 1px 1px 0;
	background: color-mix(in srgb, currentColor 38%, transparent);
}
.device-chrome__battery i {
	display: block;
	height: 100%;
	width: 72%;
	background: currentColor;
	border-radius: 1.5px;
}

/* ── Masthead ───────────────────────────────────────────────────────────── */

.masthead {
	flex-shrink: 0;
	/* Bottom padding + a crisp full-bleed rule — the fixed header's clean bottom edge,
	   balancing the top inset so the logo/ticker bar sits evenly framed. */
	padding: 0 var(--pad-x) 16px;
	/* Clear the status bar, then drop the bar clear of the rounded top corners so the
	   curve frames it rather than pinching it. The app/desktop add the safe-top zone
	   (59px) on top; in a Safari tab safe-top is 0, so this is a clean 16px gap below
	   the default-height status bar — same visible clearance as the app. */
	padding-top: calc(var(--safe-top) + 16px);
	border-bottom: var(--bw) solid var(--line);
}
.masthead__bar {
	position: relative;
	display: flex;
	align-items: center;
	height: 46px;
}
/* The logo framed as an app icon — an ink tile with the glyph knocked out in
   paper. The tile carries the ink (and re-inks per kind), so the source SVG's
   path flips to paper to read against it. Sits above the ticker, which crawls
   its metadata out of sight beneath it. */
.brand__mark {
	position: relative;
	z-index: 2;
	display: grid;
	place-items: center;
	flex-shrink: 0;
	width: 42px;
	height: 42px;
	border-radius: 10px;
	background: var(--ink);
}
.brand__mark svg,
.brand__mark img { display: block; width: 26px; height: 26px; }
.brand__mark svg path { fill: var(--paper); }

/* ── Metadata ticker ─────────────────────────────────────────────────────────
   The current moment (serial · date · place · temp · sky) on one crawling line
   beside the logo, so the masthead is barely taller than the icon. The track
   holds two identical sequences and animates -50% for a seamless loop; the left
   edge is masked away so each item dissolves into the logo. JS (renderTicker)
   fills the track from the clock + /now data and sets the duration for a
   constant crawl speed; the time item updates in place on the minute tick. */
.ticker {
	position: absolute;
	inset: 0;
	overflow: hidden;
	/* Fade the run into the logo on the left (the transparent lead clears the
	   42px tile + its gap), soft-cut at the right gutter. */
	-webkit-mask-image: linear-gradient(to right, transparent 48px, #000 70px, #000 calc(100% - 20px), transparent);
	        mask-image: linear-gradient(to right, transparent 48px, #000 70px, #000 calc(100% - 20px), transparent);
}
.ticker__track {
	position: absolute;
	top: 0;
	left: 0;
	height: 100%;
	display: flex;
	align-items: center;
	white-space: nowrap;
	will-change: transform;
	animation: ticker-crawl 24s linear infinite;
}
.ticker__seq { display: flex; align-items: center; }
.ticker__item {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	font-family: var(--readout);
	font-size: var(--fs-14);
	font-weight: 500;
	letter-spacing: 0;
	font-variant-numeric: tabular-nums;
}
.ticker__sep { padding: 0 6px; opacity: 0.35; }
.ticker__icon { display: inline-flex; }
.ticker__icon svg { display: block; width: 14px; height: 14px; }
@keyframes ticker-crawl {
	from { transform: translateX(0); }
	to   { transform: translateX(-50%); }
}
@media (prefers-reduced-motion: reduce) {
	/* No crawl — freeze the run just clear of the logo so the lead items read. */
	.ticker__track { animation: none; left: 56px; }
}



/* ── Views ──────────────────────────────────────────────────────────────── */

.view-container {
	flex: 1;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}
#view-compose,
#view-progress,
#view-success {
	flex: 1;
	display: flex;
	flex-direction: column;
	overflow: hidden;
	/* Anchors the floating .bottom-bar (absolute) to the view's bottom edge. */
	position: relative;
}

/* ── Type selector ──────────────────────────────────────────────────────── */

.type-grid {
	display: flex;
	gap: 6px;
	flex-shrink: 0;
	padding: 18px var(--pad-x);
	/* One row of square tiles that scrolls sideways if they don't all fit —
	   saves the vertical height the old two-row grid took. */
	overflow-x: auto;
	overscroll-behavior-x: contain;
	-webkit-overflow-scrolling: touch;
	scrollbar-width: none;
	/* Tiles settle to the gutter edge when the row is flicked — a native, camera-dial
	   feel — without fighting a mid-scroll stop (proximity, not mandatory). */
	scroll-snap-type: x proximity;
	scroll-padding-left: var(--pad-x);
	/* Only a bottom rule frames the kind selector now — the masthead's own bottom
	   rule is the strip's top edge, so a top rule here would double it. */
	border-bottom: var(--bw) solid var(--line);
}
.type-grid::-webkit-scrollbar { display: none; }

/* Horizontal twin of the vertical scroll-fades — the same ink-dot halftone screen
   + hockey-stick mask, turned 90° onto the kind row's left/right edges, so off-
   screen kinds (Repost, RSVP) announce themselves. JS ramps each one's opacity by
   how far there is to scroll that way (updateTypeFades). */
.type-grid-wrap { position: relative; flex-shrink: 0; }
.type-fade {
	position: absolute;
	top: 0;
	bottom: 0;
	width: 120px;
	z-index: 3;
	pointer-events: none;
	opacity: 0;
	/* The SAME swollen dot as the vertical shadow — now the tiles are transparent so
	   the base grid shows through the whole row, and these phase-locked bigger/darker
	   dots (background-position set in alignHalftone) land concentric on it: the grid
	   swells in place toward the edge. The mask ramps it in; opacity ramps by scroll. */
	background-image: radial-gradient(var(--ink) var(--ht-dot), transparent calc(var(--ht-dot) + 0.3px));
	background-size: var(--grain-pitch) var(--grain-pitch);
}
/* Static soft mask shapes the edge; the layer fades in/out by opacity (set in JS by
   scroll distance). Because the shadow dot is the SAME size as the base dot, the
   opacity fade just darkens each dot in place — no swelling, no sweeping mask edge,
   no halo — so you never see the pixels move. */
.type-fade-left {
	left: 0;
	-webkit-mask-image: linear-gradient( to right, #000 0%, rgba(0,0,0,0.62) 16%, rgba(0,0,0,0.32) 44%, rgba(0,0,0,0.12) 72%, transparent 100% );
	        mask-image: linear-gradient( to right, #000 0%, rgba(0,0,0,0.62) 16%, rgba(0,0,0,0.32) 44%, rgba(0,0,0,0.12) 72%, transparent 100% );
}
.type-fade-right {
	right: 0;
	-webkit-mask-image: linear-gradient( to left, #000 0%, rgba(0,0,0,0.62) 16%, rgba(0,0,0,0.32) 44%, rgba(0,0,0,0.12) 72%, transparent 100% );
	        mask-image: linear-gradient( to left, #000 0%, rgba(0,0,0,0.62) 16%, rgba(0,0,0,0.32) 44%, rgba(0,0,0,0.12) 72%, transparent 100% );
}
.type-btn {
	flex: 0 0 72px;
	aspect-ratio: 1;
	scroll-snap-align: start;
	display: grid;
	place-items: center;
	/* Each kind is a scout-badge token: an ink-ring circle with the icon centred,
	   transparent so the fixed dot grid runs straight through it. Only the active
	   kind fills (solid --accent disc, icon knocked out). */
	border: var(--bw) solid var(--ink);
	border-radius: 50%;
	background: transparent;
	color: var(--ink);
	cursor: pointer;
	-webkit-tap-highlight-color: transparent;
	/* No own colour transition: the badge ink rides the --ink crossfade directly
	   (in sync with the chrome). Selection just snaps + pops. */
}
/* Icon-only — the kind name stays for assistive tech but is visually hidden. */
.type-btn > span:not(.type-btn__icon) {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip: rect(0 0 0 0);
	white-space: nowrap;
}
.type-btn__icon {
	display: flex;
	align-items: center;
	justify-content: center;
	/* Scaled with the 72px circle to hold the icon:ring:whitespace balance. */
	width: 30px;
	height: 30px;
}
.type-btn__icon svg { width: 100%; height: 100%; }

.type-btn.is-active {
	background: var(--accent);
	color: var(--on-accent);
	border-color: var(--line);
	animation: type-pop 0.22s cubic-bezier(0.34, 1.56, 0.64, 1);
}
@keyframes type-pop {
	0%   { transform: scale(1); }
	50%  { transform: scale(1.08); }
	100% { transform: scale(1); }
}
.type-btn:active { transform: translate(1px, 1px); }

/* RSVP response — a segmented yes/maybe/no control, sharing the tile's two-tone
   active state so the choice reads in the kind ink. */
.rsvp-toggle { display: flex; gap: 6px; }
.rsvp-btn {
	flex: 1 1 0;
	padding: 11px 8px;
	border: var(--bw) solid var(--ink-30);
	border-radius: var(--radius);
	background: var(--field);
	color: color-mix( in srgb, var(--ink) 72%, var(--paper) );
	font-family: var(--display);
	font-size: var(--fs-14);
	font-weight: 800;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	cursor: pointer;
	-webkit-tap-highlight-color: transparent;
}
.rsvp-btn.is-active { background: var(--accent); color: var(--on-accent); border-color: var(--line); }
.rsvp-btn:active { transform: translate(1px, 1px); }

/* ── Compose ────────────────────────────────────────────────────────────── */

/* The single scroll region: greeting + type selector + fields scroll together.
   The masthead and the Post button (.bottom-bar) stay pinned outside it. Bare
   flex column so the greeting/type-grid keep their full-bleed dividers; field
   padding lives on .compose-fields below. */
.compose-scroll {
	flex: 1;
	overflow-y: auto;
	-webkit-overflow-scrolling: touch;
	overscroll-behavior: contain;
	display: flex;
	flex-direction: column;
	scrollbar-width: thin;
	scrollbar-color: var(--ink-38) transparent;
}
/* Conditional depth as scroll shadows — but drawn as translucent ink washes
   OVER the continuous grain, with NO opaque paper covers (those revealed a
   dot-free off-white patch on the textured paper). Two sticky overlays ride the
   top and bottom edges; a tiny scroll handler toggles .has-above / .has-below
   to fade them in. Grain runs through them, uninterrupted. */
.scroll-fade {
	position: sticky;
	z-index: 3;
	flex-shrink: 0;
	height: 160px;
	pointer-events: none;
	opacity: 0;
	/* A single halftone screen in the ink hue on a transparent ground — the page
	   grain shows between the dots. The mask shapes the darkness as a "hockey
	   stick": a long, subtle tail reaching far into the content that ramps up
	   sharply at the origin edge (the % stops hold the ratio at any height).
	   Element opacity is driven by scroll position in JS so it fades by position. */
	background-image: radial-gradient(var(--ink) var(--ht-dot), transparent calc(var(--ht-dot) + 0.3px));
	/* Same pitch as the page grain so the halftone shares its dot density and
	   interleaves cleanly (a bigger stud per grain cell), rather than clashing at
	   a different scale. (Pixel-perfect concentric phase-lock would need a shared
	   global grid via background-attachment: fixed, which breaks on this sticky
	   overlay / iOS — so this matches size, not phase.) */
	background-size: var(--grain-pitch) var(--grain-pitch);
}
/* Static soft mask; background-position set in JS (viewport master grid). The layer
   fades by opacity — same-size dots, so they darken in place without moving. */
.scroll-fade-top {
	top: 0;
	margin-bottom: -160px;
	-webkit-mask-image: linear-gradient( to bottom, #000 0%, rgba(0,0,0,0.5) 12%, rgba(0,0,0,0.18) 38%, rgba(0,0,0,0.05) 68%, transparent 100% );
	        mask-image: linear-gradient( to bottom, #000 0%, rgba(0,0,0,0.5) 12%, rgba(0,0,0,0.18) 38%, rgba(0,0,0,0.05) 68%, transparent 100% );
}
.scroll-fade-bottom {
	bottom: 0;
	margin-top: -160px;
	-webkit-mask-image: linear-gradient( to top, #000 0%, rgba(0,0,0,0.5) 12%, rgba(0,0,0,0.18) 38%, rgba(0,0,0,0.05) 68%, transparent 100% );
	        mask-image: linear-gradient( to top, #000 0%, rgba(0,0,0,0.5) 12%, rgba(0,0,0,0.18) 38%, rgba(0,0,0,0.05) 68%, transparent 100% );
}
.compose-fields {
	/* grow to fill when content is short (textarea fills the frame), but never
	   shrink below its content — so a tall form overflows into the scroll. The
	   deep bottom padding clears the floating Post button so the last line can
	   scroll above it. */
	flex: 1 0 auto;
	padding: 16px var(--pad-x) calc(var(--safe-bottom) + 88px);
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.field-group { display: flex; flex-direction: column; }
.field-group.is-conditional:not([hidden]) { animation: reveal 0.18s ease; }
@keyframes reveal {
	from { opacity: 0; transform: translateY(-6px); }
	to   { opacity: 1; transform: translateY(0); }
}

.field-label {
	display: block;
	font-family: var(--display);
	font-size: var(--fs-11);
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.08em;
	opacity: 0.7;
	margin-bottom: 6px;
}
.sr-only {
	position: absolute;
	width: 1px; height: 1px;
	padding: 0; margin: -1px;
	overflow: hidden;
	clip: rect(0,0,0,0);
	white-space: nowrap;
	border: 0;
}

/* Utility inputs are flat SOLID filled fields (figure-ground by value, no dots)
   so the text on them stays easy to read. */
.text-field {
	width: 100%;
	background: transparent;
	border: var(--bw) solid var(--ink-38);
	border-radius: var(--radius);
	padding: 11px 12px;
	font-size: var(--fs-16);
	font-family: inherit;
	font-weight: 500;
	color: var(--text);
	outline: none;
}
.text-field:focus { border-color: var(--accent); }
.text-field::placeholder { color: var(--text); opacity: 0.4; }

/* Compose textarea is the hero — a flat legal-pad: faint ruled lines for the
   text to sit on, a bold red margin instead of a box, and a big rotating prompt
   that recedes as you type. Grows to fill the space. */
#fieldContent { display: flex; flex-direction: column; }
.compose-wrap { position: relative; }
.compose-field {
	display: block;
	width: 100%;
	min-height: 150px;
	height: auto;
	background-color: transparent;
	/* Ruled legal-pad — faint hairlines sitting just under each baseline so text
	   reads as written ON the line. The field auto-grows with its content (see
	   the JS), so an empty note stays a short pad and the page grain runs on,
	   uninterrupted, below — no paper veil, so no hard seam where the texture
	   would otherwise stop. */
	background-image: repeating-linear-gradient( to bottom,
		transparent 0, transparent 28px,
		var(--rule) 28px, var(--rule) 29px,
		transparent 29px, transparent 30px );
	border: none;
	padding: 6px 4px 4px 0;
	font-size: var(--fs-18);
	line-height: 30px;
	font-family: inherit;
	font-weight: 500;
	color: var(--text);
	resize: none;
	outline: none;
	overflow: hidden;
}

/* Big expressive prompt overlay — fades out once there's content. Sits on the
   first rule like the typed text: top is nudged so its baseline lands at 28px
   (the content baseline / first rule), so placeholder and content share the
   same line. Measured for Brandon Condensed 800 at 30px. */
.compose-prompt {
	position: absolute;
	top: 1.3px;
	left: 0;
	right: 8px;
	font-family: var(--display);
	font-size: 30px;
	font-weight: 800;
	line-height: 1.05;
	text-transform: uppercase;
	letter-spacing: 0.01em;
	/* A solid faint tint — not translucent ink — so the ruled lines and the
	   page grain don't bleed through the letters; they sit behind the opaque
	   glyphs and resume either side, like a label printed on ruled paper. */
	color: color-mix( in srgb, var(--ink) 26%, var(--paper) );
	pointer-events: none;
	transition: opacity 0.18s ease, transform 0.18s ease;
}
.compose-prompt.is-hidden { opacity: 0; transform: translateY(-6px); }

/* Photo picker */
.photo-picker {
	background: var(--field);
	border: var(--bw) dashed var(--line);
	border-radius: var(--radius);
	padding: 26px 16px;
	text-align: center;
	cursor: pointer;
	-webkit-tap-highlight-color: transparent;
}
.photo-picker:active,
.photo-picker.drag-over { border-style: solid; border-color: var(--accent); }
.photo-picker input[type="file"] { display: none; }
.photo-picker-icon { display: block; margin-bottom: 8px; }
.photo-picker p { font-family: var(--display); font-size: 17px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; }
.photo-picker small { font-size: var(--fs-12); font-weight: 500; opacity: 0.6; display: block; margin-top: 4px; }

.thumbnails {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(104px, 1fr));
	gap: 8px;
	margin-top: 10px;
}
.thumbnails img {
	width: 100%; aspect-ratio: 1; object-fit: cover;
	border: var(--bw) solid var(--line);
	border-radius: var(--radius); display: block;
}
.thumb { display: flex; flex-direction: column; }
.alt-texts { display: flex; flex-direction: column; gap: 8px; margin-top: 10px; }
.alt-text-row { display: flex; flex-direction: column; gap: 4px; }
.alt-text-label {
	font-size: var(--fs-10);
	font-weight: 700;
	letter-spacing: 0.07em;
	text-transform: uppercase;
	color: var(--text);
	opacity: 0.5;
}
.thumb__alt {
	width: 100%;
	background: transparent;
	border: none;
	border-bottom: 1.5px solid var(--line);
	border-radius: 0;
	padding: 6px 0;
	font-size: var(--fs-16);
	font-family: inherit;
	font-weight: 500;
	color: var(--text);
	outline: none;
	min-height: 36px;
}
.thumb__alt:focus { border-bottom-color: var(--accent); }
.thumb__alt::placeholder { color: var(--text); opacity: 0.35; }

/* Tags — solid accent chips, knockout text, square remove. */
.tags-field {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
	background: transparent;
	border: var(--bw) solid var(--ink-38);
	border-radius: var(--radius);
	padding: 8px 10px;
	min-height: 46px;
	cursor: text;
}
.tags-field:focus-within { border-color: var(--accent); }
.tag-chip {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	background: var(--accent);
	color: var(--on-accent);
	border-radius: var(--radius);
	padding: 4px 4px 4px 9px;
	font-size: var(--fs-13);
	font-weight: 700;
	white-space: nowrap;
}
.tag-chip__remove {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 18px; height: 18px;
	background: var(--on-accent);
	color: var(--accent);
	border: none; padding: 0;
	border-radius: 1px;
	cursor: pointer; font-size: var(--fs-13); line-height: 1;
	font-family: inherit; font-weight: 800;
}
.tag-input {
	flex: 1;
	min-width: 80px;
	border: none; outline: none;
	font-size: var(--fs-16);
	font-family: inherit;
	font-weight: 500;
	color: var(--text);
	background: transparent;
	padding: 2px 0;
}
.tag-input::placeholder { color: var(--text); opacity: 0.45; }

/* URL specimen — fills the void on URL-only kinds (like, repost). Empty: the
   kind glyph as a printed watermark. Filled: the target's hostname set big in
   condensed caps, confirming what you're acting on before you post. */
.url-specimen {
	flex: 1 1 auto;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 10px;
	min-height: 140px;
	padding: 8px 12px 20px;
	text-align: center;
}
.url-specimen__glyph { display: flex; color: var(--ink); opacity: 0.12; }
.url-specimen__glyph svg { display: block; width: 96px; height: 96px; }
.url-specimen__hint {
	font-family: var(--display);
	font-size: var(--fs-13);
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.08em;
	opacity: 0.4;
}
.url-specimen__host {
	font-family: var(--display);
	font-size: clamp(30px, 10vw, 44px);
	font-weight: 800;
	line-height: 0.95;
	text-transform: uppercase;
	letter-spacing: 0.01em;
	word-break: break-word;
	animation: reveal 0.18s ease;
}
.url-specimen__path {
	font-size: var(--fs-13);
	font-weight: 500;
	opacity: 0.6;
	word-break: break-all;
	max-width: 100%;
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
	overflow: hidden;
}

/* Syndicate-to */
.syndicate-details {
	border: none;
	border-top: var(--bw) solid var(--line);
}
.syndicate-summary {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 0;
	font-family: var(--display);
	font-size: var(--fs-13);
	font-weight: 800;
	text-transform: uppercase;
	letter-spacing: 0.1em;
	cursor: pointer;
	list-style: none;
	-webkit-tap-highlight-color: transparent;
	user-select: none;
}
.syndicate-summary::-webkit-details-marker { display: none; }
.syndicate-summary::after {
	content: '+';
	font-size: var(--fs-18); font-weight: 800; line-height: 1;
}
details[open] .syndicate-summary::after { content: '\2212'; }
/* Animated open — interpolate-size lets block-size transition to auto
   (Chrome 131+ / Safari 18.2+; elsewhere it just opens instantly). */
.syndicate-details::details-content {
	block-size: 0;
	overflow: hidden;
	interpolate-size: allow-keywords;
	transition: block-size 0.25s ease, content-visibility 0.25s allow-discrete;
}
.syndicate-details[open]::details-content { block-size: auto; }
.syndicators {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 12px 0 4px;
}
.syndicator-item {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: var(--fs-14);
	font-weight: 700;
	cursor: pointer;
	user-select: none;
}
/* Square ink toggle — same language as the type tiles; the real checkbox
   stays in the tree (sr-only) for keyboard and screen readers. */
.syndicator-box {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 20px; height: 20px;
	flex-shrink: 0;
	border: var(--bw) solid var(--line);
	border-radius: var(--radius);
	background: var(--field);
	color: var(--on-accent);
}
.syndicator-box svg {
	display: block;
	opacity: 0;
	transform: scale(0.5);
	transition: opacity 0.12s, transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.syndicator-item input:checked + .syndicator-box { background: var(--accent); }
.syndicator-item input:checked + .syndicator-box svg { opacity: 1; transform: scale(1); }
.syndicator-item input:focus-visible + .syndicator-box {
	box-shadow: 0 0 0 2px var(--field), 0 0 0 4px var(--accent);
}
.syndicator-item__limit {
	margin-left: auto;
	font-size: var(--fs-11);
	font-weight: 700;
	font-variant-numeric: tabular-nums;
	opacity: 0.55;
}

/* ── Bottom bar + buttons ───────────────────────────────────────────────── */

/* The footer floats: no band, just the button over the continuous grain, which
   now runs to the shell's bottom edge (clipped to the rounded corner by .app's
   overflow:hidden). The bar is a transparent overlay pinned to the view bottom;
   it ignores pointer events so a drag over it still scrolls the content beneath,
   while the button itself stays tappable. Bottom padding lifts the button clear
   of the iOS home-indicator zone (--safe-bottom, faked on the desktop mock). */
.bottom-bar {
	position: absolute;
	left: 0;
	right: 0;
	bottom: 0;
	z-index: 4;
	/* Centre a narrow floating pill (not a full-width bar), matching the iOS Safari
	   URL pill; the 16px gutter is just a safety margin so it never kisses the edge.
	   Bottom padding lifts it clear of the home-indicator zone. */
	display: flex;
	justify-content: center;
	padding: 14px 16px;
	padding-bottom: calc(var(--safe-bottom) + 14px);
	background: transparent;
	pointer-events: none;
}
.bottom-bar .btn { pointer-events: auto; }
/* Free-floating stadium pill, no longer shaped to hug the shell corner. Height is
   pinned to --post-btn-h (the iOS toolbar pill) instead of the default padding, so
   it reads as the same control class as Safari's own bottom pill. Border-box +
   flex-centred content means the min-height IS the total height. */
.bottom-bar .btn { border-radius: var(--nop-radius-pill); width: var(--post-btn-w); min-height: var(--post-btn-h); padding-block: 0; }

.btn {
	display: block;
	width: 100%;
	padding: 15px;
	border: var(--bw) solid var(--line);
	border-radius: var(--radius);
	font-size: var(--fs-16);
	font-weight: 800;
	font-family: inherit;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	cursor: pointer;
	-webkit-tap-highlight-color: transparent;
	text-align: center;
	text-decoration: none;
}

/* Post button — solid accent, knockout label. Floats over the grain on a soft
   shadow (the band that used to carry the section's depth is gone); it settles
   onto a tighter shadow on press. */
.btn-primary {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 9px;
	background: var(--accent);
	color: var(--on-accent);
	border-color: var(--accent);
	box-shadow: 0 6px 16px rgba(0,0,0,0.18);
	transition: transform 0.08s, box-shadow 0.18s ease, opacity 0.18s ease;
}
.btn-primary__icon { flex-shrink: 0; }
.btn-primary:active {
	transform: translate(0, 2px);
	box-shadow: 0 3px 9px rgba(0,0,0,0.16);
}
/* Disabled stays a solid, muted primary — never a ghost outline — so the
   commit target always reads as the primary action. When the form becomes valid
   the fill saturates to full accent and the offset shadow grows: it "charges". */
.btn-primary:disabled {
	background: color-mix( in srgb, var(--accent) 30%, var(--device-ink) );
	color: color-mix( in srgb, var(--on-accent) 38%, var(--device-ink) );
	border-color: transparent;
	opacity: 1;
	cursor: default;
	transform: none;
	box-shadow: 0 3px 10px rgba(0,0,0,0.10);
}
.btn-secondary { background: var(--field); color: var(--text); }
.btn-secondary:active { transform: translate(1px, 1px); }
.btn-accent { background: var(--field); color: var(--text); margin-top: 0; }
.btn-instagram {
	background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888);
	color: #fff;
	border-color: var(--line);
}

/* ── Progress ───────────────────────────────────────────────────────────── */

.progress-view {
	flex: 1;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 22px;
	text-align: center;
	padding: 24px;
}
.progress-spinner {
	width: 44px; height: 44px;
	border: 4px solid var(--line);
	border-top-color: var(--accent);
	border-radius: 50%;
	animation: spin 0.8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
.progress-status { font-family: var(--display); font-size: 15px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; }
.progress-bar-track {
	width: 200px; height: 6px;
	border: var(--bw) solid var(--line);
	border-radius: 1px;
	overflow: hidden;
}
.progress-bar-fill {
	height: 100%;
	background: var(--accent);
	width: 0%;
	transition: width 0.3s;
}

/* ── Success — poster moment ────────────────────────────────────────────── */

.success-scroll {
	flex: 1;
	overflow-y: auto;
	-webkit-overflow-scrolling: touch;
	/* deep bottom padding clears the floating "Post another" button */
	padding: 16px var(--pad-x) calc(var(--safe-bottom) + 88px);
}
.success-hero {
	position: relative;
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 36px 0 12px;
	margin-bottom: 16px;
}
/* The rubber stamp — slams down (springy bezier overshoots the landing) with
   a fixed -3° rotation; `rotate` is its own property so the keyframes only
   need to scale. */
.success-banner {
	position: relative;
	z-index: 1;
	display: inline-flex;
	align-items: center;
	gap: 12px;
	background: var(--accent);
	color: var(--on-accent);
	border: var(--bw) solid var(--line);
	border-radius: var(--radius);
	padding: 14px 26px;
	box-shadow: var(--shadow);
	rotate: -3deg;
	animation: stamp 0.32s cubic-bezier(0.22, 0.61, 0.36, 1) both;
}
@keyframes stamp {
	0%   { transform: scale(1.12); opacity: 0; }
	100% { transform: scale(1); opacity: 1; }
}
.success-check { flex-shrink: 0; display: flex; }
.success-banner h2 {
	font-family: var(--display);
	font-size: 40px;
	font-weight: 800;
	letter-spacing: 0.01em;
	text-transform: uppercase;
	line-height: 1;
}
/* Bauhaus burst — the one deliberate break from two-tone: a single flash of
   the full six-ink set radiating from the stamp's impact, then gone. */
.burst {
	position: absolute;
	inset: 0;
	display: block;
	pointer-events: none;
}
.burst i {
	position: absolute;
	left: 50%;
	top: 50%;
	width: 10px;
	height: 10px;
	margin: -5px;
	background: currentColor;
	animation: burst-fly 0.7s cubic-bezier(0.2, 0.6, 0.3, 1) 0.18s both;
}
.burst i:nth-child(6n)   { color: var(--red); }
.burst i:nth-child(6n+1) { color: var(--blue); }
.burst i:nth-child(6n+2) { color: var(--teal); }
.burst i:nth-child(6n+3) { color: var(--green); }
.burst i:nth-child(6n+4) { color: var(--violet); }
.burst i:nth-child(6n+5) { color: var(--orange); }
.burst i:nth-child(4n)   { border-radius: 50%; }
.burst i:nth-child(4n+2) { clip-path: polygon(50% 0, 100% 100%, 0 100%); }
@keyframes burst-fly {
	0%   { transform: rotate(var(--a)) translateY(-20px) scale(0.3); opacity: 0; }
	25%  { opacity: 1; }
	100% { transform: rotate(var(--a)) translateY(-92px) scale(1); opacity: 0; }
}
/* Streak set as a type specimen — oversized ordinal, small caps label. */
.success-streak {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 2px;
	margin-top: 22px;
}
.success-streak__num {
	font-family: var(--display);
	font-size: 42px;
	font-weight: 800;
	line-height: 1;
}
.success-streak__label {
	font-family: var(--display);
	font-size: var(--fs-12);
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.12em;
	opacity: 0.6;
}
.success-photos {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(88px, 1fr));
	gap: 6px;
	margin-bottom: 16px;
}
.success-photos img {
	width: 100%; aspect-ratio: 1; object-fit: cover;
	border: var(--bw) solid var(--line);
	border-radius: var(--radius);
}
.success-permalink {
	font-size: var(--fs-13);
	font-weight: 700;
	color: var(--text);
	text-decoration: underline;
	text-decoration-thickness: 2px;
	display: block;
	margin-bottom: 16px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.success-actions { display: flex; flex-direction: column; gap: 10px; }

/* Character counter — a small countdown chip that only appears as you near a
   syndication limit (see updateCounter), so it never floats as a stray number. */
.char-count {
	align-self: flex-end;
	margin-top: 4px;
	padding: 2px 9px;
	border-radius: var(--nop-radius-pill);
	background: var(--surface);
	font-size: var(--fs-11);
	font-weight: 800;
	color: var(--text);
	font-variant-numeric: tabular-nums;
}
/* Over-limit: a muted, palette-matched red on the same chip — a quiet warning,
   not a loud red block. */
.char-count.is-over {
	background: color-mix( in srgb, var(--red) 10%, var(--surface) );
	color: color-mix( in srgb, var(--red) 50%, var(--charcoal) );
}

/* Toast */
.toast {
	position: fixed;
	left: 50%;
	bottom: calc(var(--safe-bottom) + 20px);
	transform: translate(-50%, 12px);
	max-width: 88%;
	padding: 12px 16px;
	background: var(--field);
	border: var(--bw) solid var(--line);
	border-radius: var(--radius);
	box-shadow: var(--shadow);
	color: var(--text);
	font-size: var(--fs-14);
	font-weight: 700;
	opacity: 0;
	transition: opacity 0.2s, transform 0.2s;
	z-index: 50;
	pointer-events: none;
	text-align: center;
}
.toast.is-visible { opacity: 1; transform: translate(-50%, 0); }
.toast--error { border-color: var(--red); color: var(--red); }

@media (prefers-reduced-motion: reduce) {
	.type-btn.is-active,
	.success-banner,
	.url-specimen__host { animation: none; }
	.burst { display: none; }
	.field-group.is-conditional:not([hidden]) { animation: none; }
	.compose-prompt, .btn-primary, .syndicator-box, .syndicator-box svg { transition: none; }
	.syndicate-details::details-content { transition: none; }
	.btn-primary:active { transform: none; }
	.toast { transition: opacity 0.01ms; }
}

</style>
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
	};

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
			tkTime = time + ' · ' + date;   // the ticker's date+time item
			setTk( 'tk-time', tkTime );
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
	var tkTime = '', tkPlace = '', tkTemp = '', tkSky = '';

	function tkItems() {
		var out = [ { c: 'tk-id', h: TK_ID_PRE + nextSerial } ];
		if ( tkTime )  { out.push( { c: 'tk-time',  h: tkTime } ); }
		if ( tkPlace ) { out.push( { c: 'tk-place', h: tkPlace } ); }
		if ( tkTemp )  { out.push( { c: 'tk-temp',  h: tkTemp } ); }
		if ( tkSky )   { out.push( { c: 'tk-sky',   h: tkSky } ); }
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
		renderTicker();   // rebuild once now that place/temp/sky have resolved
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
		var RAMP  = 40;
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

	postBtn.addEventListener( 'click', async function () {
		try {
			showView( 'progress' );

			var photoUrls = [];
			if ( currentType === 'photo' && selectedFiles.length ) {
				for ( var i = 0; i < selectedFiles.length; i++ ) {
					setProgress( 'Uploading ' + ( i + 1 ) + ' of ' + selectedFiles.length + '…', ( i / selectedFiles.length ) * 0.75 );
					var uploaded = await uploadPhoto( selectedFiles[ i ] );
					photoUrls.push( uploaded.source_url );
				}
			}

			setProgress( 'Posting…', 0.88 );

			var payload  = buildPayload( photoUrls );
			var response = await fetch( NOP.micropubUrl, {
				method:  'POST',
				headers: { 'X-WP-Nonce': NOP.nonce, 'Content-Type': 'application/json' },
				body: JSON.stringify( payload ),
			} );

			if ( response.status !== 201 ) {
				var errBody = await response.json().catch( function () { return {}; } );
				throw new Error( errBody.message || 'Posting failed (' + response.status + ')' );
			}

			recordKindUse( currentType );   // float this kind to the front next time

			var permalink = response.headers.get( 'Location' ) || '';
			var editUrl   = response.headers.get( 'X-Edit-URL' ) || '';

			setProgress( 'Syndicating…', 0.97 );
			await delay( 600 );

			if ( currentType === 'photo' ) {
				var caption = contentInput.value.trim();
				if ( caption ) await navigator.clipboard.writeText( caption ).catch( function () {} );
			}

			showSuccess( permalink, editUrl, photoUrls );

		} catch ( err ) {
			showView( 'compose' );
			showToast( 'Something went wrong: ' + err.message, 'error' );
		}
	} );

	function buildPayload( photoUrls ) {
		var cfg   = TYPE_CONFIG[ currentType ];
		var props = {};

		props[ 'post-kind' ] = [ currentType ];

		var content = contentInput.value.trim();
		if ( content && cfg.hasContent ) props.content = [ content ];

		if ( cfg.urlProp ) {
			var url = urlInput.value.trim();
			if ( url ) props[ cfg.urlProp ] = [ url ];
		}

		// RSVP rides on in-reply-to (the event URL); the rsvp property is what makes
		// the server resolve it as an RSVP rather than a plain reply.
		if ( cfg.hasRsvp ) props.rsvp = [ currentRsvp ];

		// Alt text rides along as the server's array photo shape ({primary, alt});
		// sideload_photos copies it onto the attachment, where both the rendered
		// post and the Mastodon/Bluesky syndicators read it.
		if ( photoUrls && photoUrls.length ) {
			props.photo = photoUrls.map( function (url, i) {
				var alt = ( photoAlts[ i ] || '' ).trim();
				return alt ? { primary: url, alt: alt } : url;
			} );
		}
		if ( cfg.hasTags && currentTags.length ) props.category = currentTags.slice();

		// Always sent, even when empty — an explicitly empty selection means
		// "this site only"; omitting the property would fall back to the
		// server's default of syndicating to every enabled platform.
		props[ 'syndicate-to' ] = Array.from(
			document.querySelectorAll( '#syndicators input[type="checkbox"]:checked' )
		).map( function (cb) { return cb.value; } );

		return { type: [ 'h-entry' ], properties: props };
	}

	async function uploadPhoto( file ) {
		var res = await fetch( NOP.mediaUrl, {
			method:  'POST',
			headers: {
				'X-WP-Nonce':         NOP.nonce,
				'Content-Disposition': 'attachment; filename="' + ( file.name || 'photo.jpg' ) + '"',
				'Content-Type':        file.type || 'image/jpeg',
			},
			body: file,
		} );
		if ( ! res.ok ) {
			var err = await res.json().catch( function () { return {}; } );
			throw new Error( err.message || 'Upload failed (' + res.status + ')' );
		}
		return res.json();
	}

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
