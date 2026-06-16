<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Semantic;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Injects microformats2 classes into front-end HTML at render time.
 *
 * Nothing is written to the database — all classes are added server-side
 * only. Deactivating the plugin removes them automatically.
 *
 * Covers the HTML layer:
 *   h-entry    → body class on IndieWeb posts
 *   p-name     → outer tag of core/post-title block
 *   u-url      → inner <a> of core/post-title block + hidden footer anchor
 *   p-author   → hidden h-card anchor in wp_footer (Bridgy + XRay readability)
 *   dt-published → <time> element inside core/post-date block
 *   e-content  → wrapper div inside core/post-content block
 *
 * Venue-related mf2 classes (p-name, p-street-address, p-locality,
 * p-country-name, p-adr, p-category, u-url) are injected onto bound
 * core blocks at render time by Block_Bindings::inject_mf2_classes().
 */
class Semantic_Markup {

	private ?bool $is_active = null;

	public function register(): void {
		if ( ! \NOP\IndieWeb\nop_indieweb_get_option( 'mf2_enabled', true ) ) {
			return;
		}
		add_filter( 'body_class',   [ $this, 'add_hentry_class' ] );
		add_filter( 'body_class',   [ $this, 'add_hfeed_class' ] );
		add_filter( 'render_block', [ $this, 'inject_block_classes' ], 10, 2 );
		add_action( 'wp_head',      [ $this, 'output_alternate_link' ] );
		add_action( 'wp_footer',    [ $this, 'output_syndication_links' ] );
		add_action( 'wp_footer',    [ $this, 'output_kind_links' ] );
		add_action( 'wp_footer',    [ $this, 'output_author_hcard' ] );
		add_action( 'wp_footer',    [ $this, 'output_post_url' ] );
	}

	public function add_hentry_class( array $classes ): array {
		if ( $this->is_active() ) {
			$classes[] = 'h-entry';
		}
		return $classes;
	}

	public function add_hfeed_class( array $classes ): array {
		if ( is_home() || is_archive() ) {
			$classes[] = 'h-feed';
		}
		return $classes;
	}

	public function inject_block_classes( string $html, array $block ): string {
		// h-feed: inject h-entry into archive post loops regardless of singular-post context.
		if ( 'core/post-template' === $block['blockName'] && ( is_home() || is_archive() ) ) {
			return $this->inject_hentry_into_post_template( $html );
		}

		if ( ! $this->is_active() ) {
			return $html;
		}

		return match ( $block['blockName'] ) {
			'core/post-title'   => $this->inject_post_title_classes( $html ),
			'core/post-date'    => $this->add_class_to_tag( $html, 'time', 'dt-published' ),
			'core/post-content' => $this->add_class_to_tag( $html, 'div',  'e-content' ),
			default             => $html,
		};
	}

	public function output_alternate_link(): void {
		if ( ! $this->is_active() ) {
			return;
		}
		$post_id = get_queried_object_id();
		printf(
			"<link rel=\"alternate\" type=\"application/mf2+json\" href=\"%s\">\n",
			esc_url( rest_url( "nop-indieweb/v1/mf2/{$post_id}" ) )
		);
	}

	public function output_syndication_links(): void {
		if ( ! $this->is_active() ) {
			return;
		}
		$urls = get_post_meta( get_queried_object_id(), 'nop_indieweb_syndication', true );
		if ( ! is_array( $urls ) || empty( $urls ) ) {
			return;
		}
		foreach ( $urls as $url ) {
			printf( "<a class=\"u-syndication\" href=\"%s\" hidden></a>\n", esc_url( $url ) );
		}
	}

	public function output_kind_links(): void {
		if ( ! $this->is_active() ) {
			return;
		}
		$post_id = get_queried_object_id();
		$kind    = get_post_meta( $post_id, 'nop_indieweb_post_kind', true );

		// RSVP carries a richer h-event in-reply-to (name/start/end/location) plus
		// the p-rsvp value and an optional note — handled separately below.
		if ( 'rsvp' === $kind ) {
			$this->output_rsvp_links( $post_id );
			return;
		}

		$url_kinds = [
			'bookmark' => [ 'nop_indieweb_bookmark_of', 'u-bookmark-of' ],
			'reply'    => [ 'nop_indieweb_in_reply_to', 'u-in-reply-to' ],
			'like'     => [ 'nop_indieweb_like_of',     'u-like-of'     ],
			'repost'   => [ 'nop_indieweb_repost_of',   'u-repost-of'   ],
			'quote'    => [ 'nop_indieweb_quote_of',    'u-quotation-of' ],
		];

		if ( isset( $url_kinds[ $kind ] ) ) {
			[ $meta_key, $rel ] = $url_kinds[ $kind ];
			$url = get_post_meta( $post_id, $meta_key, true );
			if ( $url ) {
				$this->output_kind_url( $rel, (string) $url, $post_id );
			}
		}
	}

