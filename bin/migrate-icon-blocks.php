<?php
/**
 * Moves saved content off four of the plugin's own blocks onto core blocks bound to the post-meta source:
 *
 *   nop-indieweb/kind-icon          → core/icon bound to the `kind_icon` field
 *   nop-indieweb/exercise-type-icon → core/icon bound to `activity_icon`
 *   nop-indieweb/weather-icon       → core/icon bound to `weather_icon`
 *   nop-indieweb/weather-temp       → core/paragraph bound to `weather_temp`
 *
 * The blocks themselves still render (they are only hidden from the inserter), so anything this does not reach keeps
 * working; this just leaves nothing relying on them. Needs WordPress 7.2 (core/icon `icon` bindings). Safe to run twice.
 *
 *   wp eval-file wp-content/plugins/nop-indieweb/bin/migrate-icon-blocks.php          # report only
 *   wp eval-file wp-content/plugins/nop-indieweb/bin/migrate-icon-blocks.php apply    # write the changes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;
$apply = in_array( 'apply', $args ?? [], true ) || ( isset( $GLOBALS['argv'] ) && in_array( 'apply', $GLOBALS['argv'], true ) );

$icon = static fn( string $field, string $class ): string => '<!-- wp:icon {"metadata":{"bindings":{"icon":{"source":"nop-indieweb/post-meta","args":{"field":"' . $field . '"}}}},"className":"' . $class . '"} /-->';
$temp = static function ( ?string $attrs ): string {
	$size = $attrs && preg_match( '/"fontSize":"([a-z-]+)"/', $attrs, $m ) ? $m[1] : '';
	return '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"nop-indieweb/post-meta","args":{"field":"weather_temp"}}}},"className":"nop-weather-temp"' . ( $size ? ',"fontSize":"' . $size . '"' : '' ) . '} -->'
		. '<p class="nop-weather-temp' . ( $size ? ' has-' . $size . '-font-size' : '' ) . '"></p><!-- /wp:paragraph -->';
};

$rows = $wpdb->get_results(
	"SELECT ID, post_type, post_name, post_content FROM {$wpdb->posts}
	 WHERE post_type <> 'revision' AND post_status NOT IN ('trash','auto-draft') AND post_content REGEXP 'wp:nop-indieweb/(kind-icon|exercise-type-icon|weather-icon|weather-temp)'"
);

$done = 0;
foreach ( (array) $rows as $row ) {
	$new = $row->post_content;
	$new = (string) preg_replace_callback( '/<!-- wp:nop-indieweb\/kind-icon(?: \{[^}]*\})? \/-->/', static fn() => $icon( 'kind_icon', 'nop-kind-icon' ), $new );
	$new = (string) preg_replace_callback( '/<!-- wp:nop-indieweb\/exercise-type-icon(?: \{[^}]*\})? \/-->/', static fn() => $icon( 'activity_icon', 'nop-exercise-type-icon' ), $new );
	$new = (string) preg_replace_callback( '/<!-- wp:nop-indieweb\/weather-icon(?: \{[^}]*\})? \/-->/', static fn() => $icon( 'weather_icon', 'nop-weather-icon' ), $new );
	$new = (string) preg_replace_callback( '/<!-- wp:nop-indieweb\/weather-temp(?: (\{[^}]*\}))? \/-->/', static fn( array $m ) => $temp( $m[1] ?? null ), $new );
	if ( $new === $row->post_content ) {
		continue;
	}
	echo ( $apply ? 'migrated ' : 'would migrate ' ) . "#{$row->ID} {$row->post_type} {$row->post_name}\n";
	if ( $apply ) {
		$wpdb->update( $wpdb->posts, [ 'post_content' => $new ], [ 'ID' => $row->ID ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a one-off content migration; wp_update_post would add a revision and run save hooks
		clean_post_cache( (int) $row->ID );
	}
	$done++;
}
echo "\n" . ( $apply ? 'Migrated' : 'Would migrate' ) . " {$done} of " . count( (array) $rows ) . " rows.\n";
