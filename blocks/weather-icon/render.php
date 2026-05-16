<?php
/**
 * Weather Icon block — server-side render.
 *
 * Reads nop_indieweb_weather_icon meta on the current post and renders the
 * matching Phosphor SVG inside a wp-block-icon wrapper, so it inherits the
 * same color/size context as a stock core/icon block.
 *
 * Front-end: renders truly nothing when the post has no weather meta, so
 * posts without weather data look like posts without weather data.
 *
 * Editor: falls back to a "cloudy" placeholder so the block is visible in
 * the template editor and on posts being authored before enrichment runs.
 * Without this, the editor shows its built-in "Block rendered as empty"
 * box that looks like a stack trace to non-technical authors.
 *
 * The SVGs use stroke="currentColor" so the icon colour follows whatever
 * text colour the wrapping context (or block inspector) sets.
 */

declare( strict_types=1 );

$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : // phpcs:ignore WordPress.Security.NonceVerification
           ( $block->context['postId'] ?? get_the_ID() );

$slug = $post_id ? sanitize_key( (string) get_post_meta( $post_id, 'nop_indieweb_weather_icon', true ) ) : '';

$is_editor = defined( 'REST_REQUEST' ) && REST_REQUEST
	&& isset( $_GET['context'] ) && 'edit' === $_GET['context']; // phpcs:ignore WordPress.Security.NonceVerification

if ( '' === $slug ) {
	if ( ! $is_editor ) {
		return;
	}
	$slug = 'cloudy';
}

// Pirate Weather icon vocabulary → Phosphor SVG file. Sleet and snow share
// cloud-snow since Phosphor has no distinct sleet glyph; the underlying
// summary string carries the precise wording.
$icon_map = [
	'clear-day'           => 'sun',
	'clear-night'         => 'moon',
	'partly-cloudy-day'   => 'cloud-sun',
	'partly-cloudy-night' => 'cloud-moon',
	'cloudy'              => 'cloud',
	'fog'                 => 'cloud-fog',
	'rain'                => 'cloud-rain',
	'sleet'               => 'cloud-snow',
	'snow'                => 'cloud-snow',
	'wind'                => 'wind',
];

$file = $icon_map[ $slug ] ?? 'cloud';
$path = __DIR__ . '/icons/' . $file . '.svg';

if ( ! is_readable( $path ) ) {
	return;
}

// Phosphor SVGs are 256×256 viewBox, single-line, no external refs.
$svg = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

// Add aria-label from the summary if we have one, so the icon is announced
// meaningfully ("Partly Cloudy") to assistive tech instead of being silent.
$summary = $post_id ? (string) get_post_meta( $post_id, 'nop_indieweb_weather_summary', true ) : '';
if ( $summary && str_starts_with( $svg, '<svg ' ) ) {
	$svg = preg_replace(
		'/<svg /',
		'<svg role="img" aria-label="' . esc_attr( $summary ) . '" ',
		$svg,
		1
	);
}

$wrapper_attrs = get_block_wrapper_attributes( [
	'class' => 'wp-block-icon nop-weather-icon nop-weather-icon--' . $slug,
] );
?>
<span <?php echo $wrapper_attrs; ?>><?php echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — SVG is from a bundled file we control ?></span>
