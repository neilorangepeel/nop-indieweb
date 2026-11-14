<?php
/**
 * What a photographer wants from a photo's EXIF, kept with the attachment; nothing left in the files.
 *
 * WordPress already reads camera, aperture, focal length, ISO, shutter speed, date, credit and
 * copyright into `image_meta`. This adds make, lens, 35mm focal length, exposure compensation,
 * exposure mode, metering, flash, white balance, time zone and software, and the location, which
 * is kept apart in protected post meta (never in `image_meta`, so never in the REST API).
 *
 * Then the files: the full-size original WordPress keeps beside its 2560px copy is deleted, and
 * every remaining JPEG has its EXIF, XMP and IPTC blocks taken out without re-compressing, so no
 * public file carries a location (SiteGround Optimizer's .bak copies included). Each size also gets
 * back the colour profile of the photo it was made from: GD drops the profile but keeps the pixel
 * values as they were, so a Display P3 phone photo otherwise shows flat.
 */
declare( strict_types=1 );

namespace NOP\IndieWeb\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Photo_Metadata {

	public const LOCATION_META = '_nop_indieweb_photo_location';

	public function register(): void {
		add_filter( 'wp_read_image_metadata', [ $this, 'read' ], 10, 5 );
		add_filter( 'wp_generate_attachment_metadata', [ $this, 'clean' ], 10, 3 );
	}

	/**
	 * @param mixed $meta
	 * @param mixed $exif
	 * @return mixed
	 */
	public function read( $meta, $file, $image_type, $iptc = [], $exif = [] ) {
		if ( ! is_array( $meta ) || ! is_array( $exif ) || ! $exif ) {
			return $meta;
		}
		$meta = array_merge( $meta, self::fields( $exif ) );
		$gps  = self::location( $exif );
		if ( $gps ) {
			$meta['nop_location'] = $gps; // Moved to protected post meta in clean(), before it is saved for good.
		}
		return $meta;
	}

