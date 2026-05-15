<?php
/**
 * Venue Field block — server-side render.
 *
 * Renders a single venue meta field as a paragraph. The field is chosen
 * via the block inspector. Typography, colour, and spacing set in the
 * editor are applied automatically by get_block_wrapper_attributes().
 *
 * Outputs nothing on the frontend when the post has no venue data.
 * Returns representative preview text in the block editor context.
 */

declare( strict_types=1 );

$field   = sanitize_key( $attributes['field'] ?? 'address' );
$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : // phpcs:ignore WordPress.Security.NonceVerification
           ( $block->context['postId'] ?? get_the_ID() );

$field_config = [
	'name'             => [ 'key' => 'nop_indieweb_venue_name',     'mf2' => 'p-name',            'preview' => 'The Crown Bar' ],
	'address'          => [ 'key' => 'nop_indieweb_venue_address',  'mf2' => 'p-street-address',  'preview' => '46 Great Victoria Street' ],
	'locality'         => [ 'key' => 'nop_indieweb_venue_locality', 'mf2' => 'p-locality',        'preview' => 'Belfast' ],
	'region'           => [ 'key' => 'nop_indieweb_venue_region',   'mf2' => 'p-region',          'preview' => 'County Antrim' ],
	'country'          => [ 'key' => 'nop_indieweb_venue_country',  'mf2' => 'p-country-name',    'preview' => 'United Kingdom' ],
	'postcode'         => [ 'key' => 'nop_indieweb_venue_postcode', 'mf2' => 'p-postal-code',     'preview' => 'BT2 7BA' ],
	'locality_country' => [ 'key' => null,                              'mf2' => '',            'preview' => 'Belfast, United Kingdom' ],
	'lat'              => [ 'key' => 'nop_indieweb_venue_lat',      'mf2' => '', 'preview' => '54.5973' ],
	'lng'              => [ 'key' => 'nop_indieweb_venue_lng',      'mf2' => '', 'preview' => '-5.9301' ],
	'altitude'         => [ 'key' => 'nop_indieweb_venue_altitude', 'mf2' => '', 'preview' => '10m' ],
];

if ( ! isset( $field_config[ $field ] ) ) {
	return;
}

$config = $field_config[ $field ];

// Resolve the display value.
$value = '';
if ( $post_id ) {
	if ( 'locality_country' === $field ) {
		$locality = get_post_meta( $post_id, 'nop_indieweb_venue_locality', true );
		$country  = get_post_meta( $post_id, 'nop_indieweb_venue_country',  true );
		$parts    = array_filter( [ $locality, $country ] );
		$value    = $parts ? implode( ', ', $parts ) : '';
	} else {
		$value = (string) get_post_meta( $post_id, $config['key'], true );
	}
}

// In the editor, return preview text so the block shows something useful.
$is_editor = defined( 'REST_REQUEST' ) && REST_REQUEST
	&& isset( $_GET['context'] ) && 'edit' === $_GET['context']; // phpcs:ignore WordPress.Security.NonceVerification

if ( ! $value ) {
	if ( $is_editor ) {
		$value = $config['preview'];
	} else {
		return;
	}
}

$classes = trim( 'nop-venue-field ' . $config['mf2'] );
$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => $classes ] );
?>
<p <?php echo $wrapper_attrs; ?>><?php echo esc_html( $value ); ?></p>
