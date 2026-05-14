<?php
declare( strict_types=1 );

namespace NOP\IndieWeb;

/**
 * Block content helpers used by syndication.
 *
 * Walks parse_blocks() output to produce:
 *   - plain text with paragraph breaks preserved
 *   - a list of image references with alt text and dimensions
 *
 * Used by the syndicators to compose posts for services that take plain text
 * + native media attachments (Mastodon, Bluesky, etc.).
 */

/**
 * Extracts a plain-text rendering of block content for syndication.
 *
 * Paragraphs are joined with "\n\n". <br> inside a block becomes "\n".
 * Image, gallery, button, separator, and spacer blocks are skipped — they're
 * either handled separately (images) or have no useful text content.
 *
 * Returns the empty string if no text content is found.
 */
function nop_indieweb_block_text( string $post_content ): string {
	if ( '' === trim( $post_content ) ) {
		return '';
	}
	$parts = nop_indieweb_walk_blocks_for_text( parse_blocks( $post_content ) );
	return trim( implode( "\n\n", $parts ) );
}

/**
 * Returns up to $limit image references found in block content.
 *
 * Each item: [ 'url' => string, 'alt' => string, 'attachment_id' => int, 'width' => int, 'height' => int ].
 * Images inside galleries and nested containers are included.
 */
function nop_indieweb_block_images( string $post_content, int $limit = 4 ): array {
	if ( $limit < 1 || '' === trim( $post_content ) ) {
		return [];
	}
	$images = [];
	nop_indieweb_collect_block_images( parse_blocks( $post_content ), $images, $limit );
	return $images;
}

/**
 * Returns the first core/video block reference found in block content, or null.
 *
 * { url, alt, attachment_id, width, height }. Alt is read from the attachment's
 * caption (post_excerpt) — that's where the importer parks Bluesky/Mastodon
 * alt text since WP video attachments don't expose an Alt Text field.
 *
 * Mastodon and Bluesky each cap status posts at one video, so we only need
 * the first occurrence.
 */
function nop_indieweb_block_video( string $post_content ): ?array {
	if ( '' === trim( $post_content ) ) {
		return null;
	}
	return nop_indieweb_find_first_video( parse_blocks( $post_content ) );
}

/** @internal */
function nop_indieweb_walk_blocks_for_text( array $blocks ): array {
	$parts = [];
	foreach ( $blocks as $block ) {
		$name = $block['blockName'] ?? null;

		// Classic-editor / freeform HTML appears as a block with no name.
		if ( null === $name ) {
			$text = nop_indieweb_html_to_text( $block['innerHTML'] ?? '' );
			if ( '' !== $text ) {
				$parts[] = $text;
			}
			continue;
		}

		// Skip blocks whose content lives elsewhere or is purely visual.
		if ( in_array( $name, [
			'core/image',
			'core/gallery',
			'core/video',
			'core/audio',
			'core/cover',
			'core/separator',
			'core/spacer',
			'core/buttons',
			'core/button',
			'core/embed',
			'core/file',
		], true ) ) {
			continue;
		}

		// List blocks: render each list-item as a bullet line so structure survives.
		if ( 'core/list' === $name ) {
			$items = [];
			foreach ( $block['innerBlocks'] ?? [] as $li ) {
				if ( 'core/list-item' !== ( $li['blockName'] ?? '' ) ) {
					continue;
				}
				$text = nop_indieweb_html_to_text( $li['innerHTML'] ?? '' );
				if ( '' !== $text ) {
					$items[] = '- ' . $text;
				}
			}
			if ( $items ) {
				$parts[] = implode( "\n", $items );
			}
			continue;
		}

		// Container blocks: recurse into children, no own text content.
		if ( in_array( $name, [ 'core/group', 'core/columns', 'core/column', 'core/row', 'core/stack' ], true ) ) {
			$inner = nop_indieweb_walk_blocks_for_text( $block['innerBlocks'] ?? [] );
			if ( $inner ) {
				$parts = array_merge( $parts, $inner );
			}
			continue;
		}

		$text = nop_indieweb_html_to_text( $block['innerHTML'] ?? '' );
		if ( '' !== $text ) {
			$parts[] = $text;
		}
	}
	return $parts;
}

