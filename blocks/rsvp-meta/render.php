<?php
/**
 * RSVP Meta block — server-side render.
 *
 * Renders the RSVP status badge and event link for an RSVP post.
 */

declare( strict_types=1 );

$post_id = $block->context['postId'] ?? get_the_ID();

$rsvp_labels = [
	'yes'        => 'Going',
	'maybe'      => 'Maybe',
	'interested' => 'Interested',
	'no'         => 'Not going',
];

$rsvp_colors = [
	'yes'        => '#16a34a',
	'maybe'      => '#d97706',
	'interested' => '#2563eb',
	'no'         => '#dc2626',
];

if ( ! $post_id ) {
	$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-rsvp-meta nop-rsvp-meta--preview' ] );
	?>
	<div <?php echo $wrapper_attrs; ?>>
		<p class="nop-rsvp-meta__status">
			<span class="nop-rsvp-badge" style="--rsvp-color: #16a34a">Going</span>
		</p>
		<p class="nop-rsvp-meta__event">
			Event: <a href="#" onclick="return false;">IndieWebCamp 2025 — indieweb.org</a>
		</p>
	</div>
	<?php
	return;
}

$rsvp_value = (string) get_post_meta( $post_id, 'nop_indieweb_rsvp',        true );
$event_url  = (string) get_post_meta( $post_id, 'nop_indieweb_in_reply_to', true );

if ( ! $rsvp_value && ! $event_url ) {
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
		Event:
		<a href="<?php echo esc_url( $event_url ); ?>"
		   target="_blank"
		   rel="noopener">
			<?php echo esc_html( $event_host ); ?>
		</a>
	</p>
	<?php endif; ?>

</div>
