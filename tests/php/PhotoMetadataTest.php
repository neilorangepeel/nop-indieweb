<?php
declare( strict_types=1 );

use NOP\IndieWeb\Media\Photo_Metadata;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/media/class-photo-metadata.php';

final class PhotoMetadataTest extends TestCase {

	private const IPHONE = [
		'Make'                  => 'Apple',
		'Model'                 => 'iPhone 15 Pro',
		'UndefinedTag:0xA433'   => 'Apple',
		'UndefinedTag:0xA434'   => 'iPhone 15 Pro back triple camera 6.765mm f/1.78',
		'FocalLengthIn35mmFilm' => 24,
		'ExposureBiasValue'     => '-2/3',
		'ExposureProgram'       => 2,
		'MeteringMode'          => 5,
		'Flash'                 => 16,
		'WhiteBalance'          => 0,
		'OffsetTimeOriginal'    => '+00:00',
		'Software'              => '18.1',
		'GPSLatitudeRef'        => 'N',
		'GPSLatitude'           => [ '54/1', '35/1', '4500/100' ],
		'GPSLongitudeRef'       => 'W',
		'GPSLongitude'          => [ '5/1', '56/1', '1800/100' ],
	];

	public function test_reads_what_wordpress_does_not(): void {
		$this->assertSame(
			[
				'make'              => 'Apple',
				'lens_make'         => 'Apple',
				'lens'              => 'iPhone 15 Pro back triple camera 6.765mm f/1.78',
				'focal_length_35mm' => 24,
				'exposure_bias'     => -2 / 3,
				'exposure_program'  => 'program',
				'metering_mode'     => 'matrix',
				'flash'             => false,
				'white_balance'     => 'auto',
				'offset_time'       => '+00:00',
				'software'          => '18.1',
			],
			Photo_Metadata::fields( self::IPHONE )
		);
	}

	public function test_named_lens_tags_and_missing_fields(): void {
		$this->assertSame( [ 'lens' => 'XF23mmF2 R WR', 'flash' => true ], Photo_Metadata::fields( [ 'LensModel' => 'XF23mmF2 R WR', 'Flash' => 1, 'WhiteBalance' => 9 ] ) );
		$this->assertSame( [], Photo_Metadata::fields( [] ) );
	}

	public function test_location_in_decimal_degrees(): void {
		$this->assertSame( [ 'lat' => 54.595833, 'lng' => -5.938333 ], Photo_Metadata::location( self::IPHONE ) );
	}

	public function test_no_location_when_missing_or_zero(): void {
		$this->assertNull( Photo_Metadata::location( [ 'Make' => 'Apple' ] ) );
		$this->assertNull( Photo_Metadata::location( [ 'GPSLatitude' => [ '0/1', '0/1', '0/1' ], 'GPSLongitude' => [ '0/1', '0/1', '0/1' ] ] ) );
		$this->assertNull( Photo_Metadata::location( [ 'GPSLatitude' => [ '54/0', '0/1', '0/1' ], 'GPSLongitude' => [ '5/1', '0/1', '0/1' ] ] ) );
	}

	private static function segment( int $marker, string $body ): string {
		return "\xFF" . chr( $marker ) . pack( 'n', strlen( $body ) + 2 ) . $body;
	}

	public function test_strips_exif_xmp_iptc_and_comments_keeping_the_rest(): void {
		$app0 = self::segment( 0xE0, "JFIF\0\x01\x01" );
		$icc  = self::segment( 0xE2, "ICC_PROFILE\0display-p3" );
		$dqt  = self::segment( 0xDB, str_repeat( "\x01", 65 ) );
		$scan = "\xFF\xDA" . pack( 'n', 4 ) . "\x01\x02" . "\x12\x34\xFF\x00\x56" . "\xFF\xD9";
		$jpeg = "\xFF\xD8" . self::segment( 0xE1, "Exif\0\0GPS-here" ) . $app0 . self::segment( 0xE1, 'http://ns.adobe.com/xap/1.0/' ) . $icc . self::segment( 0xED, 'Photoshop 3.0' ) . self::segment( 0xFE, 'comment' ) . $dqt . $scan;

		$this->assertSame( "\xFF\xD8" . $app0 . $icc . $dqt . $scan, Photo_Metadata::strip_jpeg( $jpeg ) );
	}

	public function test_leaves_anything_it_cannot_read(): void {
		$this->assertSame( 'not a jpeg', Photo_Metadata::strip_jpeg( 'not a jpeg' ) );
		$broken = "\xFF\xD8\xFF\xE1\x00\x01";
		$this->assertSame( $broken, Photo_Metadata::strip_jpeg( $broken ) );
		$clean = "\xFF\xD8" . self::segment( 0xDB, 'q' ) . "\xFF\xDA\x00\x02data\xFF\xD9";
		$this->assertSame( $clean, Photo_Metadata::strip_jpeg( $clean ) );
	}
}
