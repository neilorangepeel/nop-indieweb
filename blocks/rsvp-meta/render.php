<?php
/**
 * RSVP Meta block — server-side render.
 *
 * Renders the RSVP status badge and event link for an RSVP post.
 */

declare( strict_types=1 );

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id = $block->context['postId'] ?? get_the_ID();

$rsvp_labels = [
	'yes'        => __( 'Going',      'nop-indieweb' ),
	'maybe'      => __( 'Maybe',      'nop-indieweb' ),
	'interested' => __( 'Interested', 'nop-indieweb' ),
	'no'         => __( 'Not going',  'nop-indieweb' ),
];

$rsvp_colors = [
	'yes'        => '#15803d', // green-700 — 4.6:1 on white
	'maybe'      => '#92400e', // amber-800 — 5.1:1 on white (d97706 fails AA)
	'interested' => '#1d4ed8', // blue-700  — 5.0:1 on white
	'no'         => '#b91c1c', // red-700   — 5.0:1 on white
];

if ( ! $post_id ) {
	$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-rsvp-meta nop-rsvp-meta--preview' ] );
	?>
	<div <?php echo $wrapper_attrs; ?>>
		<p class="nop-rsvp-meta__status">
			<span class="nop-rsvp-badge" style="--rsvp-color: #16a34a"><?php esc_html_e( 'Going', 'nop-indieweb' ); ?></span>
		</p>
		<p class="nop-rsvp-meta__event">
			<?php esc_html_e( 'Event:', 'nop-indieweb' ); ?> <a href="#" onclick="return false;">IndieWebCamp 2025 — indieweb.org</a>
		</p>
	</div>
	<?php
	return;
}

$rsvp_value = (string) get_post_meta( $post_id, 'nop_indieweb_rsvp',        true );
$event_url  = (string) get_post_meta( $post_id, 'nop_indieweb_in_reply_to', true );

if ( ! $rsvp_value && ! $event_url ) {
	// In the block editor (REST render request), show a placeholder so the block
	// doesn't trigger a "block rendered as empty" error before data is entered.
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-rsvp-meta nop-rsvp-meta--placeholder' ] );
		echo '<div ' . $wrapper_attrs . '>';
		echo '<p class="nop-rsvp-meta__status"><span class="nop-rsvp-badge" style="--rsvp-color:#9ca3af">' . esc_html__( 'Set RSVP in sidebar →', 'nop-indieweb' ) . '</span></p>';
		echo '</div>';
	}
	return;
}

$rsvp_label = $rsvp_labels[ $rsvp_value ] ?? ucfirst( $rsvp_value );
$rsvp_color = $rsvp_colors[ $rsvp_value ] ?? '#6b7280';

$event_host = $event_url ? ( wp_parse_url( $event_url, PHP_URL_HOST ) ?? $event_url ) : '';

$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-rsvp-meta' ] );
?>
<div <?php echo $wrapper_attrs; ?>>

	<?php if ( $rsvp_value ) : ?>
	<p class="nop-rsvp-meta__status">
		<span class="nop-rsvp-badge" style="--rsvp-color: <?php echo esc_attr( $rsvp_color ); ?>">
			<?php echo esc_html( $rsvp_label ); ?>
		</span>
	</p>
	<?php endif; ?>

	<?php if ( $event_url ) : ?>
	<p class="nop-rsvp-meta__event">
		<?php esc_html_e( 'Event:', 'nop-indieweb' ); ?>
		<a href="<?php echo esc_url( $event_url ); ?>"
		   target="_blank"
		   rel="noopener noreferrer">
			<?php echo esc_html( $event_host ); ?>
		</a>
	</p>
	<?php endif; ?>

</div>
