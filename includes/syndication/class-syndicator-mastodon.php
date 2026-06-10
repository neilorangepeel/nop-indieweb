<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Syndication;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Syndicator_Mastodon extends Mastodon_Compatible_Syndicator {

	public function slug(): string  { return 'mastodon'; }
	public function label(): string { return 'Mastodon'; }

	protected function char_limit(): int { return 500; }

	/**
	 * Appends #nobridge when Bluesky syndication is also enabled. This tells
	 * Mastodon's native ATProto bridge (and Bridgy Fed) not to create a second
	 * ATProto copy of the post, which would otherwise show up as a ghost reply
	 * on the directly-syndicated Bluesky post.
	 */
	protected function build_hashtag_string( int $post_id ): string {
		$tags = parent::build_hashtag_string( $post_id );

		if ( \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.bluesky.enabled', false ) ) {
			return '' !== $tags ? $tags . ' #nobridge' : '#nobridge';
		}

		return $tags;
	}
}
