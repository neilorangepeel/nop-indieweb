<?php
/**
 * Venue Categories block — server-side render.
 *
 * Renders Foursquare venue categories as inline pills.
 * Outputs nothing when the meta value is absent or empty.
 */

declare( strict_types=1 );

$post_id = $block->context['postId'] ?? get_the_ID();

if ( ! $post_id ) {
	$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-venue-categories' ] );
	?>
	<p <?php echo $wrapper_attrs; ?>>
		<span class="nop-venue-category p-category">Bar</span>
		<span class="nop-venue-category p-category">Pub</span>
	</p>
	<?php
	return;
}

$terms      = get_the_terms( $post_id, 'nop_venue_category' );
$categories = ( $terms && ! is_wp_error( $terms ) ) ? wp_list_pluck( $terms, 'name' ) : [];

if ( ! $categories ) {
	return;
}

$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-venue-categories' ] );
?>
<p <?php echo $wrapper_attrs; ?>>
	<?php foreach ( $terms as $term ) : ?>
		<a href="<?php echo esc_url( get_term_link( $term ) ); ?>" class="nop-venue-category p-category"><?php echo esc_html( $term->name ); ?></a>
	<?php endforeach; ?>
</p>
