<?php
/**
 * Exercise Map block — server-side render.
 *
 * Renders a cached Geoapify static map image centred on the workout start
 * location, with an OpenStreetMap link and hidden mf2 data elements.
 * Outputs nothing when coordinates or a Geoapify API key are absent.
 */

declare( strict_types=1 );

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_editor = (
	defined( 'REST_REQUEST' ) && REST_REQUEST &&
	isset( $_GET['context'] ) && 'edit' === $_GET['context'] // phpcs:ignore WordPress.Security.NonceVerification
);

$post_id = $block->context['postId'] ?? get_the_ID();
if ( $is_editor && isset( $_GET['post_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
	$candidate = absint( $_GET['post_id'] ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( $candidate && current_user_can( 'edit_post', $candidate ) ) {
		$post_id = $candidate;
	}
}

if ( ! $post_id ) {
	$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-exercise-map nop-exercise-map--preview' ] );
	?>
	<div <?php echo wp_kses_data( $wrapper_attrs ); ?>>
		<div class="nop-exercise-map__placeholder" role="img" aria-label="<?php esc_attr_e( 'Map preview', 'nop-indieweb' ); ?>"></div>
		<p class="nop-exercise-map__caption"><span><?php esc_html_e( 'View on OpenStreetMap', 'nop-indieweb' ); ?></span></p>
	</div>
	<?php
	return;
}

$lat        = get_post_meta( $post_id, 'nop_indieweb_exercise_start_lat', true );
$lng        = get_post_meta( $post_id, 'nop_indieweb_exercise_start_lng', true );
$post_title = get_the_title( $post_id );

if ( ! $lat || ! $lng ) {
	if ( ! $is_editor ) {
		return;
	}
	$lat        = '54.5967';
	$lng        = '-5.9347';
	$post_title = $post_title ?: 'Workout';
}

$map_url = sprintf(
	'https://www.openstreetmap.org/?mlat=%s&mlon=%s&zoom=15&layers=M',
	rawurlencode( $lat ),
	rawurlencode( $lng )
);

/* translators: %s: post title or workout label */
$map_title   = $post_title ? sprintf( __( 'Start of %s', 'nop-indieweb' ), $post_title ) : __( 'Workout start location', 'nop-indieweb' );
$map_img_url = '';
$map_w       = 0;
$map_h       = 0;

if ( ! $is_editor ) {
	$cached = (string) get_post_meta( $post_id, 'nop_indieweb_exercise_map_url', true );
	if ( $cached ) {
		$map_img_url = $cached;
		$map_w       = 620;
		$map_h       = 310;
	} else {
		$geoapify_key = trim( \NOP\IndieWeb\nop_indieweb_get_option( 'maps.geoapify_api_key', '' ) );
		if ( $geoapify_key ) {
			$content_size_raw = wp_get_global_settings( [ 'layout', 'contentSize' ] );
			$map_w = 620;
			if ( $content_size_raw && preg_match( '/^(\d+(?:\.\d+)?)px$/i', $content_size_raw, $csm ) ) {
				$map_w = (int) round( (float) $csm[1] );
			}
			$map_h       = (int) round( $map_w / 2 );
			$map_img_url = \NOP\IndieWeb\nop_indieweb_get_or_cache_exercise_map_image(
				$post_id, (float) $lat, (float) $lng, $map_w, $map_h, $geoapify_key
			);
		}
	}
}

if ( ! $map_img_url && ! $is_editor ) {
	return;
}

$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-exercise-map' ] );
?>
<div <?php echo wp_kses_data( $wrapper_attrs ); ?>>

	<?php if ( $is_editor ) : ?>
	<div class="nop-exercise-map__placeholder" role="img" aria-label="<?php echo esc_attr( $map_title ); ?>"></div>
	<?php else : ?>
	<a class="nop-exercise-map__link" href="<?php echo esc_url( $map_url ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: map title */ __( '%s — View on OpenStreetMap', 'nop-indieweb' ), $map_title ) ); ?>">
		<img class="nop-exercise-map__img"
			src="<?php echo esc_url( $map_img_url ); ?>"
			width="<?php echo esc_attr( (string) $map_w ); ?>"
			height="<?php echo esc_attr( (string) $map_h ); ?>"
			alt=""
			loading="lazy" decoding="async" aria-hidden="true">
	</a>
	<?php endif; ?>

	<p class="nop-exercise-map__caption">
		<span aria-hidden="true"><?php esc_html_e( 'View on OpenStreetMap', 'nop-indieweb' ); ?></span>
	</p>

	<?php // Hidden mf2 properties parsed by microformat crawlers. ?>
	<data class="p-latitude"  value="<?php echo esc_attr( $lat ); ?>"></data>
	<data class="p-longitude" value="<?php echo esc_attr( $lng ); ?>"></data>

</div>
