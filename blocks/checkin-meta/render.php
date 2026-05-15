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
		<p class="nop-checkin-address p-street-address">46 Great Victoria Street</p>
		<p class="nop-checkin-location">
			<span class="p-locality">Belfast</span>,
			<span class="p-country-name">United Kingdom</span>
		</p>
		<p class="nop-checkin-categories">
			<span class="nop-checkin-category p-category">Bar</span>
			<span class="nop-checkin-category p-category">Pub</span>
		</p>
		<p class="nop-checkin-venue-link">
			<a href="#" onclick="return false;">View on foursquare.com</a>
		</p>
		<div class="nop-checkin-map nop-checkin-map--placeholder" role="img" aria-label="Map preview"></div>
		<p class="nop-checkin-map__caption">
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

$venue_name = get_post_meta( $post_id, 'nop_indieweb_venue_name', true );

// Nothing to show if there's no venue — this isn't a checkin post.
if ( ! $venue_name ) {
	return;
}

// Disable link navigation inside the iframed block editor canvas.
// Defined early so it gates the map fetch below.
$is_editor = (
	defined( 'REST_REQUEST' ) && REST_REQUEST &&
	( isset( $_GET['context'] ) && 'edit' === $_GET['context'] ) // phpcs:ignore WordPress.Security.NonceVerification
);

$lat              = get_post_meta( $post_id, 'nop_indieweb_venue_lat',        true );
$lng              = get_post_meta( $post_id, 'nop_indieweb_venue_lng',        true );
$venue_url        = get_post_meta( $post_id, 'nop_indieweb_venue_url',        true );
$venue_address    = get_post_meta( $post_id, 'nop_indieweb_venue_address',    true );
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

$map_title   = $venue_name ? sprintf( 'Map showing %s', $venue_name ) : 'Location map';
$map_img_url = '';
$map_w       = 0;
$map_h       = 0;

if ( $lat && $lng && ! $is_editor ) {
	$geoapify_key = trim( \NOP\IndieWeb\nop_indieweb_get_option( 'maps.geoapify_api_key', '' ) );
	if ( $geoapify_key ) {
		$content_size_raw = wp_get_global_settings( [ 'layout', 'contentSize' ] );
		$map_w = 620;
		if ( $content_size_raw && preg_match( '/^(\d+(?:\.\d+)?)px$/i', $content_size_raw, $csm ) ) {
			$map_w = (int) round( (float) $csm[1] );
		}
		$map_h       = (int) round( $map_w / 2 );
		$map_img_url = \NOP\IndieWeb\nop_indieweb_get_or_cache_map_image(
			$post_id, (float) $lat, (float) $lng, $map_w, $map_h, $geoapify_key
		);
	}
}

$wrapper_attrs = get_block_wrapper_attributes( [
	'class' => 'nop-checkin-meta p-checkin h-card' . ( $is_editor ? ' nop-checkin-meta--editor' : '' ),
] );
?>
<div <?php echo $wrapper_attrs; ?>>

	<?php // Hidden mf2 properties — data elements are parsed by mf2 parsers but not visible to users. ?>
	<data class="p-name" value="<?php echo esc_attr( $venue_name ); ?>"></data>
	<a class="u-url" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" hidden>permalink</a>

	<?php // ── Street address ────────────────────────────────────────────────── ?>
	<?php if ( $venue_address ) : ?>
	<p class="nop-checkin-address p-street-address"><?php echo esc_html( $venue_address ); ?></p>
	<?php endif; ?>

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

	<?php // ── Venue URL (Foursquare / Swarm link) ──────────────────────────── ?>
	<?php if ( $venue_url ) : ?>
	<p class="nop-checkin-venue-link">
		<a href="<?php echo esc_url( $venue_url ); ?>" target="_blank" rel="noopener noreferrer">
			View on <?php echo esc_html( wp_parse_url( $venue_url, PHP_URL_HOST ) ?? 'Foursquare' ); ?>
		</a>
	</p>
	<?php endif; ?>

	<?php // ── Map — cached local attachment, generated once via Geoapify ───── ?>
	<?php if ( $map_img_url ) : ?>
	<div class="nop-checkin-map">
		<img class="nop-checkin-map__img"
			src="<?php echo esc_url( $map_img_url ); ?>"
			width="<?php echo esc_attr( (string) $map_w ); ?>"
			height="<?php echo esc_attr( (string) $map_h ); ?>"
			alt="<?php echo esc_attr( $map_title ); ?>"
			loading="lazy" decoding="async">
		<p class="nop-checkin-map__caption">
			<a href="<?php echo esc_url( $map_url ); ?>" target="_blank" rel="noopener noreferrer">View on OpenStreetMap</a>
		</p>
		<data class="p-latitude"  value="<?php echo esc_attr( $lat ); ?>"></data>
		<data class="p-longitude" value="<?php echo esc_attr( $lng ); ?>"></data>
	</div>
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
