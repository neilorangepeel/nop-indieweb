<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Lookup;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Lookup_Provider_Base {

	abstract public function get_slug(): string;
	abstract public function get_label(): string;

	/**
	 * Search for items matching $query.
	 *
	 * Each result must be an array with at minimum:
	 *   id        (string)      — provider-specific identifier
	 *   title     (string)      — display title
	 *   year      (string|null) — release / publication year
	 *   thumb_url (string|null) — small thumbnail URL
	 *   meta      (array)       — post meta keys/values to apply on selection
	 *
	 * Returns WP_Error on failure.
	 */
	abstract public function search( string $query ): array|\WP_Error;
}
