<?php
/**
 * Checkin Card block — server-side render.
 *
 * Reads IndieWeb post meta and outputs the venue, address, map link,
 * syndication URLs, and photos. Microformats2 classes are included
 * throughout so the h-entry is machine-readable.
 *
 * Available variables (injected by WordPress):
 *   $attributes — block attributes array
 *   $content    — inner block content (unused)
 *   $block      — WP_Block instance, provides postId via context
 */

declare( strict_types=1 );

$post_id = $block->context['postId'] ?? get_the_ID();

// In the template editor there's no real post — render a representative preview
// so the block is visible and selectable rather than showing "Block rendered as empty".
if ( ! $post_id ) {
	$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-checkin-card nop-checkin-card--preview' ] );
	?>
	<div <?php echo $wrapper_attrs; ?>>
		<p class="nop-checkin-venue">
			<span class="p-name">📍 Venue Name</span>
			<span class="nop-checkin-location">City, Country</span>
		</p>
		<p class="nop-checkin-categories">
			<span class="nop-checkin-category p-category">Bar</span>
		</p>
		<p class="nop-checkin-map">
			<a href="#" onclick="return false;">🗺️ View on OpenStreetMap</a>
		</p>
		<p class="nop-checkin-syndication">
			Also on: <a href="#" onclick="return false;">www.swarmapp.com</a>
		</p>
		<p class="nop-checkin-source"><small>via Swarm</small></p>
	</div>
	<?php
	return;
}

// ── Venue identity ────────────────────────────────────────────────────────────
$venue_name = get_post_meta( $post_id, 'nop_indieweb_venue_name', true );
$venue_url  = get_post_meta( $post_id, 'nop_indieweb_venue_url', true );

// Nothing to show if there's no venue — this isn't a checkin post.
if ( ! $venue_name ) {
	return;
}

// ── All meta ─────────────────────────────────────────────────────────────────
$lat              = get_post_meta( $post_id, 'nop_indieweb_venue_lat', true );
$lng              = get_post_meta( $post_id, 'nop_indieweb_venue_lng', true );
$venue_locality   = get_post_meta( $post_id, 'nop_indieweb_venue_locality', true );
$venue_country    = get_post_meta( $post_id, 'nop_indieweb_venue_country', true );
$venue_categories = get_post_meta( $post_id, 'nop_indieweb_venue_categories', true );
$syndication      = get_post_meta( $post_id, 'nop_indieweb_syndication', true );
$photo_ids        = get_post_meta( $post_id, 'nop_indieweb_photo_ids', true );
$photo_urls       = get_post_meta( $post_id, 'nop_indieweb_photos', true );
$service          = get_post_meta( $post_id, 'nop_indieweb_service', true );

$syndication      = is_array( $syndication ) ? array_filter( $syndication ) : [];
$photo_ids        = is_array( $photo_ids ) ? array_filter( $photo_ids ) : [];
$photo_urls       = is_array( $photo_urls ) ? array_filter( $photo_urls ) : [];
$venue_categories = is_array( $venue_categories ) ? array_filter( $venue_categories ) : [];

// ── Derived values ────────────────────────────────────────────────────────────
$map_url = ( $lat && $lng )
	? sprintf( 'https://www.openstreetmap.org/?mlat=%s&mlon=%s&zoom=16&layers=M', rawurlencode( $lat ), rawurlencode( $lng ) )
	: '';

// Build a readable location string from the most useful address fields.
$location_parts = array_filter( [ $venue_locality, $venue_country ] );
$location_line  = implode( ', ', $location_parts );

// In the block editor the canvas is an <iframe>. Clicking any external link
// navigates the iframe, which crashes when Foursquare/OSM block framing via
// X-Frame-Options. Detect the REST edit-context request and add a modifier
// class so CSS can disable pointer events on all links inside the preview.
$is_editor = (
	defined( 'REST_REQUEST' ) && REST_REQUEST &&
	( isset( $_GET['context'] ) && 'edit' === $_GET['context'] ) // phpcs:ignore WordPress.Security.NonceVerification
);

$card_classes = 'nop-checkin-card p-location h-card' . ( $is_editor ? ' nop-checkin-card--editor' : '' );
$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => $card_classes ] );
?>
<div <?php echo $wrapper_attrs; ?>>

	<?php // ── Venue name ────────────────────────────────────────────────────── ?>
	<p class="nop-checkin-venue">
		<?php if ( $venue_url ) : ?>
			<a class="p-name u-url" href="<?php echo esc_url( $venue_url ); ?>" rel="noopener">
				📍 <?php echo esc_html( $venue_name ); ?>
			</a>
		<?php else : ?>
			<span class="p-name">📍 <?php echo esc_html( $venue_name ); ?></span>
		<?php endif; ?>
		<?php if ( $location_line ) : ?>
			<span class="nop-checkin-location">
				<span class="p-locality"><?php echo esc_html( $venue_locality ); ?></span><?php if ( $venue_locality && $venue_country ) { echo ', '; } ?><span class="p-country-name"><?php echo esc_html( $venue_country ); ?></span>
			</span>
		<?php endif; ?>
	</p>

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
			🗺️ View on OpenStreetMap
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

	<?php // ── Photos ────────────────────────────────────────────────────────── ?>
	<?php if ( $photo_ids || $photo_urls ) : ?>
	<div class="nop-checkin-photos">
		<?php if ( $photo_ids ) : ?>
			<?php
			// Use local attachments (sideloaded) — gives responsive images, proper alt text,
			// and independence from Swarm CDN availability.
			foreach ( $photo_ids as $attachment_id ) {
				echo wp_get_attachment_image( (int) $attachment_id, 'large', false, [
					'class'   => 'u-photo',
					'loading' => 'lazy',
				] );
			}
			?>
		<?php else : ?>
			<?php
			// Fallback: CDN URLs for posts imported before sideloading was enabled.
			foreach ( $photo_urls as $photo_url ) {
				printf(
					'<img src="%s" alt="%s" class="u-photo" loading="lazy">',
					esc_url( $photo_url ),
					esc_attr( $venue_name )
				);
			}
			?>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<?php // ── Source label ──────────────────────────────────────────────────── ?>
	<?php if ( $service ) : ?>
	<p class="nop-checkin-source">
		<small>via <?php echo esc_html( ucfirst( $service ) ); ?></small>
	</p>
	<?php endif; ?>

</div>
