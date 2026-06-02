<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Services;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Micropub repost-of posts.
 * Spec: https://indieweb.org/repost
 */
class Repost extends Url_Response_Service {

	public function get_name(): string { return 'Repost'; }
	public function get_slug(): string { return 'repost'; }

	protected function url_property(): string { return 'repost-of'; }
	protected function url_meta_key(): string { return 'nop_indieweb_repost_of'; }
	protected function button_label(): string { return __( 'View Original', 'nop-indieweb' ); }

	public function get_kind( array $parsed = [] ): string {
		return 'repost';
	}

	protected function get_dedup_key( array $parsed ): ?string {
		return $parsed['url'] ?: null;
	}
}
