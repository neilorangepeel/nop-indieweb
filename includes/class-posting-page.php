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
		$site_name    = esc_html( get_bloginfo( 'name' ) );
		$icon_url     = esc_url( get_site_icon_url( 192 ) );
		$font_dir     = esc_url( get_theme_file_uri( 'assets/fonts/brandon-text' ) );
		$cond_dir     = esc_url( get_theme_file_uri( 'assets/fonts/brandon-text-condensed' ) );

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
<meta name="theme-color" content="#141414" media="(prefers-color-scheme: dark)">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
<?php if ( $icon_url ) : ?>
<link rel="apple-touch-icon" href="<?php echo $icon_url; ?>">
<?php endif; ?>
<link rel="preload" href="<?php echo esc_url( $font_dir . '/brandon-text_normal_400.woff2' ); ?>" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?php echo esc_url( $cond_dir . '/brandon-text-condensed_normal_800.woff2' ); ?>" as="font" type="font/woff2" crossorigin>
<title><?php echo $site_name; ?></title>
<style>
<?php
foreach ( [ '400', '500', '700', '800' ] as $weight ) {
	printf(
		'@font-face{font-family:"Brandon Text";font-weight:%1$s;font-style:normal;font-display:swap;src:url("%2$s/brandon-text_normal_%1$s.woff2") format("woff2")}' . "\n",
		$weight, $font_dir
	);
}
foreach ( [ '700', '800' ] as $weight ) {
	printf(
		'@font-face{font-family:"Brandon Text Condensed";font-weight:%1$s;font-style:normal;font-display:swap;src:url("%2$s/brandon-text-condensed_normal_%1$s.woff2") format("woff2")}' . "\n",
		$weight, $cond_dir
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
:root {
	--paper:  #F4EFE6;
	--ink:    #141414;
	--red:    #E63329;
	--blue:   #1E4FD6;
	--yellow: #FFC400;

	--field: var(--paper);
	--line:  var(--ink);
	--text:  var(--ink);

	--accent:    var(--yellow);
	--on-accent: var(--ink);

	--display: 'Brandon Text Condensed', 'Brandon Text', -apple-system, BlinkMacSystemFont, sans-serif;

	--radius: 2px;
	--shadow: 4px 4px 0 var(--line);

	--safe-top:    env(safe-area-inset-top, 0px);
	--safe-bottom: env(safe-area-inset-bottom, 0px);
}

@media (prefers-color-scheme: dark) {
	:root {
		--field: var(--ink);
		--line:  var(--paper);
		--text:  var(--paper);
	}
}

/* Per-type accent — selecting a tile sets data-type on .app. */
.app[data-type="note"],
.app[data-type="bookmark"]               { --accent: var(--yellow); --on-accent: var(--ink); }
.app[data-type="photo"],
.app[data-type="repost"],
.app[data-type="rsvp"]                   { --accent: var(--blue);   --on-accent: var(--paper); }
.app[data-type="reply"],
.app[data-type="like"]                   { --accent: var(--red);    --on-accent: var(--paper); }
.app[data-type="article"]                { --accent: var(--ink);    --on-accent: var(--paper); }

/* Ink accent is invisible on an ink field — flip it to paper in the dark. */
@media (prefers-color-scheme: dark) {
	.app[data-type="article"]            { --accent: var(--paper);  --on-accent: var(--ink); }
}

html {
	height: 100%;
	height: -webkit-fill-available;
}
body {
	height: 100%;
	min-height: -webkit-fill-available;
	overflow: hidden;
	background: var(--field);
	color: var(--text);
	font-family: 'Brandon Text', -apple-system, BlinkMacSystemFont, sans-serif;
	-webkit-font-smoothing: antialiased;
}

/* ── App shell ──────────────────────────────────────────────────────────── */

.app {
	display: flex;
	flex-direction: column;
	height: 100vh;
	height: 100dvh;
	overflow: hidden;
	max-width: 480px;
	margin: 0 auto;
	background: var(--field);
	border-left: 2px solid var(--line);
	border-right: 2px solid var(--line);
}

/* On desktop the poster floats: centre it, cap the height, and ring it
   with the full 2px ink frame. */
@media (min-width: 600px) {
	body {
		display: flex;
		align-items: center;
		justify-content: center;
	}
	.app {
		height: calc(100dvh - 48px);
		max-height: 880px;
		width: 100%;
		border: 2px solid var(--line);
		box-shadow: var(--shadow);
	}
}

/* ── Masthead ───────────────────────────────────────────────────────────── */

.masthead {
	flex-shrink: 0;
	padding: 12px 16px 0;
	padding-top: calc(var(--safe-top) + 12px);
	border-bottom: 2px solid var(--line);
}
.masthead__top {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
}
.brand { display: flex; align-items: center; gap: 9px; }
.brand__mark { display: block; flex-shrink: 0; }
.brand__word {
	font-family: var(--display);
	font-size: 32px;
	font-weight: 800;
	letter-spacing: 0.01em;
	line-height: 0.9;
	text-transform: uppercase;
}
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

/* Sun / moon arc — the time-of-day device. The dashed arc is drawn by an
   SVG stretched to the band (preserveAspectRatio:none — distorting a dashed
   line is invisible); the celestial body is a CSS disc positioned by JS along
   the same quadratic. Yellow sun by day, cut-paper moon by night. */
.sky {
	position: relative;
	height: 38px;
	margin-top: 6px;
}
.sky__line {
	position: absolute;
	inset: 0;
	width: 100%;
	height: 100%;
	color: var(--line);
	opacity: 0.32;
}
.sky__body {
	position: absolute;
	width: 16px;
	height: 16px;
	border-radius: 50%;
	transform: translate(-50%, -50%);
	transition: left 0.6s ease, top 0.6s ease;
}
.sky__body.is-sun {
	background: var(--yellow);
	border: 2px solid var(--line);
}
.sky__body.is-moon {
	background: var(--line);
	overflow: hidden;
}
.sky__body.is-moon::after {
	content: '';
	position: absolute;
	top: -32%;
	right: -28%;
	width: 78%;
	height: 78%;
	border-radius: 50%;
	background: var(--field);
}

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
	grid-template-columns: repeat(4, 1fr);
	gap: 6px;
	flex-shrink: 0;
	padding: 12px;
	border-bottom: 2px solid var(--line);
}
.type-btn {
	position: relative;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 4px;
	padding: 9px 4px 7px;
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
	width: 22px;
	height: 22px;
}
.type-btn__dot {
	position: absolute;
	top: 4px;
	right: 4px;
	width: 7px;
	height: 7px;
	border-radius: 50%;
}
.type-btn[data-type="note"]     .type-btn__dot,
.type-btn[data-type="bookmark"] .type-btn__dot { background: var(--yellow); }
.type-btn[data-type="photo"]    .type-btn__dot,
.type-btn[data-type="repost"]   .type-btn__dot,
.type-btn[data-type="rsvp"]     .type-btn__dot { background: var(--blue); }
.type-btn[data-type="reply"]    .type-btn__dot,
.type-btn[data-type="like"]     .type-btn__dot { background: var(--red); }
.type-btn[data-type="article"]  .type-btn__dot { background: var(--line); }

.type-btn.is-active {
	background: var(--accent);
	color: var(--on-accent);
	border-color: var(--line);
	animation: type-pop 0.22s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.type-btn.is-active .type-btn__dot { display: none; }
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
	font-size: 13px;
	font-weight: 800;
	text-transform: uppercase;
	letter-spacing: 0.1em;
	margin-bottom: 7px;
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

.text-field {
	width: 100%;
	background: var(--field);
	border: 2px solid var(--line);
	border-radius: var(--radius);
	padding: 12px 14px;
	font-size: 16px;
	font-family: inherit;
	font-weight: 500;
	color: var(--text);
	outline: none;
}
.text-field:focus { outline: 3px solid var(--accent); outline-offset: -1px; }
.text-field::placeholder { color: var(--text); opacity: 0.45; }

/* Compose textarea is the hero. */
.compose-field {
	width: 100%;
	min-height: 132px;
	background: var(--field);
	border: 2px solid var(--line);
	border-radius: var(--radius);
	padding: 14px;
	font-size: 18px;
	line-height: 1.4;
	font-family: inherit;
	font-weight: 500;
	color: var(--text);
	resize: none;
	outline: none;
}
.compose-field:focus { outline: 3px solid var(--accent); outline-offset: -1px; }
.compose-field::placeholder { color: var(--text); opacity: 0.45; }

/* RSVP segmented control — bold blocks. */
.segmented {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 6px;
}
.seg {
	padding: 12px 4px;
	border: 2px solid var(--line);
	border-radius: var(--radius);
	background: var(--field);
	color: var(--text);
	font-size: 12px;
	font-weight: 800;
	font-family: var(--display);
	text-transform: uppercase;
	letter-spacing: 0.06em;
	cursor: pointer;
	-webkit-tap-highlight-color: transparent;
}
.seg.is-active { background: var(--accent); color: var(--on-accent); }
.seg:active { transform: translate(1px, 1px); }

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
	grid-template-columns: repeat(auto-fill, minmax(72px, 1fr));
	gap: 6px;
	margin-top: 10px;
}
.thumbnails img {
	width: 100%; aspect-ratio: 1; object-fit: cover;
	border: 2px solid var(--line);
	border-radius: var(--radius); display: block;
}

/* Tags — solid accent chips, knockout text, square remove. */
.tags-field {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
	background: var(--field);
	border: 2px solid var(--line);
	border-radius: var(--radius);
	padding: 8px 10px;
	min-height: 46px;
	cursor: text;
}
.tags-field:focus-within { outline: 3px solid var(--accent); outline-offset: -1px; }
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

/* Syndicate-to */
.syndicate-details {
	border: 2px solid var(--line);
	border-radius: var(--radius);
}
.syndicate-summary {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 11px 14px;
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
.syndicators {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 12px 14px 14px;
	border-top: 2px solid var(--line);
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
.syndicator-item input[type="checkbox"] {
	width: 18px; height: 18px;
	cursor: pointer;
	accent-color: var(--accent);
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
	transition: transform 0.08s, box-shadow 0.08s;
}
.btn-primary:active {
	transform: translate(4px, 4px);
	box-shadow: 0 0 0 var(--line);
}
.btn-primary:disabled {
	opacity: 0.32;
	cursor: default;
	box-shadow: none;
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
.success-banner {
	display: flex;
	align-items: center;
	gap: 12px;
	background: var(--accent);
	color: var(--on-accent);
	border: 2px solid var(--line);
	border-radius: var(--radius);
	padding: 18px 16px;
	margin-bottom: 16px;
	box-shadow: var(--shadow);
	animation: pop-in 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
}
@keyframes pop-in {
	0%   { transform: scale(0.92); opacity: 0; }
	60%  { transform: scale(1.02); }
	100% { transform: scale(1); opacity: 1; }
}
.success-check { flex-shrink: 0; display: flex; }
.success-banner h2 {
	font-family: var(--display);
	font-size: 34px;
	font-weight: 800;
	letter-spacing: 0.01em;
	text-transform: uppercase;
	line-height: 1;
}
.success-streak { font-size: 14px; font-weight: 700; margin-bottom: 16px; }
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
	.type-btn.is-active,
	.success-banner { animation: none; }
	.field-group.is-conditional:not([hidden]) { animation: none; }
	.sky__body { transition: none; }
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
				<svg class="brand__mark" width="30" height="30" viewBox="0 0 30 30" aria-hidden="true">
					<circle cx="8" cy="8" r="7" fill="var(--yellow)"/>
					<path d="M14 1 L29 1 L21.5 14 Z" fill="var(--red)"/>
					<rect x="15" y="15" width="14" height="14" fill="var(--blue)"/>
				</svg>
				<span class="brand__word"><?php esc_html_e( 'Post', 'nop-indieweb' ); ?></span>
			</div>
			<div class="clock" aria-hidden="true">
				<p class="clock__time" id="clockTime">00:00</p>
				<p class="clock__date" id="clockDate">Mon 1 Jan</p>
			</div>
		</div>
		<div class="sky" aria-hidden="true">
			<svg class="sky__line" viewBox="0 0 100 22" preserveAspectRatio="none">
				<path d="M2 18 Q50 2 98 18" fill="none" stroke="currentColor" stroke-width="0.7" stroke-dasharray="2 4" stroke-linecap="round"/>
			</svg>
			<span class="sky__body is-sun" id="skyBody"></span>
		</div>
	</header>

	<!-- View container -->
	<div class="view-container">

		<!-- Compose view -->
		<div id="view-compose">
			<p class="greeting" id="greeting"></p>

			<div class="type-grid" id="typeBar" role="group" aria-label="<?php esc_attr_e( 'Post type', 'nop-indieweb' ); ?>">
				<button class="type-btn is-active" data-type="note" aria-pressed="true" type="button">
					<span class="type-btn__dot" aria-hidden="true"></span>
					<span class="type-btn__icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg></span>
					<span><?php esc_html_e( 'Note', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="photo" aria-pressed="false" type="button">
					<span class="type-btn__dot" aria-hidden="true"></span>
					<span class="type-btn__icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2Z"/><circle cx="12" cy="13" r="4"/></svg></span>
					<span><?php esc_html_e( 'Photo', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="reply" aria-pressed="false" type="button">
					<span class="type-btn__dot" aria-hidden="true"></span>
					<span class="type-btn__icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 10 4 15 9 20"/><path d="M20 4v7a4 4 0 0 1-4 4H4"/></svg></span>
					<span><?php esc_html_e( 'Reply', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="like" aria-pressed="false" type="button">
					<span class="type-btn__dot" aria-hidden="true"></span>
					<span class="type-btn__icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54Z"/></svg></span>
					<span><?php esc_html_e( 'Like', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="bookmark" aria-pressed="false" type="button">
					<span class="type-btn__dot" aria-hidden="true"></span>
					<span class="type-btn__icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2Z"/></svg></span>
					<span><?php esc_html_e( 'Bookmark', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="repost" aria-pressed="false" type="button">
					<span class="type-btn__dot" aria-hidden="true"></span>
					<span class="type-btn__icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg></span>
					<span><?php esc_html_e( 'Repost', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="article" aria-pressed="false" type="button">
					<span class="type-btn__dot" aria-hidden="true"></span>
					<span class="type-btn__icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/></svg></span>
					<span><?php esc_html_e( 'Article', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="rsvp" aria-pressed="false" type="button">
					<span class="type-btn__dot" aria-hidden="true"></span>
					<span class="type-btn__icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="9 15 11 16.5 15 13"/></svg></span>
					<span><?php esc_html_e( 'RSVP', 'nop-indieweb' ); ?></span>
				</button>
			</div><!-- .type-grid -->

			<div class="compose-scroll">

				<!-- URL field (reply, like, bookmark, repost, rsvp) -->
				<div class="field-group is-conditional" id="fieldUrl" hidden>
					<label class="field-label" id="urlLabel" for="typeUrl"><?php esc_html_e( 'URL', 'nop-indieweb' ); ?></label>
					<input type="url" id="typeUrl" class="text-field" placeholder="https://…" autocomplete="off">
				</div>

				<!-- Title field (article) -->
				<div class="field-group is-conditional" id="fieldTitle" hidden>
					<label class="field-label" for="titleInput"><?php esc_html_e( 'Title', 'nop-indieweb' ); ?></label>
					<input type="text" id="titleInput" class="text-field" placeholder="<?php esc_attr_e( 'Article title…', 'nop-indieweb' ); ?>" autocomplete="off">
				</div>

				<!-- RSVP segmented control (rsvp) -->
				<div class="field-group is-conditional" id="fieldRsvp" hidden>
					<span class="field-label"><?php esc_html_e( 'RSVP', 'nop-indieweb' ); ?></span>
					<div class="segmented" id="rsvpControl" role="group" aria-label="<?php esc_attr_e( 'RSVP response', 'nop-indieweb' ); ?>">
						<button class="seg" data-rsvp="yes" aria-pressed="false" type="button"><?php esc_html_e( 'Yes', 'nop-indieweb' ); ?></button>
						<button class="seg" data-rsvp="no" aria-pressed="false" type="button"><?php esc_html_e( 'No', 'nop-indieweb' ); ?></button>
						<button class="seg" data-rsvp="maybe" aria-pressed="false" type="button"><?php esc_html_e( 'Maybe', 'nop-indieweb' ); ?></button>
						<button class="seg" data-rsvp="interested" aria-pressed="false" type="button"><?php esc_html_e( 'Keen', 'nop-indieweb' ); ?></button>
					</div>
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
					<textarea
						class="compose-field"
						id="content"
						placeholder="<?php esc_attr_e( 'Write a note…', 'nop-indieweb' ); ?>"
						rows="4"
					></textarea>
					<div class="char-count" id="charCount" aria-live="polite" hidden></div>
				</div>

				<!-- Tags (note, photo, article) -->
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
				<div class="success-banner">
					<span class="success-check" aria-hidden="true"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
					<h2><?php esc_html_e( 'Posted', 'nop-indieweb' ); ?></h2>
				</div>
				<p class="success-streak" id="successStreak" hidden></p>
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

	// Quadratic arc M2,18 Q50,2 98,18 in the 100×22 viewBox the dashed line uses.
	// The body rides the same curve; left/top are expressed as % of the band so a
	// CSS disc stays round while the SVG line stretches to full width.
	function positionSky( now ) {
		var frac = ( now.getHours() + now.getMinutes() / 60 ) / 24;
		var u  = frac;
		var mu = 1 - u;
		var x  = mu * mu * 2  + 2 * mu * u * 50 + u * u * 98;
		var y  = mu * mu * 18 + 2 * mu * u * 2  + u * u * 18;
		skyBody.style.left = x + '%';
		skyBody.style.top  = ( y / 22 * 100 ) + '%';
		var daytime = now.getHours() >= 6 && now.getHours() < 18;
		skyBody.classList.toggle( 'is-sun', daytime );
		skyBody.classList.toggle( 'is-moon', ! daytime );
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
			positionSky( now );
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
		like:     { urlProp: 'like-of',      hasContent: false, hasTags: false, urlLabel: 'Like URL' },
		bookmark: { urlProp: 'bookmark-of',  hasContent: true,  hasTags: false, urlLabel: 'Bookmark URL', contentPlaceholder: 'Notes…' },
		repost:   { urlProp: 'repost-of',    hasContent: false, hasTags: false, urlLabel: 'Repost URL' },
		article:  { urlProp: null,           hasContent: true,  hasTags: true,  hasTitle: true, contentPlaceholder: 'Write your article…' },
		rsvp:     { urlProp: 'in-reply-to',  hasContent: true,  hasTags: false, hasRsvp: true, urlLabel: 'Event URL', contentPlaceholder: 'Add a note (optional)…' },
	};

	var currentType   = 'note';
	var selectedFiles = [];
	var currentTags   = [];
	var selectedRsvp  = '';

	// ── DOM refs ──────────────────────────────────────────────────────────────

	var postBtn      = document.getElementById( 'postBtn' );
	var fieldUrl     = document.getElementById( 'fieldUrl' );
	var fieldTitle   = document.getElementById( 'fieldTitle' );
	var fieldRsvp    = document.getElementById( 'fieldRsvp' );
	var fieldPhoto   = document.getElementById( 'fieldPhoto' );
	var fieldContent = document.getElementById( 'fieldContent' );
	var fieldTags    = document.getElementById( 'fieldTags' );
	var urlInput     = document.getElementById( 'typeUrl' );
	var urlLabel     = document.getElementById( 'urlLabel' );
	var titleInput   = document.getElementById( 'titleInput' );
	var contentInput = document.getElementById( 'content' );
	var picker       = document.getElementById( 'photoPicker' );
	var photoInput   = document.getElementById( 'photoInput' );
	var thumbs       = document.getElementById( 'thumbnails' );
	var rsvpControl  = document.getElementById( 'rsvpControl' );

	// ── Syndicators ───────────────────────────────────────────────────────────
	// Targets are inlined server-side (NOP.syndicateTo) — no fetch needed.

	(function renderSyndicators() {
		var synTo = NOP.syndicateTo || [];
		if ( ! synTo.length ) return;
		document.getElementById( 'syndicators' ).innerHTML = synTo.map( function (s) {
			var limit = CHAR_LIMITS[ s.uid ];
			return '<label class="syndicator-item">'
				+ '<input type="checkbox" value="' + escAttr( s.uid ) + '" checked>'
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
		fieldTitle.hidden   = ! cfg.hasTitle;
		fieldRsvp.hidden    = ! cfg.hasRsvp;
		fieldPhoto.hidden   = type !== 'photo';
		fieldContent.hidden = ! cfg.hasContent;
		fieldTags.hidden    = ! cfg.hasTags;

		if ( cfg.urlProp ) urlLabel.textContent = cfg.urlLabel || 'URL';
		if ( cfg.hasContent ) {
			contentInput.placeholder = ( type === 'note' ) ? notePrompt : ( cfg.contentPlaceholder || 'Write…' );
		}

		updateCounter();
		saveDraft();
		updatePostBtn();
	}

	// ── RSVP segmented control ──────────────────────────────────────────────────

	rsvpControl.addEventListener( 'click', function (e) {
		var btn = e.target.closest( '.seg' );
		if ( ! btn ) return;
		selectedRsvp = btn.dataset.rsvp;
		document.querySelectorAll( '.seg' ).forEach( function (b) {
			var active = b === btn;
			b.classList.toggle( 'is-active', active );
			b.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
		} );
		updatePostBtn();
		saveDraft();
	} );

	// ── Post button state ─────────────────────────────────────────────────────

	function updatePostBtn() {
		var cfg     = TYPE_CONFIG[ currentType ];
		var enabled = false;
		if ( currentType === 'photo' ) {
			enabled = selectedFiles.length > 0;
		} else if ( currentType === 'rsvp' ) {
			enabled = !! selectedRsvp;
		} else if ( currentType === 'article' ) {
			enabled = contentInput.value.trim().length > 0 || titleInput.value.trim().length > 0;
		} else if ( cfg.urlProp ) {
			enabled = urlInput.value.trim().length > 0;
		} else {
			enabled = contentInput.value.trim().length > 0;
		}
		postBtn.disabled = ! enabled;
	}

	urlInput.addEventListener( 'input', function () { updatePostBtn(); saveDraft(); } );
	titleInput.addEventListener( 'input', function () { updatePostBtn(); saveDraft(); } );
	contentInput.addEventListener( 'input', function () { updatePostBtn(); updateCounter(); saveDraft(); } );
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
		thumbs.innerHTML = '';
		selectedFiles.forEach( function (file) {
			var img = document.createElement( 'img' );
			img.src = URL.createObjectURL( file );
			img.alt = '';
			thumbs.appendChild( img );
		} );
		picker.querySelector( 'p' ).textContent = selectedFiles.length
			? selectedFiles.length + ' photo' + ( selectedFiles.length > 1 ? 's' : '' ) + ' selected'
			: 'Add photos';
		updatePostBtn();
	}

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

		var content = contentInput.value.trim();
		if ( content && cfg.hasContent ) props.content = [ content ];

		if ( cfg.hasTitle ) {
			var title = titleInput.value.trim();
			if ( title ) props.name = [ title ];
		}

		if ( cfg.urlProp ) {
			var url = urlInput.value.trim();
			if ( url ) props[ cfg.urlProp ] = [ url ];
		}

		if ( cfg.hasRsvp && selectedRsvp ) props.rsvp = [ selectedRsvp ];

		if ( photoUrls && photoUrls.length ) props.photo = photoUrls;
		if ( cfg.hasTags && currentTags.length ) props.category = currentTags.slice();

		var synTo = Array.from(
			document.querySelectorAll( '#syndicators input[type="checkbox"]:checked' )
		).map( function (cb) { return cb.value; } );
		if ( synTo.length ) props[ 'syndicate-to' ] = synTo;

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
			streakEl.textContent = 'Your ' + ordinal( count ) + ' post today.';
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
		selectedFiles = []; currentTags = []; selectedRsvp = '';
		contentInput.value = ''; urlInput.value = ''; titleInput.value = '';
		thumbs.innerHTML = ''; photoInput.value = '';
		picker.querySelector( 'p' ).textContent = 'Add photos';
		document.querySelectorAll( '.seg' ).forEach( function (b) {
			b.classList.remove( 'is-active' );
			b.setAttribute( 'aria-pressed', 'false' );
		} );
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
				title:   titleInput.value,
				rsvp:    selectedRsvp,
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
		if ( typeof d.title === 'string' ) titleInput.value = d.title;
		if ( typeof d.rsvp === 'string' && d.rsvp ) {
			var seg = rsvpControl.querySelector( '.seg[data-rsvp="' + d.rsvp + '"]' );
			if ( seg ) {
				selectedRsvp = d.rsvp;
				seg.classList.add( 'is-active' );
				seg.setAttribute( 'aria-pressed', 'true' );
			}
		}
		if ( Array.isArray( d.tags ) ) { currentTags = d.tags.slice(); renderTags(); }
		restoring = false;
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
			el.textContent = len + ' / ' + lim;
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

	contentInput.placeholder = notePrompt;
	loadDraft();
	updateCounter();

} )();
</script>
</body>
</html>
		<?php
	}
}
