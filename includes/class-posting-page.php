<?php
declare( strict_types=1 );

namespace NOP\IndieWeb;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use NOP\IndieWeb\Kind\Kind_Taxonomy;

/**
 * Registers a standalone mobile posting page at /post on the front end.
 *
 * The page is only accessible to logged-in users with publish_posts capability.
 * It renders a full-screen photo-posting UI that:
 *   1. Lets the user pick photos and write a caption
 *   2. Uploads photos to the WP media library via REST
 *   3. Creates a photo-kind post via a dedicated REST endpoint
 *   4. Opens the iOS Share Sheet (Web Share API) for Instagram handoff
 *
 * Also registers POST /nop-indieweb/v1/create-photo-post — the endpoint the
 * page's JS calls after uploading photos. Uses WP cookie + nonce auth so no
 * Application Password is needed.
 */
class Posting_Page {

	private const QUERY_VAR = 'nop_post_page';
	private const REWRITE   = '^post/?$';

	public function register(): void {
		add_action( 'init',             [ $this, 'add_rewrite_rule' ] );
		add_filter( 'query_vars',       [ $this, 'add_query_var' ] );
		add_action( 'template_redirect', [ $this, 'maybe_render' ] );
		add_action( 'rest_api_init',    [ $this, 'register_rest_route' ] );
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

	// ——— REST endpoint ————————————————————————————————————————————————————————

	public function register_rest_route(): void {
		register_rest_route( 'nop-indieweb/v1', '/create-photo-post', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'create_photo_post' ],
			'permission_callback' => fn() => current_user_can( 'publish_posts' ),
			'args'                => [
				'caption'   => [ 'type' => 'string',  'default' => '' ],
				'photo_ids' => [ 'type' => 'array',   'required' => true, 'items' => [ 'type' => 'integer' ] ],
			],
		] );
	}

	public function create_photo_post( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$caption   = sanitize_textarea_field( $request->get_param( 'caption' ) );
		$photo_ids = array_map( 'absint', (array) $request->get_param( 'photo_ids' ) );
		$photo_ids = array_filter( $photo_ids );

		if ( empty( $photo_ids ) ) {
			return new \WP_Error( 'no_photos', __( 'At least one photo is required.', 'nop-indieweb' ), [ 'status' => 400 ] );
		}

		$photo_urls = array_filter( array_map( 'wp_get_attachment_url', $photo_ids ) );

		// Build post content.
		$blocks = '';
		if ( $caption ) {
			$blocks .= "<!-- wp:paragraph -->\n<p>" . wp_kses_post( $caption ) . "</p>\n<!-- /wp:paragraph -->\n\n";
		}
		$blocks .= $this->build_image_blocks( $photo_ids );

		// Generate a readable title from caption or date.
		$words = preg_split( '/\s+/', wp_strip_all_tags( $caption ), -1, PREG_SPLIT_NO_EMPTY );
		$title = $words
			? implode( ' ', array_slice( $words, 0, 6 ) ) . ( count( $words ) > 6 ? '…' : '' )
			: wp_date( 'j M Y' );

		// Insert as draft first so meta is set before syndication fires on publish.
		$post_id = wp_insert_post( [
			'post_title'   => $title,
			'post_content' => $blocks,
			'post_status'  => 'draft',
			'post_author'  => get_current_user_id(),
			'meta_input'   => [
				'nop_indieweb_photo_ids' => $photo_ids,
				'nop_indieweb_photos'    => array_values( $photo_urls ),
			],
		] );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Assign kind taxonomy.
		wp_set_object_terms( $post_id, 'photo', Kind_Taxonomy::TAXONOMY );

		// Publish — triggers wp_after_insert_post → Syndication_Manager.
		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );

		return new \WP_REST_Response( [
			'post_id'   => $post_id,
			'permalink' => get_permalink( $post_id ),
		], 201 );
	}

	private function build_image_blocks( array $photo_ids ): string {
		$blocks = [];
		foreach ( $photo_ids as $id ) {
			$url = wp_get_attachment_url( $id );
			if ( ! $url ) {
				continue;
			}
			$alt     = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
			$blocks[] = '<!-- wp:image {"id":' . (int) $id . ',"sizeSlug":"large","linkDestination":"none"} -->'
				. '<figure class="wp-block-image size-large">'
				. '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . (int) $id . '"/>'
				. '</figure>'
				. '<!-- /wp:image -->';
		}
		return implode( "\n\n", $blocks );
	}

	// ——— Page render ——————————————————————————————————————————————————————————

	private function render_page(): void {
		$nonce      = wp_create_nonce( 'wp_rest' );
		$media_url  = esc_url( rest_url( 'wp/v2/media' ) );
		$create_url = esc_url( rest_url( 'nop-indieweb/v1/create-photo-post' ) );
		$site_name  = esc_html( get_bloginfo( 'name' ) );
		$icon_url   = esc_url( get_site_icon_url( 192 ) );
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Post Photo">
<?php if ( $icon_url ) : ?>
<link rel="apple-touch-icon" href="<?php echo $icon_url; ?>">
<?php endif; ?>
<title><?php esc_html_e( 'Post Photo', 'nop-indieweb' ); ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
	--bg:        #f2f2f7;
	--surface:   #ffffff;
	--text:      #000000;
	--text-2:    #3c3c43cc;
	--accent:    #007aff;
	--accent-bg: #007aff1a;
	--border:    #3c3c4333;
	--danger:    #ff3b30;
	--success:   #34c759;
	--radius:    12px;
	--radius-sm: 8px;
	--safe-top:    env(safe-area-inset-top, 0px);
	--safe-bottom: env(safe-area-inset-bottom, 0px);
}

