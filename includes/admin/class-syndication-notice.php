<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Admin;

use NOP\IndieWeb\Syndication\Syndication_Manager;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Self-resolving admin notice for failed syndications.
 *
 * The per-post editor panel already shows a delivery failure, but only if you
 * open that post — so a dead token (revoked Mastodon app, lapsed Tumblr refresh,
 * expired Pixelfed token) fails silently. This surfaces the aggregate across all
 * posts on the screens you actually look at, and disappears the moment the last
 * failure is retried away (no dismissal state — it's only here when something is
 * genuinely broken).
 */
class Syndication_Notice {

	/** Screens the notice appears on — dashboard, the posts list, and the settings page. */
	private const SCREENS = [ 'dashboard', 'edit-post', 'settings_page_nop-indieweb-settings' ];

	public function __construct( private Syndication_Manager $manager ) {}

	public function register(): void {
		add_action( 'admin_notices', [ $this, 'render' ] );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, self::SCREENS, true ) ) {
			return;
		}

		$count = (int) $this->manager->failure_summary()['total_failed_posts'];
		if ( $count < 1 ) {
			return;
		}

		$review_url = admin_url( 'options-general.php?page=nop-indieweb-settings#networks' );

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of posts that failed to syndicate */
					_n(
						'%d post failed to syndicate to one or more networks.',
						'%d posts failed to syndicate to one or more networks.',
						$count,
						'nop-indieweb'
					),
					$count
				)
			),
			esc_url( $review_url ),
			esc_html__( 'Review →', 'nop-indieweb' )
		);
	}
}
