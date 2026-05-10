<?php
/**
 * Checkin Card block — server-side render.
 *
 * Renders venue metadata (categories, map link, syndication, service) as
 * native page content below the post body. Photos are stored as real
 * wp:image blocks in post content. Venue name and locality are handled
 * by the template (post-title + Block Bindings).
 *
 * Available variables (injected by WordPress):
 *   $attributes — block attributes array
 *   $content    — inner block content (unused)
 *   $block      — WP_Block instance, provides postId via context
 */

declare( strict_types=1 );

$post_id = $block->context['postId'] ?? get_the_ID();

// In the template editor there's no real post — render a representative preview.
if ( ! $post_id ) {
	$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-checkin-meta nop-checkin-meta--preview' ] );
	?>
	<div <?php echo $wrapper_attrs; ?>>
		<p class="nop-checkin-categories">
			<span class="nop-checkin-category p-category">Bar</span>
			<span class="nop-checkin-category p-category">Pub</span>
		</p>
		<p class="nop-checkin-map">
			<a href="#" onclick="return false;">View on OpenStreetMap</a>
		</p>
		<p class="nop-checkin-syndication">
			Also on: <a href="#" onclick="return false;">www.swarmapp.com</a>
		</p>
		<p class="nop-checkin-source">via Swarm</p>
	</div>
	<?php
	return;
}

$venue_name       = get_post_meta( $post_id, 'nop_indieweb_venue_name', true );

// Nothing to show if there's no venue — this isn't a checkin post.
if ( ! $venue_name ) {
	return;
}

$lat              = get_post_meta( $post_id, 'nop_indieweb_venue_lat',        true );
$lng              = get_post_meta( $post_id, 'nop_indieweb_venue_lng',        true );
$venue_locality   = get_post_meta( $post_id, 'nop_indieweb_venue_locality',   true );
$venue_country    = get_post_meta( $post_id, 'nop_indieweb_venue_country',    true );
$venue_categories = get_post_meta( $post_id, 'nop_indieweb_venue_categories', true );
$syndication      = get_post_meta( $post_id, 'nop_indieweb_syndication',      true );
$service          = get_post_meta( $post_id, 'nop_indieweb_service',          true );

$location_parts   = array_filter( [ $venue_locality, $venue_country ] );
$location_line    = implode( ', ', $location_parts );

$syndication      = is_array( $syndication ) ? array_filter( $syndication ) : [];
$venue_categories = is_array( $venue_categories ) ? array_filter( $venue_categories ) : [];

$map_url = ( $lat && $lng )
	? sprintf( 'https://www.openstreetmap.org/?mlat=%s&mlon=%s&zoom=16&layers=M', rawurlencode( $lat ), rawurlencode( $lng ) )
	: '';

// Disable link navigation inside the iframed block editor canvas.
$is_editor = (
	defined( 'REST_REQUEST' ) && REST_REQUEST &&
	( isset( $_GET['context'] ) && 'edit' === $_GET['context'] ) // phpcs:ignore WordPress.Security.NonceVerification
);

$wrapper_attrs = get_block_wrapper_attributes( [
	'class' => 'nop-checkin-meta p-location h-card' . ( $is_editor ? ' nop-checkin-meta--editor' : '' ),
] );
?>
<div <?php echo $wrapper_attrs; ?>>

	<?php // Hidden p-name for microformats2 h-card completeness (venue name is visible in page title). ?>
	<data class="p-name" value="<?php echo esc_attr( $venue_name ); ?>"></data>

	<?php // ── Location (locality + country) ─────────────────────────────────── ?>
	<?php if ( $location_line ) : ?>
	<p class="nop-checkin-location"><?php
		if ( $venue_locality ) {
			printf( '<span class="p-locality">%s</span>', esc_html( $venue_locality ) );
			if ( $venue_country ) { echo ', '; }
		}
		if ( $venue_country ) {
			printf( '<span class="p-country-name">%s</span>', esc_html( $venue_country ) );
		}
	?></p>
	<?php endif; ?>

	<?php // ── Venue categories ──────────────────────────────────────────────── ?>
	<?php if ( $venue_categories ) : ?>
	<p class="nop-checkin-categories">
		<?php foreach ( $venue_categories as $cat ) : ?>
			<span class="nop-checkin-category p-category"><?php echo esc_html( $cat ); ?></span>
		<?php endforeach; ?>
	</p>
	<?php endif; ?>

	<?php // ── Map link with hidden mf2 geo ──────────────────────────────────── ?>
	<?php if ( $map_url ) : ?>
	<p class="nop-checkin-map">
		<a href="<?php echo esc_url( $map_url ); ?>" target="_blank" rel="noopener">
			View on OpenStreetMap
		</a>
		<data class="p-latitude" value="<?php echo esc_attr( $lat ); ?>"></data>
		<data class="p-longitude" value="<?php echo esc_attr( $lng ); ?>"></data>
	</p>
	<?php endif; ?>

	<?php // ── Syndication links ─────────────────────────────────────────────── ?>
	<?php if ( $syndication ) : ?>
	<p class="nop-checkin-syndication">
		Also on:
		<?php foreach ( $syndication as $url ) : ?>
			<a class="u-syndication" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener me">
				<?php echo esc_html( wp_parse_url( $url, PHP_URL_HOST ) ?? $url ); ?>
			</a>
		<?php endforeach; ?>
	</p>
	<?php endif; ?>

	<?php // ── Source attribution ────────────────────────────────────────────── ?>
	<?php if ( $service ) : ?>
	<p class="nop-checkin-source">via <?php echo esc_html( ucfirst( $service ) ); ?></p>
	<?php endif; ?>

</div>
