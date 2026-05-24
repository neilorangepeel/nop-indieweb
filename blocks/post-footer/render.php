<?php
/**
 * Post Footer block — server-side render.
 *
 * Compact interaction row: interactive like pill, comment count, repost count,
 * and inline syndication source. Replaces the separate like-button + post-source
 * blocks in the note template.
 */
declare( strict_types=1 );

$heart_icon = '<svg class="nop-post-footer__pill-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" width="15" height="15"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>';

$comment_icon = '<svg class="nop-post-footer__pill-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" width="15" height="15"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>';

$repost_icon = '<svg class="nop-post-footer__pill-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" width="15" height="15"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>';

$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );

if ( ! $post_id ) {
	$wrapper = get_block_wrapper_attributes( [ 'class' => 'nop-post-footer nop-post-footer--preview' ] );
	?>
	<div <?php echo $wrapper; ?>>
		<button class="nop-post-footer__pill nop-post-footer__pill--like" type="button" aria-pressed="false" disabled>
			<?php echo $heart_icon; ?>
			<span class="nop-post-footer__pill-count" aria-label="12 likes">12</span>
		</button>
		<span class="nop-post-footer__pill">
			<?php echo $comment_icon; ?>
			<span class="nop-post-footer__pill-count" aria-label="4 comments">4</span>
		</span>
		<span class="nop-post-footer__pill">
			<?php echo $repost_icon; ?>
			<span class="nop-post-footer__pill-count" aria-label="4 reposts">4</span>
		</span>
		<span class="nop-post-footer__sep" aria-hidden="true">·</span>
		<span class="nop-post-footer__source">
			<span class="nop-post-footer__source-label"><?php esc_html_e( 'Originally posted on', 'nop-indieweb' ); ?></span>
			<span class="nop-post-footer__source-link">Bluesky</span>
		</span>
	</div>
	<?php
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
		'meta_query' => [ [
			'relation' => 'OR',
			[ 'key' => 'webmention_type', 'compare' => 'NOT EXISTS' ],
			[ 'key' => 'webmention_type', 'value' => [ 'like', 'repost' ], 'compare' => 'NOT IN' ],
		] ],
	] );

	$repost = (int) get_comments( [
		'post_id'    => $post_id,
		'type'       => 'webmention',
		'status'     => 'approve',
		'count'      => true,
		'meta_key'   => 'webmention_type',
		'meta_value' => 'repost',
	] );

	$counts[ $post_id ] = [
		'like'   => $endpoint->like_count( $post_id ),
		'liked'  => $endpoint->visitor_has_liked( $post_id ),
		'reply'  => $reply_wp + $reply_wm,
		'repost' => $repost,
	];
}

$like_count   = $counts[ $post_id ]['like'];
$liked        = $counts[ $post_id ]['liked'];
$reply_count  = $counts[ $post_id ]['reply'];
$repost_count = $counts[ $post_id ]['repost'];
$rest_url     = rest_url( 'nop-indieweb/v1/like' );
$nonce        = wp_create_nonce( 'wp_rest' );

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
<div <?php echo $wrapper; ?>>

	<button class="nop-post-footer__pill nop-post-footer__pill--like<?php echo $liked ? ' is-liked' : ''; ?>"
	        type="button"
	        aria-pressed="<?php echo $liked ? 'true' : 'false'; ?>"
	        aria-label="<?php echo esc_attr( $liked ? __( 'Liked', 'nop-indieweb' ) : __( 'Like', 'nop-indieweb' ) ); ?>"
	        <?php echo $liked ? 'disabled' : ''; ?>>
		<?php echo $heart_icon; ?>
		<span class="nop-post-footer__pill-count"
		      aria-label="<?php echo esc_attr( sprintf( _n( '%d like', '%d likes', $like_count, 'nop-indieweb' ), $like_count ) ); ?>"
		      <?php echo 0 === $like_count ? 'hidden' : ''; ?>>
			<?php echo esc_html( (string) $like_count ); ?>
		</span>
	</button>

	<a class="nop-post-footer__pill nop-post-footer__pill--link"
	   href="#comments"
	   aria-label="<?php echo esc_attr( $reply_count > 0
	       ? sprintf( _n( 'Jump to %d comment', 'Jump to %d comments', $reply_count, 'nop-indieweb' ), $reply_count )
	       : __( 'Jump to the reply form', 'nop-indieweb' ) ); ?>">
		<?php echo $comment_icon; ?>
		<span class="nop-post-footer__pill-count"
		      aria-hidden="true"
		      <?php echo 0 === $reply_count ? 'hidden' : ''; ?>>
			<?php echo esc_html( (string) $reply_count ); ?>
		</span>
	</a>

	<span class="nop-post-footer__pill" aria-label="<?php echo esc_attr( sprintf( _n( '%d repost', '%d reposts', $repost_count, 'nop-indieweb' ), $repost_count ) ); ?>">
		<?php echo $repost_icon; ?>
		<span class="nop-post-footer__pill-count"
		      aria-hidden="true"
		      <?php echo 0 === $repost_count ? 'hidden' : ''; ?>>
			<?php echo esc_html( (string) $repost_count ); ?>
		</span>
	</span>

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

</div>
