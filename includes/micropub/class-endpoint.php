<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Micropub;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use NOP\IndieWeb\Services\Service_Base;
use NOP\IndieWeb\Micropub\Media_Endpoint;
use NOP\IndieWeb\Syndication\Syndication_Manager;

class Endpoint {

	private array $services;
	private ?Syndication_Manager $syndication_manager;

	public function __construct( array $services, ?Syndication_Manager $syndication_manager = null ) {
		$this->services             = $services;
		$this->syndication_manager  = $syndication_manager;
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
			$syndicate_to = [];
			if ( $this->syndication_manager ) {
				$syndicate_to = array_map(
					fn( $s ) => [ 'uid' => $s['slug'], 'name' => $s['label'] ],
					$this->syndication_manager->get_panel_data()
				);
			}

			return new WP_REST_Response( [
				'media-endpoint' => Media_Endpoint::url(),
				'syndicate-to'   => $syndicate_to,
				'post-types'     => [
					[ 'type' => 'note',     'name' => 'Note' ],
					[ 'type' => 'article',  'name' => 'Article' ],
					[ 'type' => 'bookmark', 'name' => 'Bookmark' ],
					[ 'type' => 'like',     'name' => 'Like' ],
					[ 'type' => 'repost',   'name' => 'Repost' ],
					[ 'type' => 'quote',    'name' => 'Quote' ],
					[ 'type' => 'reply',    'name' => 'Reply' ],
					[ 'type' => 'rsvp',     'name' => 'RSVP' ],
					[ 'type' => 'checkin',  'name' => 'Checkin' ],
					[ 'type' => 'listen',   'name' => 'Listen' ],
					[ 'type' => 'photo',    'name' => 'Photo' ],
				],
			], 200 );
		}

		if ( 'syndicate-to' === $q ) {
			$syndicate_to = [];
			if ( $this->syndication_manager ) {
				$syndicate_to = array_map(
					fn( $s ) => [ 'uid' => $s['slug'], 'name' => $s['label'] ],
					$this->syndication_manager->get_panel_data()
				);
			}
			return new WP_REST_Response( [ 'syndicate-to' => $syndicate_to ], 200 );
		}

		if ( 'source' === $q ) {
			$token = ( new Auth() )->verify( $request );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			return $this->handle_source_query( $request, $token );
		}

