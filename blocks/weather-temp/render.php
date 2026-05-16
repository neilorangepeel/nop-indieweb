<?php
/**
 * Weather Temperature block — server-side render.
 *
 * Reads nop_indieweb_weather_temp_c or _temp_f meta on the current post
 * based on the chosen unit, rounds to an integer for display, and outputs
 * a paragraph. The °C/°F suffix is optional via the inspector.
 *
 * Outputs nothing on the frontend when the post has no weather meta.
 * Returns a preview value ("12") in the editor so the block is visible
 * during composition.
 */

declare( strict_types=1 );

$unit         = ( ( $attributes['unit'] ?? 'c' ) === 'f' ) ? 'f' : 'c';
$show_symbol  = ! isset( $attributes['showSymbol'] ) || (bool) $attributes['showSymbol'];

$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : // phpcs:ignore WordPress.Security.NonceVerification
           ( $block->context['postId'] ?? get_the_ID() );

$meta_key = 'f' === $unit ? 'nop_indieweb_weather_temp_f' : 'nop_indieweb_weather_temp_c';
$raw      = $post_id ? (string) get_post_meta( $post_id, $meta_key, true ) : '';

$is_editor = defined( 'REST_REQUEST' ) && REST_REQUEST
	&& isset( $_GET['context'] ) && 'edit' === $_GET['context']; // phpcs:ignore WordPress.Security.NonceVerification

if ( '' === $raw ) {
	if ( ! $is_editor ) {
		return;
	}
	$raw = 'f' === $unit ? '54' : '12';
}

// Stored values are floats like "9.3" — display as integer for the inline
// "9°C" treatment. Sub-degree precision adds noise without information.
$display = (string) (int) round( (float) $raw );

if ( $show_symbol ) {
	$display .= 'f' === $unit ? '°F' : '°C';
}

$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-weather-temp' ] );
?>
<span <?php echo $wrapper_attrs; ?>><?php echo esc_html( $display ); ?></span>
