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

/**
 * Human label for an exercise-type slug, e.g. "run" → "Run". The single source
 * of truth shared by the Strava importer, the Exercise service, and the title
 * resolver.
 */
function nop_indieweb_exercise_type_label( string $type ): string {
	$labels = [
		'run'      => __( 'Run', 'nop-indieweb' ),
		'ride'     => __( 'Ride', 'nop-indieweb' ),
		'swim'     => __( 'Swim', 'nop-indieweb' ),
		'walk'     => __( 'Walk', 'nop-indieweb' ),
		'hike'     => __( 'Hike', 'nop-indieweb' ),
		'strength' => __( 'Strength', 'nop-indieweb' ),
		'yoga'     => __( 'Yoga', 'nop-indieweb' ),
		'pilates'  => __( 'Pilates', 'nop-indieweb' ),
		'rowing'   => __( 'Rowing', 'nop-indieweb' ),
		'workout'  => __( 'Workout', 'nop-indieweb' ),
	];
	return $labels[ $type ] ?? ( '' !== $type ? ucfirst( $type ) : __( 'Exercise', 'nop-indieweb' ) );
}

/**
 * Resolves a workout post title: keeps a genuine human-written title, otherwise
 * builds "{Type} in {place}" from the start coordinates (mirroring how check-ins
 * are named by venue), falling back to the bare type label. Apple Health / Health
 * Auto Export only ever supply the activity type as the name, and Strava
 * auto-names ("Afternoon Ride") read the same way — both are treated as "no real
 * title" so every workout gets a consistent, place-aware heading.
 */
function nop_indieweb_exercise_title( string $name, string $type, float $lat, float $lng ): string {
	$label = nop_indieweb_exercise_type_label( $type );

	$name = trim( $name );
	if ( '' !== $name && ! nop_indieweb_is_generic_workout_name( $name ) ) {
		return $name;
	}

	if ( $lat || $lng ) {
		$geo      = \NOP\IndieWeb\Venue\Geoapify_Geocoder::reverse_geocode( $lat, $lng );
		$locality = (string) ( $geo['locality'] ?? '' );
		if ( '' !== $locality ) {
			/* translators: 1: activity type label e.g. "Run", 2: place name e.g. "Belfast" */
			return sprintf( __( '%1$s in %2$s', 'nop-indieweb' ), $label, $locality );
		}
	}
	return $label;
}

/**
 * Creates an exercise-kind post from normalised workout data. The single insert
 * path shared by the Strava importer and the Health Auto Export endpoint:
 * writes the post (title resolved, draft by default), sets the kind term, stores
 * meta, saves the GPX, renders the route map, and enriches weather. Photos are
 * source-specific and handled by the caller after this returns the post ID.
 *
 * @param array  $a       name, type, gmt, content, status, start [lat,lng],
 *                        points [[lat,lon],…], gpx (string), meta (extra meta),
 *                        source_id, source_url, service.
 * @param string $api_key Geoapify key for the route map (optional).
 * @return int|\WP_Error  New post ID, or WP_Error on insert failure.
 */
function nop_indieweb_save_exercise_post( array $a, string $api_key = '' ) {
	$start = $a['start'] ?? [ 0, 0 ];
	$gmt   = (string) ( $a['gmt'] ?? '' );

	$post_id = wp_insert_post( [
		'post_title'    => nop_indieweb_exercise_title( (string) ( $a['name'] ?? '' ), (string) ( $a['type'] ?? 'workout' ), (float) $start[0], (float) $start[1] ),
		'post_content'  => (string) ( $a['content'] ?? '' ),
		'post_status'   => (string) ( $a['status'] ?? 'draft' ),
		'post_type'     => 'post',
		'post_date_gmt' => $gmt,
		'post_date'     => $gmt ? get_date_from_gmt( $gmt ) : '',
	], true );
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	wp_set_object_terms( $post_id, 'exercise', \NOP\IndieWeb\Kind\Kind_Taxonomy::TAXONOMY );

	$meta = array_merge( [
		'nop_indieweb_service'            => (string) ( $a['service'] ?? 'exercise' ),
		'nop_indieweb_exercise_type'      => (string) ( $a['type'] ?? 'workout' ),
		'nop_indieweb_exercise_start_lat' => (string) $start[0],
		'nop_indieweb_exercise_start_lng' => (string) $start[1],
	], $a['meta'] ?? [] );
	if ( ! empty( $a['source_id'] ) ) {
		$meta['nop_indieweb_exercise_source_id'] = (string) $a['source_id'];
	}
	if ( ! empty( $a['source_url'] ) ) {
		$meta['nop_indieweb_exercise_source_url'] = (string) $a['source_url'];
	}
	foreach ( $meta as $k => $v ) {
		if ( '' !== $v && null !== $v ) {
			update_post_meta( $post_id, $k, $v );
		}
	}

	if ( ! empty( $a['gpx'] ) ) {
		nop_indieweb_store_exercise_gpx( $post_id, (string) $a['gpx'] );
	}
	if ( ! empty( $a['points'] ) && '' !== $api_key ) {
		nop_indieweb_render_route_map( $post_id, $a['points'], $api_key, [ 'color' => 'e03232' ] );
	}
	if ( (float) $start[0] || (float) $start[1] ) {
		\NOP\IndieWeb\Weather\Weather_Fetcher::enrich_post( $post_id, (float) $start[0], (float) $start[1], (int) get_post_timestamp( $post_id, 'date_gmt' ) );
	}

	return $post_id;
}

