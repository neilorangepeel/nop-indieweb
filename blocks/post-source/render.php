<?php
/**
 * Post Source block — server-side render.
 *
 * Shows the originating platform and source URL for an imported social post,
 * plus outbound syndication links. Hidden when the post has neither.
 */
declare( strict_types=1 );

$post_id = $block->context['postId'] ?? get_the_ID();

if ( ! $post_id ) {
	// Template editor preview.
	$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-post-source nop-post-source--preview' ] );
	?>
	<div <?php echo $wrapper_attrs; ?>>
		<span class="nop-post-source__label">Originally posted on</span>
		<a class="nop-post-source__link" href="#" onclick="return false;">Mastodon</a>
		<span class="nop-post-source__sep">·</span>
		<span class="nop-post-source__label">Also on</span>
		<a class="nop-post-source__link" href="#" onclick="return false;">bsky.app</a>
	</div>
	<?php
	return;
}

$platform    = (string) get_post_meta( $post_id, 'nop_indieweb_platform',   true );
$source_url  = (string) get_post_meta( $post_id, 'nop_indieweb_source_url', true );
$syndication = get_post_meta( $post_id, 'nop_indieweb_syndication', true );
$syndication = is_array( $syndication ) ? array_filter( $syndication ) : [];

// Remove the source URL from syndication so it doesn't appear twice.
if ( $source_url ) {
	$syndication = array_filter( $syndication, fn( $u ) => $u !== $source_url );
}

$has_source = $source_url && $platform && 'entries' !== $platform;
$has_synds  = ! empty( $syndication );

if ( ! $has_source && ! $has_synds ) {
	return;
}

$platform_labels = [
	'mastodon' => 'Mastodon',
	'bluesky'  => 'Bluesky',
];

$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-post-source' ] );
?>
<div <?php echo $wrapper_attrs; ?>>

	<?php if ( $has_source ) : ?>
	<span class="nop-post-source__item">
		<span class="nop-post-source__label">Originally posted on</span>
		<a class="nop-post-source__link u-syndication"
		   href="<?php echo esc_url( $source_url ); ?>"
		   target="_blank" rel="noopener me">
			<?php echo esc_html( $platform_labels[ $platform ] ?? ucfirst( $platform ) ); ?>
		</a>
	</span>
	<?php endif; ?>

	<?php if ( $has_synds ) : ?>
	<span class="nop-post-source__item">
		<span class="nop-post-source__label">Also on</span>
		<?php foreach ( array_values( $syndication ) as $i => $url ) : ?>
			<?php if ( $i > 0 ) : ?><span class="nop-post-source__sep">,</span><?php endif; ?>
			<a class="nop-post-source__link u-syndication"
			   href="<?php echo esc_url( $url ); ?>"
			   target="_blank" rel="noopener me">
				<?php echo esc_html( wp_parse_url( $url, PHP_URL_HOST ) ?? $url ); ?>
			</a>
		<?php endforeach; ?>
	</span>
	<?php endif; ?>

</div>
