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
		// Escaped at the point of output below (PHPCS can't track escaping through assignment).
		$site_name    = get_bloginfo( 'name' );
		$icon_url     = get_site_icon_url( 192 );
		$font_dir     = get_theme_file_uri( 'assets/fonts/brandon-text' );
		$cond_dir     = get_theme_file_uri( 'assets/fonts/brandon-text-condensed' );

		$user      = wp_get_current_user();
		$user_name = $user->first_name ?: $user->display_name;

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
				fn( $s ) => [ 'uid' => $s['slug'], 'name' => $s['label'] ],
				$manager->get_panel_data()
			);
		}
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#F4EFE6" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#15140F" media="(prefers-color-scheme: dark)">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
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
/* Registering --ink as a real <color> lets the .app transition interpolate it;
   every derived token (surfaces, rules, grain, shadow) re-mixes per frame, so
   one transition sweeps the whole poster through the colour change. */
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
	--charcoal: light-dark(#1A1A1A, #F4EFE6);

	/* Two-tone risograph: ONE ink per screen, printed on paper. The ink-derived
	   tokens live on .app (not here) so they re-resolve with the per-type --ink;
	   defining them on :root would bake in the default ink and never re-tint. */
	--field:     var(--paper);
	--on-accent: var(--field);

	--display: 'Brandon Text Condensed', 'Brandon Text', -apple-system, BlinkMacSystemFont, sans-serif;

	--radius: 2px;

	--safe-top:    env(safe-area-inset-top, 0px);
	--safe-bottom: env(safe-area-inset-bottom, 0px);
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

html {
	height: 100%;
	height: -webkit-fill-available;
}
body {
	height: 100%;
	min-height: -webkit-fill-available;
	overflow: hidden;
	background-color: var(--field);
	/* Full-bleed neutral grain — on desktop the framed poster sits on a textured
	   field instead of a void; on phone it's covered by the app. */
	background-image: radial-gradient(color-mix(in srgb, var(--charcoal) 7%, transparent) 0.6px, transparent 0.9px);
	background-size: 4px 4px;
	color: var(--charcoal);
	font-family: 'Brandon Text', -apple-system, BlinkMacSystemFont, sans-serif;
	-webkit-font-smoothing: antialiased;
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
	--rule:     color-mix(in srgb, var(--ink) 16%, transparent);
	--grain:    color-mix(in srgb, var(--ink) 7%, transparent);
	--shadow:   4px 4px 0 var(--ink);

	/* The kind-switch moment: --ink is a registered <color>, so the whole
	   poster crossfades to the new ink instead of snapping. */
	transition: --ink 0.4s ease;

	display: flex;
	flex-direction: column;
	height: 100vh;
	height: 100dvh;
	overflow: hidden;
	margin: 0 auto;
	color: var(--text);
	background-color: var(--field);
	/* Faint halftone grain — the organic "printed on paper" texture. */
	background-image: radial-gradient(var(--grain) 0.6px, transparent 0.9px);
	background-size: 4px 4px;
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
	}
	.app {
		height: calc(100dvh - 48px);
		max-height: 880px;
		width: 100%;
		max-width: 480px;
		border: 2px solid var(--line);
		box-shadow: var(--shadow);
	}
}

/* ── Masthead ───────────────────────────────────────────────────────────── */

.masthead {
	flex-shrink: 0;
	padding: 0 16px 14px;
	padding-top: calc(var(--safe-top) + 14px);
	border-bottom: 2px solid var(--line);
}
.masthead__top {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}
.brand { display: flex; align-items: center; gap: 10px; min-width: 0; }
.brand__mark { display: flex; flex-shrink: 0; }
.brand__mark svg,
.brand__mark img { display: block; width: 34px; height: 34px; }
/* Re-ink the logo with the type ink so it joins the two-tone (and stays
   visible in dark mode); the source SVG ships with a hard-coded black fill. */
