<?php
/**
 * Checkin Link block — server-side render.
 *
 * Renders a link to the original Swarm checkin with the hostname as
 * the display label. Outputs nothing when the meta value is absent.
 */

declare( strict_types=1 );

$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : // phpcs:ignore WordPress.Security.NonceVerification
           ( $block->context['postId'] ?? get_the_ID() );

$is_editor = defined( 'REST_REQUEST' ) && REST_REQUEST
	&& isset( $_GET['context'] ) && 'edit' === $_GET['context']; // phpcs:ignore WordPress.Security.NonceVerification

$checkin_url = $post_id ? (string) get_post_meta( $post_id, 'nop_indieweb_checkin_url', true ) : '';

if ( ! $checkin_url ) {
	if ( ! $is_editor ) {
		return;
	}
	$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-checkin-link' ] );
	?>
	<p <?php echo $wrapper_attrs; ?>>
		<span>View on swarmapp.com</span>
	</p>
	<?php
	return;
}

$host  = wp_parse_url( $checkin_url, PHP_URL_HOST ) ?: $checkin_url;
$label = sprintf( 'View on %s', $host );

$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-checkin-link' ] );
?>
<p <?php echo $wrapper_attrs; ?>>
	<a class="u-url" href="<?php echo esc_url( $checkin_url ); ?>" target="_blank" rel="noopener noreferrer">
		<?php echo esc_html( $label ); ?>
	</a>
</p>
