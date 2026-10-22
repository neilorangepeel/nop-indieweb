<?php
/**
 * Photos: the post's photos (nop_indieweb_photo_ids; else the first Video block in the post, playing in place; else the
 * featured image), one at a time, filling the block's height.
 * They are a core Gallery of core Image blocks with Expand on click, so the full-size view is WordPress's own lightbox,
 * stepping through the gallery. This block adds only the strip: several photos get previous and next, dots, and
 * swiping (a scroll-snap strip). The first photo's shape is published as --nop-photos-ratio, so a theme can size the
 * column to it.
 *
 * @package NOP_IndieWeb
 *
 * @var array<string, mixed> $attributes
 * @var \WP_Block            $block
 */

declare( strict_types=1 );

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
$ids     = $post_id ? array_values( array_filter( array_map( 'absint', (array) get_post_meta( $post_id, 'nop_indieweb_photo_ids', true ) ), 'wp_attachment_is_image' ) ) : [];
// No photos but a video in the post (a story): the video is the media, at its poster's shape, playing in place.
if ( ! $ids && $post_id ) {
	foreach ( parse_blocks( (string) get_post_field( 'post_content', $post_id ) ) as $parsed ) {
		if ( 'core/video' !== $parsed['blockName'] ) {
			continue;
		}
		$poster = wp_get_attachment_metadata( (int) get_post_thumbnail_id( $post_id ) );
		$shape  = ! empty( $poster['width'] ) && ! empty( $poster['height'] ) ? $poster['width'] . ' / ' . $poster['height'] : '9 / 16';
		$video  = get_block_wrapper_attributes( [
			'class'               => 'nop-photos has-video',
			'style'               => '--nop-photos-ratio:' . $shape,
			'data-wp-interactive' => 'nop-indieweb/photos',
			'data-wp-context'     => wp_json_encode( [ 'playing' => false, 'play' => __( 'Play video', 'nop-indieweb' ), 'pause' => __( 'Pause video', 'nop-indieweb' ) ] ),
			'data-wp-class--is-playing' => 'context.playing',
		] );
		// The video plays in place without the browser's own bar (it would sit under whatever a theme lays over the
		// picture): one large, labelled play/pause button does, and clicking the video does too.
		$player = new WP_HTML_Tag_Processor( render_block( $parsed ) );
		if ( $player->next_tag( 'video' ) ) {
			$player->remove_attribute( 'controls' );
			$player->set_attribute( 'playsinline', true );
			$player->set_attribute( 'data-wp-on--click', 'actions.toggle' );
			$player->set_attribute( 'data-wp-on--play', 'actions.played' );
			$player->set_attribute( 'data-wp-on--pause', 'actions.paused' );
			$player->set_attribute( 'data-wp-on--ended', 'actions.paused' );
		}
		$button = '<button type="button" class="nop-photos__play" data-wp-on--click="actions.toggle" data-wp-bind--aria-label="state.playLabel" aria-label="' . esc_attr__( 'Play video', 'nop-indieweb' ) . '"></button>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes from get_block_wrapper_attributes(), the video rendered by core, the button escaped above.
		echo '<div ' . $video . '>' . $player->get_updated_html() . $button . '</div>';
		return;
	}
}
if ( ! $ids && $post_id && has_post_thumbnail( $post_id ) ) {
	$ids = [ (int) get_post_thumbnail_id( $post_id ) ];
}
if ( ! $ids ) {
	return;
}

$n     = count( $ids );
$meta  = wp_get_attachment_metadata( $ids[0] );
$ratio = ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ? $meta['width'] . ' / ' . $meta['height'] : '4 / 3';
// A fixed shape (an album's square cover), cropping the photos to it.
if ( preg_match( '#^\d+(\.\d+)?( ?/ ?\d+(\.\d+)?)?$#', (string) ( $attributes['ratio'] ?? '' ) ) ) {
	$ratio = (string) $attributes['ratio'];
}

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
