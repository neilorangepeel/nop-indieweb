<?php
/**
 * Film Meta block — server-side render.
 *
 * Renders the star rating, film poster, watch date, rewatch badge,
 * and Letterboxd attribution for a film diary entry.
 */

declare( strict_types=1 );

$post_id = $block->context['postId'] ?? get_the_ID();

// Template editor preview — no real post.
if ( ! $post_id ) {
	$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-film-meta nop-film-meta--preview' ] );
	?>
	<div <?php echo $wrapper_attrs; ?>>
		<div class="nop-film-meta__rating">
			<span class="nop-film-stars" aria-label="<?php echo esc_attr( sprintf( __( '%s out of 5 stars', 'nop-indieweb' ), 4 ) ); ?>">
				<span class="nop-film-star is-full" aria-hidden="true">★</span>
				<span class="nop-film-star is-full" aria-hidden="true">★</span>
				<span class="nop-film-star is-full" aria-hidden="true">★</span>
				<span class="nop-film-star is-full" aria-hidden="true">★</span>
				<span class="nop-film-star is-empty" aria-hidden="true">★</span>
			</span>
		</div>
		<div class="nop-film-meta__row">
			<span class="nop-film-meta__year">2019</span>
			<span class="nop-film-meta__date"><?php echo esc_html( sprintf( __( 'Watched %s', 'nop-indieweb' ), '1 January 2025' ) ); ?></span>
			<a class="nop-film-meta__source" href="#" onclick="return false;"><?php esc_html_e( 'View on Letterboxd', 'nop-indieweb' ); ?></a>
		</div>
	</div>
	<?php
	return;
}

$rating     = (float) ( get_post_meta( $post_id, 'nop_indieweb_film_rating',  true ) ?: 0 );
$film_year  = (string) get_post_meta( $post_id, 'nop_indieweb_film_year',    true );
$poster_url = (string) get_post_meta( $post_id, 'nop_indieweb_film_poster',   true );
$watch_date = (string) get_post_meta( $post_id, 'nop_indieweb_watch_date',    true );
$source_url = (string) get_post_meta( $post_id, 'nop_indieweb_source_url',    true );
$rewatch    = '1' === (string) get_post_meta( $post_id, 'nop_indieweb_film_rewatch', true );

// Use sideloaded attachment if available, fall back to remote URL.
$photo_ids  = (array) ( get_post_meta( $post_id, 'nop_indieweb_photo_ids', true ) ?: [] );
if ( ! empty( $photo_ids[0] ) ) {
	$poster_url = (string) wp_get_attachment_url( (int) $photo_ids[0] );
}

// ── Star markup ───────────────────────────────────────────────────────────────

/* translators: %s: numeric rating e.g. 4.5 */
$star_label = $rating > 0 ? sprintf( __( '%s out of 5 stars', 'nop-indieweb' ), $rating ) : __( 'Not rated', 'nop-indieweb' );
$stars_html = '<span class="nop-film-stars" aria-label="' . esc_attr( $star_label ) . '">';
for ( $i = 1; $i <= 5; $i++ ) {
	if ( $rating >= $i ) {
		$class = 'is-full';
	} elseif ( $rating >= $i - 0.5 ) {
		$class = 'is-half';
	} else {
		$class = 'is-empty';
	}
	$stars_html .= '<span class="nop-film-star ' . $class . '" aria-hidden="true">★</span>';
}
$stars_html .= '</span>';

// ── Watch date ────────────────────────────────────────────────────────────────

$date_display = '';
if ( $watch_date ) {
	$ts = strtotime( $watch_date );
	if ( $ts ) {
		$date_display = wp_date( 'j F Y', $ts );
	}
}

$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-film-meta' ] );
?>
<div <?php echo $wrapper_attrs; ?>>

	<?php if ( $poster_url ) : ?>
	<img class="nop-film-meta__poster"
	     src="<?php echo esc_url( $poster_url ); ?>"
	     alt=""
	     loading="lazy">
	<?php endif; ?>

	<div class="nop-film-meta__rating">
		<?php echo $stars_html; ?>
	</div>

	<?php if ( $film_year || $date_display || $rewatch || $source_url ) : ?>
	<div class="nop-film-meta__row">
		<?php if ( $film_year ) : ?>
			<span class="nop-film-meta__year"><?php echo esc_html( $film_year ); ?></span>
		<?php endif; ?>
		<?php if ( $date_display ) : ?>
			<span class="nop-film-meta__date"><?php echo esc_html( sprintf( __( 'Watched %s', 'nop-indieweb' ), $date_display ) ); ?></span>
		<?php endif; ?>
		<?php if ( $rewatch ) : ?>
			<span class="nop-film-meta__rewatch"><?php esc_html_e( 'Rewatch', 'nop-indieweb' ); ?></span>
		<?php endif; ?>
		<?php if ( $source_url ) : ?>
			<a class="nop-film-meta__source"
			   href="<?php echo esc_url( $source_url ); ?>"
			   target="_blank"
			   rel="noopener noreferrer"><?php esc_html_e( 'View on Letterboxd', 'nop-indieweb' ); ?></a>
		<?php endif; ?>
	</div>
	<?php endif; ?>

</div>
