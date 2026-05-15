<?php
// CLI-only — refuse to run if reached over HTTP.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'This file may only be executed via WP-CLI.' );
}
/**
 * Smoke test for the Bluesky import path. Run via:
 *   studio wp eval-file wp-content/plugins/nop-indieweb/bin/test-import.php
 *
 * Exercises bluesky_extract_photos / bluesky_extract_videos with synthetic
 * post records; no live API calls.
 */

use NOP\IndieWeb\Importer\Feed_Importer;
use NOP\IndieWeb\Services\Note;
use NOP\IndieWeb\Services\Letterboxd;

$importer = new Feed_Importer( new Note(), new Letterboxd() );

$ref            = new ReflectionClass( $importer );
$extract_photos = $ref->getMethod( 'bluesky_extract_photos' );
$extract_videos = $ref->getMethod( 'bluesky_extract_videos' );
$blob_url       = $ref->getMethod( 'bluesky_blob_url' );
$to_payload     = $ref->getMethod( 'bluesky_to_payload' );

$did = 'did:plc:xlqxp4tmt6pvgpycrsshih2l';

echo "── blob URL ──\n";
echo $blob_url->invoke( $importer, $did, 'bafkreitest123' ) . "\n";

echo "\n── photo extraction (2 images, with sizes) ──\n";
$post_with_images = [
	'author' => [ 'did' => $did ],
	'record' => [
		'text'  => 'A test post',
		'embed' => [
			'$type'  => 'app.bsky.embed.images',
			'images' => [
				[
					'alt'   => 'alpha',
					'image' => [
						'$type'    => 'blob',
						'ref'      => [ '$link' => 'bafkreialphaCID' ],
						'mimeType' => 'image/jpeg',
						'size'     => 850000,
					],
				],
				[
					'alt'   => 'bravo (huge, should use fallback)',
					'image' => [
						'$type'    => 'blob',
						'ref'      => [ '$link' => 'bafkreibravoCID' ],
						'mimeType' => 'image/jpeg',
						'size'     => 50 * 1024 * 1024,
					],
				],
			],
		],
	],
	'embed' => [
		'$type'  => 'app.bsky.embed.images#view',
		'images' => [
			[ 'fullsize' => 'https://cdn.bsky.app/img/feed_fullsize/alpha.jpg', 'alt' => 'alpha' ],
			[ 'fullsize' => 'https://cdn.bsky.app/img/feed_fullsize/bravo.jpg', 'alt' => 'bravo' ],
		],
	],
];

$photos = $extract_photos->invoke( $importer, $post_with_images, $did );
foreach ( $photos as $i => $p ) {
	echo "  [$i] primary={$p['primary']}\n";
	echo "      fallback={$p['fallback']} size={$p['size']} alt='{$p['alt']}'\n";
}

echo "\n── video extraction ──\n";
$post_with_video = [
	'author' => [ 'did' => $did ],
	'record' => [
		'text'  => 'A video post',
		'embed' => [
			'$type' => 'app.bsky.embed.video',
			'video' => [
				'$type'    => 'blob',
				'ref'      => [ '$link' => 'bafkreivideoCID' ],
				'mimeType' => 'video/mp4',
				'size'     => 12345678,
			],
			'alt'         => 'a short clip',
			'aspectRatio' => [ 'width' => 1080, 'height' => 1920 ],
		],
	],
];

$videos = $extract_videos->invoke( $importer, $post_with_video, $did );
foreach ( $videos as $v ) {
	echo "  primary={$v['primary']}\n  size={$v['size']} alt='{$v['alt']}' {$v['width']}x{$v['height']}\n";
}

echo "\n── recordWithMedia (image inside quote) ──\n";
$rwm_post = $post_with_images;
$rwm_post['record']['embed'] = [
	'$type'  => 'app.bsky.embed.recordWithMedia',
	'record' => [ '$type' => 'app.bsky.embed.record', 'record' => [ /* quoted */ ] ],
	'media'  => [
		'$type'  => 'app.bsky.embed.images',
		'images' => [ [
			'alt'   => 'rwm',
			'image' => [
				'ref'  => [ '$link' => 'bafkreirwmCID' ],
				'size' => 100000,
			],
		] ],
	],
];
$rwm_post['embed'] = [
	'$type' => 'app.bsky.embed.recordWithMedia#view',
	'media' => [
		'$type'  => 'app.bsky.embed.images#view',
		'images' => [ [ 'fullsize' => 'https://cdn.bsky.app/img/rwm.jpg', 'alt' => 'rwm' ] ],
	],
];
$photos = $extract_photos->invoke( $importer, $rwm_post, $did );
echo "  rwm images found: " . count( $photos ) . "\n";
if ( $photos ) {
	echo "  primary={$photos[0]['primary']}\n";
}

echo "\n── full payload shape ──\n";
$payload = $to_payload->invoke( $importer, $post_with_images, 'https://bsky.app/profile/foo/post/abc' );
echo "properties.photo entries: " . count( $payload['properties']['photo'] ) . "\n";
echo "properties.video entries: " . count( $payload['properties']['video'] ) . "\n";

echo "\nOK\n";
