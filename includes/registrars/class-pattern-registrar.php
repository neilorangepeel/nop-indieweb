<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Registrars;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's block patterns (checkin/exercise data palettes,
 * venue helpers, note layout) under the IndieWeb pattern category.
 */
class Pattern_Registrar {

	public function register(): void {
		add_action( 'init', [ $this, 'register_patterns' ] );
		// Extend the core Query Loop used by the Stories rail pattern: scope it to the
		// story kind and the last 24h. Keyed on the query's `namespace` so it only
		// touches that one loop — every other Query Loop is left untouched.
		add_filter( 'query_loop_block_query_vars', [ $this, 'stories_rail_query_vars' ], 10, 2 );
	}

	/**
	 * @param array<string,mixed> $query
	 * @param \WP_Block            $block
	 * @return array<string,mixed>
	 */
	public function stories_rail_query_vars( array $query, $block ): array {
		$namespace = $block->context['query']['namespace'] ?? '';
		if ( 'nop-indieweb/stories-rail' !== $namespace ) {
			return $query;
		}
		$query['post_type'] = 'post';
		$query['tax_query'] = [
			[
				'taxonomy' => \NOP\IndieWeb\Kind\Kind_Taxonomy::TAXONOMY,
				'field'    => 'slug',
				'terms'    => 'story',
			],
		];
		$query['date_query'] = [
			[
				'after'     => '24 hours ago',
				'inclusive' => true,
			],
		];
		return $query;
	}

	public function register_patterns(): void {
		register_block_pattern_category(
			'nop-indieweb',
			[ 'label' => __( 'IndieWeb', 'nop-indieweb' ) ]
		);

		register_block_pattern( 'nop-indieweb/checkin-post', [
			'title'         => __( 'Checkin Post', 'nop-indieweb' ),
			'description'   => __( 'Granular venue blocks (categories, name, address, coordinates, map, venue link) bound to checkin post meta. Each block is individually styleable.', 'nop-indieweb' ),
			'categories'    => [ 'nop-indieweb' ],
			'keywords'      => [ 'checkin', 'swarm', 'venue', 'location', 'indieweb' ],
			'viewportWidth' => 800,
			'content'       => <<<'HTML'
<!-- wp:group {"style":{"spacing":{"blockGap":"1rem"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group">

<!-- wp:post-terms {"term":"nop_venue_category","separator":" · "} /-->

<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"name"}}}}} -->
<p>Venue name</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"address"}}}}} -->
<p>Street address</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"locality_country"}}}}} -->
<p>Locality, country</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"venue_coordinates"}}}}} -->
<p>Coordinates</p>
<!-- /wp:paragraph -->

<!-- wp:nop-indieweb/checkin-map /-->

