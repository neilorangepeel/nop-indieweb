<?php
/**
 * Comment Form block — server-side render.
 *
 * The compact leave-a-reply form (id #respond, textarea first). comment-reply.js
 * relocates #respond beneath a clicked reply, so this block works wherever it
 * sits relative to the replies block.
 *
 * Front end: renders nothing when comments are closed on the post.
 * Editor: always renders a sample form so the block is visible and designable.
 */
declare( strict_types=1 );

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once NOP_INDIEWEB_DIR . 'includes/webmention-render.php';

$preview = nop_wm_is_editor_preview();
$post_id = nop_wm_resolve_post_id( $block );

if ( ! $preview && comments_open( $post_id ) ) {
	wp_enqueue_script( 'comment-reply' );
}

$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-comment-form' ] );
$form          = nop_wm_render_comment_form( $post_id, true, $preview );

if ( '' === $form ) {
	return;
}
?>
<div <?php echo wp_kses_data( $wrapper_attrs ); ?>>
	<?php echo $form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- form markup is escaped at source in nop_wm_render_comment_form() ?>
</div>
