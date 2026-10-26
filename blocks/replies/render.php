<?php
/**
 * Replies block — server-side render.
 *
 * The threaded conversation: webmention replies and WordPress comments in one
 * chronological list, with comment replies nested inline below the webmention
 * they answer. An optional heading ("N Responses") sits above; hide it to drop
 * a native heading block with your own styling instead.
 *
 * Front end: renders nothing when the post has no replies.
 * Editor: shows sample replies so the block is visible and designable.
 */
declare( strict_types=1 );

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once NOP_INDIEWEB_DIR . 'includes/webmention-render.php';

/**
 * WordPress injects these into a block's render scope.
 *
 * @var array<string, mixed> $attributes
 * @var string               $content
 * @var \WP_Block            $block
 */

$show_heading = ! isset( $attributes['showHeading'] ) || (bool) $attributes['showHeading'];

$preview = nop_wm_is_editor_preview();
$post_id = nop_wm_resolve_post_id( $block );
$data    = nop_wm_get_data( $post_id );

$replies = $data['replies'];
$threads = $data['children'];

if ( $preview && ! $replies ) {
	$sample  = nop_wm_sample_data();
	$replies = $sample['replies'];
	$threads = $sample['children'];
}

if ( ! $replies ) {
	return;
}

$comments_open = ! $preview && comments_open( $post_id );

