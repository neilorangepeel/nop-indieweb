<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Preview;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Request;
use WP_REST_Response;

/**
 * REST endpoint that returns a lightweight preview of a target URL for the
 * /post composer's reply / like / bookmark / repost kinds.
 *
 *   POST /wp-json/nop-indieweb/v1/fetch-context   { "url": "https://…" }
 *
 * Returns the parsed title / author / excerpt / image (Link_Parser cascade) so
 * the author can see what they're acting on instead of a bare hostname. The
 * `source` flag tells the UI which layer matched (mf2, jsonld, opengraph,
 * title) or null when nothing useful was found.
 */
class Link_Endpoint {

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );
	}

	public function register_route(): void {
		register_rest_route( 'nop-indieweb/v1', '/fetch-context', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle' ],
			'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			'args'                => [
				'url' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
					'validate_callback' => fn( $v ) => is_string( $v ) && '' !== trim( $v ),
				],
			],
		] );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$url     = (string) $request->get_param( 'url' );
		$preview = ( new Link_Parser() )->fetch( $url );
		return new WP_REST_Response( $preview, 200 );
	}
}
