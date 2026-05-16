<?php
/**
 * Venue Categories block — server-side render.
 *
 * Renders Foursquare venue categories as inline pills.
 * Outputs nothing when the meta value is absent or empty.
 */

declare( strict_types=1 );

$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : // phpcs:ignore WordPress.Security.NonceVerification
           ( $block->context['postId'] ?? get_the_ID() );

$is_editor = defined( 'REST_REQUEST' ) && REST_REQUEST
	&& isset( $_GET['context'] ) && 'edit' === $_GET['context']; // phpcs:ignore WordPress.Security.NonceVerification

$terms      = $post_id ? get_the_terms( $post_id, 'nop_venue_category' ) : [];
$categories = ( $terms && ! is_wp_error( $terms ) ) ? wp_list_pluck( $terms, 'name' ) : [];

// Editor preview when no post context or the post has no terms yet —
// shows the block's footprint so the template editor doesn't display
// the built-in "Block rendered as empty" placeholder.
if ( ! $categories ) {
	if ( ! $is_editor ) {
		return;
	}
	$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-venue-categories' ] );
	?>
	<p <?php echo $wrapper_attrs; ?>>
		<span class="nop-venue-category p-category">Bar</span>
		<span class="nop-venue-category p-category">Pub</span>
	</p>
	<?php
	return;
}

$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-venue-categories' ] );
?>
<p <?php echo $wrapper_attrs; ?>>
	<?php foreach ( $terms as $term ) : ?>
		<a href="<?php echo esc_url( get_term_link( $term ) ); ?>" class="nop-venue-category p-category"><?php echo esc_html( $term->name ); ?></a>
	<?php endforeach; ?>
</p>
