<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Services;

/**
 * Handles Micropub in-reply-to posts.
 * Spec: https://indieweb.org/reply
 *
 * Must be registered AFTER RSVP — both detect in-reply-to and RSVP
 * is the more specific case.
 */
class Reply extends Url_Response_Service {

	public function get_name(): string { return 'Reply'; }
	public function get_slug(): string { return 'reply'; }

	protected function url_property(): string { return 'in-reply-to'; }
	protected function url_meta_key(): string { return 'nop_indieweb_in_reply_to'; }

	public function can_handle( array $payload ): bool {
		$props = $payload['properties'] ?? [];
		return ! empty( $props['in-reply-to'][0] ) && empty( $props['rsvp'][0] );
	}

	public function get_kind( array $parsed = [] ): string {
		return 'reply';
	}
}
