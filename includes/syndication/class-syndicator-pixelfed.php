<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Syndication;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Syndicator_Pixelfed extends Mastodon_Compatible_Syndicator {

	public function slug(): string  { return 'pixelfed'; }
	public function label(): string { return 'Pixelfed'; }

	protected function char_limit(): int { return 2000; }

	// Pixelfed is an image grid, not a timeline — only photo posts belong there.
	protected function supports_post( int $post_id ): bool {
		return 'photo' === get_post_meta( $post_id, 'nop_indieweb_post_kind', true );
	}
}
