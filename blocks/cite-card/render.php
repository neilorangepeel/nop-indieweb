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

$title = $post_id ? (string) get_post_meta( $post_id, 'nop_indieweb_cite_title', true ) : '';

// cite_title and post_title are kept in sync by enrich_url_response_cite / Cite_Enricher.
// Fall back to post_title here so the card shows the article title even on posts where
// the cite meta wasn't written (e.g. older posts, failed enrichment fetch).
if ( '' === $title && $post_id ) {
	$post_title = get_the_title( $post_id );
	if ( '' !== $post_title && 'auto draft' !== strtolower( $post_title ) ) {
		$title = $post_title;
	}
}

// Nothing to show — show a placeholder in the editor, nothing on the front end.
if ( '' === $url && '' === $title ) {
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-cite-card nop-cite-card--preview' ] );
		echo '<div ' . wp_kses_data( $wrapper_attrs ) . '>';
		echo '<span class="nop-cite-card__title">' . esc_html__( 'Cited article title', 'nop-indieweb' ) . '</span>';
		echo '<p class="nop-cite-card__url">example.com/article</p>';
		echo '</div>';
	}
	return;
}

// Fall back to the domain when the title hasn't been captured yet.
$display = '' !== $title ? $title : (string) ( wp_parse_url( $url, PHP_URL_HOST ) ?: $url );

// Strip scheme for display so the URL reads cleanly without https://.
$display_url = $url ? preg_replace( '#^https?://#', '', $url ) : '';

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

	<?php if ( '' !== $display_url ) : ?>
	<p class="nop-cite-card__url"><?php echo esc_html( $display_url ); ?></p>
	<?php endif; ?>

</div>