<!-- wp:buttons -->
<div class="wp-block-buttons">
<!-- wp:button {"metadata":{"bindings":{"url":{"source":"nop-indieweb/post-meta","args":{"field":"url"}},"text":{"source":"nop-indieweb/post-meta","args":{"field":"venue_url_host_label"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#" target="_blank" rel="noopener noreferrer">View venue</a></div>
<!-- /wp:button -->

<!-- wp:button {"metadata":{"bindings":{"url":{"source":"nop-indieweb/post-meta","args":{"key":"nop_indieweb_checkin_url"}},"text":{"source":"nop-indieweb/post-meta","args":{"field":"checkin_url_host_label"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#" target="_blank" rel="noopener noreferrer">View checkin</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->

<!-- wp:post-date {"format":"j F Y, g:i a","isLink":false} /-->

</div>
<!-- /wp:group -->
HTML,
		] );

		register_block_pattern( 'nop-indieweb/venue-address', [
			'title'         => __( 'Venue Address', 'nop-indieweb' ),
			'description'   => __( 'Single paragraph bound to the full venue address (street, locality, country). Emits p-adr microformat class.', 'nop-indieweb' ),
			'categories'    => [ 'nop-indieweb' ],
			'keywords'      => [ 'venue', 'address', 'checkin', 'indieweb' ],
			'viewportWidth' => 600,
			'content'       => <<<'HTML'
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"full_address"}}}},"fontSize":"small"} -->
<p class="has-small-font-size">Address</p>
<!-- /wp:paragraph -->
HTML,
		] );

		register_block_pattern( 'nop-indieweb/venue-link-button', [
			'title'         => __( 'Venue Link Button', 'nop-indieweb' ),
			'description'   => __( 'Button linking to the Foursquare venue page. URL and label text both bind to post meta. Emits u-url on the anchor.', 'nop-indieweb' ),
			'categories'    => [ 'nop-indieweb' ],
			'keywords'      => [ 'venue', 'link', 'button', 'foursquare', 'checkin' ],
			'viewportWidth' => 400,
			'content'       => <<<'HTML'
<!-- wp:buttons -->
<div class="wp-block-buttons">
<!-- wp:button {"metadata":{"bindings":{"url":{"source":"nop-indieweb/post-meta","args":{"field":"url"}},"text":{"source":"nop-indieweb/post-meta","args":{"field":"venue_url_host_label"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#" target="_blank" rel="noopener noreferrer">View venue</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
HTML,
		] );

		register_block_pattern( 'nop-indieweb/checkin-meta-strip', [
			'title'         => __( 'Checkin Meta Strip', 'nop-indieweb' ),
			'description'   => __( 'Horizontal header row for a checkin: location pin, "Check-in" label, venue categories, and the post date. Designed to sit above the venue title.', 'nop-indieweb' ),
			'categories'    => [ 'nop-indieweb' ],
			'keywords'      => [ 'checkin', 'header', 'meta', 'categories' ],
			'viewportWidth' => 800,
			'content'       => <<<'HTML'
<!-- wp:group {"style":{"typography":{"fontStyle":"normal","fontWeight":"700","textTransform":"uppercase","letterSpacing":"2px"},"spacing":{"blockGap":"6px"}},"fontSize":"small","layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->
<div class="wp-block-group has-small-font-size" style="font-style:normal;font-weight:700;letter-spacing:2px;text-transform:uppercase">
<!-- wp:group {"style":{"spacing":{"blockGap":"0.5em"}},"layout":{"type":"flex","flexWrap":"wrap"}} -->
<div class="wp-block-group">
<!-- wp:icon {"icon":"core/map-marker","style":{"dimensions":{"width":"1em"}}} /-->
<!-- wp:paragraph -->
<p>Check-in</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p>·</p>
<!-- /wp:paragraph -->
<!-- wp:post-terms {"term":"nop_venue_category","separator":" "} /-->
</div>
<!-- /wp:group -->
<!-- wp:post-date {"format":"G:i · d.m.Y"} /-->
</div>
<!-- /wp:group -->
HTML,
		] );

		register_block_pattern( 'nop-indieweb/weather-row', [
			'title'         => __( 'Weather Row', 'nop-indieweb' ),
			'description'   => __( 'Weather icon + temperature for a checkin, sourced from the snapshotted weather meta. Use beside the venue address.', 'nop-indieweb' ),
			'categories'    => [ 'nop-indieweb' ],
			'keywords'      => [ 'weather', 'checkin', 'temperature' ],
			'viewportWidth' => 400,
			'content'       => <<<'HTML'
<!-- wp:group {"style":{"spacing":{"blockGap":"6px"}},"fontSize":"small","layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
<div class="wp-block-group has-small-font-size">
<!-- wp:nop-indieweb/weather-icon {"fontSize":"small"} /-->
<!-- wp:nop-indieweb/weather-temp {"fontSize":"small"} /-->
</div>
<!-- /wp:group -->
HTML,
		] );

		register_block_pattern( 'nop-indieweb/checkin-data-palette', [
			'title'         => __( 'Checkin Data Palette', 'nop-indieweb' ),
			'description'   => __( 'Every meaningful piece of data a check-in post carries — bindings + custom blocks, each labelled. Insert this into a checkin post, then copy whichever pieces you want into your real layout. Not for production use.', 'nop-indieweb' ),
			'categories'    => [ 'nop-indieweb' ],
			'keywords'      => [ 'checkin', 'data', 'palette', 'reference', 'design' ],
			'viewportWidth' => 900,
			'content'       => <<<'HTML'
<!-- wp:group {"metadata":{"name":"Checkin Data Palette"},"style":{"spacing":{"blockGap":"2.5rem","padding":{"top":"2rem","bottom":"2rem","left":"2rem","right":"2rem"}},"border":{"width":"1px","color":"#e5e7eb"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group has-border-color" style="border-color:#e5e7eb;border-width:1px;padding-top:2rem;padding-right:2rem;padding-bottom:2rem;padding-left:2rem">

<!-- wp:heading {"level":2,"fontSize":"x-large"} -->
<h2 class="wp-block-heading has-x-large-font-size">Checkin Data Palette</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"style":{"color":{"text":"#6b7280"}}} -->
<p class="has-text-color" style="color:#6b7280">Every piece of data this checkin carries, grouped by section. Copy any block into your real layout.</p>
<!-- /wp:paragraph -->

<!-- wp:separator {"backgroundColor":"accent-6"} --><hr class="wp-block-separator has-text-color has-accent-6-color has-alpha-channel-opacity has-accent-6-background-color has-background"/><!-- /wp:separator -->

<!-- wp:heading {"level":4,"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.1em","fontSize":"0.75rem"},"color":{"text":"#6b7280"}}} -->
<h4 class="wp-block-heading has-text-color" style="color:#6b7280;font-size:0.75rem;letter-spacing:0.1em;text-transform:uppercase">Identity</h4>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-title (core)</p><!-- /wp:paragraph -->
<!-- wp:post-title /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-date (core)</p><!-- /wp:paragraph -->
<!-- wp:post-date {"format":"j F Y, G:i"} /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-author (core)</p><!-- /wp:paragraph -->
<!-- wp:post-author {"showAvatar":false} /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-terms · nop_kind (core)</p><!-- /wp:paragraph -->
<!-- wp:post-terms {"term":"nop_kind"} /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-terms · category (core)</p><!-- /wp:paragraph -->
<!-- wp:post-terms {"term":"category"} /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-terms · post_tag (core)</p><!-- /wp:paragraph -->
<!-- wp:post-terms {"term":"post_tag"} /-->

<!-- wp:separator {"backgroundColor":"accent-6"} --><hr class="wp-block-separator has-text-color has-accent-6-color has-alpha-channel-opacity has-accent-6-background-color has-background"/><!-- /wp:separator -->

<!-- wp:heading {"level":4,"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.1em","fontSize":"0.75rem"},"color":{"text":"#6b7280"}}} -->
<h4 class="wp-block-heading has-text-color" style="color:#6b7280;font-size:0.75rem;letter-spacing:0.1em;text-transform:uppercase">Venue</h4>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">venue name (binding: field=name) — adds p-name</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"name"}}}}} --><p>The Crown Bar</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">full address (binding: field=full_address, derived) — adds p-adr</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"full_address"}}}}} --><p>46 Great Victoria Street, Belfast, United Kingdom</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">street address (binding: field=address) — adds p-street-address</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"address"}}}}} --><p>46 Great Victoria Street</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">locality (binding: field=locality) — adds p-locality</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"locality"}}}}} --><p>Belfast</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">region (binding: field=region) — adds p-region</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"region"}}}}} --><p>County Antrim</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">country (binding: field=country) — adds p-country-name</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"country"}}}}} --><p>United Kingdom</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">postcode (binding: field=postcode) — adds p-postal-code</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"postcode"}}}}} --><p>BT2 7BA</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">locality + country (binding: field=locality_country, derived)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"locality_country"}}}}} --><p>Belfast, United Kingdom</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">coordinates (binding: field=venue_coordinates, derived)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"venue_coordinates"}}}}} --><p>54.597 ° N · 5.935 ° W</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">latitude (binding: field=lat)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"lat"}}}}} --><p>54.5967</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">longitude (binding: field=lng)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"lng"}}}}} --><p>-5.9347</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">visit number (binding: field=venue_visit_number, derived) — "1st visit", "2nd visit", etc.</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"venue_visit_number"}}}}} --><p>1st Visit</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-terms · nop_venue_category (core) — adds p-category</p><!-- /wp:paragraph -->
<!-- wp:post-terms {"term":"nop_venue_category","separator":" · "} /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">venue link button (button bindings: url=venue_url, text=venue_url_host_label) — adds u-url</p><!-- /wp:paragraph -->
<!-- wp:buttons --><div class="wp-block-buttons">
<!-- wp:button {"metadata":{"bindings":{"url":{"source":"nop-indieweb/post-meta","args":{"field":"url"}},"text":{"source":"nop-indieweb/post-meta","args":{"field":"venue_url_host_label"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#" target="_blank" rel="noopener noreferrer">View on foursquare.com</a></div>
<!-- /wp:button -->
</div><!-- /wp:buttons -->

<!-- wp:separator {"backgroundColor":"accent-6"} --><hr class="wp-block-separator has-text-color has-accent-6-color has-alpha-channel-opacity has-accent-6-background-color has-background"/><!-- /wp:separator -->

<!-- wp:heading {"level":4,"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.1em","fontSize":"0.75rem"},"color":{"text":"#6b7280"}}} -->
<h4 class="wp-block-heading has-text-color" style="color:#6b7280;font-size:0.75rem;letter-spacing:0.1em;text-transform:uppercase">Check-in source</h4>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">checkin link button (button bindings: url=nop_indieweb_checkin_url, text=checkin_url_host_label) — adds u-url</p><!-- /wp:paragraph -->
<!-- wp:buttons --><div class="wp-block-buttons">
<!-- wp:button {"metadata":{"bindings":{"url":{"source":"nop-indieweb/post-meta","args":{"key":"nop_indieweb_checkin_url"}},"text":{"source":"nop-indieweb/post-meta","args":{"field":"checkin_url_host_label"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#" target="_blank" rel="noopener noreferrer">View on swarmapp.com</a></div>
<!-- /wp:button -->
</div><!-- /wp:buttons -->

<!-- wp:separator {"backgroundColor":"accent-6"} --><hr class="wp-block-separator has-text-color has-accent-6-color has-alpha-channel-opacity has-accent-6-background-color has-background"/><!-- /wp:separator -->

<!-- wp:heading {"level":4,"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.1em","fontSize":"0.75rem"},"color":{"text":"#6b7280"}}} -->
<h4 class="wp-block-heading has-text-color" style="color:#6b7280;font-size:0.75rem;letter-spacing:0.1em;text-transform:uppercase">Weather</h4>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">weather-icon (custom block — inlines SVG from slug)</p><!-- /wp:paragraph -->
<!-- wp:nop-indieweb/weather-icon /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">weather-temp (custom block)</p><!-- /wp:paragraph -->
<!-- wp:nop-indieweb/weather-temp /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">weather summary (binding: key=nop_indieweb_weather_summary)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"key":"nop_indieweb_weather_summary"}}}}} --><p>Light Rain</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">weather temp °C (binding: key=nop_indieweb_weather_temp_c)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"key":"nop_indieweb_weather_temp_c"}}}}} --><p>9.3</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">weather temp °F (binding: key=nop_indieweb_weather_temp_f)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"key":"nop_indieweb_weather_temp_f"}}}}} --><p>48.7</p><!-- /wp:paragraph -->

<!-- wp:separator {"backgroundColor":"accent-6"} --><hr class="wp-block-separator has-text-color has-accent-6-color has-alpha-channel-opacity has-accent-6-background-color has-background"/><!-- /wp:separator -->

<!-- wp:heading {"level":4,"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.1em","fontSize":"0.75rem"},"color":{"text":"#6b7280"}}} -->
<h4 class="wp-block-heading has-text-color" style="color:#6b7280;font-size:0.75rem;letter-spacing:0.1em;text-transform:uppercase">Visual</h4>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">checkin-map (custom block — Geoapify static map with marker + attribution overlay)</p><!-- /wp:paragraph -->
<!-- wp:nop-indieweb/checkin-map /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-featured-image (core)</p><!-- /wp:paragraph -->
<!-- wp:post-featured-image /-->

