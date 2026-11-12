<?php
/**
 * A #hashtag typed in a post becomes its tag (the theme's STRUCTURE.md: tags are Subjects).
 *
 * Inbound only: whatever made the post (the editor, /post, Micropub, an importer),
 * once it is saved its words are read and each hashtag is added as a tag. Tags are
 * only ever added, never removed, and a hashtag that matches an existing tag's slug
 * joins it (#boro files under BORO). Tags are still never appended as hashtags when
 * syndicating.
 */
declare( strict_types=1 );

namespace NOP\IndieWeb\Kind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Hashtags {

	public function register(): void {
		add_action( 'wp_after_insert_post', [ $this, 'tag_post' ], 10, 2 );
	}

	public function tag_post( int $post_id, \WP_Post $post ): void {
		if ( 'post' !== $post->post_type || 'auto-draft' === $post->post_status || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$names = self::tag_names( $post->post_content );
		if ( $names ) {
			wp_set_post_tags( $post_id, $names, true );
		}
	}

	/** @return string[] The tags a post's hashtags name, using an existing tag's own spelling when there is one. */
	public static function tag_names( string $content ): array {
		return array_map( static function ( string $tag ): string {
			$term = get_term_by( 'slug', sanitize_title( $tag ), 'post_tag' );
			return $term instanceof \WP_Term ? $term->name : $tag;
		}, self::extract( $content ) );
	}

	/**
	 * The hashtags in a post's words, once each (case-insensitively), without the #.
	 * A hashtag starts a word and begins with a letter: #1, a colour code in a style attribute and site.com/#anchor are not tags.
	 *
	 * @return string[]
	 */
	public static function extract( string $content ): array {
		$text = html_entity_decode( strip_tags( str_replace( '<', ' <', $content ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( ! preg_match_all( '/(?:^|[\s(])[#＃](\p{L}[\p{L}\p{N}_]*)/u', $text, $matches ) ) {
			return [];
		}
		$tags = [];
		foreach ( $matches[1] as $tag ) {
			$tags[ mb_strtolower( $tag ) ] ??= $tag;
		}
		return array_values( $tags );
	}
}
