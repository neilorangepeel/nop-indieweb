<?php
/**
 * Like Button block — server-side render.
 *
 * Initialises the liked/count state in PHP so the page is meaningful before
 * JS runs. The view.js enhances the button with a fetch() so the full-page
 * reload fallback is never needed.
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

$endpoint  = new \NOP\IndieWeb\Webmention\Like_Endpoint();
$count     = $endpoint->like_count( $post_id );
$liked     = $endpoint->visitor_has_liked( $post_id );
$rest_url  = rest_url( 'nop-indieweb/v1/like' );
$nonce     = wp_create_nonce( 'wp_rest' );

$wrapper = get_block_wrapper_attributes( [
	'class'         => 'nop-like-button' . ( $liked ? ' is-liked' : '' ),
	'data-post-id'  => (string) $post_id,
	'data-endpoint' => $rest_url,
	'data-nonce'    => $nonce,
] );
?>
<div <?php echo wp_kses_data( $wrapper ); ?>>
	<button class="nop-like-button__btn"
	        type="button"
	        aria-pressed="<?php echo $liked ? 'true' : 'false'; ?>"
	        <?php echo $liked ? 'disabled' : ''; ?>>
		<?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bundled, plugin-authored SVG constant; wp_kses would lowercase the case-sensitive viewBox attribute and break it ?>
		<span class="nop-like-button__label"><?php echo $liked ? esc_html__( 'Liked', 'nop-indieweb' ) : esc_html__( 'Like', 'nop-indieweb' ); ?></span>
	</button>
	<?php /* translators: %d: number of likes */ ?>
	<span class="nop-like-button__count"
	      aria-label="<?php echo esc_attr( sprintf( _n( '%d like', '%d likes', $count, 'nop-indieweb' ), $count ) ); ?>"
	      <?php echo 0 === $count ? 'hidden' : ''; ?>>
		<?php echo esc_html( (string) $count ); ?>
	</span>
</div>