<!-- wp:separator {"backgroundColor":"accent-6"} --><hr class="wp-block-separator has-text-color has-accent-6-color has-alpha-channel-opacity has-accent-6-background-color has-background"/><!-- /wp:separator -->

<!-- wp:heading {"level":4,"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.1em","fontSize":"0.75rem"},"color":{"text":"#6b7280"}}} -->
<h4 class="wp-block-heading has-text-color" style="color:#6b7280;font-size:0.75rem;letter-spacing:0.1em;text-transform:uppercase">Content</h4>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-content (core)</p><!-- /wp:paragraph -->
<!-- wp:post-content /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-excerpt (core)</p><!-- /wp:paragraph -->
<!-- wp:post-excerpt /-->

<!-- wp:separator {"backgroundColor":"accent-6"} --><hr class="wp-block-separator has-text-color has-accent-6-color has-alpha-channel-opacity has-accent-6-background-color has-background"/><!-- /wp:separator -->

<!-- wp:heading {"level":4,"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.1em","fontSize":"0.75rem"},"color":{"text":"#6b7280"}}} -->
<h4 class="wp-block-heading has-text-color" style="color:#6b7280;font-size:0.75rem;letter-spacing:0.1em;text-transform:uppercase">Interactions</h4>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">like-button (custom block — site likes + aggregated webmention likes)</p><!-- /wp:paragraph -->
<!-- wp:nop-indieweb/like-button /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">replies (custom block — threaded conversation; reactions revealed from the post-footer pills)</p><!-- /wp:paragraph -->
<!-- wp:nop-indieweb/replies /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">comment-form (custom block — leave a reply)</p><!-- /wp:paragraph -->
<!-- wp:nop-indieweb/comment-form /-->

