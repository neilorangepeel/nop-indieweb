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
 * six post types — Note, Photo, Reply, Like, Bookmark, Repost — and routes
 * all posts through the Micropub endpoint using WordPress cookie + nonce auth.
 * After posting the success screen links to both the published permalink and
 * the WordPress block editor.
 */
class Posting_Page {

	private const QUERY_VAR = 'nop_post_page';
	private const REWRITE   = '^post/?$';

	public function register(): void {
		add_action( 'init',              [ $this, 'add_rewrite_rule' ] );
		add_filter( 'query_vars',        [ $this, 'add_query_var' ] );
		add_action( 'template_redirect', [ $this, 'maybe_render' ] );
	}

	public function add_rewrite_rule(): void {
		add_rewrite_rule( self::REWRITE, 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	public function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public function maybe_render(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			wp_redirect( wp_login_url( home_url( '/post' ) ) );
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
		$nonce        = wp_create_nonce( 'wp_rest' );
		$media_url    = esc_url( rest_url( 'wp/v2/media' ) );
		$micropub_url = esc_url( rest_url( 'nop-indieweb/v1/micropub' ) );
		$site_name    = esc_html( get_bloginfo( 'name' ) );
		$icon_url     = esc_url( get_site_icon_url( 192 ) );
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?php echo $site_name; ?>">
<?php if ( $icon_url ) : ?>
<link rel="apple-touch-icon" href="<?php echo $icon_url; ?>">
<?php endif; ?>
<title><?php echo $site_name; ?></title>
<style>
<?php
$font_dir = esc_url( get_theme_file_uri( 'assets/fonts/brandon-text' ) );
foreach ( [ '400' => 'normal', '500' => 'normal', '700' => 'normal' ] as $weight => $style ) {
	printf(
		'@font-face{font-family:"Brandon Text";font-weight:%s;font-style:%s;font-display:swap;src:url("%s/brandon-text_normal_%s.woff2") format("woff2")}' . "\n",
		$weight, $style, $font_dir, $weight
	);
}
?>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
[hidden] { display: none !important; }

:root {
	--bg:        #ffffff;
	--surface:   #f7f7f7;
	--text:      #111111;
	--text-2:    #686868;
	--accent:    #503AA8;
	--accent-bg: #FFEE5826;
	--highlight: #FFEE58;
	--border:    #e8e8e8;
	--radius:    2px;
	--safe-top:    env(safe-area-inset-top, 0px);
	--safe-bottom: env(safe-area-inset-bottom, 0px);
}

html {
	height: 100%;
	height: -webkit-fill-available;
}
body {
	height: 100%;
	min-height: -webkit-fill-available;
	overflow: hidden;
	background: var(--bg);
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
	border-left: 1px solid var(--border);
	border-right: 1px solid var(--border);
}

/* ── Header ─────────────────────────────────────────────────────────────── */

.app-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 10px 16px;
	padding-top: calc(var(--safe-top) + 10px);
	border-bottom: 1px solid var(--border);
	flex-shrink: 0;
	background: var(--bg);
}
.app-header__left { display: flex; flex-direction: column; gap: 1px; }
.app-title {
	font-size: 17px;
	font-weight: 700;
	letter-spacing: -0.2px;
	line-height: 1.1;
}
.app-site {
	font-size: 11px;
	color: var(--text-2);
	letter-spacing: 0.01em;
}
.app-clock {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: 1px;
}
.app-clock__time {
	font-size: 22px;
	font-weight: 700;
	letter-spacing: -0.5px;
	line-height: 1;
	font-variant-numeric: tabular-nums;
	font-feature-settings: "tnum";
}
.app-clock__date {
	font-size: 11px;
	color: var(--text-2);
	letter-spacing: 0.01em;
}

/* ── View container ──────────────────────────────────────────────────────── */

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

/* ── Type bar ────────────────────────────────────────────────────────────── */

.type-bar {
	display: flex;
	flex-shrink: 0;
	gap: 6px;
	padding: 10px 12px;
	border-bottom: 1px solid var(--border);
	background: var(--bg);
}
.type-btn {
	display: flex;
	flex: 1;
	flex-direction: column;
	align-items: center;
	gap: 3px;
	padding: 8px 4px;
	border: 1px solid var(--border);
	border-radius: var(--radius);
	background: var(--bg);
	font-size: 10px;
	font-weight: 700;
	font-family: inherit;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	cursor: pointer;
	color: var(--text-2);
	-webkit-tap-highlight-color: transparent;
	transition: background 0.1s, border-color 0.1s, color 0.1s;
}
.type-btn__icon {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 18px;
	height: 18px;
}
.type-btn.is-active {
	background: var(--highlight);
	border-color: var(--highlight);
	color: var(--text);
	animation: type-pop 0.22s cubic-bezier(0.34, 1.56, 0.64, 1);
}
@keyframes type-pop {
	0%   { transform: scale(1); }
	50%  { transform: scale(1.1); }
	100% { transform: scale(1); }
}
.type-btn:active { opacity: 0.65; }

/* ── Compose scroll area ─────────────────────────────────────────────────── */

.compose-scroll {
	flex: 1;
	overflow-y: auto;
	-webkit-overflow-scrolling: touch;
	overscroll-behavior: contain;
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

/* ── Bottom bar ──────────────────────────────────────────────────────────── */

.bottom-bar {
	flex-shrink: 0;
	padding: 10px 16px;
	padding-bottom: calc(var(--safe-bottom) + 10px);
	border-top: 1px solid var(--border);
	background: var(--bg);
}

/* ── Fields ──────────────────────────────────────────────────────────────── */

.field-label {
	display: block;
	font-size: 11px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	color: var(--text-2);
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
.field-group { display: flex; flex-direction: column; }

.url-field {
	width: 100%;
	background: var(--bg);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	padding: 12px 14px;
	font-size: 16px;
	font-family: inherit;
	color: var(--text);
	outline: none;
}
.url-field:focus { border-color: var(--text); outline: 2px solid var(--highlight); outline-offset: -1px; }
.url-field::placeholder { color: var(--text-2); }

/* Photo picker */
.photo-picker {
	background: var(--surface);
	border: 2px dashed var(--border);
	border-radius: var(--radius);
	padding: 24px 16px;
	text-align: center;
	cursor: pointer;
	transition: border-color 0.15s, background 0.15s;
	-webkit-tap-highlight-color: transparent;
}
.photo-picker:active,
.photo-picker.drag-over { border-color: var(--highlight); background: var(--accent-bg); }
.photo-picker input[type="file"] { display: none; }
.photo-picker-icon { font-size: 32px; margin-bottom: 6px; display: block; }
.photo-picker p { font-size: 15px; font-weight: 500; }
.photo-picker small { font-size: 12px; color: var(--text-2); display: block; margin-top: 3px; }

.thumbnails {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(72px, 1fr));
	gap: 5px;
	margin-top: 8px;
}
.thumbnails img {
	width: 100%; aspect-ratio: 1; object-fit: cover;
	border-radius: var(--radius); display: block;
}

.caption-field {
	width: 100%;
	background: var(--bg);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	padding: 12px 14px;
	font-size: 16px;
	font-family: inherit;
	color: var(--text);
	resize: none;
	min-height: 100px;
	outline: none;
}
.caption-field:focus { border-color: var(--text); outline: 2px solid var(--highlight); outline-offset: -1px; }
.caption-field::placeholder { color: var(--text-2); }

/* Tags */
.tags-field {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
	background: var(--bg);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	padding: 8px 10px;
	min-height: 44px;
	cursor: text;
}
.tags-field:focus-within { border-color: var(--text); outline: 2px solid var(--highlight); outline-offset: -1px; }
.tag-chip {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	background: var(--highlight);
	color: var(--text);
	border-radius: var(--radius);
	padding: 3px 8px;
	font-size: 13px;
	font-weight: 700;
	white-space: nowrap;
}
.tag-chip__remove {
	background: none; border: none; padding: 0;
	cursor: pointer; font-size: 15px; line-height: 1;
	color: var(--text); opacity: 0.55; font-family: inherit;
}
.tag-chip__remove:hover { opacity: 1; }
.tag-input {
	flex: 1;
	min-width: 80px;
	border: none; outline: none;
	font-size: 16px;
	font-family: inherit;
	color: var(--text);
	background: transparent;
	padding: 2px 0;
}
.tag-input::placeholder { color: var(--text-2); }

/* Syndicators */
.syndicate-details {
	border: 1px solid var(--border);
	border-radius: var(--radius);
}
.syndicate-summary {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 10px 14px;
	font-size: 11px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	color: var(--text-2);
	cursor: pointer;
	list-style: none;
	-webkit-tap-highlight-color: transparent;
	user-select: none;
}
.syndicate-summary::-webkit-details-marker { display: none; }
.syndicate-summary::after {
	content: '›';
	font-size: 18px; line-height: 1;
	display: inline-block;
	transition: transform 0.15s;
}
details[open] .syndicate-summary::after { transform: rotate(90deg); }
.syndicators {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	padding: 10px 14px 14px;
	border-top: 1px solid var(--border);
}
.syndicator-item {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 13px;
	font-weight: 500;
	color: var(--text-2);
	cursor: pointer;
	user-select: none;
}
.syndicator-item input[type="checkbox"] { cursor: pointer; accent-color: var(--text); }

/* ── Buttons ──────────────────────────────────────────────────────────────── */

.btn {
	display: block;
	width: 100%;
	padding: 15px;
	border: none;
	border-radius: var(--radius);
	font-size: 17px;
	font-weight: 700;
	font-family: inherit;
	cursor: pointer;
	transition: opacity 0.1s, transform 0.1s;
	-webkit-tap-highlight-color: transparent;
	text-align: center;
	text-decoration: none;
}
.btn:active { opacity: 0.8; transform: scale(0.98); }
.btn:disabled { opacity: 0.3; cursor: default; transform: none; }
.btn-primary  { background: var(--text); color: #ffffff; }
.btn-secondary { background: var(--bg); color: var(--text); border: 1px solid var(--border); }
.btn-accent { background: var(--bg); color: var(--accent); border: 1px solid var(--border); font-weight: 700; }
.btn-instagram {
	background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888);
	color: #fff;
	margin-top: 8px;
}

/* ── Progress view ────────────────────────────────────────────────────────── */

.progress-view {
	flex: 1;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 20px;
	text-align: center;
	padding: 24px;
}
.progress-spinner {
	width: 44px; height: 44px;
	border: 3px solid var(--border);
	border-top-color: var(--text);
	border-radius: 50%;
	animation: spin 0.8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
.progress-status { font-size: 15px; color: var(--text-2); }
.progress-bar-track {
	width: 180px; height: 3px;
	background: var(--border);
	border-radius: 2px;
	overflow: hidden;
}
.progress-bar-fill {
	height: 100%;
	background: var(--highlight);
	border-radius: 2px;
	width: 0%;
	transition: width 0.3s;
}

/* ── Success view ─────────────────────────────────────────────────────────── */

.success-scroll {
	flex: 1;
	overflow-y: auto;
	-webkit-overflow-scrolling: touch;
	padding: 20px 16px 8px;
}
.success-header {
	display: flex;
	align-items: center;
	gap: 10px;
	margin-bottom: 16px;
}
.success-check {
	width: 30px; height: 30px;
	background: var(--highlight);
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--text);
	font-size: 16px;
	flex-shrink: 0;
}
.success-header h2 { font-size: 20px; font-weight: 700; }
.success-photos {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(88px, 1fr));
	gap: 5px;
	margin-bottom: 16px;
}
.success-photos img {
	width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: var(--radius);
}
.success-permalink {
	font-size: 13px;
	color: var(--accent);
	text-decoration: underline;
	display: block;
	margin-bottom: 16px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.success-actions { display: flex; flex-direction: column; gap: 8px; }

@media (prefers-reduced-motion: reduce) {
	.type-btn.is-active { animation: none; }
	.btn:active { transform: none; }
}
</style>
</head>
<body>
<div class="app" id="app">

	<!-- Always-visible header -->
	<header class="app-header">
		<div class="app-header__left">
			<p class="app-title"><?php esc_html_e( 'Quick Post', 'nop-indieweb' ); ?></p>
			<p class="app-site"><?php echo $site_name; ?></p>
		</div>
		<div class="app-clock" aria-hidden="true">
			<p class="app-clock__time" id="clockTime">00:00</p>
			<p class="app-clock__date" id="clockDate">Mon 1 Jan</p>
		</div>
	</header>

	<!-- View container -->
	<div class="view-container">

		<!-- Compose view -->
		<div id="view-compose">
			<div class="type-bar" id="typeBar" role="group" aria-label="<?php esc_attr_e( 'Post type', 'nop-indieweb' ); ?>">
				<button class="type-btn is-active" data-type="note" aria-pressed="true" type="button">
					<span class="type-btn__icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg></span>
					<span><?php esc_html_e( 'Note', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="photo" aria-pressed="false" type="button">
					<span class="type-btn__icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg></span>
					<span><?php esc_html_e( 'Photo', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="reply" aria-pressed="false" type="button">
					<span class="type-btn__icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 10 4 15 9 20"/><path d="M20 4v7a4 4 0 01-4 4H4"/></svg></span>
					<span><?php esc_html_e( 'Reply', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="like" aria-pressed="false" type="button">
					<span class="type-btn__icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg></span>
					<span><?php esc_html_e( 'Like', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="bookmark" aria-pressed="false" type="button">
					<span class="type-btn__icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/></svg></span>
					<span><?php esc_html_e( 'Bookmark', 'nop-indieweb' ); ?></span>
				</button>
				<button class="type-btn" data-type="repost" aria-pressed="false" type="button">
					<span class="type-btn__icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg></span>
					<span><?php esc_html_e( 'Repost', 'nop-indieweb' ); ?></span>
				</button>
			</div><!-- .type-bar -->

			<div class="compose-scroll">

				<!-- URL field (reply, like, bookmark, repost) -->
				<div class="field-group" id="fieldUrl" hidden>
					<label class="field-label" id="urlLabel" for="typeUrl"><?php esc_html_e( 'URL', 'nop-indieweb' ); ?></label>
					<input type="url" id="typeUrl" class="url-field" placeholder="https://…" autocomplete="off">
				</div>

				<!-- Photo picker -->
				<div class="field-group" id="fieldPhoto" hidden>
					<div class="photo-picker" id="photoPicker">
						<input type="file" id="photoInput" accept="image/*" multiple>
						<span class="photo-picker-icon" aria-hidden="true"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg></span>
						<p><?php esc_html_e( 'Add photos', 'nop-indieweb' ); ?></p>
						<small><?php esc_html_e( 'Tap to select · up to 10', 'nop-indieweb' ); ?></small>
					</div>
					<div class="thumbnails" id="thumbnails"></div>
				</div>

				<!-- Content -->
				<div class="field-group" id="fieldContent">
					<label class="sr-only" for="content"><?php esc_html_e( 'Content', 'nop-indieweb' ); ?></label>
					<textarea
						class="caption-field"
						id="content"
						placeholder="<?php esc_attr_e( 'Write a note…', 'nop-indieweb' ); ?>"
						rows="4"
					></textarea>
				</div>

				<!-- Tags (note + photo only) -->
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
				<div class="success-header">
					<div class="success-check" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></div>
					<h2><?php esc_html_e( 'Posted', 'nop-indieweb' ); ?></h2>
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

</div><!-- .app -->
<script>
(function () {
	'use strict';

	var NOP = {
		nonce:       <?php echo wp_json_encode( $nonce ); ?>,
		mediaUrl:    <?php echo wp_json_encode( $media_url ); ?>,
		micropubUrl: <?php echo wp_json_encode( $micropub_url ); ?>,
	};

	// ── Clock ─────────────────────────────────────────────────────────────────

	var DAYS   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
	var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

	var clockTimeEl = document.getElementById( 'clockTime' );
	var clockDateEl = document.getElementById( 'clockDate' );
	var lastTime = '', lastDate = '';

	function updateClock() {
		var now  = new Date();
		var time = String( now.getHours() ).padStart( 2, '0' ) + ':' + String( now.getMinutes() ).padStart( 2, '0' );
		var date = DAYS[ now.getDay() ] + ' ' + now.getDate() + ' ' + MONTHS[ now.getMonth() ];
		if ( time !== lastTime ) { clockTimeEl.textContent = time; lastTime = time; }
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
	};

	var currentType   = 'note';
	var selectedFiles = [];
	var currentTags   = [];

	// ── DOM refs ──────────────────────────────────────────────────────────────

	var postBtn      = document.getElementById( 'postBtn' );
	var fieldUrl     = document.getElementById( 'fieldUrl' );
	var fieldPhoto   = document.getElementById( 'fieldPhoto' );
	var fieldContent = document.getElementById( 'fieldContent' );
	var fieldTags    = document.getElementById( 'fieldTags' );
	var urlInput     = document.getElementById( 'typeUrl' );
	var urlLabel     = document.getElementById( 'urlLabel' );
	var contentInput = document.getElementById( 'content' );
	var picker       = document.getElementById( 'photoPicker' );
	var photoInput   = document.getElementById( 'photoInput' );
	var thumbs       = document.getElementById( 'thumbnails' );

	// ── Syndicators ───────────────────────────────────────────────────────────

	(function loadConfig() {
		fetch( NOP.micropubUrl + '?q=config', {
			headers: { 'X-WP-Nonce': NOP.nonce },
		} )
		.then( function (res) { return res.ok ? res.json() : null; } )
		.then( function (data) {
			if ( ! data ) return;
			var synTo = data['syndicate-to'] || [];
			if ( ! synTo.length ) return;
			document.getElementById( 'syndicators' ).innerHTML = synTo.map( function (s) {
				return '<label class="syndicator-item">'
					+ '<input type="checkbox" value="' + escAttr( s.uid ) + '" checked>'
					+ ' ' + escHtml( s.name )
					+ '</label>';
			} ).join( '' );
			document.getElementById( 'syndicateDetails' ).hidden = false;
		} )
		.catch( function () {} );
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
		if ( cfg.hasContent ) contentInput.placeholder = cfg.contentPlaceholder || 'Write…';

		urlInput.value     = '';
		contentInput.value = '';
		selectedFiles      = [];
		currentTags        = [];
		thumbs.innerHTML   = '';
		renderTags();
		picker.querySelector( 'p' ).textContent = 'Add photos';

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

	urlInput.addEventListener( 'input', updatePostBtn );
	contentInput.addEventListener( 'input', updatePostBtn );

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
			alert( 'Something went wrong: ' + err.message );
		}
	} );

	function buildPayload( photoUrls ) {
		var cfg   = TYPE_CONFIG[ currentType ];
		var props = {};

		var content = contentInput.value.trim();
		if ( content && cfg.hasContent ) props.content = [ content ];

		if ( cfg.urlProp ) {
			var url = urlInput.value.trim();
			if ( url ) props[ cfg.urlProp ] = [ url ];
		}

		if ( photoUrls && photoUrls.length ) props.photo = photoUrls;
		if ( currentTags.length ) props.category = currentTags.slice();

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
				catch ( e ) { if ( e.name !== 'AbortError' ) alert( 'Share from your Photos app instead.' ); }
			} else {
				alert( 'Web sharing is not supported on this browser.' );
			}
		};

		document.getElementById( 'anotherBtn' ).onclick = resetForm;
	}

	function resetForm() {
		selectedFiles = []; currentTags = [];
		contentInput.value = ''; urlInput.value = '';
		thumbs.innerHTML = ''; photoInput.value = '';
		picker.querySelector( 'p' ).textContent = 'Add photos';
		renderTags();
		switchType( 'note' );
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

} )();
</script>
</body>
</html>
		<?php
	}
}
