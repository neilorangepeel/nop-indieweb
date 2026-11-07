<?php
/**
 * Look: how a post looks (the theme's STRUCTURE.md). A closed list Neil keeps: a
 * new Look is a decision, added to LOOKS (or the `nop_indieweb_looks` filter),
 * not typed in the editor, so the editor shows it as checkboxes (hierarchical,
 * with no children). Archive at /look/<slug>/; the Logbook filters by it as
 * ?look= (Browse\Wall_Filters).
 */
declare( strict_types=1 );

namespace NOP\IndieWeb\Kind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Look_Taxonomy {

	public const TAXONOMY = 'nop_look';

	/** @return array<string,string> slug => name. */
	public static function looks(): array {
		return (array) apply_filters( 'nop_indieweb_looks', [
			'black-and-white' => __( 'Black & white', 'nop-indieweb' ),
			'grain'           => __( 'Grain', 'nop-indieweb' ),
			'film'            => __( 'Film', 'nop-indieweb' ),
			'shadows-light'   => __( 'Shadows & light', 'nop-indieweb' ),
			'texture'         => __( 'Texture', 'nop-indieweb' ),
			'double-exposure' => __( 'Double exposure', 'nop-indieweb' ),
		] );
	}

	public function register(): void {
		add_action( 'init', [ $this, 'register_taxonomy' ] );
	}

	public function register_taxonomy(): void {
		register_taxonomy( self::TAXONOMY, 'post', [
			'labels'            => [
				'name'          => __( 'Looks', 'nop-indieweb' ),
				'singular_name' => __( 'Look', 'nop-indieweb' ),
				'menu_name'     => __( 'Looks', 'nop-indieweb' ),
			],
			'hierarchical'      => true,
			'show_in_rest'      => true,
			'show_admin_column' => true,
			'rewrite'           => [ 'slug' => 'look' ],
		] );
		// The list is seeded (and /look/ written into the rewrite rules) once per change of the list, not on every request.
		$version = md5( (string) wp_json_encode( self::looks() ) );
		if ( get_option( 'nop_indieweb_looks_seeded' ) === $version ) {
			return;
		}
		foreach ( self::looks() as $slug => $name ) {
			if ( ! term_exists( $slug, self::TAXONOMY ) ) {
				wp_insert_term( $name, self::TAXONOMY, [ 'slug' => $slug ] );
			}
		}
		add_action( 'wp_loaded', static fn() => flush_rewrite_rules( false ) );
		update_option( 'nop_indieweb_looks_seeded', $version, false );
	}
}