<!-- wp:separator {"backgroundColor":"accent-6"} --><hr class="wp-block-separator has-text-color has-accent-6-color has-alpha-channel-opacity has-accent-6-background-color has-background"/><!-- /wp:separator -->

<!-- wp:heading {"level":4,"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.1em","fontSize":"0.75rem"},"color":{"text":"#6b7280"}}} -->
<h4 class="wp-block-heading has-text-color" style="color:#6b7280;font-size:0.75rem;letter-spacing:0.1em;text-transform:uppercase">Provenance</h4>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">syndication-panel (custom block — "Also on Mastodon · Bluesky · Swarm")</p><!-- /wp:paragraph -->
<!-- wp:nop-indieweb/syndication-panel /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-source (custom block — originating platform link)</p><!-- /wp:paragraph -->
<!-- wp:nop-indieweb/post-source /-->

</div>
<!-- /wp:group -->
HTML,
		] );

		register_block_pattern( 'nop-indieweb/exercise-data-palette', [
			'title'         => __( 'Exercise Data Palette', 'nop-indieweb' ),
			'description'   => __( 'Every meaningful piece of data an exercise post carries — bindings + custom blocks, each labelled. Insert this into an exercise post, then copy whichever pieces you want into your real layout. Not for production use.', 'nop-indieweb' ),
			'categories'    => [ 'nop-indieweb' ],
			'keywords'      => [ 'exercise', 'workout', 'data', 'palette', 'reference', 'design' ],
			'viewportWidth' => 900,
			'content'       => <<<'HTML'
<!-- wp:group {"metadata":{"name":"Exercise Data Palette"},"style":{"spacing":{"blockGap":"2.5rem","padding":{"top":"2rem","bottom":"2rem","left":"2rem","right":"2rem"}},"border":{"width":"1px","color":"#e5e7eb"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group has-border-color" style="border-color:#e5e7eb;border-width:1px;padding-top:2rem;padding-right:2rem;padding-bottom:2rem;padding-left:2rem">

<!-- wp:heading {"level":2,"fontSize":"x-large"} -->
<h2 class="wp-block-heading has-x-large-font-size">Exercise Data Palette</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"style":{"color":{"text":"#6b7280"}}} -->
<p class="has-text-color" style="color:#6b7280">Every piece of data this workout carries, grouped by section. Copy any block into your real layout.</p>
<!-- /wp:paragraph -->

<!-- wp:separator {"backgroundColor":"accent-6"} --><hr class="wp-block-separator has-text-color has-accent-6-color has-alpha-channel-opacity has-accent-6-background-color has-background"/><!-- /wp:separator -->

<!-- wp:heading {"level":4,"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.1em","fontSize":"0.75rem"},"color":{"text":"#6b7280"}}} -->
<h4 class="wp-block-heading has-text-color" style="color:#6b7280;font-size:0.75rem;letter-spacing:0.1em;text-transform:uppercase">Identity</h4>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-title (core)</p><!-- /wp:paragraph -->
<!-- wp:post-title /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-date (core)</p><!-- /wp:paragraph -->
<!-- wp:post-date {"format":"j F Y, G:i"} /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-terms · nop_kind (core)</p><!-- /wp:paragraph -->
<!-- wp:post-terms {"term":"nop_kind"} /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-terms · category (core)</p><!-- /wp:paragraph -->
<!-- wp:post-terms {"term":"category"} /-->

<!-- wp:separator {"backgroundColor":"accent-6"} --><hr class="wp-block-separator has-text-color has-accent-6-color has-alpha-channel-opacity has-accent-6-background-color has-background"/><!-- /wp:separator -->

<!-- wp:heading {"level":4,"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.1em","fontSize":"0.75rem"},"color":{"text":"#6b7280"}}} -->
<h4 class="wp-block-heading has-text-color" style="color:#6b7280;font-size:0.75rem;letter-spacing:0.1em;text-transform:uppercase">Activity stats</h4>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">type (binding: field=exercise_type_label)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"exercise_type_label"}}}}} --><p>Run</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">distance (binding: field=exercise_distance)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"exercise_distance"}}}}} --><p>7.1 km</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">duration (binding: field=exercise_duration)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"exercise_duration"}}}}} --><p>34:57</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">pace — run/walk/hike/swim only (binding: field=exercise_pace)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"exercise_pace"}}}}} --><p>4:56 /km</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">speed — ride/rowing only (binding: field=exercise_speed)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"exercise_speed"}}}}} --><p>22.4 km/h</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">elevation gain (binding: field=exercise_elevation)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"exercise_elevation"}}}}} --><p>+145 m</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">elevation range (binding: field=exercise_elevation_range)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"exercise_elevation_range"}}}}} --><p>1–33 m</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">max grade (binding: field=exercise_max_grade)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"exercise_max_grade"}}}}} --><p>26.0%</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">max speed (binding: field=exercise_max_speed)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"exercise_max_speed"}}}}} --><p>31.0 km/h</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">calories (binding: field=exercise_calories)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"exercise_calories"}}}}} --><p>415 kcal</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">average heart rate — Apple Watch only (binding: field=exercise_avg_hr)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"exercise_avg_hr"}}}}} --><p>152 bpm</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">max heart rate — Apple Watch only (binding: field=exercise_max_hr)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"exercise_max_hr"}}}}} --><p>178 bpm</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">gear — when present (binding: field=exercise_gear)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"exercise_gear"}}}}} --><p>Vitus Zenium</p><!-- /wp:paragraph -->

