<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Services;

/**
 * Handles Micropub like-of posts.
 * Spec: https://indieweb.org/like
 */
class Like extends Url_Response_Service {

	public function get_name(): string { return 'Like'; }
	public function get_slug(): string { return 'like'; }

	protected function url_property(): string { return 'like-of'; }
	protected function url_meta_key(): string { return 'nop_indieweb_like_of'; }

	public function get_kind( array $parsed = [] ): string {
		return 'like';
	}

	protected function get_dedup_key( array $parsed ): ?string {
		return $parsed['url'] ?: null;
	}
}
