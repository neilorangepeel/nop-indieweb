<?php
declare( strict_types=1 );

$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : // phpcs:ignore WordPress.Security.NonceVerification
           ( $block->context['postId'] ?? get_the_ID() );

$is_editor = defined( 'REST_REQUEST' ) && REST_REQUEST
	&& isset( $_GET['context'] ) && 'edit' === $_GET['context']; // phpcs:ignore WordPress.Security.NonceVerification

$lat = $post_id ? (float) get_post_meta( $post_id, 'nop_indieweb_venue_lat', true ) : 0;
$lng = $post_id ? (float) get_post_meta( $post_id, 'nop_indieweb_venue_lng', true ) : 0;

if ( ! $lat || ! $lng ) {
	if ( $is_editor ) {
		$lat = 54.5973;
		$lng = -5.9301;
	} else {
		return;
	}
}

$lat_dir = $lat >= 0 ? 'N' : 'S';
$lng_dir = $lng >= 0 ? 'E' : 'W';

$lat_str = number_format( abs( $lat ), 3 );
$lng_str = number_format( abs( $lng ), 3 );

$value = sprintf( '%s ° %s · %s ° %s', $lat_str, $lat_dir, $lng_str, $lng_dir );

$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-venue-coordinates' ] );
?>
<p <?php echo $wrapper_attrs; ?>><?php echo esc_html( $value ); ?></p>
