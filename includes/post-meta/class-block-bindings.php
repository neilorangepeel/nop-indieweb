<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Post_Meta;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Block;

/**
 * Registers the nop-indieweb/post-meta Block Bindings source and injects
 * microformat classes onto bound core blocks at render time.
 *
 * Requires WordPress 7.2 (the plugin's minimum: core/icon accepts an `icon` binding from there).
 *
 * Usage — two equivalent forms:
 *
 *   Full meta key:
 *     "source": "nop-indieweb/post-meta",
 *     "args": { "key": "nop_indieweb_venue_locality" }
 *
 *   Venue field shorthand (prepends nop_indieweb_venue_):
 *     "source": "nop-indieweb/post-meta",
 *     "args": { "field": "locality" }
 *
 *   Derived field (computed, not stored in meta):
 *     "args": { "field": "full_address" }            → "46 Great Victoria Street, Belfast, United Kingdom"
 *     "args": { "field": "locality_country" }        → "Belfast, United Kingdom"
 *     "args": { "field": "venue_coordinates" }       → "54.597° N · 5.935° W"
 *     "args": { "field": "venue_url_host_label" }    → "View on foursquare.com"
 *     "args": { "field": "checkin_url_host_label" }  → "View on swarmapp.com"
 *
 * Bindable string keys:
 *   nop_indieweb_venue_name, nop_indieweb_venue_url, nop_indieweb_venue_uid,
 *   nop_indieweb_venue_lat, nop_indieweb_venue_lng, nop_indieweb_venue_altitude,
 *   nop_indieweb_venue_accuracy, nop_indieweb_venue_address,
 *   nop_indieweb_venue_locality, nop_indieweb_venue_region,
 *   nop_indieweb_venue_country, nop_indieweb_venue_postcode,
 *   nop_indieweb_checkin_url, nop_indieweb_service,
 *   nop_indieweb_weather_summary, nop_indieweb_weather_temp_c
 *
 * Array keys (returns item count as a string):
 *   nop_indieweb_syndication, nop_indieweb_photos, nop_indieweb_photo_ids
 */
class Block_Bindings {

	/**
	 * Synthesized meta-key → microformat class for paragraph/heading content
	 * bindings. The "key" is the resolved key after applying the venue_ shorthand.
	 */
	private const KEY_MF2_CLASSES = [
		'nop_indieweb_venue_name'     => 'p-name',
		'nop_indieweb_venue_address'  => 'p-street-address',
		'nop_indieweb_venue_locality' => 'p-locality',
		'nop_indieweb_venue_region'   => 'p-region',
		'nop_indieweb_venue_country'  => 'p-country-name',
		'nop_indieweb_venue_postcode' => 'p-postal-code',
	];

	/**
	 * Derived-field → microformat class for paragraph/heading content
	 * bindings on fields that don't have a backing meta key.
	 */
	private const DERIVED_MF2_CLASSES = [
		// Kind derived fields
		'kind_label'            => '',
		// Icon names for a core/icon block's `icon` binding (WordPress 7.2+), and the weather temperature for a paragraph.
		'kind_icon'             => '',
		'activity_icon'         => '',
		'weather_icon'          => '',
		'weather_temp'          => '',
		'full_address'          => 'p-adr',
		'locality_country'      => '',
		'venue_coordinates'     => '',
		'venue_url_host_label'  => '',
		'checkin_url_host_label' => '',
		'venue_visit_number'    => '',
		// Flight derived fields
		'flight_route'          => '',
		'flight_distance'       => '',
		// Exercise derived fields
		'exercise_distance'     => '',
		'exercise_duration'     => '',
		'exercise_pace'         => '',
		'exercise_speed'        => '',
		'exercise_elevation'    => '',
		'exercise_type_label'   => '',
		'exercise_calories'     => '',
		'exercise_avg_hr'       => '',
		'exercise_max_hr'       => '',
		'exercise_max_speed'    => '',
		'exercise_elevation_range'   => '',
		'exercise_max_grade'         => '',
		'exercise_gear'              => '',
		'exercise_type_archive_link' => '',
		'exercise_personal_best'     => '',
	];

