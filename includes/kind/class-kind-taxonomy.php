<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Kind;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
		'exercise' => 'Exercise',
		'watch'    => 'Watch',
		'listen'   => 'Listen',
		'photo'    => 'Photo',
		'quote'    => 'Quote',
		'video'    => 'Video',
		'story'    => 'Story',
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
		'exercise'   => 'A workout or physical activity — run, ride, swim, yoga, gym session, and more.',
		'watch'      => 'A film or show watched, logged via Letterboxd.',
		'listen'     => 'Music or audio actively listened to.',
		'photo'      => 'A post where an image is the primary content.',
		'quote'      => 'A quotation from elsewhere, with attribution.',
		'video'      => 'A post where a video is the primary content.',
		'story'      => 'A short self-hosted video, featured for 24h then kept in the archive.',
		'collection' => 'Ownership of a physical or digital media item.',
		'music'      => 'A music album or release in the collection.',
		'film'       => 'A film owned on physical or digital media.',
		'book'       => 'A book owned or read.',
	];

	/**
	 * The curated topic categories (slug => display name).
	 * Categories answer "what's it about?"; kinds answer "what is it?".
	 */
	const TOPIC_CATEGORIES = [
		'photography'     => 'Photography',
		'performance'     => 'Performance',
		'web-development' => 'Web & Development',
		'places-travel'   => 'Places & Travel',
		'media-diet'      => 'Media Diet',
		'health-fitness'  => 'Health & Fitness',
		'journal'         => 'Journal',
	];

	/**
	 * Kind → default topic category. Applied by apply_default_category() only
	 * when the author hasn't picked a category — an explicit choice always wins.
	 * Kinds absent from this map (article) get no default: the author picks the
	 * topic per-post.
	 */
	const KIND_DEFAULT_CATEGORIES = [
		'photo'      => 'photography',
		'video'      => 'photography',
		'checkin'    => 'places-travel',
		'exercise'   => 'health-fitness',
		'watch'      => 'media-diet',
		'listen'     => 'media-diet',
		'collection' => 'media-diet',
		'music'      => 'media-diet',
		'film'       => 'media-diet',
		'book'       => 'media-diet',
		'note'       => 'journal',
		'reply'      => 'journal',
		'like'       => 'journal',
		'repost'     => 'journal',
		'bookmark'   => 'journal',
		'rsvp'       => 'journal',
		'quote'      => 'journal',
		'story'      => 'journal',
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
		// Priority 11: after the meta mirror, fill in the kind's default topic category.
		add_action( 'set_object_terms', [ $this, 'apply_default_category' ], 11, 6 );
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
	 * Fills in the kind's default topic category when the author hasn't picked one.
	 *
	 * "Hasn't picked" means the post has no categories, or only the WP default
	 * category — which acts purely as a sentinel for "nothing chosen" (WordPress
	 * auto-assigns it on insert). An explicit category choice is never overridden.
	 *
	 * Unmapped kinds (article) get the sentinel stripped instead, leaving the post
	 * category-less until the author picks a topic.
	 */
	public function apply_default_category( int $object_id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
		if ( self::TAXONOMY !== $taxonomy || 'post' !== get_post_type( $object_id ) ) {
			return;
		}

		$assigned = wp_get_object_terms( $object_id, self::TAXONOMY, [ 'fields' => 'slugs', 'orderby' => 'term_id' ] );
		$kind     = ! is_wp_error( $assigned ) && $assigned ? (string) $assigned[0] : '';
		if ( ! $kind ) {
			return;
		}

		$current = wp_get_object_terms( $object_id, 'category', [ 'fields' => 'ids' ] );
		if ( is_wp_error( $current ) ) {
			return;
		}
		$current  = array_map( 'intval', $current );
		$sentinel = (int) get_option( 'default_category' );

		$only_sentinel = ! $current || ( 1 === count( $current ) && $current[0] === $sentinel );
		if ( ! $only_sentinel ) {
			return;
		}

		$mapped = (string) apply_filters(
			'nop_indieweb_kind_default_category',
			self::KIND_DEFAULT_CATEGORIES[ $kind ] ?? '',
			$kind,
			$object_id
		);

		if ( ! $mapped ) {
			// Unmapped kind: strip the sentinel so the post stays category-less.
			if ( $current ) {
				wp_set_object_terms( $object_id, [], 'category' );
			}
			return;
		}

		$term_id = self::ensure_topic_category( $mapped );
		if ( $term_id && $current !== [ $term_id ] ) {
			wp_set_object_terms( $object_id, [ $term_id ], 'category' );
		}
	}

	/** Returns the topic category's term ID, creating it on first use. 0 on failure. */
	public static function ensure_topic_category( string $slug ): int {
		$existing = get_term_by( 'slug', $slug, 'category' );
		if ( $existing instanceof \WP_Term ) {
			return (int) $existing->term_id;
		}
		$name   = self::TOPIC_CATEGORIES[ $slug ] ?? ucwords( str_replace( '-', ' ', $slug ) );
		$result = wp_insert_term( $name, 'category', [ 'slug' => $slug ] );
		return is_wp_error( $result ) ? 0 : (int) $result['term_id'];
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
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- low-frequency meta/taxonomy lookup (import, admin, or per-post render cache), not a hot path
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
				'layout'         => '<!-- wp:nop-indieweb/cite-card /--><!-- wp:paragraph /-->',
				'title_from_url' => true,
			],
			'reply' => [
				'label'          => __( 'Reply', 'nop-indieweb' ),
				'fields'         => [
					[ 'key' => 'nop_indieweb_in_reply_to', 'label' => __( 'In reply to', 'nop-indieweb' ) ],
				],
				'layout'         => '<!-- wp:nop-indieweb/cite-card /--><!-- wp:paragraph /-->',
				'title_from_url' => true,
			],
			'like' => [
				'label'          => __( 'Like', 'nop-indieweb' ),
				'fields'         => [
					[ 'key' => 'nop_indieweb_like_of', 'label' => __( 'Like of', 'nop-indieweb' ) ],
				],
				'layout'         => '<!-- wp:nop-indieweb/cite-card /-->',
				'title_from_url' => true,
			],
			'repost' => [
				'label'          => __( 'Repost', 'nop-indieweb' ),
				'fields'         => [
					[ 'key' => 'nop_indieweb_repost_of', 'label' => __( 'Repost of', 'nop-indieweb' ) ],
				],
				'layout'         => '<!-- wp:nop-indieweb/cite-card /-->',
				'title_from_url' => true,
			],
			'rsvp' => [
				'label'          => __( 'RSVP', 'nop-indieweb' ),
				// The event URL, response, and the editable event fields all live in
				// the dedicated RSVP sub-panel (post-kinds-panel.js), which adds the
				// fetch-from-event-page behaviour on top of the meta inputs.
				'fields'         => [],
				'layout'         => '<!-- wp:nop-indieweb/rsvp-meta /--><!-- wp:paragraph /-->',
				'title_from_url' => true,
				'sub_panel'      => 'rsvp',
			],
			'photo' => [
				'label'          => __( 'Photo', 'nop-indieweb' ),
				'fields'         => [],
				'layout'         => '<!-- wp:image {"align":"wide"} /--><!-- wp:paragraph /-->',
				'title_from_url' => false,
			],
			'quote' => [
				'label'          => __( 'Quote', 'nop-indieweb' ),
				'fields'         => [
					[ 'key' => 'nop_indieweb_quote_of', 'label' => __( 'Source link (optional)', 'nop-indieweb' ) ],
				],
				// Passage + inline <cite> attribution; the source link is optional, so the
				// title isn't derived from a URL.
				'layout'         => '<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph /--><cite>Attribution</cite></blockquote><!-- /wp:quote -->',
				'title_from_url' => false,
			],
			'video' => [
				'label'          => __( 'Video', 'nop-indieweb' ),
				'fields'         => [],
				'layout'         => '<!-- wp:video /--><!-- wp:paragraph /-->',
				'title_from_url' => false,
			],
			'story' => [
				'label'          => __( 'Story', 'nop-indieweb' ),
				'fields'         => [],
				'layout'         => '<!-- wp:video /--><!-- wp:paragraph /-->',
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
				'sub_panel'      => 'venue',
			],
			'exercise' => [
				'label'          => __( 'Exercise', 'nop-indieweb' ),
				'fields'         => [],
				'layout'         => '',
				'title_from_url' => false,
				'sub_panel'      => 'exercise',
			],
			'watch' => [
				'label'          => __( 'Watch', 'nop-indieweb' ),
				'fields'         => [],
				'layout'         => '',
				'title_from_url' => false,
				'sub_panel'      => 'lookup:tmdb',
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
