<?php
/**
 * Like Button block — server-side render.
 *
 * Initialises the liked/count state in PHP so the page is meaningful before
 * JS runs. The shared likes store (assets/js/likes.js) handles the click.
 */
declare( strict_types=1 );

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$icon = '<svg class="nop-like-button__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" width="16" height="16"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>';

$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );

// Editor preview — no post context available.
if ( ! $post_id ) {
	$wrapper = get_block_wrapper_attributes( [ 'class' => 'nop-like-button' ] );
	?>
	<div <?php echo wp_kses_data( $wrapper ); ?>>
		<button class="nop-like-button__btn" type="button" aria-pressed="false">
			<?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bundled, plugin-authored SVG constant; wp_kses would lowercase the case-sensitive viewBox attribute and break it ?>
			<span class="nop-like-button__label"><?php esc_html_e( 'Like', 'nop-indieweb' ); ?></span>
		</button>
		<span class="nop-like-button__count" hidden>0</span>
	</div>
	<?php
	return;
}

$endpoint = new \NOP\IndieWeb\Webmention\Like_Endpoint();
$endpoint->seed_state( $post_id );
$count = $endpoint->like_count( $post_id );
$liked = $endpoint->visitor_has_liked( $post_id );

// State lives in the shared nop-indieweb/likes store (seeded above), so this
// heart and the post footer's pill stay in step. The server processes these
// directives too, so the markup is right before any JavaScript runs.
$wrapper = get_block_wrapper_attributes( [
	'class'               => 'nop-like-button',
	'data-wp-interactive' => 'nop-indieweb/likes',
	'data-wp-context'     => wp_json_encode( [ 'key' => 'p' . $post_id, 'postId' => $post_id, 'busy' => false, 'animating' => false, 'error' => '' ] ),
	'data-wp-class--is-liked' => 'state.liked',
] );
?>
<div <?php echo wp_kses_data( $wrapper ); ?>>
	<button class="nop-like-button__btn"
	        type="button"
	        data-wp-on--click="actions.like"
	        data-wp-on--animationend="actions.endAnimation"
	        data-wp-bind--aria-pressed="state.liked"
	        data-wp-class--is-liked="state.liked"
	        data-wp-class--is-busy="context.busy"
	        data-wp-class--is-animating="context.animating">
		<?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bundled, plugin-authored SVG constant; wp_kses would lowercase the case-sensitive viewBox attribute and break it ?>
		<span class="nop-like-button__label" data-wp-text="state.label"><?php echo $liked ? esc_html__( 'Liked', 'nop-indieweb' ) : esc_html__( 'Like', 'nop-indieweb' ); ?></span>
	</button>
	<span class="nop-like-button__count"
	      data-wp-text="state.count"
	      data-wp-bind--aria-label="state.countLabel"
	      data-wp-bind--hidden="!state.count"><?php echo esc_html( (string) $count ); ?></span>
	<span class="nop-like-button__status" role="status" aria-live="polite" data-wp-text="context.error"></span>
</div>
