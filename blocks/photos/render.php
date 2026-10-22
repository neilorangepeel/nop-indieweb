<?php
/**
 * Photos: the post's photos (nop_indieweb_photo_ids, else the featured image), one at a time, filling the block's height.
 * They are a core Gallery of core Image blocks with Expand on click, so the full-size view is WordPress's own lightbox,
 * stepping through the gallery. This block adds only the strip: several photos get previous and next, dots, and
 * swiping (a scroll-snap strip). The first photo's shape is published as --nop-photos-ratio, so a theme can size the
 * column to it.
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

// The gallery as block markup, rendered as blocks, so core adds the lightbox (with the gallery's navigation) itself.
$images = '';
foreach ( $ids as $id ) {
	$src     = (string) wp_get_attachment_image_url( $id, 'large' );
	$alt     = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
	$images .= '<!-- wp:image {"id":' . $id . ',"sizeSlug":"large","linkDestination":"none","scale":"cover","lightbox":{"enabled":true}} -->'
		. '<figure class="wp-block-image size-large"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . $id . '" style="object-fit:cover"/></figure>'
		. '<!-- /wp:image -->';
}
$gallery = render_block(
	parse_blocks(
		'<!-- wp:gallery {"linkTo":"none","className":"nop-photos__track"} -->'
		. '<figure class="wp-block-gallery has-nested-images columns-default is-cropped nop-photos__track">' . $images . '</figure>'
		. '<!-- /wp:gallery -->'
	)[0]
);
// The strip follows its scroll, for the dots and the arrows' ends. Core makes the gallery a core/gallery region, so the
// directive names this block's store.
$strip = new WP_HTML_Tag_Processor( $gallery );
if ( $strip->next_tag( [ 'class_name' => 'nop-photos__track' ] ) ) {
	$strip->set_attribute( 'data-wp-on--scroll', 'nop-indieweb/photos::actions.follow' );
	$gallery = $strip->get_updated_html();
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
	'class'                => 'nop-photos' . ( $n > 1 ? ' has-several' : '' ),
	'style'                => '--nop-photos-ratio:' . $ratio,
	'data-wp-interactive'  => 'nop-indieweb/photos',
	'data-wp-context'      => wp_json_encode( [ 'at' => 0, 'count' => $n ] ),
	'role'                 => 'group',
	'aria-roledescription' => __( 'carousel', 'nop-indieweb' ),
	'aria-label'           => __( 'Photos', 'nop-indieweb' ),
] );

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes from get_block_wrapper_attributes(), the gallery rendered by core, every string escaped above.
echo '<div ' . $wrapper . '>' . $gallery . $steps . '</div>';
