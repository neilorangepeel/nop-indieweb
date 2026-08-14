<?php
/**
 * Kind Icon block — server-side render.
 *
 * Renders a Phosphor Regular icon matching the post's nop_kind term, so every
 * post kind shows a consistent lined icon in its header. Mirrors the
 * exercise-type-icon block (which handles the exercise kind's dynamic icon).
 *
 * Front end: renders nothing when the post has no recognised kind. Editor
 * falls back to the note icon so the block stays visible in the Site Editor.
 */

declare( strict_types=1 );

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_editor = defined( 'REST_REQUEST' ) && REST_REQUEST
	&& isset( $_GET['context'] ) && 'edit' === $_GET['context']; // phpcs:ignore WordPress.Security.NonceVerification

$post_id = $block->context['postId'] ?? get_the_ID();
if ( $is_editor && isset( $_GET['post_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
	$candidate = absint( $_GET['post_id'] ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( $candidate && current_user_can( 'edit_post', $candidate ) ) {
		$post_id = $candidate;
	}
}

$kind  = '';
$label = '';
if ( $post_id ) {
	$terms = get_the_terms( $post_id, \NOP\IndieWeb\Kind\Kind_Taxonomy::TAXONOMY );
	if ( ! is_wp_error( $terms ) && $terms ) {
		$kind  = (string) $terms[0]->slug;
		$label = (string) $terms[0]->name;
	}
}

// Path geometry lives in Kind_Icons so this block and the /post composer's
// sprite draw the same marks from one source. Exercise is the one kind this
// block passes on: Kind_Icons carries a generic bolt for callers that need a
// mark per kind, but on a post the activity-specific exercise-type-icon block
// says more (a run, a swim), so it stays the owner there.
if ( 'exercise' === $kind ) {
	return;
}

$svg = \NOP\IndieWeb\Kind\Kind_Icons::svg( $kind );
if ( '' === $svg && $is_editor ) {
	$svg = \NOP\IndieWeb\Kind\Kind_Icons::svg( 'note' );
}

if ( '' === $svg ) {
	return;
}

// Role + aria-label so the icon conveys the kind to assistive tech.
$aria = $label ?: __( 'Post', 'nop-indieweb' );
$svg  = preg_replace(
	'/<svg /',
	'<svg role="img" aria-label="' . esc_attr( $aria ) . '" ',
	$svg,
	1
);

$wrapper_attrs = get_block_wrapper_attributes( [
	'class' => 'wp-block-icon nop-kind-icon nop-kind-icon--' . sanitize_html_class( $kind ?: 'unknown' ),
] );
?>
<span <?php echo wp_kses_data( $wrapper_attrs ); ?>><?php echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Phosphor SVG constant, no user input ?></span>
