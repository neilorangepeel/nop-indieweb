<?php
declare( strict_types=1 );

namespace NOP\IndieWeb;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses a GPX 1.1 track into route points plus first/last timestamps.
 *
 * Returns [ 'points' => [ [lat,lon], … ], 'start' => [lat,lon]|null,
 * 'start_time' => ISO|'', 'end_time' => ISO|'' ]. Latitude/longitude come from
 * the trkpt attributes (never namespaced), so they read reliably regardless of
 * the document's default namespace; timestamps are best-effort.
 */
function nop_indieweb_parse_gpx( string $xml ): array {
	$out = [ 'points' => [], 'start' => null, 'start_time' => '', 'end_time' => '' ];

	$prev = libxml_use_internal_errors( true );
	$sx   = simplexml_load_string( $xml );
	libxml_use_internal_errors( $prev );
	if ( false === $sx ) {
		return $out;
	}

	$ns      = $sx->getNamespaces( true );
	$default = $ns[''] ?? 'http://www.topografix.com/GPX/1/1';
	$sx->registerXPathNamespace( 'g', $default );

	$pts = $sx->xpath( '//g:trkpt' );
	if ( ! $pts ) {
		$pts = $sx->xpath( '//trkpt' );
	}
	if ( ! $pts ) {
		return $out;
	}

	foreach ( $pts as $p ) {
		$lat = (float) $p['lat'];
		$lon = (float) $p['lon'];
		if ( 0.0 === $lat && 0.0 === $lon ) {
			continue;
		}
		$out['points'][] = [ $lat, $lon ];

		$time  = $p->children( $default )->time ?? null;
		$time  = $time ? (string) $time : (string) ( $p->time ?? '' );
		if ( '' !== $time ) {
			if ( '' === $out['start_time'] ) {
				$out['start_time'] = $time;
			}
			$out['end_time'] = $time;
		}
	}

	if ( $out['points'] ) {
		$out['start'] = $out['points'][0];
	}
	return $out;
}

/**
 * Douglas–Peucker line simplification. Tolerance is in degrees (~0.00005 ≈ 5 m).
 * Drops redundant points while keeping the shape — smaller GPX, cleaner line,
 * smaller map payload.
 */
function nop_indieweb_simplify_track( array $points, float $tolerance = 0.00005 ): array {
	$n = count( $points );
	if ( $n < 3 ) {
		return $points;
	}

	$start = $points[0];
	$end   = $points[ $n - 1 ];
	$dmax  = 0.0;
	$index = 0;
	for ( $i = 1; $i < $n - 1; $i++ ) {
		$d = nop_indieweb_perp_distance( $points[ $i ], $start, $end );
		if ( $d > $dmax ) {
			$dmax  = $d;
			$index = $i;
		}
	}

	if ( $dmax > $tolerance ) {
		$left  = nop_indieweb_simplify_track( array_slice( $points, 0, $index + 1 ), $tolerance );
		$right = nop_indieweb_simplify_track( array_slice( $points, $index ), $tolerance );
		return array_merge( array_slice( $left, 0, -1 ), $right );
	}
	return [ $start, $end ];
}

/**
 * Perpendicular distance from point $p to segment $a–$b, in degree-space with
 * longitude scaled by cos(latitude) so the metric stays roughly isotropic.
 */
function nop_indieweb_perp_distance( array $p, array $a, array $b ): float {
	$cos = cos( deg2rad( $a[0] ) );
	$px  = $p[1] * $cos;
	$py  = $p[0];
	$ax  = $a[1] * $cos;
	$ay  = $a[0];
	$bx  = $b[1] * $cos;
	$by  = $b[0];

	$dx   = $bx - $ax;
	$dy   = $by - $ay;
	$len2 = $dx * $dx + $dy * $dy;
	if ( 0.0 === $len2 ) {
		return sqrt( ( $px - $ax ) ** 2 + ( $py - $ay ) ** 2 );
	}
	$t = ( ( $px - $ax ) * $dx + ( $py - $ay ) * $dy ) / $len2;
	$t = max( 0.0, min( 1.0, $t ) );

	$projx = $ax + $t * $dx;
	$projy = $ay + $t * $dy;
	return sqrt( ( $px - $projx ) ** 2 + ( $py - $projy ) ** 2 );
}

