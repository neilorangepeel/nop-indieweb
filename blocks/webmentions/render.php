<?php
/**
 * Webmentions block — server-side render.
 *
 * Unified "Responses" display: webmention interactions + regular WordPress
 * comments all in one place. The comment form lives below this block.
 *
 * Likes + reposts → overlapping facepile with "Liked by X, Y and N others"
 * Replies         → chronological list mixing webmention replies and WordPress
 *                   comments, with threaded replies nested inline
 */
declare( strict_types=1 );

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/helpers.php';

$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );

if ( ! $post_id ) {
	$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-webmentions nop-webmentions--preview' ] );
	?>
	<div <?php echo $wrapper_attrs; ?>>
		<div class="nop-webmentions__facepile" aria-hidden="true">
			<?php for ( $i = 0; $i < 4; $i++ ) : ?>
			<div class="nop-webmentions__avatar-wrap">
				<span class="nop-webmentions__avatar nop-webmentions__avatar--placeholder nop-webmentions__avatar--36"></span>
			</div>
			<?php endfor; ?>
		</div>
		<p class="nop-webmentions__liked-by">Liked by <strong>someone</strong> and 3 others</p>
	</div>
	<?php
	return;
}

if ( comments_open( $post_id ) ) {
	wp_enqueue_script( 'comment-reply' );
}

// ── Fetch all webmentions ─────────────────────────────────────────────────────

/**
 * Maximum number of webmentions to fetch per post. Filterable via
 * nop_indieweb_webmention_fetch_limit for high-traffic posts.
 */
$wm_limit = (int) apply_filters( 'nop_indieweb_webmention_fetch_limit', 100 );

$webmentions = get_comments( [
	'post_id' => $post_id,
	'type'    => 'webmention',
	'status'  => 'approve',
	'number'  => $wm_limit,
	'orderby' => 'comment_date_gmt',
	'order'   => 'ASC',
] );

// ── Fetch all regular WordPress comments ──────────────────────────────────────

$wp_comments_limit = (int) apply_filters( 'nop_indieweb_wp_comments_fetch_limit', 100 );

$wp_comments = get_comments( [
	'post_id' => $post_id,
	'type'    => 'comment',
	'status'  => 'approve',
	'number'  => $wp_comments_limit,
	'orderby' => 'comment_date_gmt',
	'order'   => 'ASC',
] );

if ( empty( $webmentions ) && empty( $wp_comments ) ) {
	nop_wm_render_empty_state( $post_id );
	return;
}

// ── Sort webmentions into buckets ─────────────────────────────────────────────

$likes           = [];
$reposts         = [];
$replies         = [];
$webmention_ids  = [];

foreach ( $webmentions as $wm ) {
	$type             = get_comment_meta( $wm->comment_ID, 'webmention_type', true );
	$webmention_ids[] = (int) $wm->comment_ID;
	$source           = get_comment_meta( $wm->comment_ID, 'webmention_source', true );

	$entry = [
		'id'         => $wm->comment_ID,
		'author'     => $wm->comment_author,
		'author_url' => $wm->comment_author_url,
		'photo'      => get_comment_meta( $wm->comment_ID, 'webmention_author_photo',  true ),
		'handle'     => get_comment_meta( $wm->comment_ID, 'webmention_author_handle', true ),
		'platform'   => get_comment_meta( $wm->comment_ID, 'webmention_platform',      true ),
		'via_bridgy' => $source && str_contains( $source, 'brid.gy' ),
		'source'     => $source ?: ( get_comment_meta( $wm->comment_ID, 'webmention_original_url', true ) ?: $wm->comment_author_url ),
		'content'    => $wm->comment_content,
		'date'       => $wm->comment_date_gmt,
	];

	if ( 'like' === $type ) {
		$likes[] = $entry;
	} elseif ( 'repost' === $type ) {
		$reposts[] = $entry;
	} else {
		$replies[] = $entry;
	}
}

// ── Distribute regular comments ───────────────────────────────────────────────
// Children of a webmention → nested below that webmention inline.
// Everything else (top-level or comment→comment) → into the unified replies list.

$comment_children = [];