	/**
	 * Emits the hidden machine-readable microformats for an RSVP post:
	 * the in-reply-to event as an h-cite (p-name, u-url, dt-start, dt-end,
	 * p-location), the p-rsvp value, and an optional note as e-content/p-summary.
	 *
	 * Optional event fields are only emitted when present. When no structured
	 * event detail was captured, falls back to the shared cite output so the
	 * post still advertises u-in-reply-to.
	 */
	private function output_rsvp_links( int $post_id ): void {
		$url      = (string) get_post_meta( $post_id, 'nop_indieweb_in_reply_to', true );
		$name     = (string) get_post_meta( $post_id, 'nop_indieweb_rsvp_event_name', true );
		$start    = (string) get_post_meta( $post_id, 'nop_indieweb_rsvp_event_start', true );
		$end      = (string) get_post_meta( $post_id, 'nop_indieweb_rsvp_event_end', true );
		$location = (string) get_post_meta( $post_id, 'nop_indieweb_rsvp_event_location', true );

		$has_event = ( '' !== $name || '' !== $start || '' !== $end || '' !== $location );

		if ( $url && $has_event ) {
			echo '<div class="h-cite u-in-reply-to" hidden>';
			if ( '' !== $name ) {
				printf( '<a class="p-name u-url" href="%s">%s</a>', esc_url( $url ), esc_html( $name ) );
			} else {
				printf( '<a class="u-url" href="%s"></a>', esc_url( $url ) );
			}
			if ( '' !== $start ) {
				printf( '<time class="dt-start" datetime="%s">%s</time>', esc_attr( $start ), esc_html( $start ) );
			}
			if ( '' !== $end ) {
				printf( '<time class="dt-end" datetime="%s">%s</time>', esc_attr( $end ), esc_html( $end ) );
			}
			if ( '' !== $location ) {
				printf( '<span class="p-location">%s</span>', esc_html( $location ) );
			}
			echo "</div>\n";
		} elseif ( $url ) {
			// No structured event detail — fall back to the shared cite output
			// (uses any captured cite_* meta, else a bare hidden anchor).
			$this->output_kind_url( 'u-in-reply-to', $url, $post_id );
		}

		$rsvp = (string) get_post_meta( $post_id, 'nop_indieweb_rsvp', true );
		if ( '' !== $rsvp ) {
			printf( "<data class=\"p-rsvp\" value=\"%s\" hidden></data>\n", esc_attr( $rsvp ) );
		}

		$note = (string) get_post_meta( $post_id, 'nop_indieweb_rsvp_note', true );
		if ( '' !== $note ) {
			printf( "<div class=\"e-content p-summary\" hidden>%s</div>\n", esc_html( $note ) );
		}
	}

	/**
	 * Emits the canonical-URL property for a response kind. When a captured cite
	 * is present (title fetched from the target), emits a nested hidden h-cite
	 * carrying the target's title, author, image, excerpt and site so consumers
	 * see real context; otherwise falls back to today's bare hidden anchor.
	 */
	private function output_kind_url( string $rel, string $url, int $post_id ): void {
		$title = (string) get_post_meta( $post_id, 'nop_indieweb_cite_title', true );
		if ( '' === $title ) {
			printf( "<a class=\"%s\" href=\"%s\" hidden></a>\n", esc_attr( $rel ), esc_url( $url ) );
			return;
		}

		printf( '<div class="h-cite %s" hidden>', esc_attr( $rel ) );
		printf( '<a class="p-name u-url" href="%s">%s</a>', esc_url( $url ), esc_html( $title ) );

		$author_name  = (string) get_post_meta( $post_id, 'nop_indieweb_cite_author_name', true );
		$author_url   = (string) get_post_meta( $post_id, 'nop_indieweb_cite_author_url', true );
		$author_photo = (string) get_post_meta( $post_id, 'nop_indieweb_cite_author_photo', true );
		if ( '' !== $author_name || '' !== $author_url || '' !== $author_photo ) {
			echo '<span class="p-author h-card">';
			if ( '' !== $author_url && '' !== $author_name ) {
				printf( '<a class="u-url p-name" href="%s">%s</a>', esc_url( $author_url ), esc_html( $author_name ) );
			} elseif ( '' !== $author_url ) {
				printf( '<a class="u-url" href="%s"></a>', esc_url( $author_url ) );
			} elseif ( '' !== $author_name ) {
				printf( '<span class="p-name">%s</span>', esc_html( $author_name ) );
			}
			if ( '' !== $author_photo ) {
				printf( '<img class="u-photo" src="%s" alt="">', esc_url( $author_photo ) );
			}
			echo '</span>';
		}

		$image = (string) get_post_meta( $post_id, 'nop_indieweb_cite_image', true );
		if ( '' !== $image ) {
			printf( '<img class="u-photo" src="%s" alt="">', esc_url( $image ) );
		}
		$excerpt = (string) get_post_meta( $post_id, 'nop_indieweb_cite_excerpt', true );
		if ( '' !== $excerpt ) {
			printf( '<p class="p-summary">%s</p>', esc_html( $excerpt ) );
		}
		$site = (string) get_post_meta( $post_id, 'nop_indieweb_cite_site_name', true );
		if ( '' !== $site ) {
			printf( '<span class="p-publication">%s</span>', esc_html( $site ) );
		}
		echo "</div>\n";
	}

