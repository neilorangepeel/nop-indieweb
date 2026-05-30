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
	--surface:   #ffffff;
	--text:      #111111;
	--text-2:    #686868;
	--accent:    #503AA8;
	--accent-bg: #FFEE5833;
	--highlight: #FFEE58;
	--border:    #e0e0e0;
	--danger:    #c0392b;
	--radius:    2px;
	--radius-sm: 2px;
	--safe-top:    env(safe-area-inset-top, 0px);
	--safe-bottom: env(safe-area-inset-bottom, 0px);
}

html, body {
	height: 100%;
	background: var(--bg);
	color: var(--text);
	font-family: 'Brandon Text', -apple-system, BlinkMacSystemFont, sans-serif;
	-webkit-font-smoothing: antialiased;
	overscroll-behavior: none;
}

.page {
	display: flex;
	flex-direction: column;
	min-height: 100%;
	padding-top: calc(var(--safe-top) + 16px);
	padding-bottom: calc(var(--safe-bottom) + 24px);
	padding-left: 16px;
	padding-right: 16px;
	max-width: 480px;
	margin: 0 auto;
}

/* Header */
.header {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	margin-bottom: 20px;
}
.header h1 {
	font-size: 22px;
	font-weight: 700;
	letter-spacing: -0.3px;
}
.header-site {
	font-size: 13px;
	color: var(--text-2);
}

/* Type bar */
.type-bar {
	display: flex;
	overflow-x: auto;
	gap: 8px;
	margin-bottom: 20px;
	scrollbar-width: none;
	-ms-overflow-style: none;
	-webkit-overflow-scrolling: touch;
}
.type-bar::-webkit-scrollbar { display: none; }

.type-btn {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 4px;
	padding: 10px 12px;
	border: 1px solid var(--border);
	border-radius: var(--radius);
	background: var(--surface);
	font-size: 11px;
	font-weight: 600;
	font-family: inherit;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	cursor: pointer;
	white-space: nowrap;
	color: var(--text-2);
	-webkit-tap-highlight-color: transparent;
	transition: background 0.1s, border-color 0.1s, color 0.1s;
	min-width: 56px;
}
.type-btn__icon {
	font-size: 18px;
	line-height: 1;
}
.type-btn.is-active {
	background: var(--highlight);
	border-color: var(--highlight);
	color: var(--text);
}
.type-btn:active { opacity: 0.7; }