foreach ( $wp_comments as $c ) {
	$parent = (int) $c->comment_parent;

	if ( $parent > 0 && in_array( $parent, $webmention_ids, true ) ) {
		$comment_children[ $parent ][] = $c;
		continue;
	}

	$replies[] = [
		'id'         => $c->comment_ID,
		'author'     => $c->comment_author,
		'author_url' => $c->comment_author_url,
		'photo'      => get_avatar_url( $c, [ 'size' => 80 ] ),
		'handle'     => '',
		'platform'   => '',
		'source'     => $c->comment_author_url,
		'content'    => $c->comment_content,
		'date'       => $c->comment_date_gmt,
	];
}

usort( $replies, static fn( array $a, array $b ) => strcmp( $a['date'], $b['date'] ) );

if ( ! $likes && ! $reposts && ! $replies ) {
	nop_wm_render_empty_state( $post_id );
	return;
}

$comments_open = comments_open( $post_id );
$total_count   = count( $likes ) + count( $reposts ) + count( $replies );
/* translators: %d: total response count */
$heading_text  = sprintf( _n( '%d Response', '%d Responses', $total_count, 'nop-indieweb' ), $total_count );
$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-webmentions' ] );
?>
<div <?php echo $wrapper_attrs; ?>>

	<h2 class="nop-webmentions__heading"><?php echo esc_html( $heading_text ); ?></h2>

	<?php if ( $likes ) :
		// Only show real photos in the facepile — silhouettes add no value there.
		// The "Liked by" sentence below conveys the same info for screen readers.
		$with_photo = array_values( array_filter( $likes, fn( array $e ) => ! empty( $e['photo'] ) ) );
		$shown      = array_slice( $with_photo, 0, 7 );
		$overflow   = count( $likes ) - count( $shown );
	?>
	<div class="nop-webmentions__likes">
		<?php if ( $shown ) : ?>
		<div class="nop-webmentions__facepile" aria-hidden="true">
			<?php foreach ( $shown as $entry ) : ?>
			<?php echo nop_wm_avatar_wrap( $entry, 36 ); // phpcs:ignore ?>
			<?php endforeach; ?>
			<?php if ( $overflow > 0 ) : ?>
			<div class="nop-webmentions__avatar-wrap">
				<span class="nop-webmentions__overflow nop-webmentions__avatar--36">+<?php echo esc_html( (string) $overflow ); ?></span>
			</div>
			<?php endif; ?>
		</div>
		<?php endif; ?>
		<p class="nop-webmentions__liked-by"><?php echo nop_wm_liked_by( $likes ); // phpcs:ignore ?></p>
	</div>
	<?php endif; ?>

	<?php if ( $reposts ) :
		$with_photo_r = array_values( array_filter( $reposts, fn( array $e ) => ! empty( $e['photo'] ) ) );
		$shown_r      = array_slice( $with_photo_r, 0, 7 );
		$overflow_r   = count( $reposts ) - count( $shown_r );
	?>
	<div class="nop-webmentions__reposts">
		<p class="nop-webmentions__section-label">
			<?php echo nop_wm_repost_icon(); // phpcs:ignore ?>
			<?php /* translators: %d: number of reposts */ ?>
			<?php echo esc_html( sprintf( _n( '%d repost', '%d reposts', count( $reposts ), 'nop-indieweb' ), count( $reposts ) ) ); ?>
		</p>
		<?php if ( $shown_r ) : ?>
		<div class="nop-webmentions__facepile" aria-hidden="true">
			<?php foreach ( $shown_r as $entry ) : ?>
			<?php echo nop_wm_avatar_wrap( $entry, 36 ); // phpcs:ignore ?>
			<?php endforeach; ?>
			<?php if ( $overflow_r > 0 ) : ?>
			<div class="nop-webmentions__avatar-wrap">
				<span class="nop-webmentions__overflow nop-webmentions__avatar--36">+<?php echo esc_html( (string) $overflow_r ); ?></span>
			</div>
			<?php endif; ?>
		</div>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<?php if ( $replies ) : ?>
	<ul class="nop-webmentions__replies">
		<?php foreach ( $replies as $entry ) :
			$profile_url  = esc_url( $entry['author_url'] );
			$reply_url    = esc_url( $entry['source'] ?: $entry['author_url'] );
			$time_display = $entry['date'] ? nop_wm_time_ago( $entry['date'] ) : '';
			$time_label   = $entry['date'] ? nop_wm_time_label( $entry['date'] ) : '';
			$time_iso     = $entry['date'] ? gmdate( 'c', (int) strtotime( $entry['date'] ) ) : '';
			$children     = $comment_children[ (int) $entry['id'] ] ?? [];
			$platform_tag = nop_wm_platform_tag( (string) ( $entry['platform'] ?? '' ), ! empty( $entry['via_bridgy'] ) );
			$author_name  = $entry['author'];
		?>
		<li class="nop-webmentions__reply" id="nop-wm-<?php echo esc_attr( (string) $entry['id'] ); ?>">
			<div class="nop-webmentions__reply-avatar" aria-hidden="true">
				<?php echo nop_wm_avatar_wrap( $entry, 40 ); // phpcs:ignore ?>
			</div>
			<div class="nop-webmentions__reply-body">
				<p class="nop-webmentions__reply-meta">
					<?php if ( $profile_url ) : ?>
					<a href="<?php echo $profile_url; ?>" target="_blank" rel="noopener noreferrer">
						<strong><?php echo esc_html( $author_name ); ?></strong>
					</a>
					<?php else : ?>
					<strong><?php echo esc_html( $author_name ); ?></strong>
					<?php endif; ?>
					<?php if ( $entry['handle'] ) : ?>
					<span class="nop-webmentions__reply-handle"><?php echo esc_html( $entry['handle'] ); ?></span>
					<?php endif; ?>
					<?php if ( $platform_tag ) : ?>
					<?php echo $platform_tag; // phpcs:ignore ?>
					<?php endif; ?>
					<?php if ( $time_display ) : ?>
					<a href="<?php echo $reply_url; ?>" class="nop-webmentions__reply-time" target="_blank" rel="noopener noreferrer">
						<time datetime="<?php echo esc_attr( $time_iso ); ?>" aria-label="<?php echo esc_attr( $time_label ); ?>"><?php echo esc_html( $time_display ); ?></time>
					</a>
					<?php endif; ?>
					<?php if ( $comments_open ) : ?>
					<?php echo nop_wm_reply_link( $entry['id'], $post_id, "nop-wm-{$entry['id']}", $author_name ); // phpcs:ignore ?>
					<?php endif; ?>
				</p>
				<?php if ( $entry['content'] ) : ?>
				<div class="nop-webmentions__reply-content"><?php echo wp_kses_post( $entry['content'] ); ?></div>
				<?php endif; ?>

				<?php if ( $children ) : ?>
				<ul class="nop-webmentions__comment-replies">
					<?php foreach ( $children as $child ) :
						$child_time     = nop_wm_time_ago( $child->comment_date_gmt );
						$child_label    = nop_wm_time_label( $child->comment_date_gmt );
						$child_time_iso = gmdate( 'c', (int) strtotime( $child->comment_date_gmt ) );
					?>
					<li class="nop-webmentions__comment-reply" id="nop-wm-<?php echo esc_attr( (string) $child->comment_ID ); ?>">
						<div class="nop-webmentions__comment-reply-avatar" aria-hidden="true">
							<?php echo get_avatar( $child, 28, '', $child->comment_author, [ 'class' => 'nop-webmentions__avatar' ] ); // phpcs:ignore ?>
						</div>
						<div class="nop-webmentions__comment-reply-body">
							<p class="nop-webmentions__reply-meta">
								<strong><?php echo esc_html( $child->comment_author ); ?></strong>
								<?php if ( $child_time ) : ?>
								<span class="nop-webmentions__reply-time">
									<time datetime="<?php echo esc_attr( $child_time_iso ); ?>" aria-label="<?php echo esc_attr( $child_label ); ?>"><?php echo esc_html( $child_time ); ?></time>
								</span>
								<?php endif; ?>
								<?php if ( $comments_open ) : ?>
								<?php echo nop_wm_reply_link( $child->comment_ID, $post_id, "nop-wm-{$child->comment_ID}", $child->comment_author ); // phpcs:ignore ?>
								<?php endif; ?>
							</p>
							<?php if ( $child->comment_content ) : ?>
							<div class="nop-webmentions__reply-content"><?php echo wp_kses_post( $child->comment_content ); ?></div>
							<?php endif; ?>
						</div>
					</li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>
			</div>
		</li>
		<?php endforeach; ?>
	</ul>
	<?php endif; ?>

	<?php echo nop_wm_render_comment_form( $post_id ); // phpcs:ignore ?>

</div>