	public function register(): void {
		add_action( 'init', [ $this, 'register_source' ] );
		add_filter( 'render_block', [ $this, 'inject_mf2_classes' ], 10, 2 );
		// WP 6.7+ gates the bindings editor UI behind a separate edit_block_binding
		// cap. Map it to edit_blocks so anyone who can edit block content can also
		// manage bindings — which matches the default behaviour WP intends but
		// which doesn't always land on fresh installs / RC builds.
		add_filter( 'map_meta_cap', [ $this, 'map_edit_block_binding_cap' ], 10, 2 );
		// Editor-side registration so the canvas previews bound paragraphs with
		// the real (or humanised) value rather than the source's generic label.
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
	}

	public function enqueue_editor_assets(): void {
		wp_enqueue_script(
			'nop-indieweb-block-bindings-ui',
			NOP_INDIEWEB_URL . 'assets/js/block-bindings-ui.js',
			[ 'wp-blocks', 'wp-data' ],
			NOP_INDIEWEB_VERSION,
			true
		);
	}

	public function map_edit_block_binding_cap( array $caps, $cap ): array {
		return 'edit_block_binding' === $cap ? [ 'edit_blocks' ] : $caps;
	}

	public function register_source(): void {
		register_block_bindings_source( 'nop-indieweb/post-meta', [
			'label'              => 'IndieWeb Post Meta',
			'get_value_callback' => [ $this, 'get_value' ],
			// Block context (postId, postType) is declared in block.json usesContext —
			// register_block_bindings_source() does not accept uses_context.
		] );
	}

	/**
	 * Preview values shown in the template editor when no real post is available.
	 * Keyed by field shorthand; also covers the resolved meta key for direct-key usage.
	 */
	private const PREVIEW_VALUES = [
		// Venue shorthand fields
		'name'                            => 'The Crown Bar',
		'address'                         => '46 Great Victoria Street',
		'locality'                        => 'Belfast',
		'region'                          => 'County Antrim',
		'country'                         => 'United Kingdom',
		'postcode'                        => 'BT2 7BA',
		'locality_country'                => 'Belfast, United Kingdom',
		'full_address'                    => '46 Great Victoria Street, Belfast, United Kingdom',
		// Derived non-venue fields
		'venue_coordinates'               => '54.597° N · 5.935° W',
		'venue_url_host_label'            => 'View on foursquare.com',
		'checkin_url_host_label'          => 'View on swarmapp.com',
		'venue_visit_number'              => '1st visit',
		'flight_route'                    => 'Belfast International Airport → Newcastle Airport',
		'flight_distance'                 => '294 km',
		// Full-key forms
		'nop_indieweb_venue_name'         => 'The Crown Bar',
		'nop_indieweb_venue_address'      => '46 Great Victoria Street',
		'nop_indieweb_venue_locality'     => 'Belfast',
		'nop_indieweb_venue_country'      => 'United Kingdom',
		'nop_indieweb_weather_summary'    => 'Light Rain',
		// Exercise derived previews
		'exercise_distance'               => '5.2 km',
		'exercise_duration'               => '32:15',
		'exercise_pace'                   => '6:12 /km',
		'exercise_speed'                  => '22.4 km/h',
		'exercise_elevation'              => '+145 m',
		'exercise_type_label'             => 'Run',
		'exercise_calories'               => '415 kcal',
		'exercise_avg_hr'                 => '152 bpm',
		'exercise_max_hr'                 => '178 bpm',
		'exercise_max_speed'              => '31.0 km/h',
		'exercise_elevation_range'        => '1–33 m',
		'exercise_max_grade'              => '26.0%',
		'exercise_gear'                   => 'Vitus Zenium',
		'exercise_type_archive_link'      => '<a href="#">Run</a>',
		'exercise_personal_best'          => 'Longest run',
		'kind_label'                      => '<a href="#">Liked</a>',
		'kind_icon'                       => 'phosphor/kind-note',
		'activity_icon'                   => 'phosphor/exercise-run',
		'weather_icon'                    => 'phosphor/weather-cloud',
		'weather_temp'                    => '12°C',
		// Entry fields (Entry_Fields) previews
		'subtitle'                        => 'Ormeau Rd',
		'detail'                          => '258th visit',
		'display_title'                   => 'Ormeau Park',
		'post_number'                     => '#4795',
		'day'                             => '8 Oct 2026',
		'date_time'                       => '8 Oct 2026 · 13:24',
		'source_url'                      => '#',
		'source_label'                    => 'Swarm',
		'cite_title'                      => 'Multi-page web apps',
		'cite_excerpt'                    => 'A question via email…',
		'cite_author'                     => 'Jeremy Keith',
		'cite_site'                       => 'adactio.com',
		'venue_category'                  => 'Park',
		'visit_label'                     => '258th visit',
		'weather_label'                   => '11°C · Light Rain',
		'exercise_summary'                => '3km Run',
		'watch_released'                  => '1920',
		'watch_date'                      => '28 Mar 2025',
		'listen_artist_year'              => 'The Strokes · 2011',
		'rsvp_answer'                     => 'Going',
		'rsvp_event'                      => 'IndieWebCamp',
		'rsvp_when'                       => '12 Oct 2026',
		'rsvp_time'                       => '10:00',
		'rsvp_where'                      => 'Brighton',
		'quote_text'                      => '“Have nothing in your house that you do not know to be useful.”',
		'quote_body'                      => 'Have nothing in your house that you do not know to be useful.',
		'quote_author'                    => 'William Morris',
		'reply_count_label'               => '2 replies',
	];

