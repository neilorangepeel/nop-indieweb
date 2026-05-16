<?php
/**
 * Weather Summary block — server-side render.
 *
 * Reads nop_indieweb_weather_summary meta on the current post and renders
 * the conditions string ("Mostly Clear", "Partly Cloudy", etc.) as a span.
 *
 * Front-end: renders truly nothing when the post has no weather meta, so
 * posts without weather data look like posts without weather data.
 *
 * Editor: falls back to a sample value so the block is visible in the
 * template editor and on posts being authored before enrichment runs.
 * Without this, the editor shows its built-in "Block rendered as empty"
 * box that looks like a stack trace to non-technical authors.
 */

declare( strict_types=1 );

$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : // phpcs:ignore WordPress.Security.NonceVerification
           ( $block->context['postId'] ?? get_the_ID() );

$value = $post_id ? (string) get_post_meta( $post_id, 'nop_indieweb_weather_summary', true ) : '';

$is_editor = defined( 'REST_REQUEST' ) && REST_REQUEST
	&& isset( $_GET['context'] ) && 'edit' === $_GET['context']; // phpcs:ignore WordPress.Security.NonceVerification

if ( '' === $value ) {
	if ( ! $is_editor ) {
		return;
	}
	$value = 'Partly Cloudy';
}

$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-weather-summary' ] );
?>
<span <?php echo $wrapper_attrs; ?>><?php echo esc_html( $value ); ?></span>
