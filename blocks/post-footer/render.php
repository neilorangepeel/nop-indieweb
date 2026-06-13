<?php
/**
 * Post Footer block — server-side render.
 *
 * Compact interaction row: interactive like pill, comment count, share pill,
 * and inline syndication source. The like and share counts carry a small caret
 * that reveals who liked / reposted in a panel below the row — the pills keep
 * their primary action (click to like / share), the caret only peeks.
 */
declare( strict_types=1 );

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once NOP_INDIEWEB_DIR . 'includes/webmention-render.php';

$heart_icon = '<svg class="nop-post-footer__pill-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" width="15" height="15"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>';

$comment_icon = '<svg class="nop-post-footer__pill-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" width="15" height="15"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>';

$repost_icon = '<svg class="nop-post-footer__pill-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" width="15" height="15"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>';

$caret_icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" width="11" height="11"><polyline points="6 9 12 15 18 9"/></svg>';

/**
 * A caret button that reveals a facepile panel. role-less <span> so it can sit
 * inside the pill's <button> without nesting interactive elements; the view
 * script wires click + keyboard and stops the click reaching the pill action.
 */
$caret = static function ( string $panel, string $label, string $controls ) use ( $caret_icon ): string {
	return '<span class="nop-post-footer__reveal" role="button" tabindex="0"'
	     . ' data-reveal="' . esc_attr( $panel ) . '"'
	     . ' aria-controls="' . esc_attr( $controls ) . '"'
	     . ' aria-expanded="false"'
	     . ' aria-label="' . esc_attr( $label ) . '">'
	     . $caret_icon
	     . '</span>';
};

/**
 * One reveal panel: leading icon, overlapping facepile, then the names line.
 * Front-end only renders avatars that have a photo; overflow counts the rest.
 */
$panel = static function ( string $key, array $entries, int $total, string $names_html, bool $preview, string $dom_id ) : string {
	$pool     = $preview ? $entries : array_values( array_filter( $entries, static fn( array $e ) => ! empty( $e['photo'] ) ) );
	$shown    = array_slice( $pool, 0, 7 );
	$overflow = max( 0, $total - count( $shown ) );

	$out  = '<div class="nop-post-footer__reveal-panel nop-post-footer__reveal-panel--' . esc_attr( $key ) . '" id="' . esc_attr( $dom_id ) . '" data-panel="' . esc_attr( $key ) . '" hidden>';
	if ( $shown ) {
		$out .= '<span class="nop-webmentions__facepile" aria-hidden="true">';
		foreach ( $shown as $entry ) {
			$out .= nop_wm_avatar_wrap( $entry, 28 );
		}
		if ( $overflow > 0 ) {
			$out .= '<span class="nop-webmentions__avatar-wrap">'
			      . '<span class="nop-webmentions__overflow nop-webmentions__avatar--28">+' . esc_html( (string) $overflow ) . '</span>'
			      . '</span>';
		}
		$out .= '</span>';
	}
	$out .= '<span class="nop-post-footer__reveal-names">' . $names_html . '</span>';
	$out .= '</div>';
	return $out;
};

$preview = nop_wm_is_editor_preview();
$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );

if ( ! $post_id && ! $preview ) {
	$post_id = (int) get_the_ID();
}

