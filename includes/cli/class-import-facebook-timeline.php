<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Cli;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use NOP\IndieWeb\Kind\Kind_Taxonomy;
use WP_CLI;

/**
 * `wp nop-indieweb import-facebook-timeline`
 *
 * Imports curated Facebook timeline posts (notes, bookmarks, photos, videos,
 * check-ins) from a resolved import manifest produced by the archive-review
 * pipeline (build_manifest.py). The manifest is the single source of truth for
 * which posts to create and their already-cleaned text, kind, media list, and
 * bookmark/venue fields — this command only inserts posts and sideloads media.
 *
 * Every imported post is created as a draft by default, backdated to its
 * original Facebook timestamp, tagged service=facebook, and hard-flagged
 * skip_syndication so it can never POSSE back out (belt-and-suspenders with the
 * backdated-age guard in Syndication_Manager). Idempotent: skips any post whose
 * nop_indieweb_fb_archive_id already exists, so it is safe to re-run.
 *
 * ## OPTIONS
 *
 * <archive>
 * : Path to the root of the extracted Facebook archive (the folder that
 *   contains your_facebook_activity/). Required unless --skip-media.
 *
 * --manifest=<path>
 * : Path to fb_import_manifest.json.
 *
 * [--status=<status>]
 * : Post status for imported posts. Default: draft
 *
 * [--kinds=<list>]
 * : Comma-separated kinds to import (note,bookmark,photo,video,checkin).
 *   Default: note,bookmark,photo,video. Check-ins are excluded by default —
 *   production already holds richer Swarm versions of nearly all of them, so
 *   only import the handful that are genuinely missing, via --ids.
 *
 * [--ids=<list>]
 * : Comma-separated manifest ids — import only these entries. Useful for the
 *   few check-ins not already on production.
 *
 * [--limit=<n>]
 * : Import at most N posts (0 = no limit). Useful for a test batch.
 *
 * [--skip-media]
 * : Do not sideload photos/videos. Photo/video posts are created with their
 *   caption text only (media can be backfilled later by re-running without it).
 *
 * [--dry-run]
 * : Report what would be imported without creating any posts.
 *
 * @when after_wp_load
 */
class Import_Facebook_Timeline {

	private const KINDS = [ 'note', 'bookmark', 'photo', 'video', 'checkin' ];

	/** Check-in excluded by default — see class docblock / --ids. */
	private const DEFAULT_KINDS = [ 'note', 'bookmark', 'photo', 'video' ];

