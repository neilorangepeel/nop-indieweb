<?php
declare( strict_types=1 );

namespace NOP\IndieWeb;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Microformats2 helpers for use in theme template parts.
 *
 * Call these from your FSE templates — they read post meta and return
 * ready-to-output HTML with the correct mf2 class names, without coupling
 * your theme to the plugin's internal structure.
 *
 * Example in a template part:
 *   echo \NOP\IndieWeb\nop_indieweb_get_venue_hcard();
 */

// Returns the h-entry class string for the outer post wrapper.
function nop_indieweb_h_entry_class( string $extra = '' ): string {
	$classes = [ 'h-entry' ];
	if ( $extra ) {
		$classes[] = $extra;
	}
	return implode( ' ', $classes );
}

// Returns an mf2 h-card HTML string for a checkin venue.
function nop_indieweb_get_venue_hcard( int $post_id = 0 ): string {
	$post_id = $post_id ?: get_the_ID();
	if ( ! $post_id ) {
		return '';
	}

	$name = get_post_meta( $post_id, 'nop_indieweb_venue_name', true );
	$url  = get_post_meta( $post_id, 'nop_indieweb_venue_url', true );
	$lat  = get_post_meta( $post_id, 'nop_indieweb_venue_lat', true );
	$lng  = get_post_meta( $post_id, 'nop_indieweb_venue_lng', true );

	if ( ! $name ) {
		return '';
	}

	$name_html = esc_html( $name );

	$name_tag = $url
		? sprintf( '<a class="p-name u-url" href="%s">%s</a>', esc_url( $url ), $name_html )
		: sprintf( '<span class="p-name">%s</span>', $name_html );

	$geo_html = '';
	if ( $lat && $lng ) {
		$geo_html = sprintf(
			'<data class="p-latitude" value="%s"></data><data class="p-longitude" value="%s"></data>',
			esc_attr( $lat ),
			esc_attr( $lng )
		);
	}

	return sprintf( '<span class="p-location h-card">%s%s</span>', $name_tag, $geo_html );
}

// Returns an array of syndication URLs for a post.
function nop_indieweb_get_syndication_urls( int $post_id = 0 ): array {
	$post_id = $post_id ?: get_the_ID();
	if ( ! $post_id ) {
		return [];
	}
	$raw = get_post_meta( $post_id, 'nop_indieweb_syndication', true );
	return is_array( $raw ) ? array_filter( array_map( 'esc_url_raw', $raw ) ) : [];
}

// Returns syndication links as HTML <a> tags with the u-syndication mf2 class.
function nop_indieweb_get_syndication_links_html( int $post_id = 0 ): string {
	$urls = nop_indieweb_get_syndication_urls( $post_id );
	if ( ! $urls ) {
		return '';
	}
	$links = array_map(
		fn( $url ) => sprintf(
			'<a class="u-syndication" href="%s">%s</a>',
			esc_url( $url ),
			esc_html( wp_parse_url( $url, PHP_URL_HOST ) ?? $url )
		),
		$urls
	);
	return implode( ' ', $links );
}

// Returns photo URLs stored on a post.
function nop_indieweb_get_photos( int $post_id = 0 ): array {
	$post_id = $post_id ?: get_the_ID();
	if ( ! $post_id ) {
		return [];
	}
	$raw = get_post_meta( $post_id, 'nop_indieweb_photos', true );
	return is_array( $raw ) ? array_filter( array_map( 'esc_url_raw', $raw ) ) : [];
}