	/**
	 * @param mixed $metadata
	 * @return mixed
	 */
	public function clean( $metadata, $attachment_id, $context = 'create' ) {
		if ( ! is_array( $metadata ) || 'create' !== $context || ! wp_attachment_is_image( (int) $attachment_id ) ) {
			return $metadata;
		}
		if ( isset( $metadata['image_meta']['nop_location'] ) ) {
			update_post_meta( (int) $attachment_id, self::LOCATION_META, $metadata['image_meta']['nop_location'] );
			unset( $metadata['image_meta']['nop_location'] );
		}

		$main   = (string) get_attached_file( (int) $attachment_id );
		$dir    = dirname( $main );
		$source = ! empty( $metadata['original_image'] ) ? $dir . '/' . $metadata['original_image'] : $main;
		$icc    = is_file( $source ) && preg_match( '/\.jpe?g$/i', $source ) ? self::icc( (string) file_get_contents( $source ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local upload
		if ( $source !== $main ) {
			wp_delete_file( $source );
			unset( $metadata['original_image'] );
		}
		$files = [ $main ];
		foreach ( (array) ( $metadata['sizes'] ?? [] ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$files[] = $dir . '/' . $size['file'];
			}
		}
		foreach ( $files as $path ) {
			self::strip_file( $path, $icc );
			self::strip_file( (string) preg_replace( '/\.(jpe?g)$/i', '.bak.$1', $path ), '' );
		}
		if ( is_file( $main ) ) {
			$metadata['filesize'] = (int) filesize( $main );
		}
		return $metadata;
	}

	private static function strip_file( string $path, string $icc ): void {
		if ( ! preg_match( '/\.jpe?g$/i', $path ) || ! is_file( $path ) ) {
			return;
		}
		$jpeg  = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local upload
		$clean = self::with_icc( self::strip_jpeg( $jpeg ), $icc );
		if ( $clean !== $jpeg ) {
			file_put_contents( $path, $clean ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local upload
		}
	}

	/**
	 * A JPEG without its EXIF/XMP (APP1), IPTC (APP13) and comment blocks; the image data is copied
	 * byte for byte, and the colour profile (APP2) and everything else stay. Anything it can't read
	 * comes back unchanged.
	 */
	public static function strip_jpeg( string $jpeg ): string {
		if ( "\xFF\xD8" !== substr( $jpeg, 0, 2 ) ) {
			return $jpeg;
		}
		$out = "\xFF\xD8";
		$pos = 2;
		$end = strlen( $jpeg );
		while ( $pos + 4 <= $end && "\xFF" === $jpeg[ $pos ] ) {
			$marker = ord( $jpeg[ $pos + 1 ] );
			if ( 0xFF === $marker ) {
				++$pos;
				continue;
			}
			if ( 0xDA === $marker ) {
				return $out . substr( $jpeg, $pos );
			}
			$size = (int) unpack( 'n', substr( $jpeg, $pos + 2, 2 ) )[1];
			if ( $size < 2 ) {
				return $jpeg;
			}
			if ( ! in_array( $marker, [ 0xE1, 0xED, 0xFE ], true ) ) {
				$out .= substr( $jpeg, $pos, 2 + $size );
			}
			$pos += 2 + $size;
		}
		return $jpeg;
	}

	/** A JPEG's colour profile: its APP2 "ICC_PROFILE" segments, in order, as they are ('' when it has none). */
	public static function icc( string $jpeg ): string {
		$icc = '';
		foreach ( self::segments( $jpeg ) as [ $marker, $segment ] ) {
			if ( 0xE2 === $marker && "ICC_PROFILE\0" === substr( $segment, 4, 12 ) ) {
				$icc .= $segment;
			}
		}
		return $icc;
	}

	/** The JPEG with $icc put in after its opening segments, unless it already has a profile. */
	public static function with_icc( string $jpeg, string $icc ): string {
		if ( '' === $icc || "\xFF\xD8" !== substr( $jpeg, 0, 2 ) || '' !== self::icc( $jpeg ) ) {
			return $jpeg;
		}
		$at = 2;
		foreach ( self::segments( $jpeg ) as [ $marker, $segment ] ) {
			if ( 0xE0 !== $marker ) { // After JFIF (APP0), before everything else.
				break;
			}
			$at += strlen( $segment );
		}
		return substr( $jpeg, 0, $at ) . $icc . substr( $jpeg, $at );
	}

	/**
	 * The segments before the image data, as [ marker, bytes ]; stops at the first thing it can't read.
	 *
	 * @return list<array{0: int, 1: string}>
	 */
	private static function segments( string $jpeg ): array {
		$out = [];
		$pos = 2;
		$end = strlen( $jpeg );
		while ( "\xFF\xD8" === substr( $jpeg, 0, 2 ) && $pos + 4 <= $end && "\xFF" === $jpeg[ $pos ] ) {
			$marker = ord( $jpeg[ $pos + 1 ] );
			$size   = (int) unpack( 'n', substr( $jpeg, $pos + 2, 2 ) )[1];
			if ( 0xDA === $marker || 0xFF === $marker || $size < 2 ) {
				break;
			}
			$out[] = [ $marker, substr( $jpeg, $pos, 2 + $size ) ];
			$pos  += 2 + $size;
		}
		return $out;
	}

	/**
	 * The fields WordPress doesn't read, from exif_read_data() output.
	 *
	 * @param array<string,mixed> $exif
	 * @return array<string,mixed>
	 */
	public static function fields( array $exif ): array {
		$text  = static fn( string $k ): string => is_string( $exif[ $k ] ?? null ) ? trim( $exif[ $k ] ) : '';
		$int   = static fn( string $k ): ?int => isset( $exif[ $k ] ) && is_numeric( $exif[ $k ] ) ? (int) $exif[ $k ] : null;
		$label = static fn( string $k, array $names ): string => $names[ $int( $k ) ?? -1 ] ?? '';

		$fields = [
			'make'               => $text( 'Make' ),
			'lens_make'          => $text( 'LensMake' ) ?: $text( 'UndefinedTag:0xA433' ),
			'lens'               => $text( 'LensModel' ) ?: $text( 'UndefinedTag:0xA434' ),
			'focal_length_35mm'  => $int( 'FocalLengthIn35mmFilm' ),
			'exposure_bias'      => self::number( $exif['ExposureBiasValue'] ?? null ),
			'exposure_program'   => $label( 'ExposureProgram', [ 1 => 'manual', 2 => 'program', 3 => 'aperture priority', 4 => 'shutter priority', 5 => 'creative', 6 => 'action', 7 => 'portrait', 8 => 'landscape' ] ),
			'metering_mode'      => $label( 'MeteringMode', [ 1 => 'average', 2 => 'centre-weighted', 3 => 'spot', 4 => 'multi-spot', 5 => 'matrix', 6 => 'partial' ] ),
			'flash'              => null === $int( 'Flash' ) ? null : (bool) ( $int( 'Flash' ) & 1 ),
			'white_balance'      => $label( 'WhiteBalance', [ 0 => 'auto', 1 => 'manual' ] ),
			'offset_time'        => $text( 'OffsetTimeOriginal' ),
			'software'           => $text( 'Software' ),
		];
		return array_filter( $fields, static fn( $v ): bool => null !== $v && '' !== $v );
	}

	/**
	 * Decimal latitude and longitude from exif_read_data() GPS fields, or null.
	 *
	 * @param array<string,mixed> $exif
	 * @return array{lat: float, lng: float}|null
	 */
	public static function location( array $exif ): ?array {
		$lat = self::degrees( $exif['GPSLatitude'] ?? null, (string) ( $exif['GPSLatitudeRef'] ?? 'N' ) );
		$lng = self::degrees( $exif['GPSLongitude'] ?? null, (string) ( $exif['GPSLongitudeRef'] ?? 'E' ) );
		return null === $lat || null === $lng || ( 0.0 === $lat && 0.0 === $lng ) ? null : [ 'lat' => $lat, 'lng' => $lng ];
	}

	/** @param mixed $dms [degrees, minutes, seconds] as EXIF rationals. */
	private static function degrees( $dms, string $ref ): ?float {
		if ( ! is_array( $dms ) || count( $dms ) < 3 ) {
			return null;
		}
		[ $d, $m, $s ] = array_map( [ self::class, 'number' ], array_slice( array_values( $dms ), 0, 3 ) );
		if ( null === $d || null === $m || null === $s ) {
			return null;
		}
		$deg = $d + $m / 60 + $s / 3600;
		return round( in_array( strtoupper( $ref ), [ 'S', 'W' ], true ) ? -$deg : $deg, 6 );
	}

	/** An EXIF number or rational ("178/100") as a float. @param mixed $value */
	private static function number( $value ): ?float {
		if ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) ) ) {
			return (float) $value;
		}
		if ( is_string( $value ) && preg_match( '#^(-?\d+)/(\d+)$#', $value, $m ) ) {
			return 0 === (int) $m[2] ? null : (int) $m[1] / (int) $m[2];
		}
		return null;
	}
}