// ── Editor preview ──────────────────────────────────────────────────────────
if ( $preview && ! $post_id ) {
	$sample        = nop_wm_sample_data();
	$wrapper       = get_block_wrapper_attributes( [ 'class' => 'nop-post-footer is-liked nop-post-footer--preview' ] );
	$likes_panel   = $panel( 'likes', $sample['likes'], count( $sample['likes'] ), nop_wm_liked_by( $sample['likes'] ), true, 'nop-reveal-preview-likes' );
	$reposts_panel = $panel( 'reposts', $sample['reposts'], count( $sample['reposts'] ), nop_wm_reposted_by( $sample['reposts'] ), true, 'nop-reveal-preview-reposts' );
	?>
	<div <?php echo wp_kses_data( $wrapper ); ?>>
		<button class="nop-post-footer__pill nop-post-footer__pill--like is-liked" type="button" aria-pressed="true">
			<?php echo $heart_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bundled, plugin-authored SVG constant ?>
			<span class="nop-post-footer__pill-count">4</span>
			<?php echo $caret( 'likes', __( 'See who liked', 'nop-indieweb' ), 'nop-reveal-preview-likes' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped at source ?>
		</button>
		<span class="nop-post-footer__pill nop-post-footer__pill--link">
			<?php echo $comment_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bundled, plugin-authored SVG constant ?>
			<span class="nop-post-footer__pill-count">2</span>
		</span>
		<button class="nop-post-footer__pill nop-post-footer__pill--share" type="button" aria-label="<?php esc_attr_e( 'Share', 'nop-indieweb' ); ?>">
			<?php echo $repost_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bundled, plugin-authored SVG constant ?>
			<span class="nop-post-footer__pill-count">2</span>
			<?php echo $caret( 'reposts', __( 'See who reposted', 'nop-indieweb' ), 'nop-reveal-preview-reposts' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped at source ?>
		</button>
		<span class="nop-post-footer__sep" aria-hidden="true">·</span>
		<span class="nop-post-footer__source">
			<span class="nop-post-footer__source-label"><?php esc_html_e( 'Originally posted on', 'nop-indieweb' ); ?></span>
			<span class="nop-post-footer__source-link">Bluesky</span>
		</span>
		<?php
		echo $likes_panel;   // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper output escaped at source
		echo $reposts_panel; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper output escaped at source
		?>
	</div>
	<?php
	return;
}

if ( ! $post_id ) {
	return;
}

// Per-request memo so listing pages that render the same post twice only hit the DB once.
static $counts = [];
if ( ! isset( $counts[ $post_id ] ) ) {
	$endpoint = new \NOP\IndieWeb\Webmention\Like_Endpoint();

	$reply_wp = (int) get_comments( [
		'post_id' => $post_id,
		'type'    => 'comment',
		'status'  => 'approve',
		'count'   => true,
	] );

	$reply_wm = (int) get_comments( [
		'post_id'    => $post_id,
		'type'       => 'webmention',
		'status'     => 'approve',
		'count'      => true,
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- low-frequency meta/taxonomy lookup (import, admin, or per-post render cache), not a hot path
		'meta_query' => [ [
			'relation' => 'OR',
			[ 'key' => 'webmention_type', 'compare' => 'NOT EXISTS' ],
			[ 'key' => 'webmention_type', 'value' => [ 'like', 'repost' ], 'compare' => 'NOT IN' ],
		] ],
	] );

	$counts[ $post_id ] = [
		'like'   => $endpoint->like_count( $post_id ),
		'liked'  => $endpoint->visitor_has_liked( $post_id ),
		'reply'  => $reply_wp + $reply_wm,
	];
}

$like_count   = $counts[ $post_id ]['like'];
$liked        = $counts[ $post_id ]['liked'];
$reply_count  = $counts[ $post_id ]['reply'];

// Reactor identities for the reveal panels — shares the per-request memo with
// the replies block, so the facepiles cost no extra queries.
$data = nop_wm_get_data( $post_id );

// A reaction is only revealable if we can actually show who it was — a name or
// a photo. Anonymous site-likes still count toward the pill, but have nothing
// to reveal; without this filter the caret opens an empty "Liked by" panel.
$revealable      = static fn( array $e ): bool => '' !== trim( (string) ( $e['author'] ?? '' ) ) || ! empty( $e['photo'] );
$repost_count    = count( $data['reposts'] );
$likes_entries   = array_values( array_filter( $data['likes'],   $revealable ) );
$reposts_entries = array_values( array_filter( $data['reposts'], $revealable ) );

$has_like_reveal   = ! empty( $likes_entries );
$has_repost_reveal = ! empty( $reposts_entries );

// Post-scoped panel ids so listing pages (many footers) stay valid HTML.
$likes_panel_id   = 'nop-reveal-' . $post_id . '-likes';
$reposts_panel_id = 'nop-reveal-' . $post_id . '-reposts';

$rest_url = rest_url( 'nop-indieweb/v1/like' );
$nonce    = wp_create_nonce( 'wp_rest' );

// ── Post source ───────────────────────────────────────────────────────────────

$platform   = (string) get_post_meta( $post_id, 'nop_indieweb_platform',   true );
$source_url = (string) get_post_meta( $post_id, 'nop_indieweb_source_url', true );

$platform_labels = [
	'mastodon' => 'Mastodon',
	'bluesky'  => 'Bluesky',
	'twitter'  => 'Twitter',
];

$has_source = $source_url && $platform && 'entries' !== $platform;

// ── Render ────────────────────────────────────────────────────────────────────

$wrapper = get_block_wrapper_attributes( [
	'class'         => 'nop-post-footer' . ( $liked ? ' is-liked' : '' ),
	'data-post-id'  => (string) $post_id,
	'data-endpoint' => $rest_url,
	'data-nonce'    => $nonce,
] );
?>
<div <?php echo wp_kses_data( $wrapper ); ?>>

	<button class="nop-post-footer__pill nop-post-footer__pill--like<?php echo $liked ? ' is-liked' : ''; ?>"
	        type="button"
	        aria-pressed="<?php echo $liked ? 'true' : 'false'; ?>"
	        aria-label="<?php echo esc_attr( $liked ? __( 'Liked', 'nop-indieweb' ) : __( 'Like', 'nop-indieweb' ) ); ?>">
		<?php echo $heart_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bundled, plugin-authored SVG constant; wp_kses would lowercase the case-sensitive viewBox attribute and break it ?>
		<span class="nop-post-footer__pill-count"
		      aria-label="<?php /* translators: %d: number of likes */ echo esc_attr( sprintf( _n( '%d like', '%d likes', $like_count, 'nop-indieweb' ), $like_count ) ); ?>"
		      <?php echo 0 === $like_count ? 'hidden' : ''; ?>>
			<?php echo esc_html( (string) $like_count ); ?>
		</span>
		<?php if ( $has_like_reveal ) {
			echo $caret( 'likes', __( 'See who liked', 'nop-indieweb' ), $likes_panel_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped at source
		} ?>
	</button>

	<a class="nop-post-footer__pill nop-post-footer__pill--link"
	   href="#comments"
	   aria-label="<?php
	       echo esc_attr( $reply_count > 0
	       /* translators: %d: number of comments */
	       ? sprintf( _n( 'Jump to %d comment', 'Jump to %d comments', $reply_count, 'nop-indieweb' ), $reply_count )
	       : __( 'Jump to the reply form', 'nop-indieweb' ) ); ?>">
		<?php echo $comment_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bundled, plugin-authored SVG constant; wp_kses would lowercase the case-sensitive viewBox attribute and break it ?>
		<span class="nop-post-footer__pill-count"
		      aria-hidden="true"
		      <?php echo 0 === $reply_count ? 'hidden' : ''; ?>>
			<?php echo esc_html( (string) $reply_count ); ?>
		</span>
	</a>

	<?php /* translators: %d: number of reposts */ ?>
	<button class="nop-post-footer__pill nop-post-footer__pill--share"
	        type="button"
	        data-url="<?php echo esc_attr( (string) get_permalink( $post_id ) ); ?>"
	        data-title="<?php echo esc_attr( (string) get_the_title( $post_id ) ); ?>"
	        aria-label="<?php echo esc_attr( $repost_count > 0
	            ? sprintf( _n( 'Share · %d repost', 'Share · %d reposts', $repost_count, 'nop-indieweb' ), $repost_count )
	            : __( 'Share', 'nop-indieweb' ) ); ?>">
		<?php echo $repost_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bundled, plugin-authored SVG constant; wp_kses would lowercase the case-sensitive viewBox attribute and break it ?>
		<span class="nop-post-footer__pill-count"
		      aria-hidden="true"
		      <?php echo 0 === $repost_count ? 'hidden' : ''; ?>>
			<?php echo esc_html( (string) $repost_count ); ?>
		</span>
		<?php if ( $has_repost_reveal ) {
			echo $caret( 'reposts', __( 'See who reposted', 'nop-indieweb' ), $reposts_panel_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped at source
		} ?>
	</button>

	<?php if ( $has_source ) : ?>
	<span class="nop-post-footer__sep" aria-hidden="true">·</span>
	<span class="nop-post-footer__source">
		<span class="nop-post-footer__source-label"><?php esc_html_e( 'Originally posted on', 'nop-indieweb' ); ?></span>
		<a class="nop-post-footer__source-link u-syndication"
		   href="<?php echo esc_url( $source_url ); ?>"
		   target="_blank" rel="noopener noreferrer me">
			<?php echo esc_html( $platform_labels[ $platform ] ?? ucfirst( $platform ) ); ?>
		</a>
	</span>
	<?php endif; ?>

	<?php
	if ( $has_like_reveal ) {
		echo $panel( 'likes', $likes_entries, count( $likes_entries ), nop_wm_liked_by( $likes_entries ), false, $likes_panel_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper output escaped at source
	}
	if ( $has_repost_reveal ) {
		echo $panel( 'reposts', $reposts_entries, count( $reposts_entries ), nop_wm_reposted_by( $reposts_entries ), false, $reposts_panel_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper output escaped at source
	}
	?>

</div>
