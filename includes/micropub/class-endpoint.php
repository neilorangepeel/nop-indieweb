<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Micropub;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use NOP\IndieWeb\Services\Service_Base;
use NOP\IndieWeb\Micropub\Media_Endpoint;

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

	public function handle_query( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$q = $request->get_param( 'q' );

		if ( 'config' === $q ) {
			return new WP_REST_Response( [
				'media-endpoint' => Media_Endpoint::url(),
				'syndicate-to'   => [],
				'post-types'     => [ [ 'type' => 'entry', 'name' => 'Post' ] ],
			], 200 );
		}

		if ( 'source' === $q ) {
			$auth_result = ( new Auth() )->verify( $request );
			if ( is_wp_error( $auth_result ) ) {
				return $auth_result;
			}
			return $this->handle_source_query( $request );
		}

		return new WP_REST_Response( [], 200 );
	}

	/**
	 * Returns mf2 properties of an existing post by URL.
	 * Supports an optional properties[] param to return a subset.
	 * Spec: https://micropub.spec.indieweb.org/#source-content
	 */
	private function handle_source_query( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$url = (string) $request->get_param( 'url' );
		if ( ! $url ) {
			return new WP_Error( 'nop_indieweb_missing_url', 'url parameter is required.', [ 'status' => 400 ] );
		}

		$post_id = url_to_postid( $url );
		if ( ! $post_id ) {
			return new WP_Error( 'nop_indieweb_not_found', 'No post found at that URL.', [ 'status' => 404 ] );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'nop_indieweb_not_found', 'Post not found.', [ 'status' => 404 ] );
		}

		$properties = [
			'content'   => [ $post->post_content ],
			'published' => [ get_the_date( 'c', $post ) ],
			'url'       => [ get_permalink( $post ) ],
		];

		if ( $post->post_title ) {
			$properties['name'] = [ $post->post_title ];
		}

		$syndication = get_post_meta( $post_id, 'nop_indieweb_syndication', true );
		if ( is_array( $syndication ) && $syndication ) {
			$properties['syndication'] = $syndication;
		}

		$requested = $request->get_param( 'properties' );
		if ( is_array( $requested ) && $requested ) {
			$properties = array_intersect_key( $properties, array_flip( $requested ) );
		}

		return new WP_REST_Response( [ 'type' => [ 'h-entry' ], 'properties' => $properties ], 200 );
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
			return $this->handle_update( $payload );
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

	/**
	 * Handles a Micropub update action.
	 * Spec: https://micropub.spec.indieweb.org/#update
	 * Update requests are always JSON — form-encoded is not supported by the spec.
	 */
	private function handle_update( array $payload ): WP_REST_Response|WP_Error {
		$url = $payload['url'] ?? '';
		if ( ! $url ) {
			return new WP_Error( 'nop_indieweb_missing_url', 'No URL provided for update action.', [ 'status' => 400 ] );
		}

		$post_id = url_to_postid( $url );
		if ( ! $post_id ) {
			return new WP_Error( 'nop_indieweb_not_found', 'No post found at that URL.', [ 'status' => 400 ] );
		}

		$args = [];

		foreach ( (array) ( $payload['replace'] ?? [] ) as $prop => $values ) {
			$this->apply_replace( $post_id, $prop, (array) $values, $args );
		}

		foreach ( (array) ( $payload['add'] ?? [] ) as $prop => $values ) {
			$this->apply_add( $post_id, $prop, (array) $values );
		}

		$delete = $payload['delete'] ?? [];
		if ( is_array( $delete ) && $delete ) {
			if ( is_string( array_key_first( $delete ) === 0 ? $delete[0] : '' ) && isset( $delete[0] ) ) {
				// Indexed array of property names — delete the whole property.
				foreach ( $delete as $prop ) {
					$this->apply_delete_prop( $post_id, (string) $prop, $args );
				}
			} else {
				// Associative — delete specific values from a property.
				foreach ( $delete as $prop => $values ) {
					$this->apply_delete_values( $post_id, (string) $prop, (array) $values );
				}
			}
		}

		if ( $args ) {
			$args['ID'] = $post_id;
			wp_update_post( wp_slash( $args ) );
		}

		\NOP\IndieWeb\nop_indieweb_log( "Micropub update: post {$post_id}", $payload );
		return new WP_REST_Response( null, 200 );
	}

	private function apply_replace( int $post_id, string $prop, array $values, array &$args ): void {
		match( $prop ) {
			'content'     => $args['post_content'] = $values[0] ?? '',
			'name'        => $args['post_title']   = $values[0] ?? '',
			'post-status' => $args['post_status']  = sanitize_key( $values[0] ?? '' ),
			'published'   => $this->apply_date( $values[0] ?? '', $args ),
			'syndication' => update_post_meta( $post_id, 'nop_indieweb_syndication', array_map( 'esc_url_raw', $values ) ),
			default       => null,
		};
	}

	private function apply_add( int $post_id, string $prop, array $values ): void {
		match( $prop ) {
			'syndication' => $this->merge_meta( $post_id, 'nop_indieweb_syndication', array_map( 'esc_url_raw', $values ) ),
			default       => null,
		};
	}

	private function apply_delete_prop( int $post_id, string $prop, array &$args ): void {
		match( $prop ) {
			'name'        => $args['post_title'] = '',
			'syndication' => delete_post_meta( $post_id, 'nop_indieweb_syndication' ),
			default       => null,
		};
	}

	private function apply_delete_values( int $post_id, string $prop, array $values ): void {
		if ( 'syndication' === $prop ) {
			$existing = (array) get_post_meta( $post_id, 'nop_indieweb_syndication', true );
			update_post_meta( $post_id, 'nop_indieweb_syndication', array_values( array_diff( $existing, $values ) ) );
		}
	}

	private function apply_date( string $published, array &$args ): void {
		$ts = $published ? strtotime( $published ) : 0;
		if ( ! $ts ) {
			return;
		}
		$args['post_date']     = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ) );
		$args['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $ts );
	}

	private function merge_meta( int $post_id, string $key, array $values ): void {
		$existing = (array) get_post_meta( $post_id, $key, true );
		update_post_meta( $post_id, $key, array_values( array_unique( array_merge( $existing, $values ) ) ) );
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
