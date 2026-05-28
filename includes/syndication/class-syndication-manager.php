<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Syndication;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Syndication_Manager {

	/** @var Syndicator_Base[] */
	private array $syndicators;

	public function __construct() {
		$this->syndicators = apply_filters( 'nop_indieweb_register_syndicators', [
			new Syndicator_Mastodon(),
			new Syndicator_Bluesky(),
			new Syndicator_Pixelfed(),
		] );
	}

	public function register(): void {
		// Editor-created posts: fires after all meta is committed.
		add_action( 'wp_after_insert_post', [ $this, 'maybe_syndicate_editor_post' ], 10, 4 );

		// Micropub-created posts: fired explicitly by the endpoint after all meta is set.
		add_action( 'nop_indieweb_post_created', [ $this, 'syndicate' ], 10, 1 );
	}

	public function maybe_syndicate_editor_post( int $post_id, \WP_Post $post, bool $update, ?\WP_Post $post_before ): void {
		if ( 'post' !== $post->post_type ) {
			return;
		}

		// Only on first publish — not on updates to already-published posts.
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		if ( $post_before && 'publish' === $post_before->post_status ) {
			return;
		}

		// Skip posts created by micropub — nop_indieweb_post_created handles those.
		if ( get_post_meta( $post_id, 'nop_indieweb_service', true ) ) {
			return;
		}

		$this->syndicate( $post_id );
	}

	public function syndicate( int $post_id ): void {
		$targets = $this->resolve_targets( $post_id );

		foreach ( $this->syndicators as $syndicator ) {
			if ( in_array( $syndicator->slug(), $targets, true ) ) {
				$syndicator->syndicate( $post_id );
			}
		}
	}

	/**
	 * Returns the list of platform slugs to syndicate to.
	 * Uses the explicit editor selection if set, otherwise defaults to all enabled syndicators.
	 */
	private function resolve_targets( int $post_id ): array {
		$selected = get_post_meta( $post_id, 'nop_indieweb_syndicate_to', true );

		if ( is_array( $selected ) && $selected ) {
			return $selected;
		}

		// No explicit selection — default to all enabled and configured syndicators.
		return array_map(
			fn( $s ) => $s->slug(),
			array_filter( $this->syndicators, fn( $s ) => $s->enabled() )
		);
	}

	/**
	 * Resolves a syndication URL to the syndicator that owns it.
	 * Returns [ 'slug' => ..., 'label' => ... ] or null when unknown.
	 */
	public function resolve_url( string $url ): ?array {
		foreach ( $this->syndicators as $syndicator ) {
			if ( $syndicator->matches_url( $url ) ) {
				return [
					'slug'  => $syndicator->slug(),
					'label' => $syndicator->label(),
				];
			}
		}
		return null;
	}

	/** Returns the syndicator with the given slug, or null if unknown. */
	public function get( string $slug ): ?Syndicator_Base {
		foreach ( $this->syndicators as $syndicator ) {
			if ( $syndicator->slug() === $slug ) {
				return $syndicator;
			}
		}
		return null;
	}

	/** Returns syndicator definitions for the editor panel. */
	public function get_panel_data(): array {
		return array_values( array_map(
			fn( $s ) => [ 'slug' => $s->slug(), 'label' => $s->label() ],
			array_filter( $this->syndicators, fn( $s ) => $s->enabled() )
		) );
	}
}
