<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Browse;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use NOP\IndieWeb\Kind\Kind_Taxonomy;
use NOP\IndieWeb\Kind\Logbook;

/**
 * Filters on the Logbook, combined in its address so a view can be shared or
 * bookmarked: ?kind=photo,note (any of these kinds), ?tag=black-and-white,belfast
 * (core's tag query: any of these topics) and ?q=… (a search). They narrow the
 * main query, so the theme's Query Loop (inherit) shows the result and core's
 * Query Total counts it.
 *
 * The furniture is core blocks a theme places: a Terms Query of kinds or tags
 * (its Term Name links become toggles: on the current view, adding or removing
 * that term, aria-current when on) and a Search block with the class
 * `nop-wall-search` (it searches the current view, as ?q=, keeping the other
 * filters), and a Paragraph with `nop-wall-all-kinds` links to every kind, keeping
 * the rest. Browse swaps the wall in place as they change. A Group with the
 * class `nop-wall-status` becomes a live region, so the new count is read out,
 * and anything with `nop-wall-clear` links to the unfiltered Logbook and shows
 * only when something is filtered.
 */
final class Wall_Filters {

	public const KIND   = 'kind';
	public const SEARCH = 'q';

	public function register(): void {
		add_filter( 'query_vars', fn( $vars ) => array_merge( $vars, [ self::KIND, self::SEARCH ] ) );
		add_action( 'pre_get_posts', [ $this, 'narrow' ], 20 );
		add_filter( 'render_block_core/term-name', [ $this, 'toggle' ], 10, 3 );
		add_filter( 'render_block_core/search', [ $this, 'search_form' ], 10, 2 );
		add_filter( 'render_block_core/group', [ $this, 'mark' ], 10, 2 );
		add_filter( 'render_block_core/paragraph', [ $this, 'mark' ], 10, 2 );
		add_filter( 'render_block_core/buttons', [ $this, 'mark' ], 10, 2 );
		add_filter( 'render_block_core/post-terms', [ $this, 'topics' ], 10, 2 );
	}

	/** @return string[] The kinds chosen (logbook kinds only). */
	public static function kinds(): array {
		$asked = array_filter( array_map( 'sanitize_key', explode( ',', (string) get_query_var( self::KIND ) ) ) );
		return array_values( array_intersect( $asked, Logbook::kinds() ) );
	}

	/** @return string[] The topics (tag slugs) chosen. */
	public static function tags(): array {
		return array_values( array_filter( array_map( 'sanitize_title', preg_split( '/[,+ ]/', (string) get_query_var( 'tag' ) ) ?: [] ) ) );
	}

	public static function search(): string {
		return trim( sanitize_text_field( (string) get_query_var( self::SEARCH ) ) );
	}

	public static function active(): bool {
		return Logbook::is_logbook() && ( self::kinds() || self::tags() || '' !== self::search() );
	}

	/** The current view's address with one filter changed (and back to page one). */
	public static function url( array $change ): string {
		$args = array_filter(
			array_merge(
				[
					self::KIND   => implode( ',', self::kinds() ),
					'tag'        => implode( ',', self::tags() ),
					self::SEARCH => self::search(),
				],
				$change
			),
			static fn( $v ) => '' !== $v
		);
		// Commas stay readable in the address (?kind=note,photo).
		return str_replace( '%2C', ',', add_query_arg( array_map( 'rawurlencode', $args ), Logbook::url() ) );
	}

