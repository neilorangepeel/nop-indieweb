<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Syndication;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Syndication_Manager {

	private const CRON_HOOK = 'nop_indieweb_syndicate_target';

	private const MAX_ATTEMPTS = 4;

	/** Seconds to wait before each retry, keyed by the attempt number being scheduled. */
	private const RETRY_DELAYS = [
		2 => 5 * MINUTE_IN_SECONDS,
		3 => 30 * MINUTE_IN_SECONDS,
		4 => 2 * HOUR_IN_SECONDS,
	];

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

		// Per-platform async worker — one cron event per (post, platform, attempt).
		add_action( self::CRON_HOOK, [ $this, 'run_target' ], 10, 3 );

		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
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

	/**
	 * Queues async syndication to every resolved target. Each platform gets its
	 * own cron event so one platform's failure or retry never blocks another —
	 * and the publish request never waits on remote APIs.
	 */
	public function syndicate( int $post_id ): void {
		$targets = $this->resolve_targets( $post_id );

		foreach ( $this->syndicators as $syndicator ) {
			if ( in_array( $syndicator->slug(), $targets, true ) ) {
				$this->queue( $post_id, $syndicator->slug(), 1 );
			}
		}
	}

	/** Cron callback: runs one platform's syndication attempt and handles retry/failure. */
	public function run_target( int $post_id, string $slug, int $attempt ): void {
		$syndicator = $this->get( $slug );
		if ( ! $syndicator ) {
			$this->update_status( $post_id, $slug, null );
			return;
		}

		$result = $syndicator->syndicate( $post_id );

		if ( is_wp_error( $result ) ) {
			if ( $attempt < self::MAX_ATTEMPTS ) {
				$next = $attempt + 1;
				$this->queue( $post_id, $slug, $next, self::RETRY_DELAYS[ $next ], $result->get_error_message() );
			} else {
				$this->update_status( $post_id, $slug, [
					'state'    => 'failed',
					'error'    => $result->get_error_message(),
					'attempts' => $attempt,
					'updated'  => time(),
				] );
			}
			return;
		}

		// Empty string = platform doesn't apply to this post — leave no trace.
		$this->update_status( $post_id, $slug, '' === $result ? null : [
			'state'    => 'sent',
			'url'      => $result,
			'attempts' => $attempt,
			'updated'  => time(),
		] );
	}

	private function queue( int $post_id, string $slug, int $attempt, int $delay = 0, string $last_error = '' ): void {
		$this->update_status( $post_id, $slug, [
			'state'    => 'pending',
			'error'    => $last_error,
			'attempts' => $attempt - 1,
			'updated'  => time(),
		] );
		wp_schedule_single_event( time() + $delay, self::CRON_HOOK, [ $post_id, $slug, $attempt ] );
	}

	/** Writes one platform's entry in the status journal meta; null removes it. */
	private function update_status( int $post_id, string $slug, ?array $entry ): void {
		$status = get_post_meta( $post_id, 'nop_indieweb_syndication_status', true );
		$status = is_array( $status ) ? $status : [];

		if ( null === $entry ) {
			unset( $status[ $slug ] );
		} else {
			$status[ $slug ] = $entry;
		}

		if ( $status ) {
			update_post_meta( $post_id, 'nop_indieweb_syndication_status', $status );
		} else {
			delete_post_meta( $post_id, 'nop_indieweb_syndication_status' );
		}
	}

	public function register_rest_routes(): void {
		register_rest_route( 'nop-indieweb/v1', '/syndication/retry', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_retry' ],
			'permission_callback' => fn( \WP_REST_Request $request ) => current_user_can( 'edit_post', (int) $request['post_id'] ),
			'args'                => [
				'post_id' => [ 'type' => 'integer', 'required' => true ],
				'target'  => [ 'type' => 'string', 'required' => true ],
			],
		] );
	}

	public function handle_retry( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = (int) $request['post_id'];
		$slug    = sanitize_key( (string) $request['target'] );

		if ( ! $this->get( $slug ) ) {
			return new \WP_Error( 'nop_unknown_target', __( 'Unknown syndication target.', 'nop-indieweb' ), [ 'status' => 404 ] );
		}

		if ( 'publish' !== get_post_status( $post_id ) ) {
			return new \WP_Error( 'nop_not_published', __( 'Only published posts can be syndicated.', 'nop-indieweb' ), [ 'status' => 400 ] );
		}

		$this->queue( $post_id, $slug, 1 );

		return new \WP_REST_Response( [ 'queued' => true ], 202 );
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
