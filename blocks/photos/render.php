<?php
/**
 * Photos: the post's photos (nop_indieweb_photo_ids, else the featured image), one at a time, filling the block's height.
 * Several get previous and next, dots, and swiping (a scroll-snap strip); any opens full size in a modal <dialog> kept
 * inside the block, so it travels with a client-side navigation. The first photo's shape is published as
 * --nop-photos-ratio, so a theme can size the column to it.
 *
 * @package NOP_IndieWeb
 */

declare( strict_types=1 );

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
$ids     = $post_id ? array_values( array_filter( array_map( 'absint', (array) get_post_meta( $post_id, 'nop_indieweb_photo_ids', true ) ), 'wp_attachment_is_image' ) ) : [];
if ( ! $ids && $post_id && has_post_thumbnail( $post_id ) ) {
	$ids = [ (int) get_post_thumbnail_id( $post_id ) ];
}
if ( ! $ids ) {
	return;
}

$n     = count( $ids );
$meta  = wp_get_attachment_metadata( $ids[0] );
$ratio = ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ? $meta['width'] . ' / ' . $meta['height'] : '4 / 3';

$slides = '';
$views  = '';
foreach ( $ids as $i => $id ) {
	$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
	/* translators: 1: photo number, 2: number of photos. */
	$open    = sprintf( __( 'View photo %1$d of %2$d full size', 'nop-indieweb' ), $i + 1, $n );
	$slides .= '<li class="nop-photos__slide"><button type="button" class="nop-photos__open" data-wp-on--click="actions.open" data-wp-context=\'' . wp_json_encode( [ 'photo' => $i ] ) . '\' aria-label="' . esc_attr( $open ) . '">'
		. wp_get_attachment_image( $id, 'large', false, [ 'class' => 'nop-photos__img', 'alt' => $alt, 'loading' => 0 === $i ? 'eager' : 'lazy', 'decoding' => 'async', 'sizes' => '(min-width: 900px) 60vw, 100vw' ] )
		. '</button></li>';
	$views  .= '<li class="nop-photos__slide">' . wp_get_attachment_image( $id, 'full', false, [ 'class' => 'nop-photos__img', 'alt' => $alt, 'loading' => 'lazy', 'decoding' => 'async', 'sizes' => '100vw' ] ) . '</li>';
}

$steps = '';
if ( $n > 1 ) {
	$dots = '';
	for ( $i = 0; $i < $n; $i++ ) {
		$dots .= '<span class="nop-photos__dot" data-wp-context=\'' . wp_json_encode( [ 'dot' => $i ] ) . '\' data-wp-class--is-current="state.isCurrent"></span>';
	}
	$steps = '<button type="button" class="nop-photos__step nop-photos__previous" data-wp-on--click="actions.previous" data-wp-bind--disabled="state.isFirst" aria-label="' . esc_attr__( 'Previous photo', 'nop-indieweb' ) . '"></button>'
		. '<button type="button" class="nop-photos__step nop-photos__next" data-wp-on--click="actions.next" data-wp-bind--disabled="state.isLast" aria-label="' . esc_attr__( 'Next photo', 'nop-indieweb' ) . '"></button>'
		. '<div class="nop-photos__dots" aria-hidden="true">' . $dots . '</div>';
}

$wrapper = get_block_wrapper_attributes( [
	'class'               => 'nop-photos' . ( $n > 1 ? ' has-several' : '' ),
	'style'               => '--nop-photos-ratio:' . $ratio,
	'data-wp-interactive' => 'nop-indieweb/photos',
	'data-wp-context'     => wp_json_encode( [ 'at' => 0, 'count' => $n ] ),
	'role'                => 'group',
	'aria-roledescription' => __( 'carousel', 'nop-indieweb' ),
	'aria-label'          => __( 'Photos', 'nop-indieweb' ),
] );

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes from get_block_wrapper_attributes(), images from wp_get_attachment_image(), every string escaped above.
echo '<div ' . $wrapper . '><ul class="nop-photos__track" data-wp-on--scroll="actions.follow">' . $slides . '</ul>' . $steps
	. '<dialog class="nop-photos__viewer" aria-label="' . esc_attr__( 'Photos, full size', 'nop-indieweb' ) . '" data-wp-on--close="actions.closed"><ul class="nop-photos__track" data-wp-on--scroll="actions.follow">' . $views . '</ul>' . $steps
	. '<button type="button" class="nop-photos__close" data-wp-on--click="actions.close">' . esc_html__( 'Close', 'nop-indieweb' ) . '</button></dialog></div>';