@media (prefers-color-scheme: dark) {
	:root {
		--bg:      #000000;
		--surface: #1c1c1e;
		--text:    #ffffff;
		--text-2:  #ebebf599;
		--border:  #54545899;
	}
}

html, body {
	height: 100%;
	background: var(--bg);
	color: var(--text);
	font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
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
	align-items: center;
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

/* Photo picker */
.photo-picker {
	background: var(--surface);
	border: 2px dashed var(--border);
	border-radius: var(--radius);
	padding: 32px 20px;
	text-align: center;
	cursor: pointer;
	transition: border-color 0.15s, background 0.15s;
	margin-bottom: 12px;
	-webkit-tap-highlight-color: transparent;
}
.photo-picker:active,
.photo-picker.drag-over {
	border-color: var(--accent);
	background: var(--accent-bg);
}
.photo-picker input[type="file"] { display: none; }
.photo-picker-icon {
	font-size: 40px;
	margin-bottom: 8px;
	display: block;
}
.photo-picker p {
	font-size: 15px;
	font-weight: 500;
	color: var(--accent);
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
	margin-bottom: 12px;
}
.thumbnails img {
	width: 100%;
	aspect-ratio: 1;
	object-fit: cover;
	border-radius: var(--radius-sm);
	display: block;
}

/* Caption */
.caption-label {
	font-size: 13px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--text-2);
	margin-bottom: 6px;
}
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
	margin-bottom: 16px;
}
.caption-field:focus { border-color: var(--accent); }
.caption-field::placeholder { color: var(--text-2); }

