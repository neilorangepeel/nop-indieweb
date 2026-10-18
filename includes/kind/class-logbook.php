<?php
/**
 * The Logbook: the day-to-day kinds, gathered at one address.
 *
 * A site files its day-to-day posts by kind, so the Logbook is those kinds,
 * not a category. Without a real Logbook category, /category/logbook/ is the
 * archive of the logbook kinds: it routes as a kind archive, then the main
 * query widens to every logbook kind. It renders the category-logbook
 * template (the plugin's plain one, or a theme's own by the same slug),
 * has one canonical address, and /logbook/ lands on it.
 *
 * Which kinds count: filter `nop_indieweb_logbook_kinds`. Page size: filter
 * `nop_indieweb_logbook_per_page`. Where another kind lives instead (a
 * section of its own): filter `nop_indieweb_kind_sections`.
 */
declare( strict_types=1 );

namespace NOP\IndieWeb\Kind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Logbook {

	public const QUERY_VAR = 'nop_logbook';

	/** @return string[] Kind slugs that make up the Logbook (replies and reposts answer other people's posts, so they stay out). */
	public static function kinds(): array {
		return (array) apply_filters( 'nop_indieweb_logbook_kinds', [ 'checkin', 'note', 'watch', 'listen', 'bookmark', 'like', 'quote', 'exercise', 'rsvp', 'story' ] );
	}

	/** The section a kind lives in: a category slug, or 'logbook'. */
	public static function section( string $kind ): string {
		$map = (array) apply_filters( 'nop_indieweb_kind_sections', [ 'photo' => 'photos', 'video' => 'films', 'article' => 'journal' ] );
		return (string) ( $map[ $kind ] ?? 'logbook' );
	}

	/** The Logbook archive: a real category, or the kinds standing in for one. */
	public static function is_logbook(): bool {
		return is_category( 'logbook' ) || self::is_routed();
	}

	/** The routed Logbook (no real category). */
	public static function is_routed(): bool {
		return (bool) get_query_var( self::QUERY_VAR );
	}

	/** A wall of logbook entries: the Logbook, or one logbook kind's archive. */
	public static function is_wall(): bool {
		if ( self::is_logbook() ) {
			return true;
		}
		$term = is_tax( Kind_Taxonomy::TAXONOMY ) ? get_queried_object() : null;
		return $term instanceof \WP_Term && 'logbook' === self::section( $term->slug );
	}

	/** A single logbook entry, which a wall can open over itself. */
	public static function is_entry(): bool {
		if ( ! is_singular( 'post' ) ) {
			return false;
		}
		$kinds = get_the_terms( get_queried_object_id(), Kind_Taxonomy::TAXONOMY );
		return $kinds && ! is_wp_error( $kinds ) && 'logbook' === self::section( $kinds[0]->slug );
	}

	public function register(): void {
		add_filter( 'query_vars', fn( $vars ) => array_merge( $vars, [ self::QUERY_VAR ] ) );
		add_filter( 'request', [ $this, 'route' ] );
		add_action( 'pre_get_posts', [ $this, 'widen' ] );
		add_filter( 'taxonomy_template_hierarchy', fn( $templates ) => self::is_routed() ? array_merge( [ 'category-logbook.php' ], $templates ) : $templates );
		add_filter( 'body_class', fn( $classes ) => self::is_routed() ? array_merge( array_diff( $classes, [ 'tax-nop_kind' ] ), [ 'category', 'category-logbook' ] ) : $classes );
		add_filter( 'single_term_title', fn( $title ) => self::is_routed() ? __( 'Logbook', 'nop-indieweb' ) : $title );
		add_filter( 'term_description', fn( $description ) => self::is_routed() ? '' : $description );
		// It routes as a kind archive, but it is the Logbook: no "Post Kind:" prefix.
		add_filter( 'get_the_archive_title_prefix', fn( $prefix ) => self::is_routed() ? '' : $prefix );
		// Before core's redirect_canonical (10), which would otherwise guess a similarly named page.
		add_action( 'template_redirect', [ $this, 'redirect' ], 9 );
		add_action( 'wp_head', [ $this, 'canonical' ] );
		add_action( 'admin_bar_menu', [ $this, 'edit_link' ], 90 );
	}

	/** /category/logbook/ without a Logbook category: route as the first kind's archive, marked as the Logbook. */
	public function route( array $vars ): array {
		if ( 'logbook' === ( $vars['category_name'] ?? '' ) && ! term_exists( 'logbook', 'category' ) ) {
			unset( $vars['category_name'] );
			$vars['taxonomy']        = Kind_Taxonomy::TAXONOMY;
			$vars['term']            = self::kinds()[0] ?? 'note';
			$vars[ self::QUERY_VAR ] = 1;
		}
		return $vars;
	}

	/** The routed Logbook shows every logbook kind; any Logbook pages by `nop_indieweb_logbook_per_page`. */
	public function widen( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( $query->get( self::QUERY_VAR ) ) {
			$query->set( 'taxonomy', '' );
			$query->set( 'term', '' );
			$query->set( 'tax_query', [ [ 'taxonomy' => Kind_Taxonomy::TAXONOMY, 'field' => 'slug', 'terms' => self::kinds() ] ] );
		}
		if ( $query->get( self::QUERY_VAR ) || $query->is_category( 'logbook' ) ) {
			$query->set( 'posts_per_page', (int) apply_filters( 'nop_indieweb_logbook_per_page', 24 ) );
		}
	}

	public function redirect(): void {
		if ( is_404() && 'logbook' === trim( (string) wp_parse_url( add_query_arg( [] ), PHP_URL_PATH ), '/' ) && ! term_exists( 'logbook', 'category' ) ) {
			wp_safe_redirect( home_url( '/category/logbook/' ), 301 );
			exit;
		}
	}

	public function canonical(): void {
		if ( self::is_routed() ) {
			$paged = (int) get_query_var( 'paged' );
			echo '<link rel="canonical" href="' . esc_url( home_url( '/category/logbook/' . ( $paged > 1 ? 'page/' . $paged . '/' : '' ) ) ) . "\" />\n";
		}
	}

	/** The admin bar's Edit opens the Logbook template, not the first kind's term. */
	public function edit_link( \WP_Admin_Bar $bar ): void {
		if ( ! self::is_routed() || ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		$template = get_block_template( get_stylesheet() . '//category-logbook' ) ?: get_block_template( 'nop-indieweb//category-logbook' );
		if ( ! $template ) {
			return;
		}
		$bar->remove_node( 'edit' );
		$bar->add_node( [
			'id'    => 'edit',
			'title' => __( 'Edit Logbook template', 'nop-indieweb' ),
			'href'  => admin_url( 'site-editor.php?postType=wp_template&postId=' . rawurlencode( $template->id ) . '&canvas=edit' ),
		] );
	}
}
