<?php
declare( strict_types=1 );

namespace NOP\IndieWeb;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Samples a curved flight path between two points as a list of [lat, lng]
 * points, using Aaron Parecki's quadratic-Bézier technique: a single control
 * point offset perpendicular to the mid-point of the great-circle chord, at a
 * fixed tangent angle. Longitude is scaled by cos(mean latitude) so the offset
 * stays visually perpendicular once the map projects it.
 *
 * Returns an empty array when the two points coincide.
 */
function nop_indieweb_flight_arc_points( array $from, array $to, array $opts = [] ): array {
	$from_lat = (float) $from[0];
	$from_lng = (float) $from[1];
	$to_lat   = (float) $to[0];
	$to_lng   = (float) $to[1];

	// Unwrap across the antimeridian so a short hop over ±180° isn't drawn the
	// long way round the map (e.g. Tokyo → Los Angeles).
	if ( abs( $to_lng - $from_lng ) > 180.0 ) {
		if ( $to_lng > $from_lng ) {
			$from_lng += 360.0;
		} else {
			$to_lng += 360.0;
		}
	}

	$angle    = (float) apply_filters( 'nop_indieweb_flight_arc_angle', (float) ( $opts['angle'] ?? 25.0 ) );
	$steps    = max( 2, (int) ( $opts['steps'] ?? 24 ) );
	$mean_lat = ( $from_lat + $to_lat ) / 2.0;
	$k        = max( 0.05, cos( deg2rad( $mean_lat ) ) );

	$ax = $from_lng * $k;
	$ay = $from_lat;
	$bx = $to_lng * $k;
	$by = $to_lat;

	$dx  = $bx - $ax;
	$dy  = $by - $ay;
	$len = sqrt( $dx * $dx + $dy * $dy );
	if ( $len < 1e-9 ) {
		return [];
	}

	$mx = ( $ax + $bx ) / 2.0;
	$my = ( $ay + $by ) / 2.0;

	// Perpendicular unit vector, signed so the arc bulges toward the nearer pole
	// (northward for our use); tan(angle) sets how tall the curve rises.
	$nx = -$dy / $len;
	$ny = $dx / $len;
	$s  = $ny >= 0 ? 1.0 : -1.0;
	$h  = 0.5 * $len * tan( deg2rad( $angle ) );

	$px = $mx + $s * $nx * $h;
	$py = $my + $s * $ny * $h;

	$points = [];
	for ( $i = 0; $i <= $steps; $i++ ) {
		$t  = $i / $steps;
		$mt = 1.0 - $t;
		$x  = $mt * $mt * $ax + 2.0 * $mt * $t * $px + $t * $t * $bx;
		$y  = $mt * $mt * $ay + 2.0 * $mt * $t * $py + $t * $t * $by;

		$lng = $x / $k;
		// Fold back into [-180, 180] after any antimeridian unwrap.
		$lng = fmod( $lng + 540.0, 360.0 ) - 180.0;
		$points[] = [ $y, $lng ];
	}

	return $points;
}

/**
 * Renders the flight arc on a Geoapify static map and caches the PNG, mirroring
 * the checkin/exercise map cachers. Draws the Bézier arc as a polyline with a
 * departure (plane) and arrival (flag) marker, framed to the arc's bounding box.
 * Stores the local URL in nop_indieweb_map_url — the same key the checkin-map
 * block and Bluesky thumbnail already read — and returns it. Returns '' on any
 * failure so a map miss never blocks the checkin.
 */
function nop_indieweb_render_flight_arc_map( int $post_id, array $from, array $to, string $api_key, array $opts = [] ): string {
	if ( '' === $api_key ) {
		return '';
	}

	$points = nop_indieweb_flight_arc_points( $from, $to, $opts );
	if ( count( $points ) < 2 ) {
		return '';
	}

	[ $def_w, $def_h ] = nop_indieweb_map_dimensions();
	$width    = (int) ( $opts['width'] ?? $def_w );
	$height   = (int) ( $opts['height'] ?? $def_h );
	// Match the single-marker checkin map so a flight looks at home on a checkin
	// post: same base style and the shared brand marker colour.
	$brand    = (string) apply_filters( 'nop_indieweb_map_marker_color', 'e03232' );
	$line_col = (string) apply_filters( 'nop_indieweb_flight_arc_color', $brand );
	$style    = (string) ( $opts['style'] ?? nop_indieweb_map_style() );

	$lats    = array_column( $points, 0 );
	$lons    = array_column( $points, 1 );
	$pad_lon = ( max( $lons ) - min( $lons ) ) * 0.12 + 0.02;
	$pad_lat = ( max( $lats ) - min( $lats ) ) * 0.18 + 0.02;
	$area    = round( min( $lons ) - $pad_lon, 5 ) . ',' . round( min( $lats ) - $pad_lat, 5 )
		. ',' . round( max( $lons ) + $pad_lon, 5 ) . ',' . round( max( $lats ) + $pad_lat, 5 );

	$geometry = 'polyline:' . nop_indieweb_encode_geom_coords( $points )
		. ';linecolor:%23' . $line_col . ';linewidth:4;lineopacity:0.9';

	$dep    = $points[0];
	$arr    = $points[ count( $points ) - 1 ];
	$marker = 'lonlat:' . round( $dep[1], 5 ) . ',' . round( $dep[0], 5 ) . ';type:awesome;color:%23' . $brand . ';icon:plane;size:medium'
		. '|lonlat:' . round( $arr[1], 5 ) . ',' . round( $arr[0], 5 ) . ';type:awesome;color:%23' . $brand . ';icon:flag;size:medium';

	$url = 'https://maps.geoapify.com/v1/staticmap'
		. '?style=' . rawurlencode( $style )
		. '&styleCustomization=' . nop_indieweb_map_style_customization()
		. '&width=' . $width . '&height=' . $height . '&scaleFactor=2'
		. '&area=rect:' . $area
		. '&geometry=' . $geometry
		. '&marker=' . $marker
		. '&apiKey=' . rawurlencode( $api_key );

	$response = wp_safe_remote_get( $url, [
		'timeout'             => 8,
		'limit_response_size' => 4 * 1024 * 1024,
	] );
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return '';
	}
	if ( ! str_starts_with( (string) wp_remote_retrieve_header( $response, 'content-type' ), 'image/' ) ) {
		return '';
	}

	$upload_dir = wp_upload_dir();
	$maps_dir   = $upload_dir['basedir'] . '/checkin-maps';
	if ( ! wp_mkdir_p( $maps_dir ) ) {
		return '';
	}

	$file = $maps_dir . "/flight-map-{$post_id}.png";
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- direct write to a plugin-owned cache dir; WP_Filesystem adds no value for this binary image write
	if ( false === file_put_contents( $file, wp_remote_retrieve_body( $response ) ) ) {
		return '';
	}

	$local_url = $upload_dir['baseurl'] . "/checkin-maps/flight-map-{$post_id}.png";
	update_post_meta( $post_id, 'nop_indieweb_map_url', $local_url );

	return $local_url;
}