/* Buttons */
.btn {
	width: 100%;
	padding: 16px;
	border: none;
	border-radius: var(--radius);
	font-size: 17px;
	font-weight: 600;
	font-family: inherit;
	cursor: pointer;
	transition: opacity 0.1s, transform 0.1s;
	-webkit-tap-highlight-color: transparent;
	margin-bottom: 10px;
}
.btn:active { opacity: 0.8; transform: scale(0.98); }
.btn:disabled { opacity: 0.4; cursor: default; transform: none; }
.btn-primary { background: var(--accent); color: #fff; }
.btn-secondary {
	background: var(--surface);
	color: var(--accent);
	border: 1px solid var(--border);
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
	border-top-color: var(--accent);
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
	background: var(--accent);
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
	gap: 8px;
	margin-bottom: 20px;
}
.success-check {
	width: 32px;
	height: 32px;
	background: var(--success);
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	color: #fff;
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
.success-note {
	font-size: 14px;
	color: var(--text-2);
	margin-bottom: 20px;
	line-height: 1.4;
}
.success-note strong { color: var(--text); }
.success-permalink {
	font-size: 13px;
	color: var(--accent);
	text-decoration: none;
	display: block;
	margin-bottom: 20px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.btn-instagram {
	background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888);
	color: #fff;
}
</style>
</head>
<body>
<div class="page" id="app">

	<!-- Compose view -->
	<div id="view-compose">
		<div class="header">
			<h1><?php esc_html_e( 'Post Photo', 'nop-indieweb' ); ?></h1>
			<span class="header-site"><?php echo $site_name; ?></span>
		</div>

		<div class="photo-picker" id="photoPicker">
			<input type="file" id="photoInput" accept="image/*" multiple>
			<span class="photo-picker-icon">📷</span>
			<p><?php esc_html_e( 'Add photos', 'nop-indieweb' ); ?></p>
			<small><?php esc_html_e( 'Tap to select · up to 10', 'nop-indieweb' ); ?></small>
		</div>

		<div class="thumbnails" id="thumbnails"></div>

		<p class="caption-label"><?php esc_html_e( 'Caption', 'nop-indieweb' ); ?></p>
		<textarea
			class="caption-field"
			id="caption"
			placeholder="<?php esc_attr_e( 'Write a caption…', 'nop-indieweb' ); ?>"
			rows="4"
		></textarea>

		<button class="btn btn-primary" id="postBtn" disabled>
			<?php esc_html_e( 'Post', 'nop-indieweb' ); ?>
		</button>
	</div>

	<!-- Progress view -->
	<div id="view-progress" style="display:none">
		<div class="progress-view">
			<div class="progress-spinner"></div>
			<p class="progress-status" id="progressStatus"><?php esc_html_e( 'Uploading…', 'nop-indieweb' ); ?></p>
			<div class="progress-bar-track">
				<div class="progress-bar-fill" id="progressFill"></div>
			</div>
		</div>
	</div>

	<!-- Success view -->
	<div id="view-success" style="display:none">
		<div class="success-view">
			<div class="success-header">
				<div class="success-check">✓</div>
				<h2><?php esc_html_e( 'Posted', 'nop-indieweb' ); ?></h2>
			</div>
			<div class="success-photos" id="successPhotos"></div>
			<p class="success-note" id="successNote"></p>
			<a class="success-permalink" id="successLink" href="#" target="_blank"></a>
			<button class="btn btn-instagram" id="instagramBtn">
				<?php esc_html_e( 'Share to Instagram', 'nop-indieweb' ); ?>
			</button>
			<button class="btn btn-secondary" id="anotherBtn">
				<?php esc_html_e( 'Post another', 'nop-indieweb' ); ?>
			</button>
		</div>
	</div>

</div>
<script>
(function () {
	'use strict';

	var NOP = {
		nonce:     <?php echo wp_json_encode( $nonce ); ?>,
		mediaUrl:  <?php echo wp_json_encode( $media_url ); ?>,
		createUrl: <?php echo wp_json_encode( $create_url ); ?>,
	};

	var selectedFiles = [];

	// ── Photo picker ──────────────────────────────────────────────────────────

	var picker  = document.getElementById( 'photoPicker' );
	var input   = document.getElementById( 'photoInput' );
	var thumbs  = document.getElementById( 'thumbnails' );
	var postBtn = document.getElementById( 'postBtn' );

	picker.addEventListener( 'click', function () { input.click(); } );

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

	input.addEventListener( 'change', function () {
		handleFiles( Array.from( input.files ) );
	} );

	function handleFiles( files ) {
		selectedFiles = files.slice( 0, 10 );
		thumbs.innerHTML = '';
		selectedFiles.forEach( function (file) {
			var img = document.createElement( 'img' );
			img.src = URL.createObjectURL( file );
			img.alt = '';
			thumbs.appendChild( img );
		} );
		postBtn.disabled = selectedFiles.length === 0;
		if ( selectedFiles.length ) {
			picker.querySelector( 'p' ).textContent = selectedFiles.length + ' photo' + ( selectedFiles.length > 1 ? 's' : '' ) + ' selected';
		}
	}

	// ── Post ──────────────────────────────────────────────────────────────────

	postBtn.addEventListener( 'click', async function () {
		if ( ! selectedFiles.length ) return;

		var caption = document.getElementById( 'caption' ).value.trim();

		try {
			showView( 'progress' );

			// Upload photos one at a time.
			var photoIds  = [];
			var photoUrls = [];
			for ( var i = 0; i < selectedFiles.length; i++ ) {
				setProgress(
					'Uploading photo ' + ( i + 1 ) + ' of ' + selectedFiles.length + '…',
					( i / selectedFiles.length ) * 0.8
				);
				var uploaded = await uploadPhoto( selectedFiles[ i ] );
				photoIds.push( uploaded.id );
				photoUrls.push( uploaded.source_url );
			}

			setProgress( 'Creating post…', 0.9 );
			var post = await createPost( caption, photoIds );

			setProgress( 'Syndicating…', 0.97 );

			// Give syndication a moment to fire before showing success.
			await delay( 600 );

			// Copy caption to clipboard (best-effort).
			if ( caption ) {
				await navigator.clipboard.writeText( caption ).catch( function () {} );
			}

			showSuccess( post.permalink, caption, photoUrls );

		} catch ( err ) {
			showView( 'compose' );
			alert( 'Something went wrong: ' + err.message );
		}
	} );

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

	async function createPost( caption, photoIds ) {
		var res = await fetch( NOP.createUrl, {
			method:  'POST',
			headers: {
				'X-WP-Nonce':   NOP.nonce,
				'Content-Type': 'application/json',
			},
			body: JSON.stringify( { caption: caption, photo_ids: photoIds } ),
		} );
		if ( ! res.ok ) {
			var err = await res.json().catch( function () { return {}; } );
			throw new Error( err.message || 'Post creation failed (' + res.status + ')' );
		}
		return res.json();
	}

	// ── Success ───────────────────────────────────────────────────────────────

	function showSuccess( permalink, caption, photoUrls ) {
		showView( 'success' );

		var container = document.getElementById( 'successPhotos' );
		container.innerHTML = photoUrls.map( function (url) {
			return '<img src="' + url + '" alt="">';
		} ).join( '' );

		var note = document.getElementById( 'successNote' );
		if ( caption ) {
			note.innerHTML = '<strong>Caption copied to clipboard</strong> — paste it in Instagram after the photos load.';
		} else {
			note.textContent = 'Posted. Use Instagram\'s caption field if needed.';
		}

		var link = document.getElementById( 'successLink' );
		link.href = permalink;
		link.textContent = permalink;

		document.getElementById( 'instagramBtn' ).onclick = async function () {
			if ( navigator.canShare && navigator.canShare( { files: selectedFiles } ) ) {
				try {
					await navigator.share( { files: selectedFiles } );
				} catch ( e ) {
					if ( e.name !== 'AbortError' ) {
						alert( 'Could not open share sheet. Share your photos from the Photos app instead.' );
					}
				}
			} else {
				alert( 'Web sharing is not supported on this browser. Open your Photos app and share from there.' );
			}
		};

		document.getElementById( 'anotherBtn' ).onclick = function () {
			selectedFiles = [];
			document.getElementById( 'caption' ).value = '';
			document.getElementById( 'thumbnails' ).innerHTML = '';
			document.getElementById( 'photoInput' ).value = '';
			document.getElementById( 'photoPicker' ).querySelector( 'p' ).textContent = 'Add photos';
			postBtn.disabled = true;
			showView( 'compose' );
		};
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	function showView( name ) {
		[ 'compose', 'progress', 'success' ].forEach( function (v) {
			document.getElementById( 'view-' + v ).style.display = v === name ? '' : 'none';
		} );
	}

	function setProgress( message, fraction ) {
		document.getElementById( 'progressStatus' ).textContent = message;
		document.getElementById( 'progressFill' ).style.width = Math.round( fraction * 100 ) + '%';
	}

	function delay( ms ) {
		return new Promise( function (resolve) { setTimeout( resolve, ms ); } );
	}

} )();
</script>
</body>
</html>
		<?php
	}
}
