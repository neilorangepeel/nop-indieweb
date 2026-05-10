<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Syndication;

abstract class Syndicator_Base {

	abstract public function slug(): string;

	abstract public function label(): string;

	abstract protected function is_configured(): bool;

	abstract protected function do_syndicate( int $post_id ): ?string;

	abstract protected function owns_url( string $url ): bool;

	public function enabled(): bool {
		return (bool) \NOP\IndieWeb\nop_indieweb_get_option( "syndicators.{$this->slug()}.enabled", false );
	}

	public function syndicate( int $post_id ): void {
		if ( ! $this->enabled() || ! $this->is_configured() ) {
			return;
		}

		// Dedup — skip if this platform already has a syndication URL on this post.
		$existing = get_post_meta( $post_id, 'nop_indieweb_syndication', true );
		$existing = is_array( $existing ) ? $existing : [];

		foreach ( $existing as $url ) {
			if ( $this->owns_url( $url ) ) {
				return;
			}
		}

		$url = $this->do_syndicate( $post_id );

		if ( $url ) {
			$existing[] = $url;
			update_post_meta( $post_id, 'nop_indieweb_syndication', $existing );
		}
	}

	protected function build_status_text( int $post_id, int $limit ): string {
		$post       = get_post( $post_id );
		$venue_name = get_post_meta( $post_id, 'nop_indieweb_venue_name', true );
		$permalink  = get_permalink( $post_id );

		if ( $venue_name ) {
			$text = sprintf( 'Checked in at %s', $venue_name );
		} elseif ( $post->post_title ) {
			$text = $post->post_title;
		} else {
			$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 );
			$text    = $excerpt ?: '';
		}

		// Reserve space for the URL + newlines.
		$url_part   = "\n\n" . $permalink;
		$max_text   = $limit - mb_strlen( $url_part );
		if ( mb_strlen( $text ) > $max_text ) {
			$text = mb_substr( $text, 0, $max_text - 1 ) . '…';
		}

		return $text . $url_part;
	}
}