		return new WP_REST_Response( [], 200 );
	}

	/**
	 * Returns mf2 properties of an existing post by URL.
	 * Supports an optional properties[] param to return a subset.
	 * Spec: https://micropub.spec.indieweb.org/#source-content
	 *
	 * Non-public posts (draft/private/pending/future) require the token's
	 * owning user to have edit_post capability — otherwise we'd leak unpublished
	 * content to any holder of a valid Micropub token.
	 */
	private function handle_source_query( WP_REST_Request $request, array $token ): WP_REST_Response|WP_Error {
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

		if ( 'publish' !== $post->post_status && ! Auth::can_edit_post( $token, $post_id ) ) {
			return new WP_Error(
				'nop_indieweb_forbidden',
				'Token cannot read this post.',
				[ 'status' => 403 ]
			);
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
		$token = ( new Auth() )->verify( $request );
		if ( is_wp_error( $token ) ) {
			\NOP\IndieWeb\nop_indieweb_log( 'Auth failed', $token->get_error_message() );
			return $token;
		}

		$payload = ( new Request() )->normalise( $request );
		\NOP\IndieWeb\nop_indieweb_log( 'Micropub payload received', \NOP\IndieWeb\nop_indieweb_redact_for_log( $payload ) );

		$action = $payload['action'] ?? '';

		// Route non-create Micropub actions.
		// Note: OwnYourSwarm is create-only and never sends these. We handle them
		// gracefully so the endpoint is spec-compliant for future Micropub clients.
		if ( 'delete' === $action ) {
			return $this->handle_delete( $payload['url'] ?? '', $token );
		}

		if ( 'update' === $action ) {
			return $this->handle_update( $payload, $token );
		}

		// Default: create.
		$scope_err = Auth::require_scope( $token, 'create' );
		if ( $scope_err ) {
			return $scope_err;
		}

		$service = $this->resolve_service( $payload );
		if ( ! $service ) {
			return new WP_Error(
				'nop_indieweb_unhandled_payload',
				'No active service recognised this payload.',
				[ 'status' => 400 ]
			);
		}

		$post_id = $service->handle( $payload, (int) ( $token['user_id'] ?? 0 ) );
		if ( is_wp_error( $post_id ) ) {
			\NOP\IndieWeb\nop_indieweb_log( 'Service handler error', $post_id->get_error_message() );
			return $post_id;
		}

		// Honour an explicit syndication-target selection from the client before
		// the syndication hook fires. mp-syndicate-to is the spec property;
		// syndicate-to is what the bundled /post client sends. Values are the
		// syndicator slugs advertised as uids by the ?q=config response.
		//
		// Property present but empty = "this site only" — stored as the sentinel
		// 'none' (matches no syndicator slug). Property absent = no preference,
		// which keeps the default of syndicating to every enabled platform.
		$props = $payload['properties'] ?? [];
		if ( array_key_exists( 'mp-syndicate-to', $props ) || array_key_exists( 'syndicate-to', $props ) ) {
			$targets = $props['mp-syndicate-to'] ?? $props['syndicate-to'];
			$targets = is_array( $targets ) ? array_map( 'sanitize_key', array_filter( $targets ) ) : [];
			update_post_meta( $post_id, 'nop_indieweb_syndicate_to', $targets ?: [ 'none' ] );
		}

		// An opt-in geotag from the composer — a geo: URI plus the reverse-geocoded
		// place. Stored on the shared venue meta so the same block bindings / mf2
		// output a checkin uses render it. Kind-agnostic: a geotagged note stays a
		// note, it just carries a place.
		if ( array_key_exists( 'location', $props ) ) {
			$this->store_location( $post_id, $props );
		}

		// Phase 2 hook: POSSE syndication to Mastodon, Bluesky, etc. hangs off this.
		do_action( 'nop_indieweb_post_created', $post_id, $payload, $service );

		// Micropub spec: 201 Created with the new post URL in Location header.
		$response = new WP_REST_Response( null, 201 );
		$response->header( 'Location', get_permalink( $post_id ) );
		$response->header( 'X-Edit-URL', admin_url( 'post.php?post=' . $post_id . '&action=edit' ) );
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
	private function handle_delete( string $url, array $token ): WP_REST_Response|WP_Error {
		$scope_err = Auth::require_scope( $token, 'delete' );
		if ( $scope_err ) {
			return $scope_err;
		}

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
				[ 'status' => 404 ]
			);
		}

		if ( ! Auth::can_delete_post( $token, $post_id ) ) {
			return new WP_Error(
				'nop_indieweb_forbidden',
				'Token cannot delete this post.',
				[ 'status' => 403 ]
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
	private function handle_update( array $payload, array $token ): WP_REST_Response|WP_Error {
		$scope_err = Auth::require_scope( $token, 'update' );
		if ( $scope_err ) {
			return $scope_err;
		}

		$url = $payload['url'] ?? '';
		if ( ! $url ) {
			return new WP_Error( 'nop_indieweb_missing_url', 'No URL provided for update action.', [ 'status' => 400 ] );
		}

		$post_id = url_to_postid( $url );
		if ( ! $post_id ) {
			return new WP_Error( 'nop_indieweb_not_found', 'No post found at that URL.', [ 'status' => 404 ] );
		}

		if ( ! Auth::can_edit_post( $token, $post_id ) ) {
			return new WP_Error(
				'nop_indieweb_forbidden',
				'Token cannot edit this post.',
				[ 'status' => 403 ]
			);
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
			// Spec: string keys = delete specific values; integer keys = delete whole property.
			if ( is_string( array_key_first( $delete ) ) ) {
				foreach ( $delete as $prop => $values ) {
					$this->apply_delete_values( $post_id, (string) $prop, (array) $values );
				}
			} else {
				foreach ( $delete as $prop ) {
					$this->apply_delete_prop( $post_id, (string) $prop, $args );
				}
			}
		}

		if ( $args ) {
			$args['ID'] = $post_id;
			$result     = wp_update_post( wp_slash( $args ), true );
			if ( is_wp_error( $result ) ) {
				return new WP_Error(
					'nop_indieweb_update_failed',
					'Failed to update post: ' . $result->get_error_message(),
					[ 'status' => 500 ]
				);
			}
		}

		\NOP\IndieWeb\nop_indieweb_log( "Micropub update: post {$post_id}", \NOP\IndieWeb\nop_indieweb_redact_for_log( $payload ) );
		$response = new WP_REST_Response( null, 200 );
		$response->header( 'Location', get_permalink( $post_id ) );
		return $response;
	}

	private function apply_replace( int $post_id, string $prop, array $values, array &$args ): void {
		match( $prop ) {
			'content'     => $args['post_content'] = $this->plain_text_to_block( (string) ( $values[0] ?? '' ), $post_id ),
			'name'        => $args['post_title']   = sanitize_text_field( (string) ( $values[0] ?? '' ) ),
			'post-status' => $args['post_status']  = sanitize_key( $values[0] ?? '' ),
			'published'   => $this->apply_date( $values[0] ?? '', $args ),
			'syndication' => update_post_meta( $post_id, 'nop_indieweb_syndication', array_map( 'esc_url_raw', $values ) ),
			default       => null,
		};
	}

	private function apply_add( int $post_id, string $prop, array $values ): void {
		match( $prop ) {
			'syndication' => $this->merge_meta( $post_id, 'nop_indieweb_syndication', array_map( 'esc_url_raw', $values ) ),
			'photo'       => $this->apply_add_photos( $post_id, $values ),
			default       => null,
		};
	}

	private function plain_text_to_block( string $content, int $post_id = 0 ): string {
		$content = trim( $content );
		if ( $content === '' || str_starts_with( $content, '<!--' ) ) {
			return $content;
		}
		// Plain-text content goes into post_content — escape it. (The block-markup
		// path above returns early so wp_kses_post never strips block delimiters.)
		$content       = wp_kses_post( $content );
		$new_paragraph = "<!-- wp:paragraph -->\n<p>{$content}</p>\n<!-- /wp:paragraph -->";

		// When the post already has content (e.g. an image block appended during
		// ingest), only swap out the paragraph block so image blocks are preserved.
		if ( $post_id ) {
			$existing = trim( (string) get_post_field( 'post_content', $post_id ) );
			if ( $existing ) {
				$count   = 0;
				$updated = preg_replace(
					'/<!-- wp:paragraph -->.*?<!-- \/wp:paragraph -->/s',
					$new_paragraph,
					$existing,
					1,
					$count
				);
				if ( null !== $updated && $count > 0 ) {
					return $updated;
				}
				return $new_paragraph . "\n\n" . $existing;
			}
		}

		return $new_paragraph;
	}

	private function apply_add_photos( int $post_id, array $urls ): void {
		$urls = array_values( array_filter( array_map( 'esc_url_raw', $urls ) ) );
		if ( ! $urls ) {
			return;
		}
		$slug = (string) get_post_meta( $post_id, 'nop_indieweb_service', true );
		foreach ( $this->services as $service ) {
			if ( $service instanceof Service_Base && $service->get_slug() === $slug ) {
				$service->append_photos( $post_id, $urls );
				return;
			}
		}
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

	/**
	 * Stores an opt-in geotag onto the shared venue meta.
	 *
	 * Accepts a `geo:LAT,LON` URI (the Micropub-standard simple location value,
	 * optionally with a `;u=ACCURACY` suffix we ignore) for the coordinates, plus
	 * two flat companion properties the bundled composer sends for the human place:
	 * `location-locality` and `location-country`. Anything missing is simply not
	 * written — a coordinates-only or locality-only geotag is valid.
	 *
	 * @param array<string,mixed> $props
	 */
	private function store_location( int $post_id, array $props ): void {
		// Only the simple geo: URI string form is handled here — a checkin's h-adr
		// object location (OwnYourSwarm) is left untouched (its service owns it).
		$loc = $props['location'] ?? '';
		if ( is_array( $loc ) ) {
			$loc = is_string( $loc[0] ?? null ) ? $loc[0] : '';
		}
		$geo = is_string( $loc ) ? trim( $loc ) : '';
		if ( preg_match( '/^geo:(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/i', $geo, $m ) ) {
			update_post_meta( $post_id, 'nop_indieweb_venue_lat', $m[1] );
			update_post_meta( $post_id, 'nop_indieweb_venue_lng', $m[2] );
		}

		$locality = sanitize_text_field( (string) ( $props['location-locality'][0] ?? '' ) );
		$country  = sanitize_text_field( (string) ( $props['location-country'][0] ?? '' ) );
		if ( '' !== $locality ) {
			update_post_meta( $post_id, 'nop_indieweb_venue_locality', $locality );
		}
		if ( '' !== $country ) {
			update_post_meta( $post_id, 'nop_indieweb_venue_country', $country );
		}
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
