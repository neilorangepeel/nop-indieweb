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

	const FLAT_KINDS = [
		'note'     => 'Note',
		'article'  => 'Article',
		'bookmark' => 'Bookmark',
		'reply'    => 'Reply',
		'like'     => 'Like',
		'repost'   => 'Repost',
		'rsvp'     => 'RSVP',
		'checkin'  => 'Checkin',
		'watch'    => 'Watch',
		'listen'   => 'Listen',
		'photo'    => 'Photo',
	];

	const KIND_DESCRIPTIONS = [
		'note'       => 'Short-form posts without a title — thoughts, status updates, quick reactions.',
		'article'    => 'Long-form posts with a title — essays, blog posts, written pieces.',
		'bookmark'   => 'A saved link to content worth revisiting.',
		'reply'      => 'A response to content elsewhere on the web.',
		'like'       => 'Appreciation for something elsewhere on the web.',
		'repost'     => 'Sharing someone else\'s post without added commentary.',
		'rsvp'       => 'A response to an event invitation.',
		'checkin'    => 'A location check-in, usually from Swarm.',
		'watch'      => 'A film or show watched, logged via Letterboxd.',
		'listen'     => 'Music or audio actively listened to.',
		'photo'      => 'A post where an image is the primary content.',
		'collection' => 'Ownership of a physical or digital media item.',
		'music'      => 'A music album or release in the collection.',
		'film'       => 'A film owned on physical or digital media.',
		'book'       => 'A book owned or read.',
	];

	// collection → {music, film, book}; format detail (vinyl, cd, dvd, etc.) goes on tags.
	const HIERARCHICAL_KINDS = [
		'collection' => [
			'name'     => 'Collection',
			'children' => [
				'music' => 'Music',
				'film'  => 'Film',
				'book'  => 'Book',
			],
		],
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
			'show_ui'           => true,
			'show_admin_column' => true,
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
		foreach ( self::FLAT_KINDS as $slug => $name ) {
			if ( ! term_exists( $slug, self::TAXONOMY ) ) {
				wp_insert_term( $name, self::TAXONOMY, [ 'slug' => $slug ] );
			}
			$this->set_description( $slug );
		}

		foreach ( self::HIERARCHICAL_KINDS as $parent_slug => $parent ) {
			if ( ! term_exists( $parent_slug, self::TAXONOMY ) ) {
				wp_insert_term( $parent['name'], self::TAXONOMY, [ 'slug' => $parent_slug ] );
			}
			$this->set_description( $parent_slug );
			$parent_term = get_term_by( 'slug', $parent_slug, self::TAXONOMY );
			if ( ! $parent_term instanceof \WP_Term ) {
				continue;
			}
			foreach ( $parent['children'] as $child_slug => $child_name ) {
				if ( ! term_exists( $child_slug, self::TAXONOMY ) ) {
					wp_insert_term( $child_name, self::TAXONOMY, [
						'slug'   => $child_slug,
						'parent' => $parent_term->term_id,
					] );
				}
				$this->set_description( $child_slug );
			}
		}
	}

	/**
	 * Per-kind editor panel config consumed by admin/post-kinds-panel.js.
	 *
	 * Single source of truth for the post-kind selector in the block editor:
	 *   - label          → dropdown label
	 *   - fields         → URL/select inputs shown when the kind is selected
	 *   - layout         → starter blocks injected into an empty post on selection
	 *   - title_from_url → if true, the first valid URL entered auto-fills an
	 *                      empty post title with the URL's hostname
	 *
	 * To add a new kind: register the term in FLAT_KINDS / HIERARCHICAL_KINDS,
	 * then add a config entry here. Service-created kinds (no manual editor
	 * input) keep empty fields and layout — they still appear in the dropdown
	 * so a hand-authored post can adopt the kind.
	 *
	 * Order in the returned array determines order in the dropdown.
	 */
	public static function get_editor_panel_config(): array {
		$button = static function ( string $meta_key, string $label ): string {
			return '<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"left"}} -->'
				. '<div class="wp-block-buttons">'
				. '<!-- wp:button {"metadata":{"bindings":{"url":{"source":"core/post-meta","args":{"key":"' . $meta_key . '"}}}},"className":"is-style-outline"} -->'
				. '<div class="wp-block-button is-style-outline">'
				. '<a class="wp-block-button__link wp-element-button" href="#" target="_blank" rel="noreferrer noopener">'
				. esc_html( $label ) . ' →'
				. '</a></div>'
				. '<!-- /wp:button -->'
				. '</div>'
				. '<!-- /wp:buttons -->';
		};

		return [
			'note' => [
				'label'          => __( 'Note', 'nop-indieweb' ),
				'fields'         => [],
				'layout'         => '<!-- wp:paragraph /-->',
				'title_from_url' => false,
			],
			'article' => [
				'label'          => __( 'Article', 'nop-indieweb' ),
				'fields'         => [],
				'layout'         => '',
				'title_from_url' => false,
			],
			'bookmark' => [
				'label'          => __( 'Bookmark', 'nop-indieweb' ),
				'fields'         => [
					[ 'key' => 'nop_indieweb_bookmark_of', 'label' => __( 'Bookmark of', 'nop-indieweb' ) ],
				],
				'layout'         => $button( 'nop_indieweb_bookmark_of', __( 'View Bookmark', 'nop-indieweb' ) ) . '<!-- wp:paragraph /-->',
				'title_from_url' => true,
			],
			'reply' => [
				'label'          => __( 'Reply', 'nop-indieweb' ),
				'fields'         => [
					[ 'key' => 'nop_indieweb_in_reply_to', 'label' => __( 'In reply to', 'nop-indieweb' ) ],
				],
				'layout'         => $button( 'nop_indieweb_in_reply_to', __( 'View Original Post', 'nop-indieweb' ) ) . '<!-- wp:paragraph /-->',
				'title_from_url' => true,
			],
			'like' => [
				'label'          => __( 'Like', 'nop-indieweb' ),
				'fields'         => [
					[ 'key' => 'nop_indieweb_like_of', 'label' => __( 'Like of', 'nop-indieweb' ) ],
				],
				'layout'         => $button( 'nop_indieweb_like_of', __( 'View Post', 'nop-indieweb' ) ),
				'title_from_url' => true,
			],
			'repost' => [
				'label'          => __( 'Repost', 'nop-indieweb' ),
				'fields'         => [
					[ 'key' => 'nop_indieweb_repost_of', 'label' => __( 'Repost of', 'nop-indieweb' ) ],
				],
				'layout'         => $button( 'nop_indieweb_repost_of', __( 'View Original', 'nop-indieweb' ) ),
				'title_from_url' => true,
			],
			'rsvp' => [
				'label'          => __( 'RSVP', 'nop-indieweb' ),
				'fields'         => [
					[ 'key' => 'nop_indieweb_in_reply_to', 'label' => __( 'Event URL', 'nop-indieweb' ) ],
					[
						'key'     => 'nop_indieweb_rsvp',
						'label'   => __( 'Response', 'nop-indieweb' ),
						'type'    => 'select',
						'options' => [
							[ 'value' => 'yes',        'label' => __( 'Yes',        'nop-indieweb' ) ],
							[ 'value' => 'no',         'label' => __( 'No',         'nop-indieweb' ) ],
							[ 'value' => 'maybe',      'label' => __( 'Maybe',      'nop-indieweb' ) ],
							[ 'value' => 'interested', 'label' => __( 'Interested', 'nop-indieweb' ) ],
						],
					],
				],
				'layout'         => '<!-- wp:nop-indieweb/rsvp-meta /--><!-- wp:paragraph /-->',
				'title_from_url' => true,
			],
			'photo' => [
				'label'          => __( 'Photo', 'nop-indieweb' ),
				'fields'         => [],
				'layout'         => '',
				'title_from_url' => false,
			],
			// ── Service-created kinds ────────────────────────────────────────────
			// Posts of these kinds are produced by Micropub / RSS flows. The
			// dropdown entry lets a hand-authored post adopt the kind without
			// going through the service. Per-kind editor inputs land here when
			// the manual-composition flows are designed (roadmap Phase 2+).
			'checkin' => [
				'label'          => __( 'Checkin', 'nop-indieweb' ),
				'fields'         => [],
				'layout'         => '',
				'title_from_url' => false,
			],
			'watch' => [
				'label'          => __( 'Watch', 'nop-indieweb' ),
				'fields'         => [],
				'layout'         => '',
				'title_from_url' => false,
			],
			'listen' => [
				'label'          => __( 'Listen', 'nop-indieweb' ),
				'fields'         => [],
				'layout'         => '',
				'title_from_url' => false,
			],
		];
	}

	private function set_description( string $slug ): void {
		$description = self::KIND_DESCRIPTIONS[ $slug ] ?? '';
		if ( ! $description ) {
			return;
		}
		$term = get_term_by( 'slug', $slug, self::TAXONOMY );
		if ( $term instanceof \WP_Term && $term->description !== $description ) {
			wp_update_term( $term->term_id, self::TAXONOMY, [ 'description' => $description ] );
		}
	}
}