	public function get_value( array $source_args, WP_Block $block ): ?string {
		$post_id = $block->context['postId'] ?? get_the_ID();
		$field   = sanitize_key( $source_args['field'] ?? '' );

		// Derived fields handled by name before the venue_ shorthand kicks in.
		if ( $post_id && $field ) {
			$derived = $this->get_derived_value( $field, (int) $post_id, $source_args );
			if ( null !== $derived ) {
				return $derived;
			}
		}

		// Venue field shorthand: field="locality" → nop_indieweb_venue_locality.
		// Otherwise honour an explicit key.
		if ( $field && ! isset( $source_args['key'] ) && ! isset( self::DERIVED_MF2_CLASSES[ $field ] ) ) {
			$key = 'nop_indieweb_venue_' . $field;
		} else {
			$key = sanitize_key( $source_args['key'] ?? '' );
		}

		if ( $post_id && $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( $value ) {
				return is_array( $value ) ? (string) count( $value ) : (string) $value;
			}
		}

		// No value found. In the editor (REST request with context=edit), return
		// a representative preview string so the block shows meaningful placeholder
		// content rather than the binding source label "IndieWeb Post Meta".
		$is_editor = defined( 'REST_REQUEST' ) && REST_REQUEST
			&& isset( $_GET['context'] ) && 'edit' === $_GET['context']; // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $post_id || $is_editor ) {
			return self::PREVIEW_VALUES[ $field ]
				?? self::PREVIEW_VALUES[ $key ]
				?? $this->humanize_placeholder( $field, $key );
		}

