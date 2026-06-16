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
	<div <?php echo wp_kses_data( $wrapper_attrs ); ?>>
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
$event_name = (string) get_post_meta( $post_id, 'nop_indieweb_rsvp_event_name', true );
$event_start= (string) get_post_meta( $post_id, 'nop_indieweb_rsvp_event_start', true );
$event_end  = (string) get_post_meta( $post_id, 'nop_indieweb_rsvp_event_end', true );
$event_loc  = (string) get_post_meta( $post_id, 'nop_indieweb_rsvp_event_location', true );
$rsvp_note  = (string) get_post_meta( $post_id, 'nop_indieweb_rsvp_note', true );

// Format a stored datetime for display, falling back to the raw value when it
// can't be parsed. Drops the · H:i suffix when the stored value is date-only
// (`YYYY-MM-DD`) — the parser deliberately returns date-only for sources that
// publish just a date (e.g. theatrical runs), and emitting `· 00:00` here would
// re-introduce the fabricated-midnight lie the parser change was made to avoid.
$fmt_dt = static function ( string $value ): string {
	$value = trim( $value );
	if ( '' === $value ) {
		return '';
	}
	$date_only = (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value );
	$ts        = strtotime( $value );
	if ( false === $ts ) {
		return $value;
	}
	return date_i18n( $date_only ? 'j M Y' : 'j M Y · H:i', $ts );
};

if ( ! $rsvp_value && ! $event_url && ! $event_name ) {
	// In the block editor (REST render request), show a placeholder so the block
	// doesn't trigger a "block rendered as empty" error before data is entered.
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-rsvp-meta nop-rsvp-meta--placeholder' ] );
		echo '<div ' . wp_kses_data( $wrapper_attrs ) . '>';
		echo '<p class="nop-rsvp-meta__status"><span class="nop-rsvp-badge" style="--rsvp-color:#9ca3af">' . esc_html__( 'Set RSVP in sidebar →', 'nop-indieweb' ) . '</span></p>';
		echo '</div>';
	}
	return;
}

$rsvp_label = $rsvp_labels[ $rsvp_value ] ?? ucfirst( $rsvp_value );
$rsvp_color = $rsvp_colors[ $rsvp_value ] ?? '#6b7280';

$event_host  = $event_url ? ( wp_parse_url( $event_url, PHP_URL_HOST ) ?? $event_url ) : '';
$event_label = '' !== $event_name ? $event_name : $event_host;

$start_disp = $fmt_dt( $event_start );
$end_disp   = $fmt_dt( $event_end );
if ( '' !== $start_disp && '' !== $end_disp ) {
	/* translators: 1: event start datetime, 2: event end datetime. */
	$when = sprintf( __( '%1$s – %2$s', 'nop-indieweb' ), $start_disp, $end_disp );
} else {
	$when = $start_disp;
}

$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-rsvp-meta' ] );
?>
<div <?php echo wp_kses_data( $wrapper_attrs ); ?>>

	<?php if ( $rsvp_value ) : ?>
	<p class="nop-rsvp-meta__status">
		<span class="nop-rsvp-badge" style="--rsvp-color: <?php echo esc_attr( $rsvp_color ); ?>">
			<?php echo esc_html( $rsvp_label ); ?>
		</span>
	</p>
	<?php endif; ?>

	<?php if ( '' !== $event_label ) : ?>
	<p class="nop-rsvp-meta__event">
		<?php esc_html_e( 'Event:', 'nop-indieweb' ); ?>
		<?php if ( $event_url ) : ?>
		<a href="<?php echo esc_url( $event_url ); ?>"
		   target="_blank"
		   rel="noopener noreferrer">
			<?php echo esc_html( $event_label ); ?>
		</a>
		<?php else : ?>
		<?php echo esc_html( $event_label ); ?>
		<?php endif; ?>
	</p>
	<?php endif; ?>

	<?php if ( '' !== $when ) : ?>
	<p class="nop-rsvp-meta__when"><?php echo esc_html( $when ); ?></p>
	<?php endif; ?>

	<?php if ( '' !== $event_loc ) : ?>
	<p class="nop-rsvp-meta__location"><?php echo esc_html( $event_loc ); ?></p>
	<?php endif; ?>

	<?php if ( '' !== $rsvp_note ) : ?>
	<p class="nop-rsvp-meta__note"><?php echo esc_html( $rsvp_note ); ?></p>
	<?php endif; ?>

</div>
