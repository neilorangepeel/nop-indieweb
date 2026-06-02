<?php
/**
 * Cite Card block — server-side render.
 *
 * Shows the captured context for a like, bookmark, reply, or repost:
 * the target's title (linked), author (when available), excerpt, and site name.
 */

declare( strict_types=1 );

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id = $block->context['postId'] ?? ( isset( $args['post_id'] ) ? (int) $args['post_id'] : get_the_ID() );

// Resolve the response URL from whichever kind meta is set.
$url = '';
if ( $post_id ) {
	foreach ( [ 'nop_indieweb_like_of', 'nop_indieweb_bookmark_of', 'nop_indieweb_repost_of', 'nop_indieweb_in_reply_to' ] as $meta_key ) {
		$val = (string) get_post_meta( $post_id, $meta_key, true );
		if ( '' !== $val ) {
			$url = $val;
			break;
		}
	}
}

$title   = $post_id ? (string) get_post_meta( $post_id, 'nop_indieweb_cite_title',       true ) : '';
$author  = $post_id ? (string) get_post_meta( $post_id, 'nop_indieweb_cite_author_name',  true ) : '';
$excerpt = $post_id ? (string) get_post_meta( $post_id, 'nop_indieweb_cite_excerpt',      true ) : '';
$site    = $post_id ? (string) get_post_meta( $post_id, 'nop_indieweb_cite_site_name',    true ) : '';

// Nothing to show — show a placeholder in the editor, nothing on the front end.
if ( '' === $url && '' === $title ) {
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-cite-card nop-cite-card--preview' ] );
		echo '<div ' . wp_kses_data( $wrapper_attrs ) . '>';
		echo '<span class="nop-cite-card__title">' . esc_html__( 'Cited article title', 'nop-indieweb' ) . '</span>';
		echo '<p class="nop-cite-card__byline">example.com</p>';
		echo '</div>';
	}
	return;
}

// Fall back to the domain when the title hasn't been captured yet.
$display = '' !== $title ? $title : ( '' !== $site ? $site : (string) ( wp_parse_url( $url, PHP_URL_HOST ) ?: $url ) );

$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-cite-card' ] );
?>
<div <?php echo wp_kses_data( $wrapper_attrs ); ?>>

	<?php if ( '' !== $url ) : ?>
	<a class="nop-cite-card__title"
	   href="<?php echo esc_url( $url ); ?>"
	   target="_blank"
	   rel="noopener noreferrer">
		<?php echo esc_html( $display ); ?>
	</a>
	<?php else : ?>
	<span class="nop-cite-card__title"><?php echo esc_html( $display ); ?></span>
	<?php endif; ?>

	<?php if ( '' !== $author || '' !== $site ) : ?>
	<p class="nop-cite-card__byline">
		<?php if ( '' !== $author ) : ?>
			<span class="nop-cite-card__author"><?php echo esc_html( $author ); ?></span>
		<?php endif; ?>
		<?php if ( '' !== $author && '' !== $site ) : ?>
			<span class="nop-cite-card__sep" aria-hidden="true"> · </span>
		<?php endif; ?>
		<?php if ( '' !== $site ) : ?>
			<span class="nop-cite-card__site"><?php echo esc_html( $site ); ?></span>
		<?php endif; ?>
	</p>
	<?php endif; ?>

	<?php if ( '' !== $excerpt ) : ?>
	<p class="nop-cite-card__excerpt"><?php echo esc_html( $excerpt ); ?></p>
	<?php endif; ?>

</div>