		// Return empty string (not null) so WordPress overrides the block's static
		// fallback HTML — null leaves the placeholder text intact on the front end.
		return '';
	}

	/**
	 * Last-resort placeholder text shown in the editor when a field has no
	 * specific preview value. Turns "venue_coordinates" into "Venue coordinates"
	 * and "nop_indieweb_weather_temp_c" into "Weather temp c" — always
	 * something more informative than the generic source label.
	 */
	private function humanize_placeholder( string $field, string $key ): string {
		$raw = $field ?: preg_replace( '/^nop_indieweb_/', '', $key );
		return ucfirst( str_replace( '_', ' ', (string) $raw ) ) ?: 'Venue data';
	}

	/**
	 * Resolves a derived field to its computed string, or null if the field
	 * isn't a derived one (in which case the caller falls through to normal
	 * meta lookup).
	 */
	private function get_derived_value( string $field, int $post_id, array $args = [] ): ?string {
		// Exercise stat formatting lives in one shared helper. Return '' (not null)
		// for absent stats so the bound paragraph renders truly empty — letting the
		// template hide it with CSS :has(:empty) rather than keeping a placeholder.
		// Ready-to-read entry facts (subtitle, detail, visit_label, rsvp_answer…): Entry_Fields.
		$entry = Entry_Fields::value( $field, $post_id );
		if ( null !== $entry ) {
			return $entry;
		}

		if ( str_starts_with( $field, 'exercise_' ) ) {
			return \NOP\IndieWeb\nop_indieweb_exercise_stat( $field, $post_id ) ?? '';
		}

		switch ( $field ) {
			// The icon for the post's kind: `phosphor/kind-<slug>`. Exercise is passed on (its own icon says the activity: a run, a swim),
			// and a kind with no mark gives '' so the Icon block renders nothing.
			case 'kind_icon': {
				wp_enqueue_style( 'nop-blocks-shared' ); // The icon's size and alignment live there; core/icon is not a plugin block, so rendering it would not load it.
				$terms = get_the_terms( $post_id, \NOP\IndieWeb\Kind\Kind_Taxonomy::TAXONOMY );
				$slug  = ( $terms && ! is_wp_error( $terms ) ) ? (string) reset( $terms )->slug : '';
				return ( '' !== $slug && 'exercise' !== $slug && '' !== \NOP\IndieWeb\Kind\Kind_Icons::path( $slug ) ) ? 'phosphor/kind-' . $slug : '';
			}

			// The activity's icon: `phosphor/exercise-<type>`, from the exercise type term (or the stored type on a post not yet termed).
			case 'activity_icon': {
				wp_enqueue_style( 'nop-blocks-shared' );
				$terms = get_the_terms( $post_id, \NOP\IndieWeb\Kind\Exercise_Type_Taxonomy::TAXONOMY );
				$type  = ( $terms && ! is_wp_error( $terms ) ) ? (string) reset( $terms )->slug : sanitize_key( (string) get_post_meta( $post_id, 'nop_indieweb_exercise_type', true ) );
				return ( '' !== $type && is_readable( NOP_INDIEWEB_DIR . 'assets/icons/phosphor/exercise-' . $type . '.svg' ) ) ? 'phosphor/exercise-' . $type : '';
			}

			// The conditions' icon: Pirate Weather's vocabulary onto the Phosphor marks. Sleet and snow share cloud-snow (Phosphor has
			// no sleet glyph; the summary carries the exact wording). An unknown condition is a plain cloud.
			case 'weather_icon': {
				wp_enqueue_style( 'nop-blocks-shared' );
				$slug = sanitize_key( (string) get_post_meta( $post_id, 'nop_indieweb_weather_icon', true ) );
				if ( '' === $slug ) {
					return '';
				}
				$map = [
					'clear-day' => 'sun', 'clear-night' => 'moon', 'partly-cloudy-day' => 'cloud-sun', 'partly-cloudy-night' => 'cloud-moon',
					'cloudy' => 'cloud', 'fog' => 'cloud-fog', 'rain' => 'cloud-rain', 'sleet' => 'cloud-snow', 'snow' => 'cloud-snow', 'wind' => 'wind',
				];
				return 'phosphor/weather-' . ( $map[ $slug ] ?? 'cloud' );
			}

			// The temperature to the degree: args.unit "c" (default) or "f"; args.symbol false leaves off the "°C".
			case 'weather_temp': {
				$unit = 'f' === ( $args['unit'] ?? 'c' ) ? 'f' : 'c';
				$raw  = (string) get_post_meta( $post_id, 'nop_indieweb_weather_temp_' . $unit, true );
				if ( '' === $raw ) {
					return '';
				}
				return (string) (int) round( (float) $raw ) . ( false === ( $args['symbol'] ?? true ) ? '' : ( 'f' === $unit ? '°F' : '°C' ) );
			}

			// The post's kind, in its reading voice, linked to its archive.
			//
			// Returns markup on purpose: WP_Block::replace_html() passes a bound
			// value through wp_kses_post() before splicing it in, so an anchor
			// survives. That is what lets one binding replace the hardcoded
			// <a href="/kind/like/">Liked</a> that each of the fourteen kind
			// templates carried — English that no translation could reach, and a
			// root-relative href that broke outside a root install.
			//
			// get_term_link() means the URL follows the taxonomy's rewrite slug
			// and the site's own path, rather than assuming both.
			case 'kind_label': {
				$terms = get_the_terms( $post_id, \NOP\IndieWeb\Kind\Kind_Taxonomy::TAXONOMY );
				if ( is_wp_error( $terms ) || ! $terms ) {
					return null;
				}
				$term  = $terms[0];
				$label = \NOP\IndieWeb\Kind\Kind_Taxonomy::eyebrow_labels()[ $term->slug ] ?? $term->name;

				$url = get_term_link( $term );
				if ( is_wp_error( $url ) ) {
					return esc_html( $label );
				}
				return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
			}

			case 'full_address':
				$parts = array_filter( [
					get_post_meta( $post_id, 'nop_indieweb_venue_address',  true ),
					get_post_meta( $post_id, 'nop_indieweb_venue_locality', true ),
					get_post_meta( $post_id, 'nop_indieweb_venue_country',  true ),
				] );
				return $parts ? implode( ', ', $parts ) : null;

			case 'locality_country':
				$parts = array_filter( [
					get_post_meta( $post_id, 'nop_indieweb_venue_locality', true ),
					get_post_meta( $post_id, 'nop_indieweb_venue_country',  true ),
				] );
				return $parts ? implode( ', ', $parts ) : null;

			case 'venue_coordinates':
				$lat = (float) get_post_meta( $post_id, 'nop_indieweb_venue_lat', true );
				$lng = (float) get_post_meta( $post_id, 'nop_indieweb_venue_lng', true );
				if ( ! $lat && ! $lng ) {
					return null;
				}
				return sprintf(
					'%s° %s · %s° %s',
					number_format( abs( $lat ), 3 ),
					$lat >= 0 ? 'N' : 'S',
					number_format( abs( $lng ), 3 ),
					$lng >= 0 ? 'E' : 'W'
				);

			case 'venue_url_host_label':
				$url = (string) get_post_meta( $post_id, 'nop_indieweb_venue_url', true );
				if ( '' === $url ) {
					return null;
				}
				$host = wp_parse_url( $url, PHP_URL_HOST ) ?: $url;
				return sprintf( 'View on %s', $host );

			case 'checkin_url_host_label':
				$url = (string) get_post_meta( $post_id, 'nop_indieweb_checkin_url', true );
				if ( '' === $url ) {
					return null;
				}
				$host = wp_parse_url( $url, PHP_URL_HOST ) ?: $url;
				return sprintf( 'View on %s', $host );

			case 'venue_visit_number':
				$n = (int) get_post_meta( $post_id, 'nop_indieweb_venue_visit_number', true );
				if ( ! $n ) {
					return null;
				}
				/* translators: %s = ordinal number, e.g. "1st" */
				return sprintf( __( '%s visit', 'nop-indieweb' ), \NOP\IndieWeb\nop_indieweb_ordinal( $n ) );

			case 'flight_route':
				$from = (string) get_post_meta( $post_id, 'nop_indieweb_flight_from_name', true );
				if ( '' === $from ) {
					return null;
				}
				$to = (string) get_post_meta( $post_id, 'nop_indieweb_flight_to_name', true );
				if ( '' === $to ) {
					$to = (string) get_post_meta( $post_id, 'nop_indieweb_venue_name', true );
				}
				return $from . ' → ' . $to;

			case 'flight_distance':
				$from = (string) get_post_meta( $post_id, 'nop_indieweb_flight_from_name', true );
				$km   = (string) get_post_meta( $post_id, 'nop_indieweb_flight_distance_km', true );
				if ( '' === $from || '' === $km ) {
					return null;
				}
				/* translators: %s = distance in kilometres */
				return sprintf( __( '%s km', 'nop-indieweb' ), number_format( (int) $km ) );

		}

		return null;
	}

	/**
	 * Injects microformat classes onto the rendered output of bound core
	 * blocks. Dispatches per block type — different elements (paragraph
	 * wrapper, button anchor, post-terms anchors) need different class
	 * placements. Runs frontend-only (render_block fires on output).
	 */
	public function inject_mf2_classes( string $html, array $block ): string {
		$block_name = $block['blockName'] ?? '';

		switch ( $block_name ) {
			case 'core/paragraph':
			case 'core/heading':
				return $this->inject_content_mf2( $html, $block );
			case 'core/button':
				return $this->inject_button_mf2( $html, $block );
			case 'core/post-terms':
				return $this->inject_post_terms_mf2( $html, $block );
		}

		return $html;
	}

	/**
	 * Paragraph / heading: inject the mf2 class on the wrapper element when
	 * the `content` attribute is bound to one of our mapped venue fields.
	 */
	private function inject_content_mf2( string $html, array $block ): string {
		$binding = $block['attrs']['metadata']['bindings']['content'] ?? null;
		if ( ! $binding || ! $this->is_our_source( $binding['source'] ?? '' ) ) {
			return $html;
		}

		$class = $this->resolve_content_mf2_class( $binding['args'] ?? [] );
		if ( '' === $class ) {
			return $html;
		}

		return $this->prepend_class_to_first_tag( $html, $class );
	}

	/**
	 * Button: inject `u-url` on the inner `<a>` when the `url` attribute is
	 * bound to a venue URL or checkin URL.
	 */
	private function inject_button_mf2( string $html, array $block ): string {
		$binding = $block['attrs']['metadata']['bindings']['url'] ?? null;
		if ( ! $binding || ! $this->is_our_source( $binding['source'] ?? '' ) ) {
			return $html;
		}

		$key = $this->resolve_key( $binding['args'] ?? [] );
		if ( ! in_array( $key, [ 'nop_indieweb_venue_url', 'nop_indieweb_checkin_url' ], true ) ) {
			return $html;
		}

		return $this->add_class_to_first_anchor( $html, 'u-url' );
	}

	/**
	 * Post Terms: inject `p-category` on each term link when the block is
	 * rendering the nop_venue_category taxonomy.
	 */
	private function inject_post_terms_mf2( string $html, array $block ): string {
		$taxonomy = $block['attrs']['term'] ?? '';
		if ( 'nop_venue_category' !== $taxonomy ) {
			return $html;
		}

		return preg_replace_callback(
			'/<a\b([^>]*)>/i',
			static function ( $m ) {
				$attrs = $m[1];
				if ( preg_match( '/\sclass="([^"]*)"/i', $attrs, $cm ) ) {
					$attrs = preg_replace(
						'/\sclass="[^"]*"/i',
						' class="p-category ' . $cm[1] . '"',
						$attrs,
						1
					);
				} else {
					$attrs .= ' class="p-category"';
				}
				return '<a' . $attrs . '>';
			},
			$html
		) ?? $html;
	}

	private function is_our_source( string $source ): bool {
		return in_array( $source, [ 'nop-indieweb/post-meta', 'core/post-meta' ], true );
	}

	private function resolve_key( array $args ): string {
		$field = sanitize_key( $args['field'] ?? '' );
		if ( $field && ! isset( $args['key'] ) ) {
			return 'nop_indieweb_venue_' . $field;
		}
		return sanitize_key( $args['key'] ?? '' );
	}

	private function resolve_content_mf2_class( array $args ): string {
		$field = sanitize_key( $args['field'] ?? '' );

		// Derived fields are mapped by field name.
		if ( $field && isset( self::DERIVED_MF2_CLASSES[ $field ] ) ) {
			return self::DERIVED_MF2_CLASSES[ $field ];
		}

		$key = $this->resolve_key( $args );
		return self::KEY_MF2_CLASSES[ $key ] ?? '';
	}

	/**
	 * Prepends a class to the first opening tag's class attribute, or adds a
	 * class attribute if none exists. The `[^>]*` is permissive enough to
	 * match tags where `class="` follows immediately (e.g. `<p class="...">`)
	 * as well as ones with other attributes between the tag name and class.
	 */
	private function prepend_class_to_first_tag( string $html, string $class ): string {
		$replaced = preg_replace( '/(<\w[^>]*\sclass=")/', '$1' . $class . ' ', $html, 1, $count );
		if ( $count ) {
			return $replaced;
		}
		return preg_replace( '/(<\w+)(\s|>)/', '$1 class="' . $class . '"$2', $html, 1 ) ?? $html;
	}

	/**
	 * Adds a class to the first `<a>` tag in the HTML.
	 */
	private function add_class_to_first_anchor( string $html, string $class ): string {
		// Anchor with existing class attribute.
		$replaced = preg_replace( '/(<a\b[^>]*\sclass=")/i', '$1' . $class . ' ', $html, 1, $count );
		if ( $count ) {
			return $replaced;
		}
		// Anchor without class attribute.
		return preg_replace( '/(<a\b)(\s|>)/i', '$1 class="' . $class . '"$2', $html, 1 ) ?? $html;
	}
}
