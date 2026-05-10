<?php
/**
 * Webmentions block — server-side render.
 *
 * Groups approved webmention comments by type:
 *   likes + reposts → facepile of avatars
 *   replies + mentions → list with avatar, name, content, date
 *
 * Available variables (injected by WordPress):
 *   $attributes — block attributes array
 *   $content    — inner block content (unused)
 *   $block      — WP_Block instance, provides postId via context
 */

declare( strict_types=1 );

$post_id = $block->context['postId'] ?? get_the_ID();

// Editor preview with no real post.
if ( ! $post_id ) {
	$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-webmentions nop-webmentions--preview' ] );
	?>
	<div <?php echo $wrapper_attrs; ?>>
		<p class="nop-webmentions__label">3 likes</p>
		<div class="nop-webmentions__facepile">
			<span class="nop-webmentions__avatar nop-webmentions__avatar--placeholder"></span>
			<span class="nop-webmentions__avatar nop-webmentions__avatar--placeholder"></span>
			<span class="nop-webmentions__avatar nop-webmentions__avatar--placeholder"></span>
		</div>
	</div>
	<?php
	return;
}

$webmentions = get_comments( [
	'post_id' => $post_id,
	'type'    => 'webmention',
	'status'  => 'approve',
	'number'  => 200,
	'orderby' => 'comment_date_gmt',
	'order'   => 'ASC',
] );

if ( empty( $webmentions ) ) {
	return;
}

$likes   = [];
$reposts = [];
$replies = [];

foreach ( $webmentions as $wm ) {
	$type         = get_comment_meta( $wm->comment_ID, 'webmention_type', true );
	$photo        = get_comment_meta( $wm->comment_ID, 'webmention_author_photo', true );
	$original_url = get_comment_meta( $wm->comment_ID, 'webmention_original_url', true ) ?: $wm->comment_author_url;

	$entry = [
		'author'       => $wm->comment_author,
		'author_url'   => $wm->comment_author_url,
		'original_url' => $original_url,
		'photo'        => $photo,
		'content'      => $wm->comment_content,
		'date'         => $wm->comment_date_gmt,
	];

	if ( 'like' === $type ) {
		$likes[] = $entry;
	} elseif ( 'repost' === $type ) {
		$reposts[] = $entry;
	} else {
		$replies[] = $entry;
	}
}

if ( ! $likes && ! $reposts && ! $replies ) {
	return;
}

$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-webmentions' ] );
?>
<div <?php echo $wrapper_attrs; ?>>

	<?php if ( $likes || $reposts ) :
		$parts = [];
		if ( $likes )   $parts[] = count( $likes )   === 1 ? '1 like'   : count( $likes )   . ' likes';
		if ( $reposts ) $parts[] = count( $reposts )  === 1 ? '1 repost' : count( $reposts )  . ' reposts';
	?>
	<div class="nop-webmentions__facepile-section">
		<p class="nop-webmentions__label"><?php echo esc_html( implode( ', ', $parts ) ); ?></p>
		<div class="nop-webmentions__facepile">
			<?php foreach ( array_merge( $likes, $reposts ) as $entry ) : ?>
			<a href="<?php echo esc_url( $entry['original_url'] ?: $entry['author_url'] ); ?>"
			   class="nop-webmentions__avatar-link"
			   title="<?php echo esc_attr( $entry['author'] ); ?>"
			   target="_blank" rel="noopener">
				<?php if ( $entry['photo'] ) : ?>
				<img src="<?php echo esc_url( $entry['photo'] ); ?>"
				     alt="<?php echo esc_attr( $entry['author'] ); ?>"
				     class="nop-webmentions__avatar"
				     width="32" height="32" loading="lazy">
				<?php else : ?>
				<span class="nop-webmentions__avatar nop-webmentions__avatar--fallback"
				      aria-label="<?php echo esc_attr( $entry['author'] ); ?>">
					<?php echo esc_html( mb_substr( $entry['author'], 0, 1 ) ); ?>
				</span>
				<?php endif; ?>
			</a>
			<?php endforeach; ?>
		</div>
	</div>
	<?php endif; ?>

	<?php if ( $replies ) : ?>
	<ul class="nop-webmentions__replies">
		<?php foreach ( $replies as $entry ) : ?>
		<li class="nop-webmentions__reply">
			<a href="<?php echo esc_url( $entry['original_url'] ?: $entry['author_url'] ); ?>"
			   class="nop-webmentions__avatar-link"
			   target="_blank" rel="noopener">
				<?php if ( $entry['photo'] ) : ?>
				<img src="<?php echo esc_url( $entry['photo'] ); ?>"
				     alt="<?php echo esc_attr( $entry['author'] ); ?>"
				     class="nop-webmentions__avatar"
				     width="40" height="40" loading="lazy">
				<?php else : ?>
				<span class="nop-webmentions__avatar nop-webmentions__avatar--fallback"
				      aria-label="<?php echo esc_attr( $entry['author'] ); ?>">
					<?php echo esc_html( mb_substr( $entry['author'], 0, 1 ) ); ?>
				</span>
				<?php endif; ?>
			</a>
			<div class="nop-webmentions__reply-body">
				<p class="nop-webmentions__reply-meta">
					<a href="<?php echo esc_url( $entry['author_url'] ); ?>" target="_blank" rel="noopener">
						<?php echo esc_html( $entry['author'] ); ?>
					</a>
					<time class="nop-webmentions__reply-date" datetime="<?php echo esc_attr( $entry['date'] ); ?>">
						<?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $entry['date'] ) ) ); ?>
					</time>
				</p>
				<?php if ( $entry['content'] ) : ?>
				<p class="nop-webmentions__reply-content"><?php echo wp_kses_post( $entry['content'] ); ?></p>
				<?php endif; ?>
			</div>
		</li>
		<?php endforeach; ?>
	</ul>
	<?php endif; ?>

</div>
