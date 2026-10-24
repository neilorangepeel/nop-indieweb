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
	// Words kept from earlier searches (Enter keeps what was typed): each a chip, all of them searched together.
	public const WORDS  = 'words';

	public function register(): void {
		add_filter( 'query_vars', fn( $vars ) => array_merge( $vars, [ self::KIND, self::SEARCH, self::WORDS ] ) );
		add_action( 'pre_get_posts', [ $this, 'narrow' ], 20 );
		add_filter( 'render_block_core/term-name', [ $this, 'toggle' ], 10, 3 );
		add_filter( 'render_block_core/search', [ $this, 'search_form' ], 10, 2 );
		add_filter( 'render_block_core/group', [ $this, 'mark' ], 10, 2 );
		add_filter( 'render_block_core/paragraph', [ $this, 'mark' ], 10, 2 );
		add_filter( 'render_block_core/buttons', [ $this, 'mark' ], 10, 2 );
		add_filter( 'render_block_core/post-terms', [ $this, 'topics' ], 10, 2 );
		add_filter( 'render_block_core/query-total', [ $this, 'total' ], 10, 2 );
		add_action( 'save_post_post', [ self::class, 'forget_counts' ] );
		add_action( 'deleted_post', [ self::class, 'forget_counts' ] );
		add_action( 'set_object_terms', [ self::class, 'forget_counts' ] );
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

	/** @return string[] The words kept as chips. */
	public static function words(): array {
		$words = array_map( static fn( $w ) => trim( sanitize_text_field( $w ) ), explode( ',', (string) get_query_var( self::WORDS ) ) );
		return array_values( array_unique( array_filter( $words, static fn( $w ) => '' !== $w ) ) );
	}

	/** Everything searched for: the kept words and what is in the field now. */
	public static function terms(): string {
		return trim( implode( ' ', self::words() ) . ' ' . self::search() );
	}

	public static function search(): string {
		return trim( sanitize_text_field( (string) get_query_var( self::SEARCH ) ) );
	}

	public static function active(): bool {
		return Logbook::is_logbook() && ( self::kinds() || self::tags() || '' !== self::terms() );
	}

	/** The current view's address with one filter changed (and back to page one). */
	public static function url( array $change ): string {
		$args = array_filter(
			array_merge(
				[
					self::KIND   => implode( ',', self::kinds() ),
					// Topics combine as all of them (core reads "a+b" as both): cross-referencing narrows.
					'tag'        => implode( '+', self::tags() ),
					self::WORDS  => implode( ',', self::words() ),
					self::SEARCH => self::search(),
				],
				$change
			),
			static fn( $v ) => '' !== $v
		);
		// Commas stay readable in the address (?kind=note,photo).
		return str_replace( [ '%2C', '%2B' ], [ ',', '+' ], add_query_arg( array_map( 'rawurlencode', $args ), Logbook::url() ) );
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
		if ( '' !== self::terms() ) {
			// As ?q= and ?w=, not ?s=: a search would turn the Logbook into the search results page.
			$query->set( 's', self::terms() );
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
		$p->set_attribute( 'href', self::url( [ $key => implode( Kind_Taxonomy::TAXONOMY === $taxonomy ? ',' : '+', $next ) ] ) );
		$p->set_attribute( 'rel', 'nofollow' );
		// How many entries this choice holds among the others: a kind, its own entries with the chosen topics; a topic, the
		// wall with it added: a choice that would leave nothing is marked, so it can step back rather than lead to an empty wall.
		$count = Kind_Taxonomy::TAXONOMY === $taxonomy
			? self::count( [ $term->slug ], self::tags() )
			: self::count( self::kinds(), $on ? self::tags() : array_merge( self::tags(), [ $term->slug ] ) );
		if ( $on ) {
			$p->set_attribute( 'aria-current', 'true' );
			$p->add_class( 'is-current' );
		} elseif ( 0 === $count ) {
			$p->add_class( 'is-empty' );
			$p->set_attribute( 'aria-disabled', 'true' );
			$p->set_attribute( 'tabindex', '-1' );
		}
		$html = $p->get_updated_html();
		return (string) preg_replace( '#</a>#', '<span class="nop-wall-count">' . esc_html( number_format_i18n( $count ) ) . '</span></a>', $html, 1 );
	}

	/**
	 * How many Logbook entries match these kinds (any of them; none = every Logbook kind), topics (all of them) and the
	 * current search. Kept for a day per combination and forgotten whenever a post is saved or deleted.
	 *
	 * @param string[] $kinds Kind slugs.
	 * @param string[] $tags  Tag slugs.
	 */
	public static function count( array $kinds, array $tags, bool $searched = true ): int {
		$terms = $searched ? self::terms() : '';
		sort( $kinds );
		sort( $tags );
		$key = 'nop_wall_count_' . md5( wp_json_encode( [ $kinds, $tags, $terms, (int) get_option( 'nop_wall_counts_version', 0 ) ] ) );
		static $seen = [];
		if ( isset( $seen[ $key ] ) ) {
			return $seen[ $key ];
		}
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $seen[ $key ] = (int) $cached;
		}
		$args = [
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'tax_query'              => [ [ 'taxonomy' => Kind_Taxonomy::TAXONOMY, 'field' => 'slug', 'terms' => $kinds ?: Logbook::kinds() ] ],
		];
		if ( $tags ) {
			$args['tag_slug__and'] = $tags;
		}
		if ( '' !== $terms ) {
			$args['s'] = $terms;
		}
		$count = (int) ( new \WP_Query( $args ) )->found_posts;
		set_transient( $key, $count, DAY_IN_SECONDS );
		return $seen[ $key ] = $count;
	}

	/** Saving or deleting a post forgets every count (a new version in the keys). */
	public static function forget_counts(): void {
		update_option( 'nop_wall_counts_version', (int) get_option( 'nop_wall_counts_version', 0 ) + 1, false );
	}

	/** The kept words, chosen kinds and topics as chips (a Paragraph with the class nop-wall-chips): each a link that drops it. */
	private function chips(): string {
		$out = '';
		foreach ( self::words() as $word ) {
			$out .= $this->chip( '“' . $word . '”', self::url( [ self::WORDS => implode( ',', array_diff( self::words(), [ $word ] ) ) ] ) );
		}
		foreach ( self::kinds() as $slug ) {
			$term  = get_term_by( 'slug', $slug, Kind_Taxonomy::TAXONOMY );
			$out  .= $this->chip( $term ? $term->name : $slug, self::url( [ self::KIND => implode( ',', array_diff( self::kinds(), [ $slug ] ) ) ] ) );
		}
		foreach ( self::tags() as $slug ) {
			$term  = get_term_by( 'slug', $slug, 'post_tag' );
			$out  .= $this->chip( '#' . ( $term ? $term->name : $slug ), self::url( [ 'tag' => implode( '+', array_diff( self::tags(), [ $slug ] ) ) ] ) );
		}
		return $out;
	}

	private function chip( string $label, string $href ): string {
		/* translators: %s: a filter, e.g. "Photo" or "#Belfast" */
		return '<a class="nop-wall-chip" rel="nofollow" href="' . esc_url( $href ) . '" aria-label="' . esc_attr( sprintf( __( 'Remove %s', 'nop-indieweb' ), $label ) ) . '">' . esc_html( $label ) . '<span class="nop-wall-chip__x" aria-hidden="true">×</span></a>';
	}

	/** On the Logbook at rest, Query Total says how many entries there are ("1,718 entries"). */
	public function total( string $content, array $block ): string {
		if ( ! Logbook::is_logbook() || self::active() ) {
			return $content;
		}
		global $wp_query;
		$n = (int) $wp_query->found_posts;
		/* translators: %s: number of entries */
		$text = sprintf( _n( '%s entry', '%s entries', $n, 'nop-indieweb' ), number_format_i18n( $n ) );
		return (string) preg_replace( '#(<div\b[^>]*>).*?(</div>)#s', '${1}' . esc_html( $text ) . '$2', $content, 1 );
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
				// With chips in the field, its placeholder says it takes more.
				if ( self::kinds() || self::tags() || self::words() ) {
					$p->set_attribute( 'placeholder', __( 'Add more…', 'nop-indieweb' ) );
				}
			}
		}
		$hidden = '';
		foreach ( [ self::KIND => implode( ',', self::kinds() ), 'tag' => implode( '+', self::tags() ), self::WORDS => implode( ',', self::words() ) ] as $name => $value ) {
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
		// The bar itself: its drawer opens while it is in use (browse.js keeps the state across the wall's swaps). The
		// store is named in each directive, as the bar can sit outside the wall's own interactive region.
		if ( preg_match( '/\bnop-wall-filters\b/', $class ) ) {
			if ( ! Browse::is_wall() ) {
				return $content; // No wall script here: the drawer opens on focus alone (CSS :focus-within).
			}
			$p = new \WP_HTML_Tag_Processor( $content );
			if ( $p->next_tag() ) {
				$p->set_attribute( 'data-wp-class--is-open', 'nop-indieweb/browse::state.filtersOpen' );
				$p->set_attribute( 'data-wp-on--focusin', 'nop-indieweb/browse::actions.openFilters' );
				$p->set_attribute( 'data-wp-on--focusout', 'nop-indieweb/browse::actions.leaveFilters' );
				$p->set_attribute( 'data-wp-on--keydown', 'nop-indieweb/browse::actions.filterKeys' );
				$p->set_attribute( 'data-wp-on--mousedown', 'nop-indieweb/browse::actions.holdFilters' );
				$p->set_attribute( 'data-wp-on-document--pointerdown', 'nop-indieweb/browse::actions.outsideFilters' );
				return $p->get_updated_html();
			}
			return $content;
		}
		if ( ! Logbook::is_logbook() || ! preg_match( '/\bnop-wall-(status|clear|all-kinds|chips|applied|idle|summary)\b/', $class, $m ) ) {
			return $content;
		}
		$p = new \WP_HTML_Tag_Processor( $content );
		if ( ! $p->next_tag() ) {
			return $content;
		}
		// The count at the line's end: "1,718 entries" (Query Total in nop-wall-idle) while nothing is applied, "6 of 1,718"
		// (nop-wall-summary) while something is. (nop-wall-applied: a Group shown only while something is applied.)
		if ( 'applied' === $m[1] ) {
			return self::active() ? $content : '';
		}
		if ( 'idle' === $m[1] ) {
			return self::active() ? '' : $content;
		}
		// "6 of 1,718": the wall's count against the whole Logbook, read out as it changes.
		if ( 'summary' === $m[1] ) {
			if ( ! self::active() ) {
				return '';
			}
			global $wp_query;
			/* translators: 1: entries shown, 2: all Logbook entries */
			$text = sprintf( __( '%1$s of %2$s', 'nop-indieweb' ), number_format_i18n( (int) $wp_query->found_posts ), number_format_i18n( self::count( [], [], false ) ) );
			$p->set_attribute( 'role', 'status' );
			$p->set_attribute( 'aria-live', 'polite' );
			return (string) preg_replace( '#(<p\b[^>]*>)\s*(</p>)#', '${1}' . esc_html( $text ) . '$2', $p->get_updated_html(), 1 );
		}
		if ( 'chips' === $m[1] ) {
			$chips = $this->chips();
			return '' === $chips ? '' : (string) preg_replace( '#(<p\b[^>]*>)\s*(</p>)#', '${1}' . $chips . '$2', $content, 1 );
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
		// Clear all: only when there is more than one thing to clear (one chip's own × does the job).
		if ( count( self::kinds() ) + count( self::tags() ) + count( self::words() ) + ( '' !== self::search() ? 1 : 0 ) < 2 ) {
			return '';
		}
		while ( $p->next_tag( [ 'tag_name' => 'a' ] ) ) {
			$p->set_attribute( 'href', Logbook::url() );
		}
		return $p->get_updated_html();
	}
}