<!-- wp:separator {"backgroundColor":"accent-6"} --><hr class="wp-block-separator has-text-color has-accent-6-color has-alpha-channel-opacity has-accent-6-background-color has-background"/><!-- /wp:separator -->

<!-- wp:heading {"level":4,"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.1em","fontSize":"0.75rem"},"color":{"text":"#6b7280"}}} -->
<h4 class="wp-block-heading has-text-color" style="color:#6b7280;font-size:0.75rem;letter-spacing:0.1em;text-transform:uppercase">Route &amp; source</h4>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">route map (custom block: nop-indieweb/exercise-map)</p><!-- /wp:paragraph -->
<!-- wp:nop-indieweb/exercise-map /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">start latitude (binding: key=nop_indieweb_exercise_start_lat)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"key":"nop_indieweb_exercise_start_lat"}}}}} --><p>54.5888</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">start longitude (binding: key=nop_indieweb_exercise_start_lng)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"key":"nop_indieweb_exercise_start_lng"}}}}} --><p>-5.9105</p><!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">Strava link (button bindings: url=nop_indieweb_exercise_source_url)</p><!-- /wp:paragraph -->
<!-- wp:buttons --><div class="wp-block-buttons">
<!-- wp:button {"metadata":{"bindings":{"url":{"source":"nop-indieweb/post-meta","args":{"key":"nop_indieweb_exercise_source_url"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#" target="_blank" rel="noopener noreferrer">View on Strava</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">GPX download (button bindings: url=nop_indieweb_exercise_gpx_url) — own-your-data artifact</p><!-- /wp:paragraph -->
<!-- wp:buttons --><div class="wp-block-buttons">
<!-- wp:button {"className":"is-style-outline","metadata":{"bindings":{"url":{"source":"nop-indieweb/post-meta","args":{"key":"nop_indieweb_exercise_gpx_url"}}}}} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="#">Download GPX</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->

<!-- wp:separator {"backgroundColor":"accent-6"} --><hr class="wp-block-separator has-text-color has-accent-6-color has-alpha-channel-opacity has-accent-6-background-color has-background"/><!-- /wp:separator -->

<!-- wp:heading {"level":4,"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.1em","fontSize":"0.75rem"},"color":{"text":"#6b7280"}}} -->
<h4 class="wp-block-heading has-text-color" style="color:#6b7280;font-size:0.75rem;letter-spacing:0.1em;text-transform:uppercase">Weather (when enriched)</h4>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">weather icon (custom block: nop-indieweb/weather-icon)</p><!-- /wp:paragraph -->
<!-- wp:nop-indieweb/weather-icon /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">temperature (custom block: nop-indieweb/weather-temp)</p><!-- /wp:paragraph -->
<!-- wp:nop-indieweb/weather-temp /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">summary (binding: key=nop_indieweb_weather_summary)</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"key":"nop_indieweb_weather_summary"}}}}} --><p>Overcast</p><!-- /wp:paragraph -->

<!-- wp:separator {"backgroundColor":"accent-6"} --><hr class="wp-block-separator has-text-color has-accent-6-color has-alpha-channel-opacity has-accent-6-background-color has-background"/><!-- /wp:separator -->

<!-- wp:heading {"level":4,"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.1em","fontSize":"0.75rem"},"color":{"text":"#6b7280"}}} -->
<h4 class="wp-block-heading has-text-color" style="color:#6b7280;font-size:0.75rem;letter-spacing:0.1em;text-transform:uppercase">Media &amp; words</h4>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">featured image — first activity photo (core)</p><!-- /wp:paragraph -->
<!-- wp:post-featured-image /-->

<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.75rem"}}} --><p class="has-text-color" style="color:#9ca3af;font-size:0.75rem">post-content — description + any photos (core)</p><!-- /wp:paragraph -->
<!-- wp:post-content /-->

</div>
<!-- /wp:group -->
HTML,
		] );

		register_block_pattern( 'nop-indieweb/note-post', [
			'title'         => __( 'Note Post', 'nop-indieweb' ),
			'description'   => __( 'Short-form note layout: inline kind/date header, featured image, content, and a compact interaction row (like · comments · reposts · source).', 'nop-indieweb' ),
			'categories'    => [ 'nop-indieweb' ],
			'keywords'      => [ 'note', 'social', 'indieweb', 'like', 'webmention' ],
			'viewportWidth' => 800,
			'content'       => <<<'HTML'
<!-- wp:group {"style":{"spacing":{"blockGap":"0"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group">

<!-- wp:group {"style":{"spacing":{"padding":{"top":"2rem","bottom":"1.5rem"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="padding-top:2rem;padding-bottom:1.5rem">
<!-- wp:group {"style":{"spacing":{"blockGap":"0.5rem"}},"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
<div class="wp-block-group">
<!-- wp:paragraph {"style":{"color":{"text":"#6b7280"},"typography":{"fontSize":"0.6875rem","fontWeight":"600","letterSpacing":"0.1em","textTransform":"uppercase"}}} -->
<p class="has-text-color" style="color:#6b7280;font-size:0.6875rem;font-weight:600;letter-spacing:0.1em;text-transform:uppercase">Note</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"style":{"color":{"text":"#9ca3af"},"typography":{"fontSize":"0.6875rem"}}} -->
<p class="has-text-color" style="color:#9ca3af;font-size:0.6875rem" aria-hidden="true">·</p>
<!-- /wp:paragraph -->
<!-- wp:post-date {"format":"j M Y, H:i","style":{"color":{"text":"#6b7280"},"typography":{"fontSize":"0.6875rem"}}} /-->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->

<!-- wp:post-featured-image {"align":"wide","style":{"spacing":{"margin":{"top":"0","bottom":"0"}}}} /-->

<!-- wp:group {"style":{"spacing":{"padding":{"top":"1.5rem","bottom":"1.25rem"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="padding-top:1.5rem;padding-bottom:1.25rem">
<!-- wp:post-content {"layout":{"type":"constrained"}} /-->
</div>
<!-- /wp:group -->

<!-- wp:group {"style":{"spacing":{"padding":{"top":"0","bottom":"2.5rem"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="padding-top:0;padding-bottom:2.5rem">
<!-- wp:nop-indieweb/post-footer /-->
</div>
<!-- /wp:group -->

</div>
<!-- /wp:group -->
HTML,
		] );

		register_block_pattern( 'nop-indieweb/stories-rail', [
			'title'         => __( 'Stories Rail', 'nop-indieweb' ),
			'description'   => __( 'A horizontal rail of story-kind videos from the last 24 hours. Core Query Loop showing each story\'s poster (featured image) linked to its permalink; drops to the Story archive once they age out. Drop it at the top of your home template.', 'nop-indieweb' ),
			'categories'    => [ 'nop-indieweb' ],
			'keywords'      => [ 'stories', 'story', 'video', 'rail', 'recent', 'indieweb' ],
			'viewportWidth' => 900,
			'content'       => <<<'HTML'
<!-- wp:group {"className":"is-style-stories-rail","metadata":{"name":"Stories"},"align":"wide","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide is-style-stories-rail">
<!-- wp:query {"query":{"perPage":12,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","inherit":false,"namespace":"nop-indieweb/stories-rail"},"align":"wide"} -->
<div class="wp-block-query alignwide">
<!-- wp:post-template {"layout":{"type":"flex","orientation":"horizontal","flexWrap":"nowrap"}} -->
<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"9/16","width":"112px","style":{"spacing":{"margin":{"top":"0","bottom":"0"}}}} /-->
<!-- /wp:post-template -->
<!-- wp:query-no-results -->
<!-- wp:paragraph {"style":{"color":{"text":"#6b7280"},"typography":{"fontSize":"0.8125rem"}}} -->
<p class="has-text-color" style="color:#6b7280;font-size:0.8125rem">No stories in the last 24 hours.</p>
<!-- /wp:paragraph -->
<!-- /wp:query-no-results -->
</div>
<!-- /wp:query -->
</div>
<!-- /wp:group -->
HTML,
		] );
	}
}