/* Fields */
.field-label {
	display: block;
	font-size: 12px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.05em;
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
.field-group {
	margin-bottom: 12px;
}

/* URL input */
.url-field {
	width: 100%;
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	padding: 12px 14px;
	font-size: 16px;
	font-family: inherit;
	color: var(--text);
	outline: none;
	transition: border-color 0.15s;
}
.url-field:focus { border-color: var(--text); outline: 2px solid var(--highlight); outline-offset: -1px; }
.url-field::placeholder { color: var(--text-2); }

/* Photo picker */
.photo-picker {
	background: var(--surface);
	border: 2px dashed var(--border);
	border-radius: var(--radius);
	padding: 28px 20px;
	text-align: center;
	cursor: pointer;
	transition: border-color 0.15s, background 0.15s;
	-webkit-tap-highlight-color: transparent;
}
.photo-picker:active,
.photo-picker.drag-over {
	border-color: var(--highlight);
	background: var(--accent-bg);
}
.photo-picker input[type="file"] { display: none; }
.photo-picker-icon {
	font-size: 36px;
	margin-bottom: 8px;
	display: block;
}
.photo-picker p {
	font-size: 15px;
	font-weight: 500;
	color: var(--text);
}
.photo-picker small {
	font-size: 12px;
	color: var(--text-2);
	display: block;
	margin-top: 4px;
}

/* Thumbnails */
.thumbnails {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
	gap: 6px;
	margin-top: 8px;
}
.thumbnails img {
	width: 100%;
	aspect-ratio: 1;
	object-fit: cover;
	border-radius: var(--radius-sm);
	display: block;
}

/* Caption / content */
.caption-field {
	width: 100%;
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	padding: 12px 14px;
	font-size: 16px;
	font-family: inherit;
	color: var(--text);
	resize: none;
	min-height: 96px;
	outline: none;
	transition: border-color 0.15s;
}
.caption-field:focus { border-color: var(--text); outline: 2px solid var(--highlight); outline-offset: -1px; }
.caption-field::placeholder { color: var(--text-2); }

/* Syndicators */
.syndicators {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	margin-bottom: 16px;
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

/* Buttons */
.btn {
	display: block;
	width: 100%;
	padding: 16px;
	border: none;
	border-radius: var(--radius);
	font-size: 17px;
	font-weight: 700;
	font-family: inherit;
	cursor: pointer;
	transition: opacity 0.1s, transform 0.1s;
	-webkit-tap-highlight-color: transparent;
	margin-bottom: 10px;
	text-align: center;
	text-decoration: none;
}
.btn:active { opacity: 0.8; transform: scale(0.98); }
.btn:disabled { opacity: 0.35; cursor: default; transform: none; }
.btn-primary  { background: var(--text); color: #ffffff; }
.btn-secondary {
	background: var(--surface);
	color: var(--text);
	border: 1px solid var(--border);
}
.btn-accent {
	background: var(--surface);
	color: var(--accent);
	border: 1px solid var(--border);
	font-weight: 600;
}
.btn-instagram {
	background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888);
	color: #fff;
}

/* Progress */
.progress-view {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	flex: 1;
	gap: 20px;
	text-align: center;
}
.progress-spinner {
	width: 48px;
	height: 48px;
	border: 3px solid var(--border);
	border-top-color: var(--text);
	border-radius: 50%;
	animation: spin 0.8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
.progress-status {
	font-size: 16px;
	color: var(--text-2);
}
.progress-bar-track {
	width: 200px;
	height: 4px;
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

/* Success */
.success-view {
	display: flex;
	flex-direction: column;
	flex: 1;
}
.success-header {
	display: flex;
	align-items: center;
	gap: 10px;
	margin-bottom: 20px;
}
.success-check {
	width: 32px;
	height: 32px;
	background: var(--highlight);
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--text);
	font-size: 18px;
	flex-shrink: 0;
}
.success-header h2 {
	font-size: 20px;
	font-weight: 700;
}
.success-photos {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
	gap: 6px;
	margin-bottom: 16px;
}
.success-photos img {
	width: 100%;
	aspect-ratio: 1;
	object-fit: cover;
	border-radius: var(--radius-sm);
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
</style>
</head>
<body>
<div class="page" id="app">

	<!-- Compose view -->
	<div id="view-compose">
		<div class="header">
			<h1><?php esc_html_e( 'Quick Post', 'nop-indieweb' ); ?></h1>
			<span class="header-site"><?php echo $site_name; ?></span>
		</div>

		<!-- Type selector -->
		<nav class="type-bar" id="typeBar" aria-label="<?php esc_attr_e( 'Post type', 'nop-indieweb' ); ?>">
			<button class="type-btn is-active" data-type="note" aria-pressed="true" type="button">
				<span class="type-btn__icon" aria-hidden="true">📝</span>
				<span><?php esc_html_e( 'Note', 'nop-indieweb' ); ?></span>
			</button>
			<button class="type-btn" data-type="photo" aria-pressed="false" type="button">
				<span class="type-btn__icon" aria-hidden="true">📷</span>
				<span><?php esc_html_e( 'Photo', 'nop-indieweb' ); ?></span>
			</button>
			<button class="type-btn" data-type="reply" aria-pressed="false" type="button">
				<span class="type-btn__icon" aria-hidden="true">↩</span>
				<span><?php esc_html_e( 'Reply', 'nop-indieweb' ); ?></span>
			</button>
			<button class="type-btn" data-type="like" aria-pressed="false" type="button">
				<span class="type-btn__icon" aria-hidden="true">♡</span>
				<span><?php esc_html_e( 'Like', 'nop-indieweb' ); ?></span>
			</button>
			<button class="type-btn" data-type="bookmark" aria-pressed="false" type="button">
				<span class="type-btn__icon" aria-hidden="true">🔖</span>
				<span><?php esc_html_e( 'Bookmark', 'nop-indieweb' ); ?></span>
			</button>
			<button class="type-btn" data-type="repost" aria-pressed="false" type="button">
				<span class="type-btn__icon" aria-hidden="true">🔁</span>
				<span><?php esc_html_e( 'Repost', 'nop-indieweb' ); ?></span>
			</button>
		</nav>

		<!-- URL field (reply, like, bookmark, repost) -->
		<div class="field-group" id="fieldUrl" hidden>
			<label class="field-label" id="urlLabel" for="typeUrl"><?php esc_html_e( 'URL', 'nop-indieweb' ); ?></label>
			<input type="url" id="typeUrl" class="url-field" placeholder="https://…" autocomplete="off">
		</div>

		<!-- Photo picker -->
		<div class="field-group" id="fieldPhoto" hidden>
			<div class="photo-picker" id="photoPicker">
				<input type="file" id="photoInput" accept="image/*" multiple>
				<span class="photo-picker-icon" aria-hidden="true">📷</span>
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

		<!-- Syndicators (populated by JS from q=config) -->
		<div class="syndicators" id="syndicators" hidden></div>

		<button class="btn btn-primary" id="postBtn" disabled type="button">
			<?php esc_html_e( 'Post', 'nop-indieweb' ); ?>
		</button>
	</div>

	<!-- Progress view -->
	<div id="view-progress" style="display:none">
		<div class="progress-view">
			<div class="progress-spinner" aria-hidden="true"></div>
			<p class="progress-status" id="progressStatus"><?php esc_html_e( 'Posting…', 'nop-indieweb' ); ?></p>
			<div class="progress-bar-track" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
				<div class="progress-bar-fill" id="progressFill"></div>
			</div>
		</div>
	</div>

	<!-- Success view -->
	<div id="view-success" style="display:none">
		<div class="success-view">
			<div class="success-header">
				<div class="success-check" aria-hidden="true">✓</div>
				<h2><?php esc_html_e( 'Posted', 'nop-indieweb' ); ?></h2>
			</div>
			<div class="success-photos" id="successPhotos"></div>
			<a class="success-permalink" id="successLink" href="#" target="_blank" rel="noopener noreferrer"></a>
			<a class="btn btn-accent" id="editBtn" href="#" target="_blank" rel="noopener noreferrer" hidden>
				<?php esc_html_e( 'Open in editor →', 'nop-indieweb' ); ?>
			</a>
			<button class="btn btn-instagram" id="instagramBtn" type="button" hidden>
				<?php esc_html_e( 'Share to Instagram', 'nop-indieweb' ); ?>
			</button>
			<button class="btn btn-secondary" id="anotherBtn" type="button">
				<?php esc_html_e( 'Post another', 'nop-indieweb' ); ?>
			</button>
		</div>
	</div>

</div>
<script>
(function () {
	'use strict';

	var NOP = {
		nonce:       <?php echo wp_json_encode( $nonce ); ?>,
		mediaUrl:    <?php echo wp_json_encode( $media_url ); ?>,
		micropubUrl: <?php echo wp_json_encode( $micropub_url ); ?>,
	};

	// ── Type configuration ────────────────────────────────────────────────────

	var TYPE_CONFIG = {
		note:     { urlProp: null,           hasContent: true,  contentRequired: true,  contentPlaceholder: 'Write a note…' },
		photo:    { urlProp: null,           hasContent: true,  contentRequired: false, contentPlaceholder: 'Write a caption…' },
		reply:    { urlProp: 'in-reply-to',  hasContent: true,  contentRequired: false, urlLabel: 'Reply to URL', contentPlaceholder: 'Your reply…' },
		like:     { urlProp: 'like-of',      hasContent: false, urlLabel: 'Like URL' },
		bookmark: { urlProp: 'bookmark-of',  hasContent: true,  contentRequired: false, urlLabel: 'Bookmark URL', contentPlaceholder: 'Notes…' },
		repost:   { urlProp: 'repost-of',    hasContent: false, urlLabel: 'Repost URL' },
	};

	var currentType  = 'note';
	var selectedFiles = [];

	// ── DOM refs ──────────────────────────────────────────────────────────────

	var postBtn      = document.getElementById( 'postBtn' );
	var fieldUrl     = document.getElementById( 'fieldUrl' );
	var fieldPhoto   = document.getElementById( 'fieldPhoto' );
	var fieldContent = document.getElementById( 'fieldContent' );
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

			var container = document.getElementById( 'syndicators' );
			container.innerHTML = synTo.map( function (s) {
				return '<label class="syndicator-item">'
					+ '<input type="checkbox" value="' + escAttr( s.uid ) + '" checked>'
					+ ' ' + escHtml( s.name )
					+ '</label>';
			} ).join( '' );
			container.hidden = false;
		} )
		.catch( function () {} );
	} )();

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

		if ( cfg.urlProp ) {
			urlLabel.textContent = cfg.urlLabel || 'URL';
		}
		if ( cfg.hasContent ) {
			contentInput.placeholder = cfg.contentPlaceholder || 'Write…';
		}

		urlInput.value     = '';
		contentInput.value = '';
		selectedFiles      = [];
		thumbs.innerHTML   = '';
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

	picker.addEventListener( 'dragover', function (e) {
		e.preventDefault();
		picker.classList.add( 'drag-over' );
	} );
	picker.addEventListener( 'dragleave', function () {
		picker.classList.remove( 'drag-over' );
	} );
	picker.addEventListener( 'drop', function (e) {
		e.preventDefault();
		picker.classList.remove( 'drag-over' );
		handleFiles( Array.from( e.dataTransfer.files ).filter( function (f) {
			return f.type.startsWith( 'image/' );
		} ) );
	} );

	photoInput.addEventListener( 'change', function () {
		handleFiles( Array.from( photoInput.files ) );
	} );

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
		var cfg = TYPE_CONFIG[ currentType ];

		try {
			showView( 'progress' );

			// Upload photos (photo type only).
			var photoUrls = [];
			if ( currentType === 'photo' && selectedFiles.length ) {
				for ( var i = 0; i < selectedFiles.length; i++ ) {
					setProgress(
						'Uploading ' + ( i + 1 ) + ' of ' + selectedFiles.length + '…',
						( i / selectedFiles.length ) * 0.75
					);
					var uploaded = await uploadPhoto( selectedFiles[ i ] );
					photoUrls.push( uploaded.source_url );
				}
			}

			setProgress( 'Posting…', 0.88 );

			var payload  = buildPayload( photoUrls );
			var response = await fetch( NOP.micropubUrl, {
				method:  'POST',
				headers: {
					'X-WP-Nonce':   NOP.nonce,
					'Content-Type': 'application/json',
				},
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

			// Copy caption to clipboard for Instagram workflow (photo only).
			if ( currentType === 'photo' ) {
				var caption = contentInput.value.trim();
				if ( caption ) {
					await navigator.clipboard.writeText( caption ).catch( function () {} );
				}
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
		if ( content && cfg.hasContent ) {
			props.content = [ content ];
		}

		if ( cfg.urlProp ) {
			var url = urlInput.value.trim();
			if ( url ) props[ cfg.urlProp ] = [ url ];
		}

		if ( photoUrls && photoUrls.length ) {
			props.photo = photoUrls;
		}

		var synTo = Array.from(
			document.querySelectorAll( '#syndicators input[type="checkbox"]:checked' )
		).map( function (cb) { return cb.value; } );
		if ( synTo.length ) {
			props[ 'syndicate-to' ] = synTo;
		}

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

		var photosEl = document.getElementById( 'successPhotos' );
		photosEl.innerHTML = photoUrls.map( function (url) {
			return '<img src="' + escAttr( url ) + '" alt="">';
		} ).join( '' );

		var link = document.getElementById( 'successLink' );
		link.href        = permalink;
		link.textContent = permalink;

		var editBtn = document.getElementById( 'editBtn' );
		if ( editUrl ) {
			editBtn.href   = editUrl;
			editBtn.hidden = false;
		} else {
			editBtn.hidden = true;
		}

		var igBtn = document.getElementById( 'instagramBtn' );
		igBtn.hidden = ! ( currentType === 'photo' && selectedFiles.length );
		igBtn.onclick = async function () {
			if ( navigator.canShare && navigator.canShare( { files: selectedFiles } ) ) {
				try {
					await navigator.share( { files: selectedFiles } );
				} catch ( e ) {
					if ( e.name !== 'AbortError' ) {
						alert( 'Could not open share sheet. Share from your Photos app instead.' );
					}
				}
			} else {
				alert( 'Web sharing is not supported on this browser.' );
			}
		};

		document.getElementById( 'anotherBtn' ).onclick = resetForm;
	}

	function resetForm() {
		selectedFiles      = [];
		contentInput.value = '';
		urlInput.value     = '';
		thumbs.innerHTML   = '';
		photoInput.value   = '';
		picker.querySelector( 'p' ).textContent = 'Add photos';
		switchType( 'note' );
		showView( 'compose' );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	function showView( name ) {
		[ 'compose', 'progress', 'success' ].forEach( function (v) {
			document.getElementById( 'view-' + v ).style.display = v === name ? '' : 'none';
		} );
	}

	function setProgress( message, fraction ) {
		document.getElementById( 'progressStatus' ).textContent = message;
		var fill = document.getElementById( 'progressFill' );
		fill.style.width = Math.round( fraction * 100 ) + '%';
		fill.parentElement.setAttribute( 'aria-valuenow', Math.round( fraction * 100 ) );
	}

	function delay( ms ) {
		return new Promise( function (resolve) { setTimeout( resolve, ms ); } );
	}

	function escHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function escAttr( str ) {
		return String( str ).replace( /"/g, '&quot;' );
	}

} )();
</script>
</body>
</html>
		<?php
	}
}
