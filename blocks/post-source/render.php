<?php
/**
 * Post Source block — server-side render.
 *
 * Shows the originating platform and source URL for an imported social post,
 * plus outbound syndication links. Hidden when the post has neither.
 */
declare( strict_types=1 );

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id = $block->context['postId'] ?? get_the_ID();

$is_editor = defined( 'REST_REQUEST' ) && REST_REQUEST
	&& isset( $_GET['context'] ) && 'edit' === $_GET['context']; // phpcs:ignore WordPress.Security.NonceVerification

$platform    = $post_id ? (string) get_post_meta( $post_id, 'nop_indieweb_platform',   true ) : '';
$service     = $post_id ? (string) get_post_meta( $post_id, 'nop_indieweb_service',    true ) : '';
$source_url  = $post_id ? (string) get_post_meta( $post_id, 'nop_indieweb_source_url', true ) : '';
$syndication = $post_id ? get_post_meta( $post_id, 'nop_indieweb_syndication', true )         : [];
$syndication = is_array( $syndication ) ? array_filter( $syndication ) : [];

$is_twitter_archive = 'twitter-archive' === $service;

// Remove the source URL from syndication so it doesn't appear twice.
if ( $source_url ) {
	$syndication = array_filter( $syndication, fn( $u ) => $u !== $source_url );
}

$platform_labels = [
	'mastodon'  => 'Mastodon',
	'bluesky'   => 'Bluesky',
	'twitter'   => 'Twitter',
	'facebook'  => 'Facebook',
	'instagram' => 'Instagram',
	'dribbble'  => 'Dribbble',
];

// Facebook archive posts have no per-post URL; the Twitter account is deactivated
// so every x.com/status link is dead; the Instagram export carries no per-post
// permalink — show the label without a link for these. (Dribbble keeps its URL.)
$link_less    = in_array( $platform, [ 'twitter', 'facebook', 'instagram' ], true );
$origin_label = $platform_labels[ $platform ] ?? ( $platform ? ucfirst( $platform ) : '' );
$origin_link  = ( $source_url && ! $link_less ) ? $source_url : '';

// Twitter archive posts: always show the "Archived Tweet" label.
// Other posts: hide when there's nothing to display.
$has_source = $origin_label && 'entries' !== $platform && ( $source_url || $link_less );
$has_synds  = ! empty( $syndication );

// Editor preview when no post context or the post has neither source nor
// syndication — shows the block's footprint so the template editor doesn't
// display the built-in "Block rendered as empty" placeholder.
if ( ! $has_source && ! $has_synds && ! $is_twitter_archive ) {
	if ( ! $is_editor ) {
		return;
	}
	$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-post-source nop-post-source--preview' ] );
	?>
	<div <?php echo wp_kses_data( $wrapper_attrs ); ?>>
		<span class="nop-post-source__label"><?php esc_html_e( 'Originally posted on', 'nop-indieweb' ); ?></span>
		<a class="nop-post-source__link" href="#" onclick="return false;">Mastodon</a>
		<span class="nop-post-source__sep">·</span>
		<span class="nop-post-source__label"><?php esc_html_e( 'Also on', 'nop-indieweb' ); ?></span>
		<a class="nop-post-source__link" href="#" onclick="return false;">bsky.app</a>
	</div>
	<?php
	return;
}

$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-post-source' ] );
?>
<div <?php echo wp_kses_data( $wrapper_attrs ); ?>>

	<?php if ( $has_source ) : ?>
	<span class="nop-post-source__item">
		<span class="nop-post-source__label"><?php esc_html_e( 'Originally posted on', 'nop-indieweb' ); ?></span>
		<?php if ( $origin_link ) : ?>
		<a class="nop-post-source__link u-syndication"
		   href="<?php echo esc_url( $origin_link ); ?>"
		   target="_blank" rel="noopener noreferrer me">
			<?php echo esc_html( $origin_label ); ?>
		</a>
		<?php else : ?>
		<span class="nop-post-source__link"><?php echo esc_html( $origin_label ); ?></span>
		<?php endif; ?>
	</span>
	<?php endif; ?>

	<?php if ( $has_synds ) : ?>
	<span class="nop-post-source__item">
		<span class="nop-post-source__label"><?php esc_html_e( 'Also on', 'nop-indieweb' ); ?></span>
		<?php foreach ( array_values( $syndication ) as $i => $url ) : ?>
			<?php if ( $i > 0 ) : ?><span class="nop-post-source__sep">,</span><?php endif; ?>
			<a class="nop-post-source__link u-syndication"
			   href="<?php echo esc_url( $url ); ?>"
			   target="_blank" rel="noopener noreferrer me">
				<?php echo esc_html( wp_parse_url( $url, PHP_URL_HOST ) ?? $url ); ?>
			</a>
		<?php endforeach; ?>
	</span>
	<?php endif; ?>

	<?php if ( $is_twitter_archive ) :
		$archive_url = (string) \NOP\IndieWeb\nop_indieweb_get_option( 'twitter_archive_url', '' );
	?>
	<span class="nop-post-source__item nop-post-source__item--archive">
		<?php if ( $archive_url ) : ?>
			<a class="nop-post-source__link nop-post-source__link--archive"
			   href="<?php echo esc_url( $archive_url ); ?>"><?php esc_html_e( 'Archived Tweet', 'nop-indieweb' ); ?></a>
		<?php else : ?>
			<span class="nop-post-source__label"><?php esc_html_e( 'Archived Tweet', 'nop-indieweb' ); ?></span>
		<?php endif; ?>
	</span>
	<?php endif; ?>

</div>
