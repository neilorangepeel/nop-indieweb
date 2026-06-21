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
 * that isn't an MP4, and per-instance caps are tight (Pixelfed Stories ~14.6 MB,
 * Mastodon ~40 MB). Two strategies, picked by what the host's ffmpeg can do:
 *
 *  1. ENCODE (preferred) — when libx264 is available, downscale to 1080p and
 *     re-encode H.264 at CRF (visually small, not lossy-looking) with a maxrate
 *     cap derived from the network's byte budget so the file can't overshoot, and
 *     an optional duration cap (stories are 15 s). This also rescues HEVC sources.
 *  2. REMUX (fallback) — when there's no usable encoder (e.g. SiteGround's stock
 *     ffmpeg has no libx264), losslessly repackage an existing H.264 stream
 *     (-c copy, +faststart) and trim duration to fit. Can't touch non-H.264.
 *
 * SiteGround's sandbox blocks libx264's worker threads, so the encode runs
 * single-threaded (-threads 1). The result is cached per (budget, duration) on
 * the attachment so the three syndicators share transcodes. Returns null — caller
 * fails cleanly — when the tools are missing, exec() is disabled, or ffmpeg errors.
 */
final class Video_Transcoder {

	private const CACHE_META = '_nop_synd_web_mp4';

	/** Default upload ceiling — under Mastodon's common 40 MB instance cap. */
	private const DEFAULT_MAX_BYTES = 38 * 1024 * 1024;

	/** Downscale to fit a 1080p box (longest side ≤1920), never upscaling past it. */
	private const SCALE = 'scale=1920:1920:force_original_aspect_ratio=decrease:force_divisible_by=2';

	/**
	 * Returns a path to a network MP4 derivative of $attachment_id, or null.
	 *
	 * @param int $max_bytes   Output ceiling (0 = the default budget).
	 * @param int $max_seconds Duration cap in seconds (0 = full length).
	 */
	public static function web_mp4( int $attachment_id, int $max_bytes = 0, int $max_seconds = 0 ): ?string {
		if ( $attachment_id <= 0 ) {
			return null;
		}

		$src = (string) get_attached_file( $attachment_id );
		if ( '' === $src || ! is_readable( $src ) ) {
			return null;
		}

		if ( $max_bytes <= 0 ) {
			$max_bytes = (int) apply_filters( 'nop_indieweb_syndication_video_max_bytes', self::DEFAULT_MAX_BYTES, $attachment_id );
		}
		$cache_key = self::CACHE_META . "_{$max_bytes}_{$max_seconds}";

		// Reuse the cached derivative unless the source has changed since.
		$cached = (string) get_post_meta( $attachment_id, $cache_key, true );
		if ( '' !== $cached && is_readable( $cached ) && filemtime( $cached ) >= filemtime( $src ) ) {
			return $cached;
		}

		$ffmpeg = self::bin( 'ffmpeg' );
		if ( null === $ffmpeg ) {
			return null;
		}

		$probe   = self::probe( $src );
		$can_enc = self::has_libx264( $ffmpeg );

		// No encoder + a non-H.264 source = nothing we can do (can't remux it into a
		// container the networks accept, can't transcode it). Bail with a reason.
		if ( ! $can_enc && null !== $probe && 'h264' !== $probe['codec'] ) {
			\NOP\IndieWeb\nop_indieweb_log( "Video transcode skipped for attachment {$attachment_id}: source is {$probe['codec']}, no libx264 to convert" );
			return null;
		}

		$out = preg_replace( '/\.[A-Za-z0-9]+$/', '', $src ) . "-synd-{$max_bytes}-{$max_seconds}.mp4";
		$dur = is_array( $probe ) ? (float) $probe['duration'] : 0.0;

		$ok = $can_enc && self::encode( $ffmpeg, $src, (string) $out, $max_bytes, $max_seconds, $dur );

		// Lossless remux+trim when encoding is unavailable or fell over.
		if ( ! $ok ) {
			$ok = self::remux_to_fit( $ffmpeg, $src, (string) $out, $max_bytes, $max_seconds, $dur );
		}

		if ( ! $ok ) {
			\NOP\IndieWeb\nop_indieweb_log( "Video transcode failed for attachment {$attachment_id}" );
			return null;
		}

		update_post_meta( $attachment_id, $cache_key, (string) $out );
		return (string) $out;
	}

