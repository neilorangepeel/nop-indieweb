<?php
/**
 * Venue Link block — server-side render.
 *
 * Renders a link to the venue page (Foursquare/Swarm) with the hostname
 * derived from the URL as the display label. Outputs nothing when absent.
 */

declare( strict_types=1 );

$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : // phpcs:ignore WordPress.Security.NonceVerification
           ( $block->context['postId'] ?? get_the_ID() );

if ( ! $post_id ) {
	$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-venue-link' ] );
	?>
	<p <?php echo $wrapper_attrs; ?>>
		<span>View on foursquare.com</span>
	</p>
	<?php
	return;
}

$venue_url = get_post_meta( $post_id, 'nop_indieweb_venue_url', true );
if ( ! $venue_url ) {
	return;
}

$host  = wp_parse_url( $venue_url, PHP_URL_HOST ) ?? $venue_url;
$label = sprintf( 'View on %s', $host );

$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-venue-link' ] );
?>
<p <?php echo $wrapper_attrs; ?>>
	<a href="<?php echo esc_url( $venue_url ); ?>" target="_blank" rel="noopener noreferrer">
		<?php echo esc_html( $label ); ?>
	</a>
</p>
