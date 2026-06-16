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
		$props  = $payload['properties'] ?? [];
		$rsvp   = strtolower( sanitize_key( $props['rsvp'][0] ?? '' ) );
		$parsed['rsvp'] = in_array( $rsvp, self::VALID_VALUES, true ) ? $rsvp : 'yes';

		// Event detail (h-event), as sent by the /post authoring app's RSVP lookup.
		// Optional — absent on a bare RSVP, kept editable on the post afterwards.
		$parsed['event_name']     = sanitize_text_field( $props['event-name'][0] ?? '' );
		$parsed['event_start']    = sanitize_text_field( $props['event-start'][0] ?? '' );
		$parsed['event_end']      = sanitize_text_field( $props['event-end'][0] ?? '' );
		$parsed['event_location'] = sanitize_text_field( $props['event-location'][0] ?? '' );

		return $parsed;
	}

	public function get_kind( array $parsed = [] ): string {
		return 'rsvp';
	}

	public function get_meta( array $parsed ): array {
		$meta = parent::get_meta( $parsed ) + [
			'nop_indieweb_rsvp' => $parsed['rsvp'],
		];

		foreach ( [
			'nop_indieweb_rsvp_event_name'     => $parsed['event_name']     ?? '',
			'nop_indieweb_rsvp_event_start'    => $parsed['event_start']    ?? '',
			'nop_indieweb_rsvp_event_end'      => $parsed['event_end']      ?? '',
			'nop_indieweb_rsvp_event_location' => $parsed['event_location'] ?? '',
		] as $key => $value ) {
			if ( '' !== $value ) {
				$meta[ $key ] = $value;
			}
		}

		return $meta;
	}
}
