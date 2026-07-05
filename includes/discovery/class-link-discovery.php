<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Discovery;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use NOP\IndieWeb\IndieAuth\Auth_Endpoint;
use NOP\IndieWeb\WebSub;

/**
 * Emits IndieWeb discovery metadata: <head> link tags, Link: headers, the
 * indieauth-metadata REST route, and rel="me" identity links (on visible
 * social-link anchors plus hidden fallbacks).
 */
class Link_Discovery {

	/** Normalised identity URLs already emitted as a visible rel="me" social link this request. */
	private array $tagged_me_urls = [];

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_indieauth_metadata_route' ] );
		add_action( 'wp_head', [ $this, 'output_link_tags' ] );
		add_filter( 'render_block_core/social-link', [ $this, 'add_social_link_rel_me' ], 10, 2 );
		add_action( 'wp_footer', [ $this, 'output_me_anchor_fallback' ], 99 );
		add_action( 'send_headers', [ $this, 'output_link_headers' ] );
	}

	public function register_indieauth_metadata_route(): void {
		register_rest_route( 'nop-indieweb/v1', '/indieauth-metadata', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'indieauth_metadata_response' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function indieauth_metadata_response(): \WP_REST_Response {
		return new \WP_REST_Response( [
			'issuer'                                => home_url( '/' ),
			'authorization_endpoint'                => Auth_Endpoint::url(),
			'token_endpoint'                        => rest_url( 'nop-indieweb/v1/token' ),
			'code_challenge_methods_supported'      => [ 'S256' ],
			'scopes_supported'                      => [ 'create', 'update', 'delete', 'undelete', 'media', 'draft' ],
			'response_types_supported'              => [ 'code' ],
			'grant_types_supported'                 => [ 'authorization_code' ],
		], 200 );
	}

	public function output_link_tags(): void {
		printf( "<link rel=\"micropub\" href=\"%s\">\n",               esc_url( rest_url( 'nop-indieweb/v1/micropub' ) ) );
		printf( "<link rel=\"webmention\" href=\"%s\">\n",             esc_url( rest_url( 'nop-indieweb/v1/webmention' ) ) );
		printf( "<link rel=\"authorization_endpoint\" href=\"%s\">\n", esc_url( Auth_Endpoint::url() ) );
		printf( "<link rel=\"token_endpoint\" href=\"%s\">\n",         esc_url( rest_url( 'nop-indieweb/v1/token' ) ) );
		printf( "<link rel=\"indieauth-metadata\" href=\"%s\">\n",     esc_url( rest_url( 'nop-indieweb/v1/indieauth-metadata' ) ) );

		$hub = ( new WebSub() )->hub_url();
		if ( $hub ) {
			printf( "<link rel=\"hub\" href=\"%s\">\n", esc_url( $hub ) );
		}

		foreach ( $this->get_me_urls() as $url ) {
			printf( "<link rel=\"me\" href=\"%s\">\n", esc_url( $url ) );
		}
	}

	/**
	 * Adds rel="me" to a core/social-link anchor when its URL is one of the
	 * site's identity URLs. This turns the visible footer social icons into the
	 * rel="me" verification links consumers expect (Mastodon, IndieAuth, XRay,
	 * Bridgy) — the anchor form, in the body, that the <link> tags in
	 * output_link_tags() don't reliably satisfy on their own.
	 */
	public function add_social_link_rel_me( string $content, array $block ): string {
		$url = (string) ( $block['attrs']['url'] ?? '' );
		if ( '' === $url || ! $this->is_me_url( $url ) ) {
			return $content;
		}
		$this->tagged_me_urls[] = $this->normalise_me_url( $url );
		return preg_replace(
			'/<a (?=[^>]*\bwp-block-social-link-anchor\b)(?![^>]*\srel=)/',
			'<a rel="me" ',
			$content,
			1
		);
	}

	/**
	 * Emits a hidden <a rel="me"> anchor for each identity URL that did NOT
	 * already render as a visible social-link anchor this request. Identities
	 * surfaced as footer icons (e.g. Mastodon) carry rel="me" on the visible
	 * link; the rest (e.g. Pixelfed, GitHub) need the anchor form somewhere in
	 * the body for verifiers that don't read the <head> <link> tags. Runs late
	 * on wp_footer so add_social_link_rel_me() has populated $tagged_me_urls.
	 */
	public function output_me_anchor_fallback(): void {
		foreach ( $this->get_me_urls() as $url ) {
			if ( in_array( $this->normalise_me_url( $url ), $this->tagged_me_urls, true ) ) {
				continue;
			}
			printf( "<a rel=\"me\" href=\"%s\" hidden></a>\n", esc_url( $url ) );
		}
	}

	/**
	 * True when $url points at the same identity as one of get_me_urls().
	 */
	private function is_me_url( string $url ): bool {
		$target = $this->normalise_me_url( $url );
		foreach ( $this->get_me_urls() as $me ) {
			if ( $this->normalise_me_url( $me ) === $target ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Scheme- and trailing-slash-agnostic key for comparing identity URLs, so a
	 * social-link stored as http://… or with a trailing slash still matches an
	 * https://… me URL.
	 */
	private function normalise_me_url( string $url ): string {
		return rtrim( (string) preg_replace( '#^https?://#i', '', trim( $url ) ), '/' );
	}

	public function output_link_headers(): void {
		// Discovery headers are only useful on page responses — skip REST/AJAX/admin
		// where they add bytes nobody reads.
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		header( sprintf( 'Link: <%s>; rel="micropub"',               rest_url( 'nop-indieweb/v1/micropub' ) ), false );
		header( sprintf( 'Link: <%s>; rel="webmention"',             rest_url( 'nop-indieweb/v1/webmention' ) ), false );
		header( sprintf( 'Link: <%s>; rel="authorization_endpoint"', Auth_Endpoint::url() ),                  false );
		header( sprintf( 'Link: <%s>; rel="token_endpoint"',         rest_url( 'nop-indieweb/v1/token' ) ),  false );
		header( sprintf( 'Link: <%s>; rel="indieauth-metadata"',     rest_url( 'nop-indieweb/v1/indieauth-metadata' ) ), false );

		$hub = ( new WebSub() )->hub_url();
		if ( $hub ) {
			header( sprintf( 'Link: <%s>; rel="hub"', $hub ), false );
		}

		foreach ( $this->get_me_urls() as $url ) {
			header( sprintf( 'Link: <%s>; rel="me"', $url ), false );
		}
	}

	private function get_me_urls(): array {
		return \NOP\IndieWeb\nop_indieweb_me_urls();
	}
}
