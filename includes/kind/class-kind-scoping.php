<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Kind;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scopes the editor sidebar to the post's kind.
 *
 * A checkin has no use for Exercise Types, and an exercise post has none for
 * Venue Categories, but the block editor renders a panel for every taxonomy
 * attached to the post type regardless. Two halves solve that:
 *
 * 1. The panels. Core builds its taxonomy panel list from /wp/v2/taxonomies,
 *    keeping any whose `visibility.show_ui` is true, and there is no reversible
 *    way to remove a panel once rendered — `removeEditorPanel` only ever
 *    appends, so a panel hidden that way could never come back when the kind
 *    changes again. So these taxonomies are dropped from the REST list instead
 *    and the Post Kinds panel renders their term selector itself, for the kinds
 *    that actually use them. The admin's own term-management screens are
 *    untouched: they read the database, not REST.
 *
 * 2. The data. Switching kind mid-edit leaves the old kind's terms and URL meta
 *    attached — deliberately, so exploring kinds costs nothing. The clean-up
 *    happens once the kind is settled, on save, and only for saves that come
 *    through the block editor: importers, the Micropub composer and the CLI
 *    backfills reach wp_insert_post directly and must never have data pulled
 *    out from under them mid-import.
 */
class Kind_Scoping {

	public function register(): void {
		add_filter( 'rest_prepare_taxonomy', [ $this, 'hide_scoped_taxonomy_panels' ], 10, 3 );
		add_action( 'rest_after_insert_post', [ $this, 'drop_data_the_kind_no_longer_uses' ], 10, 1 );
	}

	/**
	 * Taxonomy slug => the kind slugs that use it, inverted from the per-kind
	 * editor config so the mapping has exactly one home.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function taxonomy_kinds(): array {
		$map = [];
		foreach ( Kind_Taxonomy::get_editor_panel_config() as $kind => $config ) {
			foreach ( (array) ( $config['taxonomies'] ?? [] ) as $taxonomy ) {
				$map[ (string) $taxonomy ][] = (string) $kind;
			}
		}
		return $map;
	}

	/** Every post-meta key any kind's panel owns, keyed by the kind that owns it. */
	private static function kind_meta_keys(): array {
		$map = [];
		foreach ( Kind_Taxonomy::get_editor_panel_config() as $kind => $config ) {
			foreach ( (array) ( $config['fields'] ?? [] ) as $field ) {
				if ( isset( $field['key'] ) ) {
					$map[ (string) $kind ][] = (string) $field['key'];
				}
			}
		}
		return $map;
	}

	/**
	 * Drops kind-scoped taxonomies from the editor's panel list. `show_ui` is
	 * what core filters on, so clearing it in the REST response is what stops
	 * the panel rendering — the taxonomy stays fully registered, and its terms
	 * stay readable and writable through the REST API as before.
	 */
	public function hide_scoped_taxonomy_panels( \WP_REST_Response $response, \WP_Taxonomy $taxonomy, \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! isset( self::taxonomy_kinds()[ $taxonomy->name ] ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( is_array( $data ) && isset( $data['visibility'] ) && is_array( $data['visibility'] ) ) {
			$data['visibility']['show_ui'] = false;
			$response->set_data( $data );
		}

		return $response;
	}

	/**
	 * On an editor save, removes the terms and URL meta belonging to kinds this
	 * post is not. Bails when the kind is unrecognised rather than guessing —
	 * a post with no kind term must not be stripped.
	 *
	 * Deliberately narrow: it clears the two scoped taxonomies and the per-kind
	 * URL fields, all of which the panel itself owns and can be re-entered by
	 * hand. Venue details and workout statistics are left alone — they come
	 * from Foursquare and Health Auto Export, and re-kinding a post by mistake
	 * must not destroy data that can't be typed back in.
	 */
	public function drop_data_the_kind_no_longer_uses( \WP_Post $post ): void {
		if ( ! apply_filters( 'nop_indieweb_scope_on_save', true, $post->ID ) ) {
			return;
		}

		$terms = get_the_terms( $post->ID, Kind_Taxonomy::TAXONOMY );
		$kind  = ( ! is_wp_error( $terms ) && $terms ) ? (string) $terms[0]->slug : '';
		if ( '' === $kind || ! isset( Kind_Taxonomy::get_editor_panel_config()[ $kind ] ) ) {
			return;
		}

		foreach ( self::taxonomy_kinds() as $taxonomy => $kinds ) {
			if ( in_array( $kind, $kinds, true ) ) {
				continue;
			}
			$attached = get_the_terms( $post->ID, $taxonomy );
			if ( ! is_wp_error( $attached ) && $attached ) {
				wp_set_object_terms( $post->ID, [], $taxonomy );
			}
		}

		$meta_by_kind = self::kind_meta_keys();
		$keep         = $meta_by_kind[ $kind ] ?? [];
		foreach ( $meta_by_kind as $owner => $keys ) {
			if ( $owner === $kind ) {
				continue;
			}
			foreach ( $keys as $key ) {
				if ( ! in_array( $key, $keep, true ) && '' !== (string) get_post_meta( $post->ID, $key, true ) ) {
					delete_post_meta( $post->ID, $key );
				}
			}
		}
	}
}