// id="comments" gives the post-footer comment pill a real anchor target when
// JS is off; the heading is always present for the document outline / screen
// readers, shown as the eyebrow only when showHeading is on.
$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-replies', 'id' => 'comments' ] );
?>
<section <?php echo wp_kses_data( $wrapper_attrs ); ?> aria-labelledby="nop-replies-heading">

	<h2 id="nop-replies-heading" class="nop-replies__label<?php echo $show_heading ? '' : ' screen-reader-text'; ?>"><?php esc_html_e( 'Replies', 'nop-indieweb' ); ?></h2>

	<ul class="nop-webmentions__replies">
		<?php foreach ( $replies as $entry ) :
			$profile_url  = esc_url( $entry['author_url'] );
			$reply_url    = esc_url( $entry['source'] ?: $entry['author_url'] );
			$time_display = $entry['date'] ? nop_wm_time_ago( $entry['date'] ) : '';
			$time_label   = $entry['date'] ? nop_wm_time_label( $entry['date'] ) : '';
			$time_iso     = $entry['date'] ? gmdate( 'c', (int) strtotime( $entry['date'] ) ) : '';
			$children     = $threads[ (int) $entry['id'] ] ?? [];
			$platform_tag = nop_wm_platform_tag( (string) ( $entry['platform'] ?? '' ), ! empty( $entry['via_bridgy'] ) );
			$author_name  = $entry['author'];
		?>
		<li class="nop-webmentions__reply h-cite" id="nop-wm-<?php echo esc_attr( (string) $entry['id'] ); ?>">
			<div class="nop-webmentions__reply-avatar" aria-hidden="true">
				<?php echo nop_wm_avatar_wrap( $entry, 40 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes its inputs ?>
			</div>
			<div class="nop-webmentions__reply-body">
				<p class="nop-webmentions__reply-meta">
					<?php if ( $profile_url ) : ?>
					<a class="p-author h-card u-url" href="<?php echo esc_url( $profile_url ); ?>" target="_blank" rel="noopener noreferrer">
						<strong class="p-name"><?php echo esc_html( $author_name ); ?></strong>
					</a>
					<?php else : ?>
					<strong class="p-author p-name"><?php echo esc_html( $author_name ); ?></strong>
					<?php endif; ?>
					<?php if ( $entry['handle'] ) : ?>
					<span class="nop-webmentions__reply-handle"><?php echo esc_html( $entry['handle'] ); ?></span>
					<?php endif; ?>
				</p>
				<?php if ( $entry['content'] ) : ?>
				<div class="nop-webmentions__reply-content e-content"><?php echo wp_kses_post( $entry['content'] ); ?></div>
				<?php endif; ?>
				<?php // When, where and Reply follow the words, so a theme can run the name into them (as a comment is read). ?>
				<p class="nop-webmentions__reply-foot">
					<?php if ( $time_display ) : ?>
					<a href="<?php echo esc_url( $reply_url ); ?>" class="nop-webmentions__reply-time u-url" target="_blank" rel="noopener noreferrer">
						<time class="dt-published" datetime="<?php echo esc_attr( $time_iso ); ?>" aria-label="<?php echo esc_attr( $time_label ); ?>"><?php echo esc_html( $time_display ); ?></time>
					</a>
					<?php endif; ?>
					<?php if ( $platform_tag ) : ?>
					<?php echo $platform_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes its inputs ?>
					<?php endif; ?>
					<?php if ( $comments_open ) : ?>
					<?php echo nop_wm_reply_link( $entry['id'], $post_id, "nop-wm-{$entry['id']}", $author_name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes its inputs ?>
					<?php endif; ?>
				</p>

				<?php if ( $children ) : ?>
				<ul class="nop-webmentions__comment-replies">
					<?php foreach ( $children as $child ) :
						$child_url      = esc_url( $child['author_url'] );
						$child_link     = esc_url( $child['source'] ?: $child['author_url'] );
						$child_time     = $child['date'] ? nop_wm_time_ago( $child['date'] ) : '';
						$child_label    = $child['date'] ? nop_wm_time_label( $child['date'] ) : '';
						$child_time_iso = $child['date'] ? gmdate( 'c', (int) strtotime( $child['date'] ) ) : '';
						$child_tag      = nop_wm_platform_tag( (string) ( $child['platform'] ?? '' ), ! empty( $child['via_bridgy'] ) );
					?>
					<li class="nop-webmentions__comment-reply h-cite" id="nop-wm-<?php echo esc_attr( (string) $child['id'] ); ?>">
						<div class="nop-webmentions__comment-reply-avatar" aria-hidden="true">
							<?php echo nop_wm_avatar_wrap( $child, 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes its inputs ?>
						</div>
						<div class="nop-webmentions__comment-reply-body">
							<p class="nop-webmentions__reply-meta">
								<?php if ( $child_url ) : ?>
								<a class="p-author h-card u-url" href="<?php echo esc_url( $child_url ); ?>" target="_blank" rel="noopener noreferrer">
									<strong class="p-name"><?php echo esc_html( $child['author'] ); ?></strong>
								</a>
								<?php else : ?>
								<strong class="p-author p-name"><?php echo esc_html( $child['author'] ); ?></strong>
								<?php endif; ?>
								<?php if ( $child['handle'] ) : ?>
								<span class="nop-webmentions__reply-handle"><?php echo esc_html( $child['handle'] ); ?></span>
								<?php endif; ?>
							</p>
							<?php if ( $child['content'] ) : ?>
							<div class="nop-webmentions__reply-content e-content"><?php echo wp_kses_post( $child['content'] ); ?></div>
							<?php endif; ?>
							<p class="nop-webmentions__reply-foot">
								<?php if ( $child_time ) : ?>
								<a href="<?php echo esc_url( $child_link ); ?>" class="nop-webmentions__reply-time u-url" target="_blank" rel="noopener noreferrer">
									<time class="dt-published" datetime="<?php echo esc_attr( $child_time_iso ); ?>" aria-label="<?php echo esc_attr( $child_label ); ?>"><?php echo esc_html( $child_time ); ?></time>
								</a>
								<?php endif; ?>
								<?php if ( $child_tag ) : ?>
								<?php echo $child_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes its inputs ?>
								<?php endif; ?>
								<?php if ( $comments_open ) : ?>
								<?php echo nop_wm_reply_link( $child['id'], $post_id, "nop-wm-{$child['id']}", $child['author'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes its inputs ?>
								<?php endif; ?>
							</p>
						</div>
					</li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>
			</div>
		</li>
		<?php endforeach; ?>
	</ul>

</section>
