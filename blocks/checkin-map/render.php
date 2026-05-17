<?php
/**
 * Checkin Map block — server-side render.
 *
 * Renders a cached Geoapify static map image with an OpenStreetMap link
 * and hidden mf2 p-latitude / p-longitude / p-name data elements.
 * Outputs nothing when coordinates or a Geoapify API key are absent.
 */

declare( strict_types=1 );

$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : // phpcs:ignore WordPress.Security.NonceVerification
           ( $block->context['postId'] ?? get_the_ID() );

if ( ! $post_id ) {
	$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-checkin-map nop-checkin-map--preview' ] );
	?>
	<div <?php echo $wrapper_attrs; ?>>
		<div class="nop-checkin-map__placeholder" role="img" aria-label="Map preview"></div>
		<p class="nop-checkin-map__caption"><span>View on OpenStreetMap</span></p>
	</div>
	<?php
	return;
}

$is_editor = (
	defined( 'REST_REQUEST' ) && REST_REQUEST &&
	isset( $_GET['context'] ) && 'edit' === $_GET['context'] // phpcs:ignore WordPress.Security.NonceVerification
);

$lat         = get_post_meta( $post_id, 'nop_indieweb_venue_lat',  true );
$lng         = get_post_meta( $post_id, 'nop_indieweb_venue_lng',  true );
$venue_name  = get_post_meta( $post_id, 'nop_indieweb_venue_name', true );

if ( ! $lat || ! $lng ) {
	if ( ! $is_editor ) {
		return;
	}
	$lat        = '54.5967';
	$lng        = '-5.9347';
	$venue_name = $venue_name ?: 'The Crown Bar';
}

$map_url = sprintf(
	'https://www.openstreetmap.org/?mlat=%s&mlon=%s&zoom=17&layers=M',
	rawurlencode( $lat ),
	rawurlencode( $lng )
);

$map_title   = $venue_name ? sprintf( 'Map showing %s', $venue_name ) : 'Location map';
$map_img_url = '';
$map_w       = 0;
$map_h       = 0;

if ( ! $is_editor ) {
	$cached = (string) get_post_meta( $post_id, 'nop_indieweb_map_url', true );
	if ( $cached ) {
		// Hot path: image already exists. Skip global settings + regex + helper call.
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
			$map_img_url = \NOP\IndieWeb\nop_indieweb_get_or_cache_map_image(
				$post_id, (float) $lat, (float) $lng, $map_w, $map_h, $geoapify_key
			);
		}
	}
}

if ( ! $map_img_url && ! $is_editor ) {
	return;
}

$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-checkin-map' ] );
?>
<div <?php echo $wrapper_attrs; ?>>

	<?php if ( $is_editor ) : ?>
	<div class="nop-checkin-map__placeholder" role="img" aria-label="<?php echo esc_attr( $map_title ); ?>"></div>
	<?php else : ?>
	<img class="nop-checkin-map__img"
		src="<?php echo esc_url( $map_img_url ); ?>"
		width="<?php echo esc_attr( (string) $map_w ); ?>"
		height="<?php echo esc_attr( (string) $map_h ); ?>"
		alt="<?php echo esc_attr( $map_title ); ?>"
		loading="lazy" decoding="async">
	<?php endif; ?>

	<p class="nop-checkin-map__caption">
		<a href="<?php echo esc_url( $map_url ); ?>" target="_blank" rel="noopener noreferrer">View on OpenStreetMap</a>
	</p>

	<?php // Hidden mf2 properties parsed by microformat crawlers. ?>
	<data class="p-latitude"  value="<?php echo esc_attr( $lat ); ?>"></data>
	<data class="p-longitude" value="<?php echo esc_attr( $lng ); ?>"></data>
	<?php if ( $venue_name ) : ?>
	<data class="p-name" value="<?php echo esc_attr( $venue_name ); ?>"></data>
	<?php endif; ?>

</div>
