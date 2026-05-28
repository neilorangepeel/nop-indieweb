<?php
/**
 * Film Card block — server-side render.
 *
 * Compact card for use inside a query loop on category archive pages.
 * Shows poster thumbnail, star rating glyphs, film title (linked), year, and watch date.
 */

declare( strict_types=1 );

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id = $block->context['postId'] ?? get_the_ID();

// ── Star builder (shared with film-meta) ──────────────────────────────────────

function nop_film_card_stars( float $rating ): string {
	/* translators: %s: numeric rating e.g. 4.5 */
	$label = $rating > 0 ? sprintf( __( '%s out of 5 stars', 'nop-indieweb' ), $rating ) : __( 'Not rated', 'nop-indieweb' );
	$html  = '<span class="nop-film-stars" aria-label="' . esc_attr( $label ) . '">';
	for ( $i = 1; $i <= 5; $i++ ) {
		if ( $rating >= $i ) {
			$class = 'is-full';
		} elseif ( $rating >= $i - 0.5 ) {
			$class = 'is-half';
		} else {
			$class = 'is-empty';
		}
		$html .= '<span class="nop-film-star ' . $class . '" aria-hidden="true">★</span>';
	}
	return $html . '</span>';
}

// ── Template editor preview ───────────────────────────────────────────────────

if ( ! $post_id ) {
	$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-film-card nop-film-card--placeholder' ] );
	?>
	<div <?php echo $wrapper_attrs; ?>>
		<div class="nop-film-card__poster-wrap">
			<div class="nop-film-card__poster nop-film-card__poster--empty"></div>
		</div>
		<div class="nop-film-card__body">
			<?php echo nop_film_card_stars( 4 ); ?>
			<p class="nop-film-card__title">Portrait of a Lady on Fire</p>
			<p class="nop-film-card__meta">2019 · 12 Apr 2025</p>
		</div>
	</div>
	<?php
	return;
}

// ── Real post ─────────────────────────────────────────────────────────────────

$rating     = (float) ( get_post_meta( $post_id, 'nop_indieweb_film_rating', true ) ?: 0 );
$film_year  = (string) get_post_meta( $post_id, 'nop_indieweb_film_year',    true );
$poster_url = (string) get_post_meta( $post_id, 'nop_indieweb_film_poster',  true );
$watch_date = (string) get_post_meta( $post_id, 'nop_indieweb_watch_date',   true );
$rewatch    = '1' === (string) get_post_meta( $post_id, 'nop_indieweb_film_rewatch', true );

// Prefer sideloaded attachment.
$photo_ids = (array) ( get_post_meta( $post_id, 'nop_indieweb_photo_ids', true ) ?: [] );
if ( ! empty( $photo_ids[0] ) ) {
	$poster_url = (string) wp_get_attachment_url( (int) $photo_ids[0] );
}

$date_display = '';
if ( $watch_date ) {
	$ts = strtotime( $watch_date );
	if ( $ts ) {
		$date_display = wp_date( 'j M Y', $ts );
	}
}

$meta_parts = array_filter( [ $film_year, $date_display ] );
$meta_line  = implode( ' · ', $meta_parts );

$permalink  = (string) get_permalink( $post_id );
$post_title = get_the_title( $post_id );

$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-film-card' ] );
?>
<div <?php echo $wrapper_attrs; ?>>

	<a class="nop-film-card__poster-wrap" href="<?php echo esc_url( $permalink ); ?>" tabindex="-1" aria-hidden="true">
		<?php if ( $poster_url ) : ?>
		<img class="nop-film-card__poster"
		     src="<?php echo esc_url( $poster_url ); ?>"
		     alt=""
		     loading="lazy">
		<?php else : ?>
		<div class="nop-film-card__poster nop-film-card__poster--empty"></div>
		<?php endif; ?>
	</a>

	<div class="nop-film-card__body">
		<?php if ( $rating > 0 ) : ?>
		<?php echo nop_film_card_stars( $rating ); ?>
		<?php endif; ?>

		<p class="nop-film-card__title">
			<a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $post_title ); ?></a>
			<?php if ( $rewatch ) : ?>
			<span class="nop-film-card__rewatch" aria-label="<?php esc_attr_e( '(rewatch)', 'nop-indieweb' ); ?>">↩</span>
			<?php endif; ?>
		</p>

		<?php if ( $meta_line ) : ?>
		<p class="nop-film-card__meta"><?php echo esc_html( $meta_line ); ?></p>
		<?php endif; ?>
	</div>

</div>