/** @internal */
function nop_indieweb_collect_block_images( array $blocks, array &$images, int $limit ): void {
	foreach ( $blocks as $block ) {
		if ( count( $images ) >= $limit ) {
			return;
		}
		$name = $block['blockName'] ?? '';
		if ( 'core/image' === $name ) {
			$img = nop_indieweb_image_from_block( $block );
			if ( $img ) {
				$images[] = $img;
			}
			continue;
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			nop_indieweb_collect_block_images( $block['innerBlocks'], $images, $limit );
		}
	}
}

/** @internal */
function nop_indieweb_image_from_block( array $block ): ?array {
	$attrs = $block['attrs'] ?? [];
	$id    = (int) ( $attrs['id'] ?? 0 );
	$url   = '';
	$alt   = '';
	$w     = 0;
	$h     = 0;

	if ( $id ) {
		$src = wp_get_attachment_image_src( $id, 'large' );
		if ( $src ) {
			$url = (string) $src[0];
			$w   = (int) ( $src[1] ?? 0 );
			$h   = (int) ( $src[2] ?? 0 );
		}
		$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
	}

	// Fallback: pull from the block's serialized <img> tag.
	$html = $block['innerHTML'] ?? '';
	if ( '' === $url && preg_match( '/<img[^>]+src="([^"]+)"/i', $html, $m ) ) {
		$url = $m[1];
	}
	if ( '' === $alt && preg_match( '/<img[^>]+alt="([^"]*)"/i', $html, $m ) ) {
		$alt = $m[1];
	}

	if ( '' === $url ) {
		return null;
	}

	return [
		'url'           => $url,
		'alt'           => $alt,
		'attachment_id' => $id,
		'width'         => $w,
		'height'        => $h,
	];
}

/** @internal */
function nop_indieweb_find_first_video( array $blocks ): ?array {
	foreach ( $blocks as $block ) {
		$name = $block['blockName'] ?? '';
		if ( 'core/video' === $name ) {
			$video = nop_indieweb_video_from_block( $block );
			if ( $video ) {
				return $video;
			}
			continue;
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			$found = nop_indieweb_find_first_video( $block['innerBlocks'] );
			if ( $found ) {
				return $found;
			}
		}
	}
	return null;
}

/** @internal */
function nop_indieweb_video_from_block( array $block ): ?array {
	$attrs = $block['attrs'] ?? [];
	$id    = (int) ( $attrs['id'] ?? 0 );
	$url   = '';
	$alt   = '';
	$w     = 0;
	$h     = 0;
	$mime  = '';

	if ( $id ) {
		$url  = (string) wp_get_attachment_url( $id );
		$mime = (string) get_post_mime_type( $id );
		// Importer parks alt text in post_excerpt; fall back to image-alt meta
		// for older or hand-authored attachments.
		$alt = (string) get_post_field( 'post_excerpt', $id );
		if ( '' === $alt ) {
			$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		}
		$meta = wp_get_attachment_metadata( $id );
		$w    = (int) ( $meta['width']  ?? 0 );
		$h    = (int) ( $meta['height'] ?? 0 );
	}

	// Fallback: parse innerHTML for src.
	$html = $block['innerHTML'] ?? '';
	if ( '' === $url && preg_match( '/<video[^>]+src="([^"]+)"/i', $html, $m ) ) {
		$url = $m[1];
	}

	if ( '' === $url ) {
		return null;
	}

	return [
		'url'           => $url,
		'alt'           => $alt,
		'attachment_id' => $id,
		'width'         => $w,
		'height'        => $h,
		'mime'          => '' !== $mime ? $mime : 'video/mp4',
	];
}

/** @internal */
function nop_indieweb_html_to_text( string $html ): string {
	if ( '' === $html ) {
		return '';
	}
	$html = preg_replace( '/<br\s*\/?>/i', "\n", $html );
	return trim( wp_strip_all_tags( (string) $html ) );
}
