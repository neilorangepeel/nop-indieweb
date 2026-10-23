<?php
/**
 * Browse: a wall of entries that opens each entry over itself.
 *
 * Templates stay plain blocks. As a wall or a logbook entry renders, the
 * template's <main> gets the `nop-indieweb/browse` directives
 * (assets/js/browse.js), so every page still works without JavaScript and any
 * block theme gets the overlay. An entry fetched with ?entry renders its Entry
 * template (entry-{kind}, then entry): the plugin's plain one, or a theme's
 * own by the same slug.
 *
 * The contract is a few classes: `.nop-wall-crumbs` (a Group swapped with the
 * wall), `.nop-wall-filter` (links inside navigate in place), `.nop-entry-nav`
 * (the controls Group) with buttons `.nop-entry__previous`, `__next` and
 * `__close`. Which pages are walls and entries: filters `nop_indieweb_is_wall`
 * and `nop_indieweb_is_entry`. A theme loads its own overlay styles on the
 * `nop_indieweb_browse_enqueue` action.
 */
declare( strict_types=1 );

namespace NOP\IndieWeb\Browse;

use NOP\IndieWeb\Kind\Kind_Taxonomy;
use NOP\IndieWeb\Kind\Logbook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Browse {

	public const HANDLE = 'nop-indieweb-browse';

	/** Kinds that open in another kind's Entry template. */
	private const SHARED = [ 'bookmark' => 'link', 'like' => 'link', 'reply' => 'link', 'repost' => 'link' ];

	public static function is_wall(): bool {
		return (bool) apply_filters( 'nop_indieweb_is_wall', Logbook::is_wall() );
	}

	public static function is_entry(): bool {
		return (bool) apply_filters( 'nop_indieweb_is_entry', Logbook::is_entry() );
	}

	/** An entry fetched to open over a wall; visiting the address itself still gets the full page. */
	public static function is_entry_request(): bool {
		return isset( $_GET['entry'] ) && self::is_entry(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public function register(): void {
		add_action( 'init', [ $this, 'register_assets' ] );
		// After the kind templates (10), so an opened entry renders its Entry template, not the full single.
		add_filter( 'single_template_hierarchy', [ $this, 'entry_template' ], 30 );
		add_filter( 'default_template_types', [ $this, 'template_names' ] );
		add_filter( 'wp_robots', fn( $robots ) => self::is_entry_request() ? array_merge( $robots, [ 'noindex' => true ] ) : $robots );
		add_filter( 'render_block_core/group', [ $this, 'mark_group' ], 10, 2 );
		add_filter( 'render_block_core/button', [ $this, 'mark_button' ], 10, 2 );
		add_filter( 'render_block_core/post-content', [ $this, 'drop_repeated_lead' ] );
	}

	/**
	 * An opened entry says nothing twice: the words lose a first paragraph that only repeats what the entry already
	 * shows above them, a listen's "Artist, Year." (its subtitle) or a story's caption that is its title. The full page
	 * is unchanged.
	 */
	public function drop_repeated_lead( string $content ): string {
		if ( ! self::is_entry_request() ) {
			return $content;
		}
		$id = (int) get_the_ID();
		if ( has_term( 'listen', Kind_Taxonomy::TAXONOMY ) && '' !== (string) \NOP\IndieWeb\Post_Meta\Entry_Fields::value( 'listen_artist_year', $id ) ) {
			return (string) preg_replace( '#<p\b[^>]*>\s*[^<,.\n]{1,60},\s*(?:19|20)\d{2}\.?\s*</p>\s*#u', '', $content, 1 );
		}
		// A note's title is its own words (it has no title of its own, and its card shows none), so they are never a repeat.
		if ( has_term( 'note', Kind_Taxonomy::TAXONOMY ) ) {
			return $content;
		}
		if ( preg_match( '#<p\b[^>]*>(.*?)</p>\s*#s', $content, $m, PREG_OFFSET_CAPTURE ) && 0 === strcasecmp( trim( wp_strip_all_tags( $m[1][0] ) ), trim( wp_strip_all_tags( get_the_title( $id ) ) ) ) ) {
			return substr_replace( $content, '', $m[0][1], strlen( $m[0][0] ) );
		}
		return $content;
	}

	public function register_assets(): void {
		wp_register_script_module(
			self::HANDLE,
			NOP_INDIEWEB_URL . 'assets/js/browse.js',
			[ [ 'id' => '@wordpress/interactivity' ], [ 'id' => '@wordpress/interactivity-router', 'import' => 'dynamic' ] ],
			(string) filemtime( NOP_INDIEWEB_DIR . 'assets/js/browse.js' )
		);
		wp_interactivity()->add_client_navigation_support_to_script_module( self::HANDLE );
		wp_register_style( self::HANDLE, NOP_INDIEWEB_URL . 'assets/css/overlay.css', [], (string) filemtime( NOP_INDIEWEB_DIR . 'assets/css/overlay.css' ) );
	}

	public function entry_template( array $templates ): array {
		if ( ! self::is_entry_request() ) {
			return $templates;
		}
		$kinds = get_the_terms( get_queried_object_id(), Kind_Taxonomy::TAXONOMY );
		$kind  = $kinds && ! is_wp_error( $kinds ) ? $kinds[0]->slug : '';
		$own   = $kind ? [ 'entry-' . ( self::SHARED[ $kind ] ?? $kind ) ] : [];
		return array_merge( $own, [ 'entry' ], $templates );
	}

	/** Names for the Entry templates in the Site Editor. */
	public function template_names( array $types ): array {
		$names = [
			'entry'          => __( 'Entry (opened from the wall)', 'nop-indieweb' ),
			'entry-checkin'  => __( 'Entry: check-in', 'nop-indieweb' ),
			'entry-exercise' => __( 'Entry: exercise', 'nop-indieweb' ),
			'entry-watch'    => __( 'Entry: watch', 'nop-indieweb' ),
			'entry-listen'   => __( 'Entry: listen', 'nop-indieweb' ),
			'entry-note'     => __( 'Entry: note', 'nop-indieweb' ),
			'entry-quote'    => __( 'Entry: quote', 'nop-indieweb' ),
			'entry-link'     => __( 'Entry: bookmark, like, reply, repost', 'nop-indieweb' ),
			'entry-rsvp'     => __( 'Entry: RSVP', 'nop-indieweb' ),
			'entry-story'    => __( 'Entry: story', 'nop-indieweb' ),
		];
		foreach ( $names as $slug => $title ) {
			$types[ $slug ] = [
				'title'       => $title,
				'description' => __( 'How an entry of this kind looks when it opens over the wall.', 'nop-indieweb' ),
			];
		}
		return $types;
	}

	/** The wall's or entry's <main>, the crumbs beside a wall, and the controls Group. */
	public function mark_group( string $content, array $block ): string {
		$wall  = self::is_wall();
		$entry = ! $wall && self::is_entry();
		if ( ! $wall && ! $entry ) {
			return $content;
		}
		$class = $block['attrs']['className'] ?? '';
		$p     = new \WP_HTML_Tag_Processor( $content );
		if ( ! $p->next_tag() ) {
			return $content;
		}

		if ( str_contains( $class, 'nop-wall-crumbs' ) ) {
			$p->set_attribute( 'data-wp-interactive', 'nop-indieweb/browse' );
			$p->set_attribute( 'data-wp-router-region', 'nop-indieweb/crumbs' );
			return $p->get_updated_html();
		}

		// The controls are their own region, attached to <body> beside the panel, so they sit on the dimmed wall.
		if ( $entry && str_contains( $class, 'nop-entry-nav' ) ) {
			$p->set_attribute( 'data-wp-interactive', 'nop-indieweb/browse' );
			$p->set_attribute( 'data-wp-router-region', wp_json_encode( [ 'id' => 'nop-indieweb/entry-nav', 'attachTo' => 'body' ], JSON_UNESCAPED_SLASHES ) );
			return $p->get_updated_html();
		}

		if ( 'MAIN' !== $p->get_tag() ) {
			return $content;
		}
		wp_enqueue_script_module( self::HANDLE );
		wp_enqueue_style( self::HANDLE );
		do_action( 'nop_indieweb_browse_enqueue' );
		$p->set_attribute( 'data-wp-interactive', 'nop-indieweb/browse' );
		$p->set_attribute( 'tabindex', '-1' );

		if ( $wall ) {
			$p->add_class( 'nop-wall' );
			$p->set_attribute( 'data-wp-router-region', 'nop-indieweb/wall' );
			$p->set_attribute( 'data-wp-watch', 'callbacks.holdFocus' );
			$p->set_attribute( 'data-wp-on--click', 'actions.follow' );
			$p->set_attribute( 'data-wp-on--pointerover', 'actions.prefetch' );
			$p->set_attribute( 'data-wp-on--focusin', 'actions.prefetch' );
			$p->set_attribute( 'data-wp-on-document--keydown', 'actions.closeOnEscape' );
			$p->set_attribute( 'data-wp-on-document--click', 'actions.closeOnScrim' );
			return $p->get_updated_html();
		}

		$p->add_class( 'nop-entry' );
		$p->set_attribute( 'data-wp-router-region', wp_json_encode( [ 'id' => 'nop-indieweb/entry', 'attachTo' => 'body' ], JSON_UNESCAPED_SLASHES ) );
		$p->set_attribute( 'aria-label', get_the_title() );
		$p->set_attribute( 'data-wp-bind--role', 'state.entryRole' );
		$p->set_attribute( 'data-wp-bind--aria-modal', 'state.entryOpen' );
		$p->set_attribute( 'data-wp-on--click', 'actions.entryLink' );
		$kinds = get_the_terms( (int) get_the_ID(), Kind_Taxonomy::TAXONOMY );
		if ( $kinds && ! is_wp_error( $kinds ) ) {
			$p->add_class( 'nop-kind-' . $kinds[0]->slug );
		}
		return $p->get_updated_html();
	}

	/** Previous, Next and Close act on the store. */
	public function mark_button( string $content, array $block ): string {
		$class = $block['attrs']['className'] ?? '';
		if ( ! str_contains( $class, 'nop-entry__' ) || ! self::is_entry() ) {
			return $content;
		}
		$actions = [
			'nop-entry__previous' => [ 'actions.previousEntry', '!state.hasPrevious' ],
			'nop-entry__next'     => [ 'actions.nextEntry', '!state.hasNext' ],
			'nop-entry__close'    => [ 'actions.closeEntry', '' ],
		];
		foreach ( $actions as $name => [ $action, $disabled ] ) {
			if ( ! str_contains( $class, $name ) ) {
				continue;
			}
			$p = new \WP_HTML_Tag_Processor( $content );
			if ( $p->next_tag( [ 'tag_name' => 'BUTTON' ] ) ) {
				$p->set_attribute( 'data-wp-on--click', $action );
				if ( $disabled ) {
					$p->set_attribute( 'data-wp-bind--disabled', $disabled );
				}
			}
			return $p->get_updated_html();
		}
		return $content;
	}
}