	/**
	 * Re-encode to 1080p H.264. CRF sets the quality (small without a lossy look);
	 * a maxrate derived from the byte budget caps peak bitrate so the file fits even
	 * on tight networks, leaving ~160 kbps of headroom for audio + container.
	 */
	private static function encode( string $ffmpeg, string $src, string $out, int $max_bytes, int $max_seconds, float $duration ): bool {
		$enc_dur = $duration > 0 ? $duration : ( $max_seconds > 0 ? (float) $max_seconds : 30.0 );
		if ( $max_seconds > 0 ) {
			$enc_dur = min( $enc_dur, (float) $max_seconds );
		}
		$maxrate = (int) max( 500, ( $max_bytes * 8 / $enc_dur / 1000 - 160 ) * 0.95 );
		$crf     = (int) apply_filters( 'nop_indieweb_syndication_video_crf', 23 );
		$trim    = $max_seconds > 0 ? '-t ' . (int) $max_seconds . ' ' : '';

		$cmd = sprintf(
			'%s -y -loglevel error -i %s %s-threads 1 -vf %s -c:v libx264 -x264-params threads=1 -preset veryfast -crf %d -maxrate %dk -bufsize %dk -pix_fmt yuv420p -profile:v high -c:a aac -b:a 128k -movflags +faststart %s 2>&1',
			escapeshellcmd( $ffmpeg ),
			escapeshellarg( $src ),
			$trim,
			escapeshellarg( self::SCALE ),
			$crf,
			$maxrate,
			$maxrate * 2,
			escapeshellarg( $out )
		);
		return self::run( $cmd, $out );
	}

	/** Lossless remux, then iterative duration-trim until under budget (no encoder). */
	private static function remux_to_fit( string $ffmpeg, string $src, string $out, int $max_bytes, int $max_seconds, float $duration ): bool {
		if ( ! self::remux( $ffmpeg, $src, $out, $max_seconds ) ) {
			return false;
		}
		// 4K bitrate is bursty, so one proportional cut can overshoot — re-trim from
		// the ACTUAL achieved size, converging in a couple of (instant) stream copies.
		$seconds = $max_seconds > 0 ? min( $duration ?: (float) $max_seconds, (float) $max_seconds ) : $duration;
		for ( $i = 0; $i < 4 && $seconds > 1 && filesize( $out ) > $max_bytes; $i++ ) {
			$seconds = max( 1.0, $seconds * ( $max_bytes / filesize( $out ) ) * 0.9 );
			if ( ! self::remux( $ffmpeg, $src, $out, (int) round( $seconds ) ) ) {
				break;
			}
		}
		return true;
	}

	/** mov→mp4 stream copy (+faststart), optionally capped to $seconds (0 = full). */
	private static function remux( string $ffmpeg, string $src, string $out, int $seconds ): bool {
		$trim = $seconds > 0 ? '-t ' . (int) $seconds . ' ' : '';
		$cmd  = sprintf(
			'%s -y -loglevel error -i %s -map 0:v:0 -map 0:a? %s-c copy -movflags +faststart %s 2>&1',
			escapeshellcmd( $ffmpeg ),
			escapeshellarg( $src ),
			$trim,
			escapeshellarg( $out )
		);
		return self::run( $cmd, $out );
	}

	/** Runs an ffmpeg command; true when it produced a non-trivial file. */
	private static function run( string $cmd, string $out ): bool {
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

	/** Whether $ffmpeg can encode H.264 in software (libx264). Cached per binary. */
	private static function has_libx264( string $ffmpeg ): bool {
		static $cache = [];
		if ( isset( $cache[ $ffmpeg ] ) ) {
			return $cache[ $ffmpeg ];
		}
		$output = [];
		exec( escapeshellcmd( $ffmpeg ) . ' -hide_banner -encoders 2>/dev/null', $output );
		$has = false;
		foreach ( $output as $line ) {
			if ( false !== strpos( $line, 'libx264' ) ) {
				$has = true;
				break;
			}
		}
		return $cache[ $ffmpeg ] = $has;
	}

	/**
	 * Probes the first video stream's codec + duration via ffprobe (stock ffprobe
	 * is fine — probing needs no encoder). Null when ffprobe is unavailable.
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