	/** The Logbook shows the chosen kinds only; a search on it searches it. */
	public function narrow( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->get( Logbook::QUERY_VAR ) ) {
			return;
		}
		$kinds = self::kinds();
		if ( $kinds ) {
			$query->set( 'tax_query', [ [ 'taxonomy' => Kind_Taxonomy::TAXONOMY, 'field' => 'slug', 'terms' => $kinds ] ] );
		}
		if ( '' !== self::search() ) {
			// As ?q=, not ?s=: a search would turn the Logbook into the search results page.
			$query->set( 's', self::search() );
		}
	}

	/** A kind or topic in a Terms Query on the Logbook toggles that filter. */
	public function toggle( string $content, array $block, \WP_Block $instance ): string {
		$taxonomy = (string) ( $instance->context['taxonomy'] ?? '' );
		$term     = get_term( (int) ( $instance->context['termId'] ?? 0 ), $taxonomy );
		if ( ! Logbook::is_logbook() || ! $term instanceof \WP_Term || ! in_array( $taxonomy, [ Kind_Taxonomy::TAXONOMY, 'post_tag' ], true ) ) {
			return $content;
		}
		// A kind that has a section of its own (Photos, Blog) keeps its link there.
		if ( Kind_Taxonomy::TAXONOMY === $taxonomy && ! in_array( $term->slug, Logbook::kinds(), true ) ) {
			return $content;
		}
		$key    = Kind_Taxonomy::TAXONOMY === $taxonomy ? self::KIND : 'tag';
		$chosen = Kind_Taxonomy::TAXONOMY === $taxonomy ? self::kinds() : self::tags();
		$on     = in_array( $term->slug, $chosen, true );
		$next   = $on ? array_diff( $chosen, [ $term->slug ] ) : array_merge( $chosen, [ $term->slug ] );
		$p      = new \WP_HTML_Tag_Processor( $content );
		if ( ! $p->next_tag( [ 'tag_name' => 'a' ] ) ) {
			return $content;
		}
		$p->set_attribute( 'href', self::url( [ $key => implode( ',', $next ) ] ) );
		$p->set_attribute( 'rel', 'nofollow' );
		if ( $on ) {
			$p->set_attribute( 'aria-current', 'true' );
			$p->add_class( 'is-current' );
		}
		return $p->get_updated_html();
	}

	/** A Search block with the class nop-wall-search searches the current view. */
	public function search_form( string $content, array $block ): string {
		if ( ! Logbook::is_logbook() || ! str_contains( (string) ( $block['attrs']['className'] ?? '' ), 'nop-wall-search' ) ) {
			return $content;
		}
		$p = new \WP_HTML_Tag_Processor( $content );
		if ( $p->next_tag( [ 'tag_name' => 'form' ] ) ) {
			$p->set_attribute( 'action', Logbook::url() );
			$p->set_attribute( 'data-wp-on--input', 'actions.searchWall' );
			$p->set_attribute( 'data-wp-on--submit', 'actions.searchWall' );
		}
		while ( $p->next_tag( [ 'tag_name' => 'input' ] ) ) {
			if ( 's' === $p->get_attribute( 'name' ) ) {
				$p->set_attribute( 'name', self::SEARCH );
				$p->set_attribute( 'value', self::search() );
				$p->set_attribute( 'autocomplete', 'off' );
			}
		}
		$hidden = '';
		foreach ( [ self::KIND => implode( ',', self::kinds() ), 'tag' => implode( ',', self::tags() ) ] as $name => $value ) {
			if ( '' !== $value ) {
				$hidden .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
			}
		}
		return (string) preg_replace( '#</form>#', $hidden . '</form>', $p->get_updated_html(), 1 );
	}

	/** A post's topics (a Post Terms block of tags with the class nop-wall-tags) open the Logbook filtered to that topic, wherever they are shown. */
	public function topics( string $content, array $block ): string {
		if ( 'post_tag' !== ( $block['attrs']['term'] ?? '' ) || ! str_contains( (string) ( $block['attrs']['className'] ?? '' ), 'nop-wall-tags' ) ) {
			return $content;
		}
		$p = new \WP_HTML_Tag_Processor( $content );
		while ( $p->next_tag( [ 'tag_name' => 'a' ] ) ) {
			$slug = basename( (string) wp_parse_url( (string) $p->get_attribute( 'href' ), PHP_URL_PATH ) );
			if ( '' !== $slug ) {
				$p->set_attribute( 'href', add_query_arg( 'tag', rawurlencode( $slug ), Logbook::url() ) );
			}
		}
		return $p->get_updated_html();
	}

	/** The status line becomes a live region; Clear shows only when something is filtered. */
	public function mark( string $content, array $block ): string {
		$class = (string) ( $block['attrs']['className'] ?? '' );
		if ( ! Logbook::is_logbook() || ! preg_match( '/\bnop-wall-(status|clear|all-kinds)\b/', $class, $m ) ) {
			return $content;
		}
		$p = new \WP_HTML_Tag_Processor( $content );
		if ( ! $p->next_tag() ) {
			return $content;
		}
		if ( 'status' === $m[1] ) {
			$p->set_attribute( 'role', 'status' );
			$p->set_attribute( 'aria-live', 'polite' );
			return $p->get_updated_html();
		}
		// "All" kinds: every kind, keeping the other filters; current when no kind is chosen.
		if ( 'all-kinds' === $m[1] ) {
			if ( $p->next_tag( [ 'tag_name' => 'a' ] ) ) {
				$p->set_attribute( 'href', self::url( [ self::KIND => '' ] ) );
				$p->set_attribute( 'rel', 'nofollow' );
				if ( self::kinds() ) {
					$p->remove_attribute( 'aria-current' );
				} else {
					$p->set_attribute( 'aria-current', 'true' );
				}
			}
			return $p->get_updated_html();
		}
		if ( ! self::active() ) {
			return '';
		}
		while ( $p->next_tag( [ 'tag_name' => 'a' ] ) ) {
			$p->set_attribute( 'href', Logbook::url() );
		}
		return $p->get_updated_html();
	}
}
