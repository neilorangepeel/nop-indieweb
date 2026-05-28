<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Kind;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the nop_venue_category taxonomy for Foursquare venue categories.
 *
 * Replaces the nop_indieweb_venue_categories serialised meta array.
 * Terms are set by the Swarm importer via wp_set_object_terms().
 */
class Venue_Category_Taxonomy {

	const TAXONOMY = 'nop_venue_category';

	public function register(): void {
		add_action( 'init', [ $this, 'register_taxonomy' ] );
	}

	public function register_taxonomy(): void {
		register_taxonomy( self::TAXONOMY, 'post', [
			'label'              => __( 'Venue Category', 'nop-indieweb' ),
			'labels'             => [
				'name'                       => __( 'Venue Categories', 'nop-indieweb' ),
				'singular_name'              => __( 'Venue Category', 'nop-indieweb' ),
				'search_items'               => __( 'Search Venue Categories', 'nop-indieweb' ),
				'all_items'                  => __( 'All Venue Categories', 'nop-indieweb' ),
				'edit_item'                  => __( 'Edit Venue Category', 'nop-indieweb' ),
				'update_item'                => __( 'Update Venue Category', 'nop-indieweb' ),
				'add_new_item'               => __( 'Add New Venue Category', 'nop-indieweb' ),
				'new_item_name'              => __( 'New Venue Category Name', 'nop-indieweb' ),
				'not_found'                  => __( 'No venue categories found.', 'nop-indieweb' ),
				'no_terms'                   => __( 'No venue categories', 'nop-indieweb' ),
				'items_list'                 => __( 'Venue categories list', 'nop-indieweb' ),
				'items_list_navigation'      => __( 'Venue categories list navigation', 'nop-indieweb' ),
				'back_to_items'              => __( '← Go to Venue Categories', 'nop-indieweb' ),
			],
			'hierarchical'       => false,
			'show_in_rest'       => true,
			'show_ui'            => true,
			'show_admin_column'  => false,
			'show_in_nav_menus'  => false,
			'rewrite'            => [ 'slug' => 'venue-category', 'with_front' => false ],
			'query_var'          => true,
		] );
	}
}