/**
 * Saves a GPX string to uploads/exercise-routes/ and records its URL — the
 * canonical own-your-data route artifact.
 */
function nop_indieweb_store_exercise_gpx( int $post_id, string $gpx ): void {
	$dir = wp_upload_dir()['basedir'] . '/exercise-routes';
	if ( '' === $gpx || ! wp_mkdir_p( $dir ) ) {
		return;
	}
	file_put_contents( $dir . "/exercise-route-{$post_id}.gpx", $gpx );
	update_post_meta(
		$post_id,
		'nop_indieweb_exercise_gpx_url',
		wp_upload_dir()['baseurl'] . "/exercise-routes/exercise-route-{$post_id}.gpx"
	);
}

/**
 * Builds a GPX 1.1 track document from an array of points, each having lat, lon
 * and optionally ele (metres) and time (ISO 8601). Used to mint a canonical GPX
 * from app sources (e.g. Health Auto Export) that send raw coordinate arrays.
 */
function nop_indieweb_build_gpx( array $points, string $name = '' ): string {
	$trkpts = '';
	foreach ( $points as $p ) {
		$lat = isset( $p['lat'] ) ? (float) $p['lat'] : null;
		$lon = isset( $p['lon'] ) ? (float) $p['lon'] : null;
		if ( null === $lat || null === $lon ) {
			continue;
		}
		$trkpts .= sprintf( '<trkpt lat="%s" lon="%s">', esc_attr( (string) $lat ), esc_attr( (string) $lon ) );
		if ( isset( $p['ele'] ) && '' !== $p['ele'] ) {
			$trkpts .= '<ele>' . esc_html( (string) (float) $p['ele'] ) . '</ele>';
		}
		if ( ! empty( $p['time'] ) ) {
			$trkpts .= '<time>' . esc_html( (string) $p['time'] ) . '</time>';
		}
		$trkpts .= '</trkpt>';
	}

	return '<?xml version="1.0" encoding="UTF-8"?>'
		. '<gpx version="1.1" creator="nop-indieweb" xmlns="http://www.topografix.com/GPX/1/1">'
		. '<trk><name>' . esc_html( $name ) . '</name><trkseg>' . $trkpts . '</trkseg></trk></gpx>';
}

/**
 * Formats a single exercise stat for display, or returns null when the
 * underlying data is absent. The one source of truth for stat formatting,
 * shared by the Block_Bindings derived fields and the exercise-stats block.
 */
