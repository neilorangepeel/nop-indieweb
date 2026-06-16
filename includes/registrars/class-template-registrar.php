<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Registrars;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's block-theme templates (kind singles + archives) and
 * injects kind-based templates into the single-post hierarchy.
 */
class Template_Registrar {

	public function register(): void {
		add_action( 'init', [ $this, 'register_templates' ] );
		add_filter( 'single_template_hierarchy', [ $this, 'inject_kind_template' ] );
	}

	public function inject_kind_template( array $templates ): array {
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return $templates;
		}

		$kind = get_post_meta( $post->ID, 'nop_indieweb_post_kind', true );
		if ( $kind ) {
			array_unshift( $templates, "single-nop_kind-{$kind}" );
		}

		return $templates;
	}

	public function register_templates(): void {
		$dir = NOP_INDIEWEB_DIR . 'templates/';

		$templates = [
			// ── Kind single-post templates (single-nop_kind-{slug}) ─────────────────
			'nop-indieweb//single-nop_kind-checkin' => [
				'title'       => __( 'Single – Checkin', 'nop-indieweb' ),
				'description' => __( 'Displays a checkin post with venue, map, and syndication metadata.', 'nop-indieweb' ),
				'file'        => 'single-nop_kind-checkin.html',
			],
			'nop-indieweb//single-nop_kind-note' => [
				'title'       => __( 'Single – Note', 'nop-indieweb' ),
				'description' => __( 'Displays an imported social post (Mastodon, Bluesky) with platform attribution and source link.', 'nop-indieweb' ),
				'file'        => 'single-nop_kind-note.html',
			],
			'nop-indieweb//single-nop_kind-bookmark' => [
				'title'       => __( 'Single – Bookmark', 'nop-indieweb' ),
				'description' => __( 'Displays a bookmark post with the bookmarked URL.', 'nop-indieweb' ),
				'file'        => 'single-nop_kind-bookmark.html',
			],
			'nop-indieweb//single-nop_kind-reply' => [
				'title'       => __( 'Single – Reply', 'nop-indieweb' ),
				'description' => __( 'Displays a reply post with the URL being replied to.', 'nop-indieweb' ),
				'file'        => 'single-nop_kind-reply.html',
			],
			'nop-indieweb//single-nop_kind-like' => [
				'title'       => __( 'Single – Like', 'nop-indieweb' ),
				'description' => __( 'Displays a like post with the liked URL.', 'nop-indieweb' ),
				'file'        => 'single-nop_kind-like.html',
			],
			'nop-indieweb//single-nop_kind-repost' => [
				'title'       => __( 'Single – Repost', 'nop-indieweb' ),
				'description' => __( 'Displays a repost with the reposted URL.', 'nop-indieweb' ),
				'file'        => 'single-nop_kind-repost.html',
			],
			'nop-indieweb//single-nop_kind-rsvp' => [
				'title'       => __( 'Single – RSVP', 'nop-indieweb' ),
				'description' => __( 'Displays an RSVP post with the event URL and response.', 'nop-indieweb' ),
				'file'        => 'single-nop_kind-rsvp.html',
			],
			'nop-indieweb//single-nop_kind-watch' => [
				'title'       => __( 'Single – Film Diary Entry', 'nop-indieweb' ),
				'description' => __( 'Displays a Letterboxd film diary entry with star rating, poster, watch date, and review.', 'nop-indieweb' ),
				'file'        => 'single-nop_kind-watch.html',
			],
			'nop-indieweb//single-nop_kind-article' => [
				'title'       => __( 'Single – Article', 'nop-indieweb' ),
				'description' => __( 'Displays a long-form article with title, date, tags, and comments.', 'nop-indieweb' ),
				'file'        => 'single-nop_kind-article.html',
			],
			'nop-indieweb//single-nop_kind-photo' => [
				'title'       => __( 'Single – Photo', 'nop-indieweb' ),
				'description' => __( 'Displays a photo post as a bordered card with kind header, photo, caption, and interaction footer.', 'nop-indieweb' ),
				'file'        => 'single-nop_kind-photo.html',
			],
			'nop-indieweb//single-nop_kind-quote' => [
				'title'       => __( 'Single – Quote', 'nop-indieweb' ),
				'description' => __( 'Displays a quotation post with the quoted text and source attribution.', 'nop-indieweb' ),
				'file'        => 'single-nop_kind-quote.html',
			],
			'nop-indieweb//single-nop_kind-video' => [
				'title'       => __( 'Single – Video', 'nop-indieweb' ),
				'description' => __( 'Displays a video post with the video as the primary content.', 'nop-indieweb' ),
				'file'        => 'single-nop_kind-video.html',
			],
			'nop-indieweb//single-nop_kind-exercise' => [
				'title'       => __( 'Single – Exercise', 'nop-indieweb' ),
				'description' => __( 'Displays a workout post with activity stats (distance, duration, pace) and a start-location map.', 'nop-indieweb' ),
				'file'        => 'single-nop_kind-exercise.html',
			],

			// ── Kind archive templates (taxonomy-nop_kind-{slug}) ───────────────────────
			'nop-indieweb//taxonomy-nop_kind-watch' => [
				'title'       => __( 'Archive – Film Diary', 'nop-indieweb' ),
				'description' => __( 'Three-column poster grid for the watch kind. Shows poster, star rating, title, and watch date.', 'nop-indieweb' ),
				'file'        => 'taxonomy-nop_kind-watch.html',
			],
			'nop-indieweb//taxonomy-nop_kind-note' => [
				'title'       => __( 'Archive – Notes', 'nop-indieweb' ),
				'description' => __( 'Dense chronological stream for imported social posts. Shows content and platform attribution.', 'nop-indieweb' ),
				'file'        => 'taxonomy-nop_kind-note.html',
			],
			'nop-indieweb//taxonomy-nop_kind-checkin' => [
				'title'       => __( 'Archive – Checkins', 'nop-indieweb' ),
				'description' => __( 'Venue list for Swarm checkin posts. Shows venue name, locality, and date.', 'nop-indieweb' ),
				'file'        => 'taxonomy-nop_kind-checkin.html',
			],
			'nop-indieweb//taxonomy-nop_kind-bookmark' => [
				'title'       => __( 'Archive – Bookmarks', 'nop-indieweb' ),
				'description' => __( 'Reading list of bookmarked URLs with optional notes.', 'nop-indieweb' ),
				'file'        => 'taxonomy-nop_kind-bookmark.html',
			],
			'nop-indieweb//taxonomy-nop_kind-like' => [
				'title'       => __( 'Archive – Likes', 'nop-indieweb' ),
				'description' => __( 'Compact list of liked posts across the web.', 'nop-indieweb' ),
				'file'        => 'taxonomy-nop_kind-like.html',
			],
			'nop-indieweb//taxonomy-nop_kind-repost' => [
				'title'       => __( 'Archive – Reposts', 'nop-indieweb' ),
				'description' => __( 'Compact list of reposted content from Mastodon and Bluesky.', 'nop-indieweb' ),
				'file'        => 'taxonomy-nop_kind-repost.html',
			],
			'nop-indieweb//taxonomy-nop_kind-reply' => [
				'title'       => __( 'Archive – Replies', 'nop-indieweb' ),
				'description' => __( 'Conversation list showing in-reply-to context and reply content.', 'nop-indieweb' ),
				'file'        => 'taxonomy-nop_kind-reply.html',
			],
			'nop-indieweb//taxonomy-nop_kind-rsvp' => [
				'title'       => __( 'Archive – RSVPs', 'nop-indieweb' ),
				'description' => __( 'Event response list showing RSVP status and event link.', 'nop-indieweb' ),
				'file'        => 'taxonomy-nop_kind-rsvp.html',
			],
			'nop-indieweb//taxonomy-nop_kind-article' => [
				'title'       => __( 'Archive – Articles', 'nop-indieweb' ),
				'description' => __( 'Chronological list of long-form articles with title, date, tags, and excerpt.', 'nop-indieweb' ),
				'file'        => 'taxonomy-nop_kind-article.html',
			],
			'nop-indieweb//taxonomy-nop_kind-photo' => [
				'title'       => __( 'Archive – Photos', 'nop-indieweb' ),
				'description' => __( 'Square photo grid for the photo kind. Featured images link to each photo post.', 'nop-indieweb' ),
				'file'        => 'taxonomy-nop_kind-photo.html',
			],
			'nop-indieweb//taxonomy-nop_kind-quote' => [
				'title'       => __( 'Archive – Quotes', 'nop-indieweb' ),
				'description' => __( 'Chronological list of quotation posts with source attribution.', 'nop-indieweb' ),
				'file'        => 'taxonomy-nop_kind-quote.html',
			],
			'nop-indieweb//taxonomy-nop_kind-video' => [
				'title'       => __( 'Archive – Videos', 'nop-indieweb' ),
				'description' => __( 'Chronological stream of video posts.', 'nop-indieweb' ),
				'file'        => 'taxonomy-nop_kind-video.html',
			],
			'nop-indieweb//taxonomy-nop_kind-exercise' => [
				'title'       => __( 'Archive – Exercise', 'nop-indieweb' ),
				'description' => __( 'Activity log of workout posts with distance, duration, and date.', 'nop-indieweb' ),
				'file'        => 'taxonomy-nop_kind-exercise.html',
			],
			'nop-indieweb//taxonomy-nop_venue_category' => [
				'title'       => __( 'Archive – Venue Category', 'nop-indieweb' ),
				'description' => __( 'Lists every check-in in a Foursquare venue category (Yoga Studios, Parks, Bars, etc.).', 'nop-indieweb' ),
				'file'        => 'taxonomy-nop_venue_category.html',
			],
		];

		// register_block_template() takes content (not a path), so without a
		// cache we'd read 18 files on every request including REST/AJAX. The
		// transient is keyed on plugin version AND a hash of file mtimes so it
		// invalidates on upgrade or on any template edit (filemtime is a cheap
		// stat-cached call).
		$mtimes = [];
		foreach ( $templates as $id => $template ) {
			$path           = $dir . $template['file'];
			$mtimes[ $id ]  = is_readable( $path ) ? filemtime( $path ) : 0;
		}
		$cache_key = 'nop_indieweb_template_contents_' . NOP_INDIEWEB_VERSION
		           . '_' . substr( md5( implode( ',', $mtimes ) ), 0, 8 );
		$contents  = get_transient( $cache_key );

		if ( false === $contents ) {
			$contents = [];
			foreach ( $templates as $id => $template ) {
				$path = $dir . $template['file'];
				if ( ! is_readable( $path ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error, WordPress.Security.EscapeOutput.OutputNotEscaped -- developer warning to the error log, not browser output; $path is a server-side plugin file path
					trigger_error( esc_html( "NOP IndieWeb: template file missing: {$path}" ), E_USER_WARNING );
					continue;
				}
				$contents[ $id ] = file_get_contents( $path );
			}
			set_transient( $cache_key, $contents, DAY_IN_SECONDS );
		}

		foreach ( $templates as $id => $template ) {
			if ( ! isset( $contents[ $id ] ) ) {
				continue;
			}
			register_block_template( $id, [
				'title'       => $template['title'],
				'description' => $template['description'],
				'content'     => $contents[ $id ],
			] );
		}
	}
}
