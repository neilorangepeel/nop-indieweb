<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Admin;

/**
 * Venue meta box for checkin posts.
 *
 * Registers a Gutenberg-compatible meta box that shows structured venue data
 * alongside the post editor — venue name (editable), address, categories,
 * and a link to the original Swarm checkin. Appears in the sidebar for any
 * post that has nop_indieweb_venue_name set.
 *
 * A meta box is used rather than InspectorControls because in WP 6.x the
 * block canvas runs inside an <iframe> — React portals from the iframe to
 * the sidebar crash the editor context.
 */
class Checkin_Metabox {

	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add' ], 10, 2 );
		add_action( 'save_post',      [ $this, 'save' ], 10, 2 );
	}

	public function add( string $post_type, \WP_Post $post ): void {
		if ( 'post' !== $post_type ) {
			return;
		}
		if ( ! get_post_meta( $post->ID, 'nop_indieweb_venue_name', true ) ) {
			return;
		}

		add_meta_box(
			'nop-indieweb-venue',
			'Venue',
			[ $this, 'render' ],
			'post',
			'side',
			'high'
		);
	}

	public function render( \WP_Post $post ): void {
		$venue_name = get_post_meta( $post->ID, 'nop_indieweb_venue_name', true );

		wp_nonce_field( 'nop_venue_metabox_' . $post->ID, 'nop_venue_nonce' );

		$address    = get_post_meta( $post->ID, 'nop_indieweb_venue_address',    true );
		$locality   = get_post_meta( $post->ID, 'nop_indieweb_venue_locality',   true );
		$region     = get_post_meta( $post->ID, 'nop_indieweb_venue_region',     true );
		$postcode   = get_post_meta( $post->ID, 'nop_indieweb_venue_postcode',   true );
		$country    = get_post_meta( $post->ID, 'nop_indieweb_venue_country',    true );
		$lat        = get_post_meta( $post->ID, 'nop_indieweb_venue_lat',        true );
		$lng        = get_post_meta( $post->ID, 'nop_indieweb_venue_lng',        true );
		$cats       = get_post_meta( $post->ID, 'nop_indieweb_venue_categories', true );
		$checkin_url= get_post_meta( $post->ID, 'nop_indieweb_checkin_url',      true );
		$service    = get_post_meta( $post->ID, 'nop_indieweb_service',          true );
		$photos     = get_post_meta( $post->ID, 'nop_indieweb_photos',           true );

		$cats       = is_array( $cats )   ? $cats   : [];
		$photos     = is_array( $photos ) ? $photos : [];

		$addr_parts = array_filter( [ $address, $locality, $postcode ] );
		$map_url    = ( $lat && $lng )
			? sprintf( 'https://www.openstreetmap.org/?mlat=%s&mlon=%s&zoom=16', rawurlencode( $lat ), rawurlencode( $lng ) )
			: '';
		?>
		<style>
		#nop-indieweb-venue .nop-mb-row { margin: 0 0 10px; }
		#nop-indieweb-venue .nop-mb-label {
			display: block; font-size: 11px; font-weight: 600;
			text-transform: uppercase; letter-spacing: 0.04em;
			color: #757575; margin-bottom: 3px;
		}
		#nop-indieweb-venue .nop-mb-meta {
			font-size: 12px; color: #1d2327; line-height: 1.5;
		}
		#nop-indieweb-venue .nop-mb-link {
			font-size: 12px; color: #2271b1; text-decoration: none;
		}
		#nop-indieweb-venue .nop-mb-link:hover { text-decoration: underline; }
		#nop-indieweb-venue .nop-mb-photos {
			display: flex; gap: 4px; flex-wrap: wrap; margin-top: 4px;
		}
		#nop-indieweb-venue .nop-mb-photo {
			width: 56px; height: 56px; object-fit: cover;
			border-radius: 2px; display: block;
		}
		</style>

		<?php // ── Venue name (editable) ──────────────────────────────────────── ?>
		<div class="nop-mb-row">
			<label class="nop-mb-label" for="nop_venue_name">Name</label>
			<input type="text" id="nop_venue_name" name="nop_venue_name"
			       value="<?php echo esc_attr( $venue_name ); ?>"
			       class="widefat" style="font-size:13px;">
		</div>

		<?php // ── Address ────────────────────────────────────────────────────── ?>
		<?php if ( $addr_parts ) : ?>
		<div class="nop-mb-row">
			<span class="nop-mb-label">Address</span>
			<span class="nop-mb-meta"><?php echo esc_html( implode( ', ', $addr_parts ) ); ?></span>
		</div>
		<?php endif; ?>

		<?php // ── Categories ─────────────────────────────────────────────────── ?>
		<?php if ( $cats ) : ?>
		<div class="nop-mb-row">
			<span class="nop-mb-label">Type</span>
			<span class="nop-mb-meta"><?php echo esc_html( implode( ' · ', $cats ) ); ?></span>
		</div>
		<?php endif; ?>

		<?php // ── Co-ordinates + map ─────────────────────────────────────────── ?>
		<?php if ( $lat && $lng ) : ?>
		<div class="nop-mb-row">
			<span class="nop-mb-label">Location</span>
			<span class="nop-mb-meta"><?php echo esc_html( $lat . ', ' . $lng ); ?></span>
			<?php if ( $map_url ) : ?>
			<br><a class="nop-mb-link" href="<?php echo esc_url( $map_url ); ?>" target="_blank" rel="noopener">View on OpenStreetMap ↗</a>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<?php // ── Photos ─────────────────────────────────────────────────────── ?>
		<?php if ( $photos ) : ?>
		<div class="nop-mb-row">
			<span class="nop-mb-label">Photos (<?php echo count( $photos ); ?>)</span>
			<div class="nop-mb-photos">
				<?php foreach ( array_slice( $photos, 0, 6 ) as $photo_url ) : ?>
				<img src="<?php echo esc_url( $photo_url ); ?>"
				     class="nop-mb-photo" alt="" loading="lazy">
				<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>

		<?php // ── Swarm link ─────────────────────────────────────────────────── ?>
		<?php if ( $checkin_url ) : ?>
		<div class="nop-mb-row" style="margin-bottom:0">
			<span class="nop-mb-label">Syndication</span>
			<a class="nop-mb-link" href="<?php echo esc_url( $checkin_url ); ?>" target="_blank" rel="noopener">
				<?php echo esc_html( ucfirst( $service ?: 'swarm' ) ); ?> checkin ↗
			</a>
		</div>
		<?php endif; ?>
		<?php
	}

	public function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['nop_venue_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nop_venue_nonce'] ) ), 'nop_venue_metabox_' . $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['nop_venue_name'] ) ) {
			update_post_meta(
				$post_id,
				'nop_indieweb_venue_name',
				sanitize_text_field( wp_unslash( $_POST['nop_venue_name'] ) )
			);
		}
	}
}
