<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Webmention;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures a cite of the target for editor-created URL-response posts
 * (like/bookmark/repost/reply/rsvp), so they get the same context as posts
 * made through the Micropub /post client.
 *
 * The two creation paths are intentionally separate — Micropub builds the post
 * before insert; the editor saves through core REST after insert — but they
 * share one engine (Cite_Extractor) and one mapper (self::meta_from_cite), so
 * there is no logic to keep in sync. This mirrors how Syndication_Manager funnels
 * both `nop_indieweb_post_created` (Micropub) and `wp_after_insert_post` (editor)
 * into a single syndicate() method.
 *
 * The fetch runs async (a scheduled single event) so it never blocks the editor
 * save, and is idempotent: a `_nop_indieweb_cite_source` marker records the URL
 * the cite was built from, so re-saves are skipped and a changed URL re-fetches.
 * The Micropub path writes the same marker inline, so a successful Micropub post
 * is skipped here; a failed Micropub fetch is naturally retried on this hook.
 */
class Cite_Enricher {

	private const SOURCE_META = '_nop_indieweb_cite_source';
	private const EVENT       = 'nop_indieweb_enrich_cite';

	/** Canonical-URL meta keys for the response kinds, in resolution order. */
	private const URL_META = [
		'nop_indieweb_like_of',
		'nop_indieweb_bookmark_of',
		'nop_indieweb_repost_of',
		'nop_indieweb_in_reply_to',
	];

	public function register(): void {
		// Editor-created posts: fires after meta is committed.
		add_action( 'wp_after_insert_post', [ $this, 'maybe_enrich_editor_post' ], 10, 4 );
		// Async worker that performs the network fetch off the save request.
		add_action( self::EVENT, [ $this, 'run' ], 10, 1 );
	}

	public function maybe_enrich_editor_post( int $post_id, \WP_Post $post, bool $update, ?\WP_Post $post_before ): void {
		if ( 'post' !== $post->post_type ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$url = $this->target_url( $post_id );
		if ( '' === $url ) {
			return;
		}

		// Idempotent: skip when the cite was already captured from this URL
		// (covers re-saves and successful Micropub posts). A changed URL or a
		// missing cite (e.g. a failed Micropub fetch) falls through to re-run.
		$have   = (string) get_post_meta( $post_id, 'nop_indieweb_cite_title', true );
		$source = (string) get_post_meta( $post_id, self::SOURCE_META, true );
		if ( '' !== $have && $source === $url ) {
			return;
		}

		if ( ! wp_next_scheduled( self::EVENT, [ $post_id ] ) ) {
			wp_schedule_single_event( time(), self::EVENT, [ $post_id ] );
		}
	}

	/** Async worker: fetch the target and write the cite meta. */
	public function run( int $post_id ): void {
		$url = $this->target_url( $post_id );
		if ( '' === $url ) {
			return;
		}

		$cite = ( new Cite_Extractor() )->extract_from_url( $url );
		if ( empty( $cite ) ) {
			return;
		}

		foreach ( self::meta_from_cite( $cite ) as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		update_post_meta( $post_id, self::SOURCE_META, $url );

		// Only fill the title when the editor hasn't set one — never clobber
		// a title the user typed. (The Micropub path, which has only a domain,
		// always overrides.)
		if ( ! empty( $cite['title'] ) && $this->title_is_fillable( $post_id ) ) {
			wp_update_post( [
				'ID'         => $post_id,
				'post_title' => $cite['title'],
			] );
		}
	}

	/**
	 * Maps a Cite_Extractor result to the cite_* post meta (non-empty values
	 * only). Shared by this editor path and the Micropub before-insert path.
	 */
	public static function meta_from_cite( array $cite ): array {
		$map = [
			'nop_indieweb_cite_title'        => $cite['title']        ?? '',
			'nop_indieweb_cite_author_name'  => $cite['author_name']  ?? '',
			'nop_indieweb_cite_author_url'   => $cite['author_url']   ?? '',
			'nop_indieweb_cite_author_photo' => $cite['author_photo'] ?? '',
			'nop_indieweb_cite_excerpt'      => $cite['excerpt']      ?? '',
			'nop_indieweb_cite_image'        => $cite['image']        ?? '',
			'nop_indieweb_cite_site_name'    => $cite['site_name']    ?? '',
		];
		return array_filter( $map, static fn( $v ) => '' !== $v );
	}

	/** The response target URL for a post, read straight off the kind meta. */
	private function target_url( int $post_id ): string {
		foreach ( self::URL_META as $key ) {
			$url = (string) get_post_meta( $post_id, $key, true );
			if ( '' !== $url ) {
				return $url;
			}
		}
		return '';
	}

	private function title_is_fillable( int $post_id ): bool {
		$title = trim( wp_strip_all_tags( (string) get_the_title( $post_id ) ) );
		return '' === $title || 'auto draft' === strtolower( $title );
	}
}
