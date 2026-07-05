<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Tests;

use PHPUnit\Framework\TestCase;

use function NOP\IndieWeb\nop_indieweb_wm_norm_url;
use function NOP\IndieWeb\nop_indieweb_wm_silo_post_id;
use function NOP\IndieWeb\nop_indieweb_wm_silo_key;

/**
 * Unit tests for the pure back-feed dedup helpers that let a Bridgy webmention
 * and the internal API poller collapse to one row for the same silo event.
 * These are side-effect-free string functions — no WP, DB, or network.
 */
final class BackfeedDedupTest extends TestCase {

	public function test_norm_url_strips_scheme_and_trailing_slash(): void {
		$this->assertSame( 'bsky.app/profile/neil', nop_indieweb_wm_norm_url( 'https://bsky.app/profile/neil/' ) );
		$this->assertSame( 'bsky.app/profile/neil', nop_indieweb_wm_norm_url( 'HTTP://BSKY.APP/profile/neil' ) );
	}

	public function test_silo_post_id_extracts_bluesky_rkey_from_both_url_shapes(): void {
		$this->assertSame( '3kabc', nop_indieweb_wm_silo_post_id( 'https://bsky.app/profile/did:plc:xyz/post/3kabc' ) );
		$this->assertSame( '3kabc', nop_indieweb_wm_silo_post_id( 'at://did:plc:xyz/app.bsky.feed.post/3kabc' ) );
	}

	public function test_silo_post_id_extracts_mastodon_status_id(): void {
		$this->assertSame( '112233', nop_indieweb_wm_silo_post_id( 'https://mastodon.social/@neil/112233' ) );
		$this->assertSame( '112233', nop_indieweb_wm_silo_post_id( 'https://pixelfed.social/p/neil/112233' ) );
	}

	public function test_reply_key_matches_across_handle_and_did_urls(): void {
		// Bridgy reports the reply under the author handle; the poller under the DID.
		$bridgy  = nop_indieweb_wm_silo_key( 'reply', 'https://bsky.app/profile/neil.example/post/3kabc', '' );
		$poller  = nop_indieweb_wm_silo_key( 'reply', 'https://bsky.app/profile/did:plc:xyz/post/3kabc', '' );
		$this->assertSame( $bridgy, $poller );
		$this->assertSame( 'reply:3kabc', $bridgy );
	}

	public function test_like_key_uses_actor_and_type(): void {
		$this->assertSame(
			'like:bsky.app/profile/fan',
			nop_indieweb_wm_silo_key( 'like', 'https://bsky.app/profile/neil/post/3kabc', 'https://bsky.app/profile/fan' )
		);
		$this->assertNotSame(
			nop_indieweb_wm_silo_key( 'like', '', 'https://bsky.app/profile/fan' ),
			nop_indieweb_wm_silo_key( 'repost', '', 'https://bsky.app/profile/fan' )
		);
	}
}
