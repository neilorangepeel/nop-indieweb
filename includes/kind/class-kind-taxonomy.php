<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Kind;

/**
 * Registers the nop_kind taxonomy and keeps nop_indieweb_post_kind meta in sync.
 *
 * Architecture:
 *   - nop_kind term  = canonical write target (queries, archives, editor, services).
 *   - nop_indieweb_post_kind meta = derived read-cache, written only by the mirror
 *     hook below. Never write this meta directly — the mirror hook is the single
 *     write path from taxonomy → meta.
 */
class Kind_Taxonomy {

	const TAXONOMY = 'nop_kind';

	const DEFAULT_KINDS = [
		'note'     => 'Note',
		'bookmark' => 'Bookmark',
		'reply'    => 'Reply',
		'like'     => 'Like',
		'repost'   => 'Repost',
		'rsvp'     => 'RSVP',
		'checkin'  => 'Checkin',
		'watch'    => 'Watch',
	];

	public function register(): void {
		add_action( 'init', [ $this, 'register_taxonomy' ] );
		// Priority 10: fires after wp_set_object_terms() commits term associations.
		add_action( 'set_object_terms', [ $this, 'mirror_kind_to_meta' ], 10, 6 );
	}

	public function register_taxonomy(): void {
		register_taxonomy( self::TAXONOMY, 'post', [
			'label'             => __( 'Post Kind', 'nop-indieweb' ),
			'labels'            => [
				'name'          => __( 'Post Kinds', 'nop-indieweb' ),
				'singular_name' => __( 'Post Kind', 'nop-indieweb' ),
			],
			'hierarchical'      => true,
			'show_in_rest'      => true,
			'show_ui'           => false,
			'show_admin_column' => false,
			'rewrite'           => [ 'slug' => 'kind' ],
			'query_var'         => false,
		] );
		$this->seed_terms();
	}

	/**
	 * Mirror the canonical nop_kind term assignment to the nop_indieweb_post_kind
	 * meta read-cache. Called by WordPress after every wp_set_object_terms() call.
	 *
	 * We re-read from the database after the write so the value is always
	 * authoritative regardless of whether terms were passed as slugs or IDs.
	 */
	public function mirror_kind_to_meta( int $object_id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
		if ( self::TAXONOMY !== $taxonomy ) {
			return;
		}
		$assigned = wp_get_object_terms( $object_id, self::TAXONOMY, [ 'fields' => 'slugs', 'orderby' => 'term_id' ] );
		$slug     = ! is_wp_error( $assigned ) && $assigned ? (string) $assigned[0] : '';
		if ( $slug ) {
			update_post_meta( $object_id, 'nop_indieweb_post_kind', $slug );
		} else {
			delete_post_meta( $object_id, 'nop_indieweb_post_kind' );
		}
	}

	/**
	 * Backfill existing posts: read nop_indieweb_post_kind meta and assign the
	 * matching nop_kind term. Safe to run multiple times — already-migrated posts
	 * are a no-op. Returns the number of posts processed.
	 */
	public static function backfill_from_meta(): int {
		$posts = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [ [
				'key'     => 'nop_indieweb_post_kind',
				'compare' => 'EXISTS',
			] ],
		] );

		$count = 0;
		foreach ( $posts as $post_id ) {
			$kind = (string) get_post_meta( $post_id, 'nop_indieweb_post_kind', true );
			if ( ! $kind ) {
				continue;
			}
			if ( term_exists( $kind, self::TAXONOMY ) ) {
				wp_set_object_terms( (int) $post_id, $kind, self::TAXONOMY );
				$count++;
			}
		}
		return $count;
	}

	private function seed_terms(): void {
		foreach ( self::DEFAULT_KINDS as $slug => $name ) {
			if ( ! term_exists( $slug, self::TAXONOMY ) ) {
				wp_insert_term( $name, self::TAXONOMY, [ 'slug' => $slug ] );
			}
		}
	}
}
