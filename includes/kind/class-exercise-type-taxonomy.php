<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Kind;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the nop_exercise_type taxonomy for exercise activity types.
 *
 * Terms are pre-seeded from the canonical type slug → label map and assigned
 * automatically whenever nop_indieweb_exercise_type meta is written, covering
 * all creation paths (HAE endpoint, Strava import, Micropub service) without
 * changes to each caller.
 */
class Exercise_Type_Taxonomy {

	const TAXONOMY = 'nop_exercise_type';

	const TYPES = [
		'run'      => 'Run',
		'ride'     => 'Ride',
		'swim'     => 'Swim',
		'walk'     => 'Walk',
		'hike'     => 'Hike',
		'strength' => 'Strength',
		'yoga'     => 'Yoga',
		'pilates'  => 'Pilates',
		'rowing'   => 'Rowing',
		'workout'  => 'Workout',
	];

	const DESCRIPTIONS = [
		'run'      => 'Running — road, trail, or track.',
		'ride'     => 'Cycling — road, gravel, or mountain bike.',
		'swim'     => 'Swimming — pool or open water.',
		'walk'     => 'Walking — casual or brisk.',
		'hike'     => 'Hiking — off-road or hill walking.',
		'strength' => 'Strength training — weights, resistance, or bodyweight.',
		'yoga'     => 'Yoga — any style or duration.',
		'pilates'  => 'Pilates — mat or reformer.',
		'rowing'   => 'Rowing — on water or ergometer.',
		'workout'  => 'General workout — gym session or mixed activity.',
	];

	public function register(): void {
		add_action( 'init', [ $this, 'register_taxonomy' ] );
		add_action( 'added_post_meta',   [ $this, 'sync_from_meta' ], 10, 4 );
		add_action( 'updated_post_meta', [ $this, 'sync_from_meta' ], 10, 4 );
	}

	public function register_taxonomy(): void {
		register_taxonomy( self::TAXONOMY, 'post', [
			'label'             => __( 'Exercise Type', 'nop-indieweb' ),
			'labels'            => [
				'name'                  => __( 'Exercise Types', 'nop-indieweb' ),
				'singular_name'         => __( 'Exercise Type', 'nop-indieweb' ),
				'search_items'          => __( 'Search Exercise Types', 'nop-indieweb' ),
				'all_items'             => __( 'All Exercise Types', 'nop-indieweb' ),
				'edit_item'             => __( 'Edit Exercise Type', 'nop-indieweb' ),
				'update_item'           => __( 'Update Exercise Type', 'nop-indieweb' ),
				'add_new_item'          => __( 'Add New Exercise Type', 'nop-indieweb' ),
				'new_item_name'         => __( 'New Exercise Type Name', 'nop-indieweb' ),
				'not_found'             => __( 'No exercise types found.', 'nop-indieweb' ),
				'no_terms'              => __( 'No exercise types', 'nop-indieweb' ),
				'items_list'            => __( 'Exercise types list', 'nop-indieweb' ),
				'items_list_navigation' => __( 'Exercise types list navigation', 'nop-indieweb' ),
				'back_to_items'         => __( '← Go to Exercise Types', 'nop-indieweb' ),
			],
			'hierarchical'      => false,
			'show_in_rest'      => true,
			'show_ui'           => true,
			'show_admin_column' => false,
			'show_in_nav_menus' => false,
			'rewrite'           => [ 'slug' => 'exercise-type', 'with_front' => false ],
			'query_var'         => true,
		] );
		$this->seed_terms();
	}

	/**
	 * Assigns the exercise type term whenever the nop_indieweb_exercise_type
	 * meta key is written. Fires on both added_post_meta and updated_post_meta
	 * so all creation and update paths are covered without modifying each caller.
	 *
	 * @param int|string $meta_id   Unused meta row ID.
	 * @param int        $post_id   The post being updated.
	 * @param string     $meta_key  The meta key being written.
	 * @param mixed      $meta_value The new meta value.
	 */
	public function sync_from_meta( $meta_id, int $post_id, string $meta_key, $meta_value ): void {
		if ( 'nop_indieweb_exercise_type' !== $meta_key ) {
			return;
		}
		$type = sanitize_key( (string) $meta_value );
		if ( '' === $type ) {
			return;
		}
		if ( ! term_exists( $type, self::TAXONOMY ) ) {
			wp_insert_term( ucfirst( $type ), self::TAXONOMY, [ 'slug' => $type ] );
		}
		wp_set_object_terms( $post_id, $type, self::TAXONOMY );
	}

	private function seed_terms(): void {
		foreach ( self::TYPES as $slug => $name ) {
			if ( ! term_exists( $slug, self::TAXONOMY ) ) {
				wp_insert_term( $name, self::TAXONOMY, [ 'slug' => $slug ] );
			}
			$this->set_description( $slug );
		}
	}

	private function set_description( string $slug ): void {
		$description = self::DESCRIPTIONS[ $slug ] ?? '';
		if ( ! $description ) {
			return;
		}
		$term = get_term_by( 'slug', $slug, self::TAXONOMY );
		if ( $term instanceof \WP_Term && $term->description !== $description ) {
			wp_update_term( $term->term_id, self::TAXONOMY, [ 'description' => $description ] );
		}
	}
}
