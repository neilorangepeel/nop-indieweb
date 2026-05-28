<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Services;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Micropub RSVP posts.
 * Spec: https://indieweb.org/rsvp
 *
 * Must be registered BEFORE Reply — both use in-reply-to and RSVP
 * is the more specific case (also has an rsvp property).
 */
class RSVP extends Url_Response_Service {

	private const VALID_VALUES = [ 'yes', 'no', 'maybe', 'interested' ];

	public function get_name(): string { return 'RSVP'; }
	public function get_slug(): string { return 'rsvp'; }

	protected function url_property(): string { return 'in-reply-to'; }
	protected function url_meta_key(): string { return 'nop_indieweb_in_reply_to'; }

	public function can_handle( array $payload ): bool {
		$props = $payload['properties'] ?? [];
		return ! empty( $props['in-reply-to'][0] ) && ! empty( $props['rsvp'][0] );
	}

	public function parse( array $payload ): array {
		$parsed = parent::parse( $payload );
		$rsvp   = strtolower( sanitize_key( $payload['properties']['rsvp'][0] ?? '' ) );
		$parsed['rsvp'] = in_array( $rsvp, self::VALID_VALUES, true ) ? $rsvp : 'yes';
		return $parsed;
	}

	public function get_kind( array $parsed = [] ): string {
		return 'rsvp';
	}

	public function get_meta( array $parsed ): array {
		return parent::get_meta( $parsed ) + [
			'nop_indieweb_rsvp' => $parsed['rsvp'],
		];
	}
}