.brand__mark svg path { fill: var(--ink); }
.brand__word {
	font-family: var(--display);
	font-size: 32px;
	font-weight: 800;
	letter-spacing: 0.01em;
	line-height: 0.9;
	text-transform: uppercase;
}
.timeblock { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.clock {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: 1px;
}
.clock__time {
	font-size: 24px;
	font-weight: 800;
	letter-spacing: -0.02em;
	line-height: 1;
	font-variant-numeric: tabular-nums;
	font-feature-settings: "tnum";
}
.clock__date {
	font-family: var(--display);
	font-size: 12px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.08em;
	opacity: 0.6;
}

/* Time-of-day arc — a slim solid arc with a distinct rayed sun (day) / crescent
   moon (night) glyph riding it, positioned by the hour. JS sets the position
   and swaps the glyph only on the per-minute tick (no per-second repaint). */
.sky {
	position: relative;
	height: 22px;
	margin-top: 10px;
}
.sky__arc {
	position: absolute;
	inset: 0;
	width: 100%;
	height: 100%;
	color: color-mix(in srgb, var(--ink) 38%, transparent);
}
.sky__body {
	position: absolute;
	width: 20px;
	height: 20px;
	color: var(--ink);
	transform: translate(-50%, -50%);
	transition: left 0.6s ease, top 0.6s ease;
}
.sky__body svg { display: block; width: 20px; height: 20px; }
.sky__body .sky__moon { display: none; }
.sky__body.is-moon .sky__sun { display: none; }
.sky__body.is-moon .sky__moon { display: block; }

.greeting {
	flex-shrink: 0;
	padding: 10px 16px;
	font-size: 14px;
	font-weight: 700;
	letter-spacing: -0.01em;
	border-bottom: 2px solid var(--line);
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
}

/* ── Type selector ──────────────────────────────────────────────────────── */

.type-grid {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 6px;
	flex-shrink: 0;
	padding: 12px;
	border-bottom: 2px solid var(--line);
}
.type-btn {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 3px;
	padding: 9px 4px;
	border: 2px solid var(--line);
	border-radius: var(--radius);
	background: var(--field);
	color: var(--text);
	font-size: 10px;
	font-weight: 800;
	font-family: var(--display);
	text-transform: uppercase;
	letter-spacing: 0.06em;
	cursor: pointer;
	-webkit-tap-highlight-color: transparent;
	transition: background 0.1s, color 0.1s;
}
.type-btn__icon {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 20px;
	height: 20px;
}

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

/* ── Compose ────────────────────────────────────────────────────────────── */

.compose-scroll {
	flex: 1;
	overflow-y: auto;
	-webkit-overflow-scrolling: touch;
	overscroll-behavior: contain;
	padding: 16px;
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
	font-size: 11px;
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
	background: var(--surface);
	border: none;
	border-radius: var(--radius);
	padding: 11px 12px;
	font-size: 16px;
	font-family: inherit;
	font-weight: 500;
	color: var(--text);
	outline: none;
}
.text-field:focus { box-shadow: inset 0 0 0 2px var(--accent); }
.text-field::placeholder { color: var(--text); opacity: 0.4; }

/* Compose textarea is the hero — a flat legal-pad: faint ruled lines for the
   text to sit on, a bold red margin instead of a box, and a big rotating prompt
   that recedes as you type. Grows to fill the space. */
#fieldContent { flex: 1 1 auto; min-height: 160px; display: flex; flex-direction: column; }
.compose-wrap { position: relative; flex: 1 1 auto; min-height: 150px; }
.compose-field {
	position: absolute;
	inset: 0;
	width: 100%;
	height: 100%;
	background-color: transparent;
	/* Ruled notepad — horizontal rule lines only (no margin line, no box-shadow). */
	background-image: repeating-linear-gradient( to bottom,
		transparent 0, transparent 29px,
		var(--rule) 29px, var(--rule) 30px );
	background-attachment: local;
	border: none;
	padding: 5px 4px 4px 16px;
	font-size: 18px;
	line-height: 30px;
	font-family: inherit;
	font-weight: 500;
	color: var(--text);
	resize: none;
	outline: none;
}

/* Big expressive prompt overlay — fades out once there's content. */
.compose-prompt {
	position: absolute;
	top: 4px;
	left: 16px;
	right: 8px;
	font-family: var(--display);
	font-size: 30px;
	font-weight: 800;
	line-height: 1.05;
	text-transform: uppercase;
	letter-spacing: 0.01em;
	color: var(--text);
	opacity: 0.22;
	pointer-events: none;
	transition: opacity 0.18s ease, transform 0.18s ease;
}
.compose-prompt.is-hidden { opacity: 0; transform: translateY(-6px); }

/* Photo picker */
.photo-picker {
	background: var(--field);
	border: 2px dashed var(--line);
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
.photo-picker small { font-size: 12px; font-weight: 500; opacity: 0.6; display: block; margin-top: 4px; }

.thumbnails {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(104px, 1fr));
	gap: 8px;
	margin-top: 10px;
}
.thumbnails img {
	width: 100%; aspect-ratio: 1; object-fit: cover;
	border: 2px solid var(--line);
	border-radius: var(--radius); display: block;
}
.thumb { display: flex; flex-direction: column; gap: 4px; }
.thumb__alt {
	width: 100%;
	background: var(--surface);
	border: none;
	border-radius: var(--radius);
	padding: 6px 8px;
	font-size: 12px;
	font-family: inherit;
	font-weight: 500;
	color: var(--text);
	outline: none;
}
.thumb__alt:focus { box-shadow: inset 0 0 0 2px var(--accent); }
.thumb__alt::placeholder { color: var(--text); opacity: 0.4; }

/* Tags — solid accent chips, knockout text, square remove. */
.tags-field {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
	background: var(--surface);
	border: none;
	border-radius: var(--radius);
	padding: 8px 10px;
	min-height: 46px;
	cursor: text;
}
.tags-field:focus-within { box-shadow: inset 0 0 0 2px var(--accent); }
.tag-chip {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	background: var(--accent);
	color: var(--on-accent);
	border-radius: var(--radius);
	padding: 4px 4px 4px 9px;
	font-size: 13px;
	font-weight: 800;
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
	cursor: pointer; font-size: 13px; line-height: 1;
	font-family: inherit; font-weight: 800;
}
.tag-input {
	flex: 1;
	min-width: 80px;
	border: none; outline: none;
	font-size: 16px;
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
	font-size: 13px;
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
	font-size: 13px;
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
	border-top: 2px solid var(--line);
}
.syndicate-summary {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 0;
	font-family: var(--display);
	font-size: 13px;
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
	font-size: 18px; font-weight: 800; line-height: 1;
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
	font-size: 14px;
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
	border: 2px solid var(--line);
	border-radius: var(--radius);
	background: var(--field);
	color: var(--on-accent);
	transition: background-color 0.12s;
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
	font-size: 11px;
	font-weight: 700;
	font-variant-numeric: tabular-nums;
	opacity: 0.55;
}

/* ── Bottom bar + buttons ───────────────────────────────────────────────── */

.bottom-bar {
	flex-shrink: 0;
	padding: 12px 16px;
	padding-bottom: calc(var(--safe-bottom) + 12px);
	border-top: 2px solid var(--line);
}

.btn {
	display: block;
	width: 100%;
	padding: 15px;
	border: 2px solid var(--line);
	border-radius: var(--radius);
	font-size: 16px;
	font-weight: 800;
	font-family: inherit;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	cursor: pointer;
	-webkit-tap-highlight-color: transparent;
	text-align: center;
	text-decoration: none;
}

/* Post button — solid accent, knockout label, hard offset shadow that
   collapses on press. */
.btn-primary {
	background: var(--accent);
	color: var(--on-accent);
	box-shadow: var(--shadow);
	transition: transform 0.08s, box-shadow 0.18s ease, background-color 0.18s ease, color 0.18s ease, opacity 0.18s ease;
}
.btn-primary:active {
	transform: translate(4px, 4px);
	box-shadow: 0 0 0 var(--line);
}
/* Disabled is structured, not washed out; when the form becomes valid the fill
   sweeps in and the offset shadow grows — the button visibly "charges". */
.btn-primary:disabled {
	background: var(--field);
	color: var(--text);
	border-color: var(--line);
	opacity: 0.45;
	cursor: default;
	box-shadow: 0 0 0 var(--ink);
	transform: none;
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
	border: 2px solid var(--line);
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
	padding: 16px;
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
	border: 2px solid var(--line);
	border-radius: var(--radius);
	padding: 14px 26px;
	box-shadow: var(--shadow);
	rotate: -3deg;
	animation: stamp 0.45s cubic-bezier(0.34, 1.56, 0.64, 1) both;
}
@keyframes stamp {
	0%   { transform: scale(1.5); opacity: 0; }
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
	font-size: 12px;
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
	border: 2px solid var(--line);
	border-radius: var(--radius);
}
.success-permalink {
	font-size: 13px;
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

/* Character counter */
.char-count {
	align-self: flex-end;
	margin-top: 6px;
	font-size: 11px;
	font-weight: 800;
	opacity: 0.6;
	font-variant-numeric: tabular-nums;
}
.char-count.is-over { color: var(--red); opacity: 1; }

/* Toast */
.toast {
	position: fixed;
	left: 50%;
	bottom: calc(var(--safe-bottom) + 20px);
	transform: translate(-50%, 12px);
	max-width: 88%;
	padding: 12px 16px;
	background: var(--field);
	border: 2px solid var(--line);
	border-radius: var(--radius);
	box-shadow: var(--shadow);
	color: var(--text);
	font-size: 14px;
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
	.app { transition: none; }
	.type-btn.is-active,
	.success-banner,
	.url-specimen__host { animation: none; }
	.burst { display: none; }
	.field-group.is-conditional:not([hidden]) { animation: none; }
	.compose-prompt, .sky__body, .btn-primary, .syndicator-box, .syndicator-box svg { transition: none; }
	.syndicate-details::details-content { transition: none; }
	.btn-primary:active { transform: none; box-shadow: var(--shadow); }
	.toast { transition: opacity 0.01ms; }
}
</style>
</head>
<body>
<div class="app" id="app" data-type="note">

	<!-- Masthead -->
	<header class="masthead">
		<div class="masthead__top">
			<div class="brand">
				<span class="brand__mark" aria-hidden="true">
					<svg viewBox="0 0 60 60" fill="none"><path fill-rule="evenodd" clip-rule="evenodd" d="M30 5.45455C16.4439 5.45455 5.45455 16.4439 5.45455 30V35.4545C5.45455 45.9982 14.0018 54.5455 24.5455 54.5455H30C43.5561 54.5455 54.5455 43.5561 54.5455 30C54.5455 16.4439 43.5561 5.45455 30 5.45455ZM0 30C0 13.4315 13.4315 0 30 0C46.5685 0 60 13.4315 60 30C60 46.5685 46.5685 60 30 60H24.5455C10.9893 60 0 49.0107 0 35.4545V30ZM30 16.3636C22.4688 16.3636 16.3636 22.4688 16.3636 30C16.3636 37.5312 22.4688 43.6364 30 43.6364C37.5312 43.6364 43.6364 37.5312 43.6364 30C43.6364 22.4688 37.5312 16.3636 30 16.3636ZM10.9091 30C10.9091 19.4564 19.4564 10.9091 30 10.9091C40.5436 10.9091 49.0909 19.4564 49.0909 30C49.0909 40.5436 40.5436 49.0909 30 49.0909C19.4564 49.0909 10.9091 40.5436 10.9091 30ZM30.0775 27.3502C28.5713 27.3502 27.3502 28.5713 27.3502 30.0775C27.3502 31.5837 26.1292 32.8048 24.623 32.8048C23.1167 32.8048 21.8957 31.5837 21.8957 30.0775C21.8957 25.5589 25.5589 21.8957 30.0775 21.8957C34.5963 21.8957 38.2593 25.5589 38.2593 30.0775C38.2593 31.5837 37.0383 32.8048 35.5321 32.8048C34.0258 32.8048 32.8048 31.5837 32.8048 30.0775C32.8048 28.5713 31.5837 27.3502 30.0775 27.3502Z"/></svg>
				</span>
				<span class="brand__word"><?php esc_html_e( 'Post', 'nop-indieweb' ); ?></span>
			</div>
			<div class="clock" aria-hidden="true">
				<p class="clock__time" id="clockTime">00:00</p>
				<p class="clock__date" id="clockDate">Mon 1 Jan</p>
			</div>
		</div>
		<div class="sky" aria-hidden="true">
			<svg class="sky__arc" viewBox="0 0 100 20" preserveAspectRatio="none"><path d="M3 16 Q50 4 97 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
			<span class="sky__body is-sun" id="skyBody">
				<svg class="sky__sun" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="12" r="5"/><g stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="1.5" x2="12" y2="4.5"/><line x1="12" y1="19.5" x2="12" y2="22.5"/><line x1="1.5" y1="12" x2="4.5" y2="12"/><line x1="19.5" y1="12" x2="22.5" y2="12"/><line x1="4.4" y1="4.4" x2="6.5" y2="6.5"/><line x1="17.5" y1="17.5" x2="19.6" y2="19.6"/><line x1="4.4" y1="19.6" x2="6.5" y2="17.5"/><line x1="17.5" y1="6.5" x2="19.6" y2="4.4"/></g></svg>
				<svg class="sky__moon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z"/></svg>
			</span>
		</div>
	</header>

	<!-- View container -->
	<div class="view-container">

		<!-- Compose view -->
		<div id="view-compose">
			<p class="greeting" id="greeting"></p>

			<div class="type-grid" id="typeBar" role="group" aria-label="<?php esc_attr_e( 'Post type', 'nop-indieweb' ); ?>">
				<button class="type-btn is-active" data-type="note" aria-pressed="true" type="button">
					<span class="type-btn__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg></span>
					<span><?php esc_html_e( 'Note', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="photo" aria-pressed="false" type="button">
					<span class="type-btn__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" fill-rule="evenodd"><path d="M4 6h3l1.8-2h6.4L17 6h3a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2zm8 3.4a3.6 3.6 0 1 0 0 7.2 3.6 3.6 0 0 0 0-7.2z"/></svg></span>
					<span><?php esc_html_e( 'Photo', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="reply" aria-pressed="false" type="button">
					<span class="type-btn__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M10 8V4l-7 7 7 7v-4.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-11z"/></svg></span>
					<span><?php esc_html_e( 'Reply', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="like" aria-pressed="false" type="button">
					<span class="type-btn__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54Z"/></svg></span>
					<span><?php esc_html_e( 'Like', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="bookmark" aria-pressed="false" type="button">
					<span class="type-btn__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2Z"/></svg></span>
					<span><?php esc_html_e( 'Bookmark', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="repost" aria-pressed="false" type="button">
					<span class="type-btn__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M7 7h10v3l4-4-4-4v3H5v6h2V7zm10 10H7v-3l-4 4 4 4v-3h12v-6h-2v4z"/></svg></span>
					<span><?php esc_html_e( 'Repost', 'nop-indieweb' ); ?></span>
				</button>
			</div><!-- .type-grid -->

			<div class="compose-scroll">

				<!-- URL field (reply, like, bookmark, repost) -->
				<div class="field-group is-conditional" id="fieldUrl" hidden>
					<label class="field-label" id="urlLabel" for="typeUrl"><?php esc_html_e( 'URL', 'nop-indieweb' ); ?></label>
					<input type="url" id="typeUrl" class="text-field" placeholder="https://…" autocomplete="off">
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
						<span class="photo-picker-icon" aria-hidden="true"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2Z"/><circle cx="12" cy="13" r="4"/></svg></span>
						<p><?php esc_html_e( 'Add photos', 'nop-indieweb' ); ?></p>
						<small><?php esc_html_e( 'Tap to select · up to 10', 'nop-indieweb' ); ?></small>
					</div>
					<div class="thumbnails" id="thumbnails"></div>
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

			</div><!-- .compose-scroll -->

			<div class="bottom-bar">
				<button class="btn btn-primary" id="postBtn" disabled type="button">
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
						<span class="success-check" aria-hidden="true"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
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
		syndicateTo: <?php echo wp_json_encode( $syndicate_to ); ?>,
		userName:    <?php echo wp_json_encode( $user_name ); ?>,
		greetings:   <?php echo wp_json_encode( $greetings ); ?>,
	};

	var DRAFT_KEY    = 'nop_post_draft';
	var CHAR_LIMITS  = { bluesky: 300, mastodon: 500, pixelfed: 500 };
	var NOTE_PROMPTS = [ "What's happening?", "Seen anything good?", "A thought…", "What's on your mind?", "Share something…" ];
	var notePrompt   = NOTE_PROMPTS[ Math.floor( Math.random() * NOTE_PROMPTS.length ) ];
	var restoring    = false;

	var app = document.getElementById( 'app' );

	// ── Clock + time-of-day device ──────────────────────────────────────────────

	var DAYS   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
	var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

	var clockTimeEl = document.getElementById( 'clockTime' );
	var clockDateEl = document.getElementById( 'clockDate' );
	var greetingEl  = document.getElementById( 'greeting' );
	var skyBody     = document.getElementById( 'skyBody' );
	var lastTime = '', lastDate = '', lastGreeting = '';

	// Rayed sun (06–18h) / crescent moon glyph riding the arc, positioned by the
	// hour along the same quadratic the arc path draws. Per-minute tick only.
	function positionSky( now ) {
		var u  = ( now.getHours() + now.getMinutes() / 60 ) / 24;
		var mu = 1 - u;
		var x  = mu * mu * 3  + 2 * mu * u * 50 + u * u * 97;
		var y  = mu * mu * 16 + 2 * mu * u * 4  + u * u * 16;
		skyBody.style.left = x + '%';
		skyBody.style.top  = ( y / 20 * 100 ) + '%';
	}
	function updateSky( now ) {
		var daytime = now.getHours() >= 6 && now.getHours() < 18;
		skyBody.classList.toggle( 'is-sun', daytime );
		skyBody.classList.toggle( 'is-moon', ! daytime );
		positionSky( now );
	}

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
			clockTimeEl.textContent = time;
			lastTime = time;
			updateSky( now );
			var greet = greetingFor( now.getHours() );
			var line  = NOP.userName ? greet + ', ' + NOP.userName : greet;
			if ( line !== lastGreeting ) { greetingEl.textContent = line; lastGreeting = line; }
		}
		if ( date !== lastDate ) { clockDateEl.textContent = date; lastDate = date; }
	}
	updateClock();
	setInterval( updateClock, 1000 );

	// ── Type configuration ────────────────────────────────────────────────────

	var TYPE_CONFIG = {
		note:     { urlProp: null,           hasContent: true,  hasTags: true,  contentPlaceholder: 'Write a note…' },
		photo:    { urlProp: null,           hasContent: true,  hasTags: true,  contentPlaceholder: 'Write a caption…' },
		reply:    { urlProp: 'in-reply-to',  hasContent: true,  hasTags: false, urlLabel: 'Reply to URL', contentPlaceholder: 'Your reply…' },
		like:     { urlProp: 'like-of',      hasContent: false, hasTags: false, urlLabel: 'Like URL', urlHint: "Paste the URL you're liking" },
		bookmark: { urlProp: 'bookmark-of',  hasContent: true,  hasTags: false, urlLabel: 'Bookmark URL', contentPlaceholder: 'Notes…' },
		repost:   { urlProp: 'repost-of',    hasContent: false, hasTags: false, urlLabel: 'Repost URL', urlHint: "Paste the URL you're reposting" },
	};

	var currentType   = 'note';
	var selectedFiles = [];
	var photoAlts     = [];
	var currentTags   = [];

	// ── DOM refs ──────────────────────────────────────────────────────────────

	var postBtn      = document.getElementById( 'postBtn' );
	var fieldUrl     = document.getElementById( 'fieldUrl' );
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
	var photoInput   = document.getElementById( 'photoInput' );
	var thumbs       = document.getElementById( 'thumbnails' );
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

	(function renderSyndicators() {
		var synTo = NOP.syndicateTo || [];
		if ( ! synTo.length ) return;
		document.getElementById( 'syndicators' ).innerHTML = synTo.map( function (s) {
			var limit = CHAR_LIMITS[ s.uid ];
			return '<label class="syndicator-item">'
				+ '<input type="checkbox" class="sr-only" value="' + escAttr( s.uid ) + '" checked>'
				+ '<span class="syndicator-box" aria-hidden="true"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>'
				+ ' ' + escHtml( s.name )
				+ ( limit ? '<span class="syndicator-item__limit">' + limit + '</span>' : '' )
				+ '</label>';
		} ).join( '' );
		document.getElementById( 'syndicateDetails' ).hidden = false;
		updateCounter();
	} )();

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
		if ( btn ) switchType( btn.dataset.type );
	} );

	function switchType( type ) {
		if ( ! TYPE_CONFIG[ type ] ) return;
		currentType = type;
		var cfg = TYPE_CONFIG[ type ];

		app.dataset.type = type;

		document.querySelectorAll( '.type-btn' ).forEach( function (b) {
			var active = b.dataset.type === type;
			b.classList.toggle( 'is-active', active );
			b.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
		} );

		fieldUrl.hidden     = ! cfg.urlProp;
		fieldPhoto.hidden   = type !== 'photo';
		fieldContent.hidden = ! cfg.hasContent;
		fieldTags.hidden    = ! cfg.hasTags;

		if ( cfg.urlProp ) urlLabel.textContent = cfg.urlLabel || 'URL';
		if ( cfg.hasContent ) {
			setPrompt( ( type === 'note' ) ? notePrompt : ( cfg.contentPlaceholder || 'Write…' ) );
		}

		updateSpecimen();
		updateCounter();
		saveDraft();
		updatePostBtn();
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

	urlInput.addEventListener( 'input', function () { updateSpecimen(); updatePostBtn(); saveDraft(); } );
	contentInput.addEventListener( 'input', function () { updatePostBtn(); updateCounter(); saveDraft(); syncPrompt(); } );
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
		selectedFiles    = files.slice( 0, 10 );
		photoAlts        = [];
		thumbs.innerHTML = '';
		selectedFiles.forEach( function (file, i) {
			var cell       = document.createElement( 'figure' );
			cell.className = 'thumb';
			var img = document.createElement( 'img' );
			img.src = URL.createObjectURL( file );
			img.alt = '';
			var alt           = document.createElement( 'input' );
			alt.type          = 'text';
			alt.className     = 'thumb__alt';
			alt.placeholder   = 'Describe…';
			alt.autocomplete  = 'off';
			alt.dataset.index = i;
			alt.setAttribute( 'aria-label', 'Alt text for photo ' + ( i + 1 ) );
			cell.appendChild( img );
			cell.appendChild( alt );
			thumbs.appendChild( cell );
		} );
		picker.querySelector( 'p' ).textContent = selectedFiles.length
			? selectedFiles.length + ' photo' + ( selectedFiles.length > 1 ? 's' : '' ) + ' selected'
			: 'Add photos';
		updatePostBtn();
	}

	thumbs.addEventListener( 'input', function (e) {
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
		thumbs.innerHTML = ''; photoInput.value = '';
		picker.querySelector( 'p' ).textContent = 'Add photos';
		renderTags();
		clearDraft();
		notePrompt = NOTE_PROMPTS[ Math.floor( Math.random() * NOTE_PROMPTS.length ) ];
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
			} ) );
		} catch ( e ) {}
	}

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
		var el = document.getElementById( 'charCount' );
		if ( ! TYPE_CONFIG[ currentType ].hasContent ) { el.hidden = true; return; }
		var len = contentInput.value.length;
		var lim = currentLimit();
		el.hidden = false;
		if ( lim ) {
			// Within 50 of the limit the counter flips to a countdown.
			var left = lim - len;
			el.textContent = left <= 50 ? String( left ) : len + ' / ' + lim;
			el.classList.toggle( 'is-over', len > lim );
		} else {
			el.textContent = String( len );
			el.classList.remove( 'is-over' );
		}
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

	// ── Init ───────────────────────────────────────────────────────────────────

	setPrompt( notePrompt );
	loadDraft();
	updateCounter();
	syncPrompt();

} )();
</script>
</body>
</html>
		<?php
	}
}