/**
 * Renders a route line on a Geoapify static map and caches the PNG.
 *
 * Builds a GET request and adaptively simplifies the track until the geometry
 * fits under Geoapify's ~2 KB URL limit — so any track, however long, renders
 * without losing the overall shape. Stores the cached URL in
 * nop_indieweb_exercise_map_url and returns it, mirroring the checkin-map cache.
 * Returns '' on any failure so a map miss never blocks an import.
 */
function nop_indieweb_render_route_map( int $post_id, array $points, string $api_key, array $opts = [] ): string {
	if ( count( $points ) < 2 || '' === $api_key ) {
		return '';
	}

	$width  = (int) ( $opts['width'] ?? 800 );
	$height = (int) ( $opts['height'] ?? 560 );
	$color  = (string) ( $opts['color'] ?? 'e03232' );
	$style  = (string) ( $opts['style'] ?? 'osm-bright' );

	// Fit the polyline coordinate string under a budget that keeps the whole
	// URL comfortably below 2 KB, simplifying harder each pass if needed.
	$tolerance = 0.00003;
	$track     = $points;
	$coords    = nop_indieweb_encode_geom_coords( $track );
	while ( strlen( $coords ) > 1650 && count( $track ) > 2 ) {
		$tolerance *= 1.8;
		$track  = nop_indieweb_simplify_track( $points, $tolerance );
		$coords = nop_indieweb_encode_geom_coords( $track );
	}

	$lats    = array_column( $track, 0 );
	$lons    = array_column( $track, 1 );
	$min_lat = min( $lats );
	$max_lat = max( $lats );
	$min_lon = min( $lons );
	$max_lon = max( $lons );
	$start   = $track[0];
	$end     = $track[ count( $track ) - 1 ];

	// Frame the map to the route's bounding box with padding, so the whole
	// trail is always in view. Latitude (vertical) gets extra room because the
	// pin markers balloon upward from their anchor point. The minimum padding
	// keeps very short routes from filling the frame edge to edge.
	$pad_lon = ( $max_lon - $min_lon ) * 0.12 + 0.0006;
	$pad_lat = ( $max_lat - $min_lat ) * 0.18 + 0.0006;
	$area    = round( $min_lon - $pad_lon, 5 ) . ',' . round( $min_lat - $pad_lat, 5 )
		. ',' . round( $max_lon + $pad_lon, 5 ) . ',' . round( $max_lat + $pad_lat, 5 );

	$geometry = 'polyline:' . $coords . ';linecolor:%23' . $color . ';linewidth:5;lineopacity:0.9';
	$marker   = 'lonlat:' . round( $start[1], 5 ) . ',' . round( $start[0], 5 ) . ';type:awesome;color:%231f8f3b;icon:play;size:medium'
		. '|lonlat:' . round( $end[1], 5 ) . ',' . round( $end[0], 5 ) . ';type:awesome;color:%23' . $color . ';icon:flag;size:medium';

	$url = 'https://maps.geoapify.com/v1/staticmap'
		. '?style=' . rawurlencode( $style )
		. '&width=' . $width . '&height=' . $height . '&scaleFactor=2'
		. '&area=rect:' . $area
		. '&geometry=' . $geometry
		. '&marker=' . $marker
		. '&apiKey=' . rawurlencode( $api_key );

	$resp = wp_remote_get( $url, [ 'timeout' => 30 ] );
	if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
		return '';
	}
	if ( ! str_starts_with( (string) wp_remote_retrieve_header( $resp, 'content-type' ), 'image/' ) ) {
		return '';
	}

	$dir = wp_upload_dir()['basedir'] . '/exercise-maps';
	if ( ! wp_mkdir_p( $dir ) ) {
		return '';
	}
	file_put_contents( $dir . "/exercise-map-{$post_id}.png", wp_remote_retrieve_body( $resp ) );

	$url_out = wp_upload_dir()['baseurl'] . "/exercise-maps/exercise-map-{$post_id}.png";
	update_post_meta( $post_id, 'nop_indieweb_exercise_map_url', $url_out );
	return $url_out;
}

/**
 * Encodes track points as Geoapify's "lon,lat,lon,lat,…" geometry string,
 * rounded to ~1 m precision to keep the URL short.
 */
function nop_indieweb_encode_geom_coords( array $points ): string {
	return implode( ',', array_map( fn( $p ) => round( $p[1], 5 ) . ',' . round( $p[0], 5 ), $points ) );
}
