<?php
declare( strict_types=1 );

namespace NOP\IndieWeb;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WebSub hub advertising and publisher pings.
 *
 * Advertises a configured hub via <link rel="hub"> and HTTP Link headers
 * (wired up in Link_Discovery::output_link_tags / output_link_headers), and pings
 * the hub when a new post is published so subscribers get notified immediately.
 *
 * Spec: https://www.w3.org/TR/websub/
 */
class WebSub {

	public function register(): void {
		add_action( 'transition_post_status', [ $this, 'maybe_ping' ], 10, 3 );
	}

	public function hub_url(): string {
		return (string) ( nop_indieweb_get_option( 'webmentions', [] )['hub_url'] ?? '' );
	}

	/**
	 * Pings the configured hub on first publish of a post.
	 * Updates are not pinged — feed subscribers care about new content.
	 */
	public function maybe_ping( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( 'post' !== $post->post_type ) {
			return;
		}

		$hub = $this->hub_url();
		if ( ! $hub ) {
			return;
		}

		wp_remote_post( $hub, [
			'timeout'    => 5,
			'user-agent' => 'NOP IndieWeb/' . NOP_INDIEWEB_VERSION . ' (websub; +' . home_url( '/' ) . ')',
			'body'       => [
				'hub.mode' => 'publish',
				'hub.url'  => get_feed_link(),
			],
		] );
	}
}
