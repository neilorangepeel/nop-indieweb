<?php
/**
 * Match Timeline: a Match post's takes, oldest first, so the page reads from
 * kick-off to full time. Each take keeps its own permalink (the minute links to
 * it) and is an h-entry inside the match's.
 *
 * @var array    $attributes Block attributes.
 * @var WP_Block $block      Block instance.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$nop_match_id = (int) ( $block->context['postId'] ?? get_the_ID() );
$nop_takes    = $nop_match_id && 'match' === get_post_meta( $nop_match_id, 'nop_indieweb_post_kind', true )
	? \NOP\IndieWeb\Football\Match_Day::takes( $nop_match_id )
	: [];
if ( ! $nop_takes ) {
	return;
}

$nop_items = '';
foreach ( $nop_takes as $nop_take ) {
	$nop_minute = (string) get_post_meta( $nop_take, 'nop_indieweb_match_minute', true );
	$nop_label  = match ( true ) {
		'' === $nop_minute                 => __( 'Pre-match', 'nop-indieweb' ),
		in_array( $nop_minute, [ 'HT', 'FT' ], true ) => $nop_minute,
		default                            => $nop_minute . '′',
	};
	$nop_items .= sprintf(
		'<li class="nop-match-timeline__take h-entry" id="take-%1$d"><a class="nop-match-timeline__minute u-url" href="%2$s"><time class="dt-published" datetime="%3$s">%4$s</time></a><div class="nop-match-timeline__text e-content">%5$s</div></li>',
		$nop_take,
		esc_url( (string) get_permalink( $nop_take ) ),
		esc_attr( (string) get_post_time( 'c', true, $nop_take ) ),
		esc_html( $nop_label ),
		apply_filters( 'the_content', (string) get_post_field( 'post_content', $nop_take ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the post's own rendered content.
	);
}

printf(
	'<ol %1$s>%2$s</ol>',
	get_block_wrapper_attributes( [ 'class' => 'nop-match-timeline', 'aria-label' => esc_attr__( 'Takes during the match', 'nop-indieweb' ) ] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	$nop_items // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
);
