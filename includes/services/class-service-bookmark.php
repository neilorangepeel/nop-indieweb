<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Services;

/**
 * Handles Micropub bookmark-of posts.
 * Spec: https://indieweb.org/bookmark
 */
class Bookmark extends Url_Response_Service {

	public function get_name(): string { return 'Bookmark'; }
	public function get_slug(): string { return 'bookmark'; }

	protected function url_property(): string { return 'bookmark-of'; }
	protected function url_meta_key(): string { return 'nop_indieweb_bookmark_of'; }

	public function get_kind( array $parsed = [] ): string {
		return 'bookmark';
	}

	protected function get_dedup_key( array $parsed ): ?string {
		return $parsed['url'] ?: null;
	}
}