function nop_indieweb_exercise_stat( string $field, int $post_id ): ?string {
	$meta = fn( string $key ) => get_post_meta( $post_id, 'nop_indieweb_exercise_' . $key, true );

	switch ( $field ) {
		case 'exercise_type_label':
			$type = (string) $meta( 'type' );
			return '' !== $type ? nop_indieweb_exercise_type_label( $type ) : null;

		case 'exercise_distance':
			$m = (float) $meta( 'distance_m' );
			/* translators: %s = distance in kilometres */
			return $m ? sprintf( __( '%s km', 'nop-indieweb' ), number_format( $m / 1000, 1 ) ) : null;

		case 'exercise_duration': {
			$s = (int) $meta( 'duration_s' );
			if ( ! $s ) {
				return null;
			}
			$h   = (int) floor( $s / 3600 );
			$min = (int) floor( ( $s % 3600 ) / 60 );
			$sec = $s % 60;
			return $h > 0 ? sprintf( '%d:%02d:%02d', $h, $min, $sec ) : sprintf( '%d:%02d', $min, $sec );
		}

		case 'exercise_pace': {
			$dist_m = (float) $meta( 'distance_m' );
			$dur_s  = (int) $meta( 'duration_s' );
			$type   = (string) $meta( 'type' );
			if ( ! $dist_m || ! $dur_s || ! in_array( $type, [ 'run', 'walk', 'hike', 'swim' ], true ) ) {
				return null;
			}
			$pace_s   = $dur_s / ( $dist_m / 1000 );
			$pace_min = (int) floor( $pace_s / 60 );
			$pace_sec = (int) round( $pace_s - $pace_min * 60 );
			/* translators: %1$d = minutes, %2$02d = seconds, e.g. "6:12 /km" */
			return sprintf( __( '%1$d:%2$02d /km', 'nop-indieweb' ), $pace_min, $pace_sec );
		}

		case 'exercise_speed': {
			$dist_m = (float) $meta( 'distance_m' );
			$dur_s  = (int) $meta( 'duration_s' );
			$type   = (string) $meta( 'type' );
			if ( ! $dist_m || ! $dur_s || ! in_array( $type, [ 'ride', 'rowing' ], true ) ) {
				return null;
			}
			/* translators: %s = speed in km/h */
			return sprintf( __( '%s km/h', 'nop-indieweb' ), number_format( ( $dist_m / $dur_s ) * 3.6, 1 ) );
		}

		case 'exercise_elevation':
			$gain = (float) $meta( 'elevation_gain_m' );
			/* translators: %d = elevation gain in metres */
			return $gain ? sprintf( __( '+%d m', 'nop-indieweb' ), (int) round( $gain ) ) : null;

		case 'exercise_calories':
			$cal = (int) $meta( 'calories' );
			/* translators: %s = active energy in kilocalories */
			return $cal ? sprintf( __( '%s kcal', 'nop-indieweb' ), number_format( $cal ) ) : null;

		case 'exercise_avg_hr':
			$hr = (int) $meta( 'avg_heart_rate' );
			/* translators: %d = average heart rate in beats per minute */
			return $hr ? sprintf( __( '%d bpm', 'nop-indieweb' ), $hr ) : null;

		case 'exercise_max_hr':
			$hr = (int) $meta( 'max_heart_rate' );
			/* translators: %d = maximum heart rate in beats per minute */
			return $hr ? sprintf( __( '%d bpm', 'nop-indieweb' ), $hr ) : null;

		case 'exercise_max_speed':
			$ms = (float) $meta( 'max_speed_ms' );
			/* translators: %s = maximum speed in km/h */
			return $ms ? sprintf( __( '%s km/h', 'nop-indieweb' ), number_format( $ms * 3.6, 1 ) ) : null;

		case 'exercise_elevation_range': {
			$low  = $meta( 'elevation_low_m' );
			$high = $meta( 'elevation_high_m' );
			if ( '' === $low && '' === $high ) {
				return null;
			}
			/* translators: %1$d = lowest elevation, %2$d = highest elevation, in metres */
			return sprintf( __( '%1$d–%2$d m', 'nop-indieweb' ), (int) round( (float) $low ), (int) round( (float) $high ) );
		}

		case 'exercise_max_grade':
			$grade = (float) $meta( 'max_grade' );
			/* translators: %s = maximum gradient as a percentage */
			return $grade ? sprintf( __( '%s%%', 'nop-indieweb' ), number_format( $grade, 1 ) ) : null;

		case 'exercise_gear':
			$gear = (string) $meta( 'gear' );
			return '' !== $gear ? $gear : null;
	}
	return null;
}

/**
 * True when a workout name is just an auto-generated activity descriptor — a
 * bare type, a time-of-day + type (Strava), or a place-qualified type (Apple) —
 * rather than something the athlete actually wrote.
 */
function nop_indieweb_is_generic_workout_name( string $name ): bool {
	$name = strtolower( trim( $name ) );
	if ( '' === $name ) {
		return true;
	}
	$types  = 'run|ride|cycle|bike ride|walk|hike|swim|row|rowing|workout|yoga|pilates|cycling|running|walking|hiking|swimming|strength training|core training';
	$prefix = 'morning|afternoon|evening|lunch|lunchtime|night|late night|midday|early morning|outdoor|indoor|pool|open water|virtual|treadmill';
	return (bool) preg_match( '/^((' . $prefix . ')\s+)?(' . $types . ')$/', $name );
}
