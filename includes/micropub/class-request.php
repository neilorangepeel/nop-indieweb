<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Micropub;

use WP_REST_Request;

/**
 * Normalises a Micropub request into a consistent array shape.
 *
 * Micropub clients can send JSON (application/json) or form-encoded
 * (application/x-www-form-urlencoded) bodies. This class smooths that out
 * so every service downstream always receives the same structure:
 *
 *   [
 *     'action'     => '',           // 'create' (implicit), 'update', or 'delete'
 *     'url'        => '',           // target URL for update/delete actions
 *     'type'       => ['h-entry'],
 *     'properties' => [
 *       'content'     => ['...'],
 *       'published'   => ['...'],
 *       'checkin'     => [...],
 *       'syndication' => [...],
 *       'photo'       => [...],
 *     ]
 *   ]
 *
 * All property values are always arrays, matching the Micropub JSON syntax.
 * The 'action' and 'url' keys live at the top level, not inside 'properties'.
 */
class Request {

	public function normalise( WP_REST_Request $request ): array {
		$content_type = $request->get_content_type()['value'] ?? '';

		if ( str_contains( $content_type, 'application/json' ) ) {
			return $this->from_json( $request );
		}

		return $this->from_form( $request );
	}

	private function from_json( WP_REST_Request $request ): array {
		$body = $request->get_json_params() ?? [];

		return [
			'action'     => sanitize_key( $body['action'] ?? '' ),
			'url'        => esc_url_raw( $body['url'] ?? '' ),
			'type'       => $body['type'] ?? [ 'h-entry' ],
			'properties' => $body['properties'] ?? [],
			'replace'    => $body['replace'] ?? [],
			'add'        => $body['add'] ?? [],
			'delete'     => $body['delete'] ?? [],
		];
	}

	private function from_form( WP_REST_Request $request ): array {
		$params = $request->get_body_params();

		// These keys exist at the top level in form-encoded Micropub and are not properties.
		$not_properties = [ 'h', 'access_token', 'action', 'url' ];

		$properties = [];
		foreach ( $params as $key => $value ) {
			if ( in_array( $key, $not_properties, true ) ) {
				continue;
			}
			$properties[ $key ] = is_array( $value ) ? $value : [ $value ];
		}

		return [
			'action'     => sanitize_key( $params['action'] ?? '' ),
			'url'        => esc_url_raw( $params['url'] ?? '' ),
			'type'       => isset( $params['h'] ) ? [ 'h-' . $params['h'] ] : [ 'h-entry' ],
			'properties' => $properties,
		];
	}
}
