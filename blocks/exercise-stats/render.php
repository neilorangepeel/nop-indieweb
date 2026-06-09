<?php
/**
 * Exercise Stats block — a grid of workout metrics, rendering only the stats
 * that actually have data so it reads cleanly whether a post carries four
 * numbers (an old phone-GPS run) or a dozen (an Apple Watch ride).
 *
 * @var WP_Block $block
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_editor = (
	defined( 'REST_REQUEST' ) && REST_REQUEST &&
	isset( $_GET['context'] ) && 'edit' === $_GET['context'] // phpcs:ignore WordPress.Security.NonceVerification
);

$post_id = $block->context['postId'] ?? get_the_ID();
if ( $is_editor && isset( $_GET['post_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
	$post_id = (int) $_GET['post_id']; // phpcs:ignore WordPress.Security.NonceVerification
}

// field => label. Primary stats are the headline numbers; secondary ones are
// the supporting detail shown smaller beneath. pace and speed are mutually
// exclusive by activity type, so only the applicable one ever resolves.
$primary = [
	'exercise_distance'  => __( 'Distance', 'nop-indieweb' ),
	'exercise_duration'  => __( 'Time', 'nop-indieweb' ),
	'exercise_pace'      => __( 'Pace', 'nop-indieweb' ),
	'exercise_speed'     => __( 'Speed', 'nop-indieweb' ),
	'exercise_elevation' => __( 'Elevation', 'nop-indieweb' ),
];
$secondary = [
	'exercise_avg_hr'          => __( 'Avg HR', 'nop-indieweb' ),
	'exercise_max_hr'          => __( 'Max HR', 'nop-indieweb' ),
	'exercise_calories'        => __( 'Calories', 'nop-indieweb' ),
	'exercise_max_speed'       => __( 'Max speed', 'nop-indieweb' ),
	'exercise_elevation_range' => __( 'Elev range', 'nop-indieweb' ),
	'exercise_max_grade'       => __( 'Max grade', 'nop-indieweb' ),
	'exercise_gear'            => __( 'Gear', 'nop-indieweb' ),
];

// Editor preview: when the block is dropped on a post with no exercise data,
// show representative numbers so the layout is designable.
$preview = [
	'exercise_distance'  => '7.1 km',
	'exercise_duration'  => '34:57',
	'exercise_pace'      => '4:56 /km',
	'exercise_elevation' => '+145 m',
	'exercise_avg_hr'    => '152 bpm',
	'exercise_calories'  => '415 kcal',
];

$resolve = static function ( string $field ) use ( $post_id, $is_editor, $preview ): ?string {
	$value = \NOP\IndieWeb\nop_indieweb_exercise_stat( $field, $post_id );
	if ( null === $value && $is_editor && isset( $preview[ $field ] ) ) {
		return $preview[ $field ];
	}
	return $value;
};

// Primary stats: prominent cells (big number + label).
$cells = '';
foreach ( $primary as $field => $label ) {
	$value = $resolve( $field );
	if ( null === $value || '' === $value ) {
		continue;
	}
	$cells .= sprintf(
		'<div class="nop-exercise-stat"><span class="nop-exercise-stat__value">%s</span><span class="nop-exercise-stat__label">%s</span></div>',
		esc_html( $value ),
		esc_html( $label )
	);
}
$primary_row = '' === $cells ? '' : '<div class="nop-exercise-stats__primary">' . $cells . '</div>';

// Secondary stats: a single muted inline line — supporting detail, not headline.
$items = [];
foreach ( $secondary as $field => $label ) {
	$value = $resolve( $field );
	if ( null === $value || '' === $value ) {
		continue;
	}
	$items[] = sprintf(
		'<span class="nop-exercise-stat-inline"><span class="nop-exercise-stat-inline__label">%s</span> %s</span>',
		esc_html( $label ),
		esc_html( $value )
	);
}
$secondary_row = $items ? '<div class="nop-exercise-stats__secondary">' . implode( '<span class="nop-exercise-stats__sep"> · </span>', $items ) . '</div>' : '';

$rows = $primary_row . $secondary_row;
if ( '' === $rows ) {
	return;
}

printf(
	'<div %s>%s</div>',
	wp_kses_data( get_block_wrapper_attributes( [ 'class' => 'nop-exercise-stats' ] ) ),
	$rows // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cells built from esc_html() values above
);