	/**
	 * Emits a hidden p-author h-card anchor inside the h-entry (the body).
	 *
	 * The anchor's text is the display name (becomes p-name on the h-card),
	 * the href is the author archive URL (becomes u-url). Three properties
	 * out of one element — minimum-viable author for any mf2 consumer.
	 */
	public function output_author_hcard(): void {
		if ( ! $this->is_active() ) {
			return;
		}
		$author_id = (int) get_post_field( 'post_author', get_queried_object_id() );
		$author    = $author_id ? get_userdata( $author_id ) : null;
		if ( ! $author ) {
			return;
		}
		printf(
			"<a class=\"p-author h-card u-url\" href=\"%s\" hidden>%s</a>\n",
			esc_url( get_author_posts_url( $author_id ) ),
			esc_html( $author->display_name )
		);
	}

	/**
	 * Hidden u-url anchor pointing at the post's permalink.
	 *
	 * Single-post templates render the post-title as a heading without a link,
	 * so the in-block u-url injection has nothing to attach to. This anchor
	 * closes that gap.
	 */
	public function output_post_url(): void {
		if ( ! $this->is_active() ) {
			return;
		}
		printf(
			"<a class=\"u-url\" href=\"%s\" hidden></a>\n",
			esc_url( get_permalink( get_queried_object_id() ) )
		);
	}

	private function is_active(): bool {
		if ( null === $this->is_active ) {
			$this->is_active = is_singular( 'post' );
		}
		return $this->is_active;
	}

	/**
	 * Adds p-name to the outermost heading of the post-title block and
	 * (if a permalink link is present inside) u-url to that link.
	 */
	private function inject_post_title_classes( string $html ): string {
		$html = $this->add_class_to_first_tag( $html, 'p-name' );
		if ( str_contains( $html, '<a' ) ) {
			$html = $this->add_class_to_tag( $html, 'a', 'u-url' );
		}
		return $html;
	}

	/**
	 * Adds h-entry to each post-template list item so archive pages expose
	 * individual h-entries within the h-feed body. Matches class="wp-block-post "
	 * specifically to avoid touching child blocks like wp-block-post-title.
	 */
	private function inject_hentry_into_post_template( string $html ): string {
		return preg_replace(
			'/class="(wp-block-post\s)/',
			'class="$1h-entry ',
			$html
		) ?? $html;
	}

	/**
	 * Adds a CSS class to the first opening tag in a string, whatever its name.
	 * Used by post-title which can render as h1–h6 or p depending on settings.
	 */
	private function add_class_to_first_tag( string $html, string $class ): string {
		return preg_replace_callback(
			'/<(\w+)\b([^>]*)>/',
			static function ( array $m ) use ( $class ): string {
				$attrs = $m[2];
				if ( preg_match( '/\bclass="([^"]*)"/', $attrs, $c ) ) {
					$attrs = str_replace(
						$c[0],
						'class="' . trim( $c[1] . ' ' . $class ) . '"',
						$attrs
					);
				} else {
					$attrs .= ' class="' . $class . '"';
				}
				return '<' . $m[1] . $attrs . '>';
			},
			$html,
			1
		) ?? $html;
	}

	/**
	 * Adds a CSS class to the first occurrence of a given HTML tag in a string.
	 * Handles both existing class attributes and tags with no class at all.
	 */
	private function add_class_to_tag( string $html, string $tag, string $class ): string {
		return preg_replace_callback(
			'/<' . preg_quote( $tag, '/' ) . '\b([^>]*)>/i',
			static function ( array $m ) use ( $tag, $class ): string {
				$attrs = $m[1];
				if ( preg_match( '/\bclass="([^"]*)"/', $attrs, $c ) ) {
					$attrs = str_replace(
						$c[0],
						'class="' . trim( $c[1] . ' ' . $class ) . '"',
						$attrs
					);
				} else {
					$attrs .= ' class="' . $class . '"';
				}
				return '<' . $tag . $attrs . '>';
			},
			$html,
			1
		) ?? $html;
	}
}
