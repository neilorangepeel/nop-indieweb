<?php
/**
 * Syndication Panel block — server-side render.
 *
 * Lists the places this post is also being discussed off-site, with platform
 * labels resolved via Syndication_Manager so custom syndicators registered
 * through the `nop_indieweb_register_syndicators` filter are honoured.
 * Renders nothing when the post has no syndication URLs.
 */
declare( strict_types=1 );

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use NOP\IndieWeb\Syndication\Syndication_Manager;

$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );

$is_editor = defined( 'REST_REQUEST' ) && REST_REQUEST
	&& isset( $_GET['context'] ) && 'edit' === $_GET['context']; // phpcs:ignore WordPress.Security.NonceVerification

$items      = [];
$is_preview = false;

if ( $post_id ) {
	$urls = get_post_meta( $post_id, 'nop_indieweb_syndication', true );
	$urls = is_array( $urls ) ? array_filter( $urls ) : [];

	// Source URL is where the post originated (Swarm, backfed Mastodon, etc.) —
	// it's already shown by post-source as "Originally posted on", so don't
	// duplicate it here. mf2 spec keeps it in the syndication array; this is a
	// display filter only.
	$source_url = (string) get_post_meta( $post_id, 'nop_indieweb_source_url', true );
	if ( $source_url ) {
		$urls = array_filter( $urls, fn( $u ) => $u !== $source_url );
	}

	if ( $urls ) {
		$manager = new Syndication_Manager();
		foreach ( $urls as $url ) {
			$resolved = $manager->resolve_url( $url );
			if ( $resolved ) {
				$items[] = [ 'url' => $url, 'slug' => $resolved['slug'], 'label' => $resolved['label'] ];
			} else {
				$host    = wp_parse_url( $url, PHP_URL_HOST );
				$items[] = [ 'url' => $url, 'slug' => 'unknown', 'label' => $host ?: $url ];
			}
		}
	}
}

// Editor preview when no post context or the post has no syndication URLs —
// shows the block's footprint so the template editor doesn't display
// the built-in "Block rendered as empty" placeholder.
if ( ! $items ) {
	if ( ! $is_editor ) {
		return;
	}
	$is_preview = true;
	$items      = [
		[ 'url' => 'https://bsky.app/profile/example.bsky.social/post/abc', 'slug' => 'bluesky',  'label' => 'Bluesky'  ],
		[ 'url' => 'https://mastodon.social/@example/123456789',            'slug' => 'mastodon', 'label' => 'Mastodon' ],
		[ 'url' => 'https://pixelfed.social/p/example/123456789',           'slug' => 'pixelfed', 'label' => 'Pixelfed' ],
	];
}

$wrapper = get_block_wrapper_attributes( [
	'class'      => 'nop-syndication-panel' . ( $is_preview ? ' nop-syndication-panel--preview' : '' ),
	'aria-label' => __( 'Also on', 'nop-indieweb' ),
] );
?>
<aside <?php echo $wrapper; ?>>
	<p class="nop-syndication-panel__heading"><?php esc_html_e( 'Also on', 'nop-indieweb' ); ?></p>
	<ul class="nop-syndication-panel__list">
		<?php foreach ( $items as $item ) : ?>
			<li class="nop-syndication-panel__item">
				<a class="nop-syndication-panel__link u-syndication"
				   href="<?php echo esc_url( $item['url'] ); ?>"
				   target="_blank"
				   rel="noopener noreferrer me">
					<span class="nop-syndication-panel__icon nop-syndication-panel__icon--<?php echo esc_attr( $item['slug'] ); ?>"
					      aria-hidden="true"><?php echo esc_html( strtoupper( substr( $item['label'], 0, 1 ) ) ); ?></span>
					<span class="nop-syndication-panel__label"><?php echo esc_html( $item['label'] ); ?></span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</aside>
