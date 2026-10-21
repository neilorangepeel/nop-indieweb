<?php
/**
 * Share button: the reshare arrows (Phosphor repeat). On a touch device with a share sheet it opens that; elsewhere it copies the
 * post's link and says so.
 *
 * @package NOP_IndieWeb
 */

declare( strict_types=1 );

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
$icon    = (string) file_get_contents( NOP_INDIEWEB_DIR . 'assets/icons/phosphor/repeat.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a bundled file.
$icon    = str_replace( '<svg ', '<svg class="nop-share-button__icon" aria-hidden="true" focusable="false" width="16" height="16" ', $icon );

$wrapper = get_block_wrapper_attributes( [
	'class'               => 'nop-share-button',
	'data-wp-interactive' => 'nop-indieweb/share',
	'data-wp-context'     => wp_json_encode( [
		'url'    => $post_id ? (string) get_permalink( $post_id ) : '',
		'title'  => $post_id ? wp_strip_all_tags( get_the_title( $post_id ) ) : '',
		'copied' => false,
		'share'  => __( 'Share', 'nop-indieweb' ),
		'done'   => __( 'Link copied', 'nop-indieweb' ),
	] ),
] );
?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() escapes. ?>>
	<button class="nop-share-button__btn" type="button" data-wp-on--click="actions.share" data-wp-class--is-copied="context.copied">
		<?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bundled SVG; wp_kses would lowercase viewBox. ?>
		<span class="nop-share-button__label" data-wp-text="state.label"><?php esc_html_e( 'Share', 'nop-indieweb' ); ?></span>
	</button>
	<span class="nop-share-button__status" role="status" aria-live="polite" data-wp-text="state.status"></span>
</div>
