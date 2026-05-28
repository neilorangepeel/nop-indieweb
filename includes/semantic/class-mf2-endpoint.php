<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Semantic;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST endpoint that serves structured mf2 JSON for IndieWeb posts.
 *
 * Route: GET /wp-json/nop-indieweb/v1/mf2/{post_id}
 *
 * Generates mf2 JSON directly from post meta — no HTML parsing required.
 * This gives parsers like XRay and Bridgy reliable, complete data regardless
 * of what mf2 classes are present in the theme HTML.
 *
 * Advertised via <link rel="alternate" type="application/mf2+json"> in <head>
 * on IndieWeb post pages.
 */
class MF2_Endpoint {

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );
	}

	public function register_route(): void {
		register_rest_route(
			'nop-indieweb/v1',
			'/mf2/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'id' => [
						'required'          => true,
						'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	public function handle( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post || 'post' !== $post->post_type ) {
			return new \WP_Error( 'not_found', 'Post not found.', [ 'status' => 404 ] );
		}

		if ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'not_found', 'Post not found.', [ 'status' => 404 ] );
		}

		if ( ! get_post_meta( $post_id, 'nop_indieweb_service', true ) ) {
			return new \WP_Error( 'not_indieweb', 'Not an IndieWeb post.', [ 'status' => 404 ] );
		}

		$response = new \WP_REST_Response( $this->build_mf2( $post ) );
		$response->header(
			'Content-Type',
			'application/mf2+json; charset=' . get_option( 'blog_charset', 'UTF-8' )
		);
		return $response;
	}

	private function build_mf2( \WP_Post $post ): array {
		$post_id     = $post->ID;
		$venue_name  = get_post_meta( $post_id, 'nop_indieweb_venue_name', true );
		$syndication = get_post_meta( $post_id, 'nop_indieweb_syndication', true );
		$syndication = is_array( $syndication ) ? array_values( array_filter( $syndication ) ) : [];

		$content_html = apply_filters( 'the_content', $post->post_content );
		$content_text = wp_strip_all_tags( $content_html );

		$categories = wp_get_post_categories( $post_id, [ 'fields' => 'names' ] );
		$tags       = wp_get_post_tags( $post_id, [ 'fields' => 'names' ] );
		$all_terms  = array_values( array_merge(
			is_array( $categories ) ? $categories : [],
			is_array( $tags )       ? $tags       : []
		) );

		$props = [
			'name'      => [ $post->post_title ],
			'published' => [ get_the_date( 'c', $post ) ],
			'updated'   => [ get_the_modified_date( 'c', $post ) ],
			'url'       => [ get_permalink( $post ) ],
			'uid'       => [ get_permalink( $post ) ],
			'content'   => [ [ 'html' => $content_html, 'value' => $content_text ] ],
		];

		if ( $all_terms ) {
			$props['category'] = $all_terms;
		}

		if ( $syndication ) {
			$props['syndication'] = $syndication;
		}

		// Per-kind canonical URL properties — match the hidden anchors in
		// Semantic_Markup::output_kind_links so the JSON has parity with the HTML.
		foreach ( [
			'in-reply-to' => 'nop_indieweb_in_reply_to',
			'bookmark-of' => 'nop_indieweb_bookmark_of',
			'like-of'     => 'nop_indieweb_like_of',
			'repost-of'   => 'nop_indieweb_repost_of',
		] as $prop => $meta_key ) {
			$value = (string) get_post_meta( $post_id, $meta_key, true );
			if ( $value ) {
				$props[ $prop ] = [ $value ];
			}
		}

		$rsvp = (string) get_post_meta( $post_id, 'nop_indieweb_rsvp', true );
		if ( $rsvp ) {
			$props['rsvp'] = [ $rsvp ];
		}

		$photos = $this->collect_photo_urls( $post_id );
		if ( $photos ) {
			$props['photo'] = $photos;
		}

		$author = get_userdata( (int) $post->post_author );
		if ( $author ) {
			$props['author'] = [ [
				'type'       => [ 'h-card' ],
				'properties' => [
					'name' => [ $author->display_name ],
					'url'  => [ get_author_posts_url( $author->ID ) ],
				],
			] ];
		}

		if ( $venue_name ) {
			$props['checkin'] = [ $this->build_venue_hcard( $post_id, $venue_name ) ];
		}

		return [
			'type'       => [ 'h-entry' ],
			'properties' => $props,
		];
	}

	/**
	 * Resolves a post's photo URLs by preferring sideloaded attachment IDs
	 * (canonical local URLs), falling back to the stored source URLs, and
	 * finally to the featured image if neither photo meta is present.
	 *
	 * @return string[]  Zero or more photo URLs, in document order.
	 */
	private function collect_photo_urls( int $post_id ): array {
		$ids = get_post_meta( $post_id, 'nop_indieweb_photo_ids', true );
		if ( is_array( $ids ) && $ids ) {
			$urls = array_filter( array_map( 'wp_get_attachment_url', array_map( 'intval', $ids ) ) );
			if ( $urls ) {
				return array_values( $urls );
			}
		}

		$photos = get_post_meta( $post_id, 'nop_indieweb_photos', true );
		if ( is_array( $photos ) && $photos ) {
			$urls = array_values( array_filter( array_map( 'esc_url_raw', $photos ) ) );
			if ( $urls ) {
				return $urls;
			}
		}

		$featured = (int) get_post_thumbnail_id( $post_id );
		if ( $featured ) {
			$url = wp_get_attachment_url( $featured );
			if ( $url ) {
				return [ $url ];
			}
		}

		return [];
	}

	private function build_venue_hcard( int $post_id, string $venue_name ): array {
		$venue_url        = get_post_meta( $post_id, 'nop_indieweb_venue_url',        true );
		$venue_uid        = get_post_meta( $post_id, 'nop_indieweb_venue_uid',        true );
		$venue_address    = get_post_meta( $post_id, 'nop_indieweb_venue_address',    true );
		$venue_locality   = get_post_meta( $post_id, 'nop_indieweb_venue_locality',   true );
		$venue_region     = get_post_meta( $post_id, 'nop_indieweb_venue_region',     true );
		$venue_country    = get_post_meta( $post_id, 'nop_indieweb_venue_country',    true );
		$venue_postcode   = get_post_meta( $post_id, 'nop_indieweb_venue_postcode',   true );
		$venue_lat        = get_post_meta( $post_id, 'nop_indieweb_venue_lat',        true );
		$venue_lng        = get_post_meta( $post_id, 'nop_indieweb_venue_lng',        true );
		$_vc_terms        = get_the_terms( $post_id, 'nop_venue_category' );
		$venue_categories = ( $_vc_terms && ! is_wp_error( $_vc_terms ) ) ? wp_list_pluck( $_vc_terms, 'name' ) : [];

		$props = [ 'name' => [ $venue_name ] ];

		if ( $venue_url )      $props['url']            = [ $venue_url ];
		if ( $venue_uid )      $props['uid']            = [ $venue_uid ];
		if ( $venue_address )  $props['street-address'] = [ $venue_address ];
		if ( $venue_locality ) $props['locality']       = [ $venue_locality ];
		if ( $venue_region )   $props['region']         = [ $venue_region ];
		if ( $venue_country )  $props['country-name']   = [ $venue_country ];
		if ( $venue_postcode ) $props['postal-code']    = [ $venue_postcode ];
		if ( $venue_lat )      $props['latitude']       = [ $venue_lat ];
		if ( $venue_lng )      $props['longitude']      = [ $venue_lng ];

		if ( $venue_categories ) {
			$props['category'] = array_values( $venue_categories );
		}

		return [
			'type'       => [ 'h-card' ],
			'properties' => $props,
		];
	}
}
