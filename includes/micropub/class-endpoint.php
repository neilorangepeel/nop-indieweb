<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Micropub;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use NOP\IndieWeb\Services\Service_Base;

class Endpoint {

	private array $services;

	public function __construct( array $services ) {
		$this->services = $services;
	}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( 'nop-indieweb/v1', '/micropub', [
			[
				// GET handles ?q=config — lets clients discover endpoint capabilities.
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_query' ],
				'permission_callback' => '__return_true',
			],
			[
				// POST receives Micropub content. Auth is handled inside the callback.
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_post' ],
				'permission_callback' => '__return_true',
			],
		] );
	}

	public function handle_query( WP_REST_Request $request ): WP_REST_Response {
		if ( 'config' === $request->get_param( 'q' ) ) {
			return new WP_REST_Response( [
				'media-endpoint' => null,
				'syndicate-to'   => [],
				'post-types'     => [ [ 'type' => 'entry', 'name' => 'Post' ] ],
			], 200 );
		}

		return new WP_REST_Response( [], 200 );
	}

	public function handle_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$auth_result = ( new Auth() )->verify( $request );
		if ( is_wp_error( $auth_result ) ) {
			\NOP\IndieWeb\nop_indieweb_log( 'Auth failed', $auth_result->get_error_message() );
			return $auth_result;
		}

		$payload = ( new Request() )->normalise( $request );
		\NOP\IndieWeb\nop_indieweb_log( 'Micropub payload received', $payload );

		$action = $payload['action'] ?? '';

		// Route non-create Micropub actions.
		// Note: OwnYourSwarm is create-only and never sends these. We handle them
		// gracefully so the endpoint is spec-compliant for future Micropub clients.
		if ( 'delete' === $action ) {
			return $this->handle_delete( $payload['url'] ?? '' );
		}

		if ( 'update' === $action ) {
			// Full update support is Phase 2. Acknowledge per spec (200 OK).
			\NOP\IndieWeb\nop_indieweb_log( 'Micropub update action received — not yet implemented', $payload['url'] ?? '' );
			return new WP_REST_Response( null, 200 );
		}

		// Default: create.
		$service = $this->resolve_service( $payload );
		if ( ! $service ) {
			return new WP_Error(
				'nop_indieweb_unhandled_payload',
				'No active service recognised this payload.',
				[ 'status' => 400 ]
			);
		}

		$post_id = $service->handle( $payload );
		if ( is_wp_error( $post_id ) ) {
			\NOP\IndieWeb\nop_indieweb_log( 'Service handler error', $post_id->get_error_message() );
			return $post_id;
		}

		// Phase 2 hook: POSSE syndication to Mastodon, Bluesky, etc. hangs off this.
		do_action( 'nop_indieweb_post_created', $post_id, $payload, $service );

		// Micropub spec: 201 Created with the new post URL in Location header.
		$response = new WP_REST_Response( null, 201 );
		$response->header( 'Location', get_permalink( $post_id ) );
		return $response;
	}

	/**
	 * Handles a Micropub delete action.
	 *
	 * Moves the post to trash (recoverable) rather than permanently deleting it.
	 * The Micropub spec says the URL should return 410 Gone after deletion —
	 * WordPress handles that automatically for trashed posts if you configure
	 * your theme to return 410 for them.
	 */
	private function handle_delete( string $url ): WP_REST_Response|WP_Error {
		if ( ! $url ) {
			return new WP_Error(
				'nop_indieweb_missing_url',
				'No URL provided for delete action.',
				[ 'status' => 400 ]
			);
		}

		$post_id = url_to_postid( $url );
		if ( ! $post_id ) {
			return new WP_Error(
				'nop_indieweb_not_found',
				'No post found at that URL.',
				[ 'status' => 400 ]
			);
		}

		$result = wp_trash_post( $post_id );
		if ( ! $result ) {
			return new WP_Error(
				'nop_indieweb_delete_failed',
				'Failed to trash post.',
				[ 'status' => 500 ]
			);
		}

		\NOP\IndieWeb\nop_indieweb_log( "Micropub delete: trashed post {$post_id}", $url );
		return new WP_REST_Response( null, 200 );
	}

	private function resolve_service( array $payload ): ?Service_Base {
		foreach ( $this->services as $service ) {
			if ( $service instanceof Service_Base && $service->is_enabled() && $service->can_handle( $payload ) ) {
				return $service;
			}
		}
		return null;
	}
}
