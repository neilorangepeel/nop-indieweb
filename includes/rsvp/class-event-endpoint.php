<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Rsvp;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Request;
use WP_REST_Response;

/**
 * REST endpoint + publish hooks for RSVP event posts.
 *
 *   POST /wp-json/nop-indieweb/v1/fetch-event   { "url": "https://…" }
 *
 * Returns the parsed event (Event_Parser cascade) so the RSVP sidebar panel can
 * pre-fill the name / start / end / location / note fields. The `source` flag
 * tells the UI which layer matched (mf2, jsonld, opengraph, title) or null when
 * nothing was found, so it can show the "fill in manually" fallback message.
 *
 * On publish of an RSVP post it also fires a non-blocking Internet Archive save
 * for the event URL, so the response always points at an archived copy. The
 * outbound webmention to the event URL is handled by Webmention_Sender, which
 * already pings nop_indieweb_in_reply_to on publish — no duplication here.
 */
class Event_Endpoint {

	/** Cron hook that performs the off-request Wayback Machine save. */
	private const ARCHIVE_EVENT = 'nop_indieweb_archive_event_url';

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );
		add_action( 'transition_post_status', [ $this, 'maybe_schedule_archive' ], 10, 3 );
		add_action( self::ARCHIVE_EVENT, [ $this, 'archive_url' ] );
	}

	public function register_route(): void {
		register_rest_route( 'nop-indieweb/v1', '/fetch-event', [
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
		$url   = (string) $request->get_param( 'url' );
		$event = ( new Event_Parser() )->fetch( $url );
		return new WP_REST_Response( $event, 200 );
	}

	/**
	 * Schedules the Internet Archive save when a post is published.
	 *
	 * Mirrors Webmention_Sender::maybe_schedule — only gates on status here and
	 * defers everything else to the cron handler, which reads the post meta once
	 * it has been committed (in the block-editor REST flow, meta and terms land
	 * after the status transition fires). The snapshot never blocks saving.
	 */
	public function maybe_schedule_archive( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' !== $new_status || 'post' !== $post->post_type ) {
			return;
		}
		if ( ! wp_next_scheduled( self::ARCHIVE_EVENT, [ $post->ID ] ) ) {
			wp_schedule_single_event( time(), self::ARCHIVE_EVENT, [ $post->ID ] );
		}
	}

	/**
	 * Cron handler: fires a non-blocking GET at the Wayback Machine save endpoint
	 * for a published RSVP post's event URL. We don't wait for or care about the
	 * response — it just nudges the Internet Archive to snapshot the event page.
	 *
	 * Idempotent: a per-post marker records the URL we last archived so re-saves
	 * of an already-published post don't re-ping for an unchanged event URL.
	 */
	public function archive_url( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}
		if ( 'rsvp' !== get_post_meta( $post_id, 'nop_indieweb_post_kind', true ) ) {
			return;
		}
		// Imported posts carry a source URL — don't archive on their behalf.
		if ( get_post_meta( $post_id, 'nop_indieweb_source_url', true ) ) {
			return;
		}

		$event_url = (string) get_post_meta( $post_id, 'nop_indieweb_in_reply_to', true );
		if ( '' === $event_url || ! \NOP\IndieWeb\nop_indieweb_is_safe_url( $event_url ) ) {
			return;
		}
		if ( $event_url === (string) get_post_meta( $post_id, 'nop_indieweb_event_archived', true ) ) {
			return;
		}

		wp_remote_get( 'https://web.archive.org/save/' . $event_url, [
			'blocking'    => false,
			'timeout'     => 0.01,
			'redirection' => 0,
			'user-agent'  => 'NOP IndieWeb/' . NOP_INDIEWEB_VERSION . ' (+' . home_url( '/' ) . ')',
		] );
		update_post_meta( $post_id, 'nop_indieweb_event_archived', $event_url );
		\NOP\IndieWeb\nop_indieweb_log( 'RSVP: requested Wayback save', [ 'url' => $event_url ] );
	}
}
