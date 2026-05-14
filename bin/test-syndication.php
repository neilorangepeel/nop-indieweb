<?php
/**
 * Local smoke test for the syndication refactor. Run via:
 *   studio wp eval-file wp-content/plugins/nop-indieweb/bin/test-syndication.php
 */

use NOP\IndieWeb\Syndication\Syndicator_Bluesky;

echo "── Helpers ──\n";
echo function_exists( 'NOP\\IndieWeb\\nop_indieweb_block_text' )  ? "block_text OK\n"  : "MISSING\n";
echo function_exists( 'NOP\\IndieWeb\\nop_indieweb_block_images' ) ? "block_images OK\n" : "MISSING\n";

$content = '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->'
	. '<!-- wp:paragraph --><p>Line two<br>still two</p><!-- /wp:paragraph -->'
	. '<!-- wp:list --><ul class="wp-block-list">'
	. '<!-- wp:list-item --><li>alpha</li><!-- /wp:list-item -->'
	. '<!-- wp:list-item --><li>bravo</li><!-- /wp:list-item -->'
	. '</ul><!-- /wp:list -->'
	. '<!-- wp:image {"id":42} --><figure class="wp-block-image"><img src="https://example.com/a.jpg" alt="alpha"/></figure><!-- /wp:image -->'
	. '<!-- wp:image {"id":43} --><figure class="wp-block-image"><img src="https://example.com/b.jpg" alt="bravo"/></figure><!-- /wp:image -->';

$text = NOP\IndieWeb\nop_indieweb_block_text( $content );
echo "TEXT:\n[$text]\n";

$images = NOP\IndieWeb\nop_indieweb_block_images( $content, 4 );
echo "IMAGES: " . count( $images ) . "\n";

echo "\n── Builder ──\n";
$post_id = wp_insert_post( [
	'post_status'  => 'draft',
	'post_title'   => '',
	'post_content' => $content,
] );

if ( is_wp_error( $post_id ) || ! $post_id ) {
	echo "FAILED to create test post\n";
	return;
}

$bluesky = new Syndicator_Bluesky();
$ref     = new ReflectionClass( $bluesky );

$build_full   = $ref->getMethod( 'build_full_text' );        $build_full->setAccessible( true );
$compose      = $ref->getMethod( 'compose_status' );         $compose->setAccessible( true );
$collect_imgs = $ref->getMethod( 'collect_inline_images' );  $collect_imgs->setAccessible( true );
$build_facet  = $ref->getMethod( 'build_label_facet' );      $build_facet->setAccessible( true );

$full = $build_full->invoke( $bluesky, $post_id );
echo "build_full_text:\n[$full]\n";

$status_bs = $compose->invoke( $bluesky, $full, 300, '↗', mb_strlen( '↗' ) );
echo "compose_status (BS ↗): len=" . mb_strlen( $status_bs ) . "\n[$status_bs]\n";

$status_ms = $compose->invoke( $bluesky, $full, 500, 'https://neilorangepeel.com/2026/05/14/note/', 23 );
echo "compose_status (MS, cost=23): len=" . mb_strlen( $status_ms ) . "\n[$status_ms]\n";

$facet = $build_facet->invoke( $bluesky, $status_bs, '↗', 'https://neilorangepeel.com/2026/05/14/note/' );
echo "facet: " . wp_json_encode( $facet ) . "\n";

$imgs = $collect_imgs->invoke( $bluesky, $post_id, 4 );
echo "inline images on post: " . count( $imgs ) . "\n";

echo "\n── Truncation ──\n";
$long  = str_repeat( 'x', 400 );
$trunc = $compose->invoke( $bluesky, $long, 300, '↗', 1 );
echo "300-limit '↗' on 400xs: len=" . mb_strlen( $trunc ) . " tail='" . mb_substr( $trunc, -5 ) . "'\n";

wp_delete_post( $post_id, true );

echo "\n── Kind-aware build_full_text ──\n";
$make_post = static function ( string $title, string $content, string $kind ): int {
	$id = wp_insert_post( [
		'post_status'  => 'draft',
		'post_title'   => $title,
		'post_content' => $content,
	] );
	wp_set_object_terms( $id, $kind, 'nop_kind' );
	return $id;
};

$note_post    = $make_post( 'Working on a syndication plugin', '<!-- wp:paragraph --><p>Body content goes here</p><!-- /wp:paragraph -->', 'note' );
$article_post = $make_post( 'My great essay', '<!-- wp:paragraph --><p>First paragraph of the article</p><!-- /wp:paragraph -->', 'article' );
$bare_note    = $make_post( 'Fallback title', '', 'note' );
$like_post    = $make_post( 'example.com', '', 'like' );

echo "note with title+body  → [" . $build_full->invoke( $bluesky, $note_post ) . "]\n";
echo "article with title+body → [" . $build_full->invoke( $bluesky, $article_post ) . "]\n";
echo "note with title only  → [" . $build_full->invoke( $bluesky, $bare_note ) . "]\n";
echo "like with title only  → [" . $build_full->invoke( $bluesky, $like_post ) . "]\n";

wp_delete_post( $note_post, true );
wp_delete_post( $article_post, true );
wp_delete_post( $bare_note, true );
wp_delete_post( $like_post, true );

echo "\nOK\n";
