<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Syndication;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a video attachment into a network-friendly MP4 for social upload.
 *
 * iOS captures video as QuickTime (.mov); Bluesky and Pixelfed reject anything
 * that isn't an MP4, and an oversized clip both stalls the syndication cron and
 * trips per-instance video caps (Mastodon's is ~40 MB). iOS "Most Compatible"
 * captures H.264/AAC already, so ffmpeg REMUXES the local attachment into an MP4
 * losslessly (-c copy, no re-encode) with the moov atom up front (faststart) —
 * this host's ffmpeg has no software/usable-hardware H.264 encoder, so re-encoding
 * isn't on the table. When the remux is over the byte budget it's trimmed (still a
 * stream copy) to fit. The result is cached on the attachment so the three
 * syndicators share one transcode.
 *
 * Returns null — and the caller fails cleanly — when the source isn't H.264, the
 * tools are missing, exec() is disabled, or ffmpeg errors.
 */
final class Video_Transcoder {

	private const CACHE_META = '_nop_synd_web_mp4';

	/** Default upload ceiling — under Mastodon's common 40 MB instance cap. */
	private const DEFAULT_MAX_BYTES = 38 * 1024 * 1024;

	/** Returns a path to a network MP4 derivative of $attachment_id, or null. */
	public static function web_mp4( int $attachment_id ): ?string {
		if ( $attachment_id <= 0 ) {
			return null;
		}

		$src = (string) get_attached_file( $attachment_id );
		if ( '' === $src || ! is_readable( $src ) ) {
			return null;
		}

		// Reuse the cached derivative unless the source has changed since.
		$cached = (string) get_post_meta( $attachment_id, self::CACHE_META, true );
		if ( '' !== $cached && is_readable( $cached ) && filemtime( $cached ) >= filemtime( $src ) ) {
			return $cached;
		}

		$ffmpeg = self::bin( 'ffmpeg' );
		if ( null === $ffmpeg ) {
			return null;
		}

		// We can only repackage an existing H.264 stream — there's no encoder here
		// to convert HEVC/other to H.264, so bail with a clear reason instead of
		// shipping a container the networks will reject.
		$probe = self::probe( $src );
		if ( null !== $probe && 'h264' !== $probe['codec'] ) {
			\NOP\IndieWeb\nop_indieweb_log( "Video transcode skipped for attachment {$attachment_id}: source is {$probe['codec']}, not h264 (no encoder available to convert)" );
			return null;
		}

		$out       = preg_replace( '/\.[A-Za-z0-9]+$/', '', $src ) . '-synd.mp4';
		$max_bytes = (int) apply_filters( 'nop_indieweb_syndication_video_max_bytes', self::DEFAULT_MAX_BYTES, $attachment_id );

		// Full lossless remux first; trim to fit only if it lands over budget.
		if ( ! self::remux( $ffmpeg, $src, (string) $out, 0 ) ) {
			\NOP\IndieWeb\nop_indieweb_log( "Video remux failed for attachment {$attachment_id}" );
			return null;
		}

		if ( filesize( (string) $out ) > $max_bytes && null !== $probe && $probe['duration'] > 0 ) {
			// Stream-copy trim cuts on a keyframe, so aim 5% under budget for slack.
			$seconds = (int) max( 1, floor( $probe['duration'] * ( $max_bytes / filesize( (string) $out ) ) * 0.95 ) );
			self::remux( $ffmpeg, $src, (string) $out, $seconds );
		}

		update_post_meta( $attachment_id, self::CACHE_META, (string) $out );
		return (string) $out;
	}

	/** Lossless mov→mp4 remux (+faststart), optionally capped to $seconds (0 = full). */
	private static function remux( string $ffmpeg, string $src, string $out, int $seconds ): bool {
		$dur = $seconds > 0 ? '-t ' . escapeshellarg( (string) $seconds ) . ' ' : '';
		$cmd = sprintf(
			'%s -y -loglevel error -i %s -map 0:v:0 -map 0:a? %s-c copy -movflags +faststart %s 2>&1',
			escapeshellcmd( $ffmpeg ),
			escapeshellarg( $src ),
			$dur,
			escapeshellarg( $out )
		);

		$output = [];
		$status = 1;
		exec( $cmd, $output, $status );

		if ( 0 !== $status || ! is_readable( $out ) || filesize( $out ) < 1024 ) {
			if ( is_file( $out ) ) {
				@unlink( $out );
			}
			return false;
		}
		return true;
	}

	/**
	 * Probes the first video stream's codec + duration via ffprobe.
	 * Returns null when ffprobe is unavailable (callers then skip codec/size logic).
	 *
	 * @return array{codec:string,duration:float}|null
	 */
	private static function probe( string $src ): ?array {
		$ffprobe = self::bin( 'ffprobe' );
		if ( null === $ffprobe ) {
			return null;
		}
		$cmd = sprintf(
			'%s -v error -select_streams v:0 -show_entries stream=codec_name -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>/dev/null',
			escapeshellcmd( $ffprobe ),
			escapeshellarg( $src )
		);
		$lines = [];
		exec( $cmd, $lines );
		if ( count( $lines ) < 1 ) {
			return null;
		}
		return [
			'codec'    => trim( (string) $lines[0] ),
			'duration' => (float) ( $lines[1] ?? 0 ),
		];
	}

	/** Locates an ffmpeg-family binary, or null when unavailable / exec() is disabled. */
	private static function bin( string $name ): ?string {
		if ( ! function_exists( 'exec' ) || ! function_exists( 'escapeshellarg' ) ) {
			return null;
		}
		$configured = (string) apply_filters( "nop_indieweb_{$name}_bin", '' );
		if ( '' !== $configured && is_executable( $configured ) ) {
			return $configured;
		}
		$found = trim( (string) @shell_exec( 'command -v ' . escapeshellarg( $name ) . ' 2>/dev/null' ) );
		return '' !== $found ? $found : null;
	}
}