	public function __invoke( array $args, array $assoc_args ): void {
		$archive   = rtrim( (string) ( $args[0] ?? '' ), '/' );
		$manifest  = (string) ( $assoc_args['manifest'] ?? '' );
		$status    = (string) ( $assoc_args['status'] ?? 'draft' );
		$limit     = (int) ( $assoc_args['limit'] ?? 0 );
		$dry_run   = isset( $assoc_args['dry-run'] );
		$skip_media = isset( $assoc_args['skip-media'] );

		$only_kinds = isset( $assoc_args['kinds'] )
			? array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['kinds'] ) ) )
			: self::DEFAULT_KINDS;

		$only_ids = isset( $assoc_args['ids'] )
			? array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['ids'] ) ) )
			: [];

		if ( ! $manifest || ! file_exists( $manifest ) ) {
			WP_CLI::error( "Manifest not found: {$manifest}" );
		}
		if ( ! $skip_media && ! is_dir( $archive ) ) {
			WP_CLI::error( "Archive folder not found: {$archive} (or pass --skip-media)" );
		}

		$entries = json_decode( (string) file_get_contents( $manifest ), true );
		if ( ! is_array( $entries ) ) {
			WP_CLI::error( 'Manifest is not valid JSON.' );
		}

		$total = count( $entries );
		WP_CLI::log( sprintf(
			'%s%d manifest entries · status=%s · kinds=%s · media=%s',
			$dry_run ? '[DRY RUN] ' : '',
			$total, $status, implode( ',', $only_kinds ), $skip_media ? 'skipped' : 'sideload'
		) );

		$progress = \WP_CLI\Utils\make_progress_bar( $dry_run ? 'Scanning' : 'Importing', $total );
		$c = [ 'created' => 0, 'skipped' => 0, 'failed' => 0, 'media' => 0 ];
		$by_kind = [];

		foreach ( $entries as $e ) {
			$progress->tick();

			$kind = (string) ( $e['kind'] ?? '' );
			$id   = (string) ( $e['id'] ?? '' );

			if ( ! in_array( $kind, $only_kinds, true ) ) {
				continue;
			}
			if ( $only_ids && ! in_array( $id, $only_ids, true ) ) {
				continue;
			}
			if ( $limit && $c['created'] >= $limit ) {
				break;
			}
			if ( ! $id || ! in_array( $kind, self::KINDS, true ) ) {
				$c['skipped']++;
				continue;
			}
			if ( $this->already_imported( $id ) ) {
				$c['skipped']++;
				continue;
			}

			if ( $dry_run ) {
				$c['created']++;
				$by_kind[ $kind ] = ( $by_kind[ $kind ] ?? 0 ) + 1;
				continue;
			}

			$post_id = $this->create_post( $e, $kind, $status );
			if ( is_wp_error( $post_id ) ) {
				WP_CLI::warning( "  fail [{$kind} {$id}]: " . $post_id->get_error_message() );
				$c['failed']++;
				continue;
			}

			$c['created']++;
			$by_kind[ $kind ] = ( $by_kind[ $kind ] ?? 0 ) + 1;

			if ( ! $skip_media && ! empty( $e['media'] ) ) {
				$n = $this->attach_media( $post_id, (array) $e['media'], $archive );
				$c['media'] += $n;
			}
		}

		$progress->finish();
		foreach ( $by_kind as $k => $n ) {
			WP_CLI::log( "  {$k}: {$n}" );
		}
		WP_CLI::success( sprintf(
			'%s%d created · %d skipped · %d failed · %d media files attached.',
			$dry_run ? '[DRY RUN] ' : '',
			$c['created'], $c['skipped'], $c['failed'], $c['media']
		) );
	}

	private function already_imported( string $id ): bool {
		return (bool) get_posts( [
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'post_status'    => 'any',
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-off import lookup, not a hot path
			'meta_key'       => 'nop_indieweb_fb_archive_id',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one-off import lookup, not a hot path
			'meta_value'     => $id,
		] );
	}

	private function create_post( array $e, string $kind, string $status ): int|\WP_Error {
		$text          = (string) ( $e['text'] ?? '' );
		$post_date_gmt = (string) ( $e['date_gmt'] ?? gmdate( 'Y-m-d H:i:s', (int) ( $e['timestamp'] ?? 0 ) ) );
		$post_date     = get_date_from_gmt( $post_date_gmt );

		$title   = '';
		$content = $this->paragraph_blocks( $text );
		$meta    = [
			'nop_indieweb_service'          => 'facebook',
			'nop_indieweb_platform'         => 'facebook',
			'nop_indieweb_skip_syndication' => '1',
			'nop_indieweb_fb_archive_id'    => (string) ( $e['id'] ?? '' ),
		];

		if ( 'bookmark' === $kind && ! empty( $e['bookmark']['url'] ) ) {
			$bm      = $e['bookmark'];
			$title   = (string) ( $bm['title'] ?? '' );
			$content = "<!-- wp:nop-indieweb/cite-card /-->\n\n" . $content;

			$meta['nop_indieweb_bookmark_of'] = (string) $bm['url'];
			// Pre-seed the cite so Cite_Enricher's idempotency guard skips the
			// live fetch (many of these old links are dead anyway).
			$meta['nop_indieweb_cite_title']     = (string) ( $bm['title'] ?: wp_parse_url( (string) $bm['url'], PHP_URL_HOST ) );
			$meta['nop_indieweb_cite_site_name'] = (string) ( $bm['site'] ?? '' );
			$meta['_nop_indieweb_cite_source']   = (string) $bm['url'];
		}

		if ( 'checkin' === $kind && ! empty( $e['venue'] ) ) {
			$v     = $e['venue'];
			$name  = (string) ( $v['name'] ?? '' );
			$addr  = (string) ( $v['address'] ?? '' );
			// FB city tags already carry the locality in the name ("Belfast, United
			// Kingdom"); only append the address when it adds something new.
			$append = $addr && $addr !== $name && false === stripos( $name, $addr );
			$title  = $append ? "{$name}, {$addr}" : ( $name ?: 'Checked in' );

			$meta['nop_indieweb_venue_name']    = $name;
			$meta['nop_indieweb_venue_lat']     = (string) ( $v['lat'] ?? '' );
			$meta['nop_indieweb_venue_lng']     = (string) ( $v['lng'] ?? '' );
			$meta['nop_indieweb_venue_address'] = $addr;
		}

		// Caption-less photo/video posts have empty content until attach_media()
		// appends the image/video blocks — give wp_insert_post a placeholder space
		// (it rejects an all-empty post) that attach_media's rtrim() then strips.
		if ( '' === trim( $content ) && '' === $title ) {
			$content = ' ';
		}

		$post_id = wp_insert_post( [
			'post_title'    => $title,
			'post_content'  => $content,
			'post_status'   => $status,
			'post_type'     => 'post',
			'post_date'     => $post_date,
			'post_date_gmt' => $post_date_gmt,
			'tags_input'    => [ 'Facebook' ],
			'meta_input'    => $meta,
		], true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		wp_set_object_terms( $post_id, $kind, Kind_Taxonomy::TAXONOMY );

		return $post_id;
	}

	/** One wp:paragraph block per blank-line-separated paragraph; single newlines become <br>. */
	private function paragraph_blocks( string $text ): string {
		$text = trim( $text );
		if ( '' === $text ) {
			return '';
		}
		$out = [];
		foreach ( preg_split( '/\n{2,}/', $text ) as $para ) {
			$para = trim( (string) $para );
			if ( '' === $para ) {
				continue;
			}
			$html  = nl2br( esc_html( $para ) );
			$out[] = "<!-- wp:paragraph -->\n<p>{$html}</p>\n<!-- /wp:paragraph -->";
		}
		return implode( "\n\n", $out );
	}

	/**
	 * Sideloads the entry's media into the library, appends image/gallery/video
	 * blocks to the post, sets the first image as the featured image, and records
	 * the photo_ids/photos meta the rest of the plugin reads.
	 */
	private function attach_media( int $post_id, array $media, string $archive_root ): int {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$images = [];   // attachment ids
		$videos = [];   // attachment ids
		$all    = [];   // attachment ids in source order
		$set_featured = ! has_post_thumbnail( $post_id );

		foreach ( $media as $m ) {
			$rel = (string) ( $m['uri'] ?? '' );
			if ( '' === $rel ) {
				continue;
			}
			$src = $archive_root . '/' . $rel;
			if ( ! file_exists( $src ) ) {
				WP_CLI::warning( "  media missing in archive: {$rel}" );
				continue;
			}

			$ext = strtolower( pathinfo( $src, PATHINFO_EXTENSION ) ) ?: 'jpg';
			$tmp = tempnam( sys_get_temp_dir(), 'fb-media-' ) . '.' . $ext;
			if ( ! copy( $src, $tmp ) ) {
				WP_CLI::warning( "  could not copy media: {$rel}" );
				continue;
			}

			$att = media_handle_sideload( [ 'name' => basename( $rel ), 'tmp_name' => $tmp ], $post_id );
			if ( is_wp_error( $att ) ) {
				WP_CLI::warning( "  sideload failed ({$rel}): " . $att->get_error_message() );
				wp_delete_file( $tmp );
				continue;
			}

			$alt = (string) ( $m['alt'] ?? '' );
			if ( '' !== $alt ) {
				update_post_meta( $att, '_wp_attachment_image_alt', $alt );
			}

			$is_video = in_array( $ext, [ 'mp4', 'mov', 'm4v', 'flv' ], true );
			$all[]    = $att;
			if ( $is_video ) {
				$videos[] = $att;
			} else {
				$images[] = $att;
				if ( $set_featured ) {
					set_post_thumbnail( $post_id, $att );
					$set_featured = false;
				}
			}
		}

		if ( ! $all ) {
			return 0;
		}

		update_post_meta( $post_id, 'nop_indieweb_photo_ids', $all );
		update_post_meta( $post_id, 'nop_indieweb_photos', array_values( array_filter(
			array_map( 'wp_get_attachment_url', $all )
		) ) );

		$blocks = $this->media_blocks( $images, $videos );
		if ( $blocks ) {
			$post    = get_post( $post_id );
			$current = rtrim( (string) ( $post->post_content ?? '' ) );
			wp_update_post( [
				'ID'           => $post_id,
				'post_content' => ( $current ? $current . "\n\n" : '' ) . $blocks,
			] );
		}

		return count( $all );
	}

	private function media_blocks( array $images, array $videos ): string {
		$parts = [];

		if ( 1 === count( $images ) ) {
			$parts[] = $this->image_block( $images[0] );
		} elseif ( count( $images ) > 1 ) {
			$inner = '';
			foreach ( $images as $id ) {
				$inner .= "\n" . $this->image_block( $id );
			}
			$parts[] = "<!-- wp:gallery {\"columns\":2,\"linkTo\":\"none\"} -->\n"
				. "<figure class=\"wp-block-gallery has-nested-images columns-2 is-cropped\">{$inner}\n</figure>\n"
				. "<!-- /wp:gallery -->";
		}

		foreach ( $videos as $id ) {
			$src = wp_get_attachment_url( $id );
			$parts[] = sprintf(
				"<!-- wp:video {\"id\":%d} -->\n<figure class=\"wp-block-video\"><video controls src=\"%s\"></video></figure>\n<!-- /wp:video -->",
				$id, esc_url( (string) $src )
			);
		}

		return implode( "\n\n", $parts );
	}

	private function image_block( int $id ): string {
		$src = wp_get_attachment_url( $id );
		$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		return sprintf(
			"<!-- wp:image {\"id\":%d,\"sizeSlug\":\"large\",\"linkDestination\":\"none\"} -->\n"
			. "<figure class=\"wp-block-image size-large\"><img src=\"%s\" alt=\"%s\" class=\"wp-image-%d\"/></figure>\n"
			. "<!-- /wp:image -->",
			$id, esc_url( (string) $src ), esc_attr( $alt ), $id
		);
	}
}
