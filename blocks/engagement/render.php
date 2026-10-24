<?php
/**
 * Likes and Reposts block — server-side render.
 *
 * One row per kind of response: its icon and total, a stack of faces (the
 * first few), and a line naming the first two ("Liked by Jeremy, Sophie and
 * 61 others"). Show all is a <details> holding every face we hold, each a link
 * to the person with their name as its label: everything is there, nothing
 * shouts. The totals count what we have no face for too (the site's own like
 * button, counts synced from Mastodon or Bluesky).
 *
 * Front end: renders nothing when the post has no likes or reposts.
 * Editor: shows sample faces so the block is visible and designable.
 *
 * @package NOP_IndieWeb
 *
 * @var array<string, mixed> $attributes
 * @var \WP_Block            $block
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once NOP_INDIEWEB_DIR . 'includes/webmention-render.php';

$preview = nop_wm_is_editor_preview();
$post_id = nop_wm_resolve_post_id( $block );
$data    = $preview ? nop_wm_sample_data() : nop_wm_get_data( $post_id );
$counts  = $post_id ? \NOP\IndieWeb\Webmention\Engagement_Counts::get( $post_id ) : [ 'likes' => 0, 'reposts' => 0 ];
$stack   = max( 1, min( 24, (int) ( $attributes['stack'] ?? 10 ) ) );

$rows = [];
if ( $attributes['showLikes'] ?? true ) {
	$total  = $preview ? count( $data['likes'] ) : max( count( $data['likes'] ), (int) $counts['likes'], (int) ( new \NOP\IndieWeb\Webmention\Like_Endpoint() )->like_count( $post_id ) );
	$rows[] = [ 'likes', $data['likes'], $total ];
}
if ( $attributes['showReposts'] ?? true ) {
	$rows[] = [ 'reposts', $data['reposts'], $preview ? count( $data['reposts'] ) : max( count( $data['reposts'] ), (int) $counts['reposts'] ) ];
}
$rows = array_filter( $rows, static fn( $row ) => $row[2] > 0 );
if ( ! $rows ) {
	return;
}

// "Liked by Jeremy, Sophie and 61 others": the first two we have names for, and everyone else as a number.
$line = static function ( string $type, array $people, int $total ): string {
	$name  = static function ( array $p ): string {
		$author = esc_html( (string) $p['author'] );
		$url    = esc_url( (string) ( $p['author_url'] ?? '' ) );
		return $url ? '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $author . '</a>' : $author;
	};
	$named  = array_slice( array_values( array_filter( $people, static fn( $p ) => '' !== trim( (string) $p['author'] ) ) ), 0, 2 );
	$others = max( 0, $total - count( $named ) );
	$names  = implode( ', ', array_map( $name, $named ) );
	if ( ! $named ) {
		/* translators: %d: number of people */
		return esc_html( sprintf( 'likes' === $type ? _n( '%d like', '%d likes', $total, 'nop-indieweb' ) : _n( '%d repost', '%d reposts', $total, 'nop-indieweb' ), $total ) );
	}
	if ( $others ) {
		/* translators: 1: one or two names, 2: number of other people */
		$names = sprintf( _n( '%1$s and %2$d other', '%1$s and %2$d others', $others, 'nop-indieweb' ), $names, $others );
	}
	/* translators: %s: who, e.g. "Jeremy, Sophie and 61 others" */
	return sprintf( 'likes' === $type ? __( 'Liked by %s', 'nop-indieweb' ) : __( 'Reposted by %s', 'nop-indieweb' ), $names );
};

$face = static function ( array $p, int $size ): string {
	return nop_wm_avatar_wrap( $p, $size );
};

$wrapper = get_block_wrapper_attributes( [ 'class' => 'nop-engagement' ] );
?>
<section <?php echo wp_kses_data( $wrapper ); ?> aria-label="<?php esc_attr_e( 'Likes and reposts', 'nop-indieweb' ); ?>">
	<?php foreach ( $rows as [ $type, $people, $total ] ) : ?>
	<div class="nop-engagement__row nop-engagement__row--<?php echo esc_attr( $type ); ?>">
		<div class="nop-engagement__summary">
			<span class="nop-engagement__total"><span class="nop-engagement__icon" aria-hidden="true"></span><?php echo esc_html( (string) $total ); ?></span>
			<?php if ( $people ) : ?>
			<div class="nop-engagement__stack" aria-hidden="true"><?php echo implode( '', array_map( static fn( $p ) => $face( $p, 28 ), array_slice( $people, 0, $stack ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes ?></div>
			<?php endif; ?>
			<span class="nop-engagement__line"><?php echo $line( $type, $people, $total ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure ?></span>
		</div>
		<?php if ( count( $people ) > 2 ) : ?>
		<details class="nop-engagement__all">
			<summary><?php echo esc_html( sprintf( /* translators: %d: number of faces */ __( 'Show all %d', 'nop-indieweb' ), count( $people ) ) ); ?></summary>
			<ul class="nop-engagement__grid">
				<?php foreach ( $people as $p ) : ?>
				<li title="<?php echo esc_attr( (string) $p['author'] ); ?>"><?php echo $face( $p, 32 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes ?><span class="screen-reader-text"><?php echo esc_html( (string) $p['author'] ); ?></span></li>
				<?php endforeach; ?>
			</ul>
		</details>
		<?php endif; ?>
	</div>
	<?php endforeach; ?>
</section>
