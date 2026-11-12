<?php
// CLI-only — refuse to run if reached over HTTP.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'This file may only be executed via WP-CLI.' );
}
/**
 * Give existing posts the tags their #hashtags name (new posts get them on save: Kind\Hashtags).
 *
 *   wp eval-file wp-content/plugins/nop-indieweb/bin/hashtags-to-tags.php          # dry run: counts only
 *   wp eval-file wp-content/plugins/nop-indieweb/bin/hashtags-to-tags.php apply    # add the tags
 *
 * Only adds tags, never removes one. Idempotent: a second run finds nothing to do.
 */

use NOP\IndieWeb\Kind\Hashtags;

$apply = in_array( 'apply', (array) ( $args ?? [] ), true );
WP_CLI::line( $apply ? 'APPLYING changes.' : 'DRY RUN: nothing is changed. Add "apply" to add the tags.' );

$posts = 0;
$added = [];
foreach ( get_posts( [ 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $post_id ) {
	$names = array_diff( Hashtags::tag_names( (string) get_post_field( 'post_content', $post_id ) ), wp_get_post_tags( $post_id, [ 'fields' => 'names' ] ) );
	if ( ! $names ) {
		continue;
	}
	$posts++;
	foreach ( $names as $name ) {
		$added[ $name ] = ( $added[ $name ] ?? 0 ) + 1;
	}
	if ( $apply ) {
		wp_set_post_tags( $post_id, array_values( $names ), true );
	}
}

arsort( $added );
foreach ( $added as $name => $count ) {
	WP_CLI::line( sprintf( '  %-24s +%d posts', $name, $count ) );
}
WP_CLI::line( sprintf( 'Posts gaining tags: %d. Tag links added: %d.', $posts, array_sum( $added ) ) );
$apply ? WP_CLI::success( 'Done.' ) : WP_CLI::success( 'Dry run finished.' );
