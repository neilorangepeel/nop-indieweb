<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Webmention;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps webmention-type comments out of the standard WordPress comment queries
 * and counts, matching the established IndieWeb pattern (Webmention plugin,
 * Semantic Linkbacks). The webmentions block fetches them explicitly by type.
 */
class Comment_Filter {

	public function register(): void {
		add_filter( 'pre_get_comments', [ $this, 'exclude_webmentions_from_default_query' ] );
		add_filter( 'get_comments_number', [ $this, 'exclude_webmentions_from_count' ], 10, 2 );
	}

	public function exclude_webmentions_from_default_query( \WP_Comment_Query $query ): void {
		// Leave admin screens and explicit REST queries alone.
		if ( is_admin() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		// If the caller already asked for a specific type, respect that.
		if ( ! empty( $query->query_vars['type'] ) ) {
			return;
		}
		$not_in = (array) ( $query->query_vars['type__not_in'] ?? [] );
		if ( ! in_array( 'webmention', $not_in, true ) ) {
			$query->query_vars['type__not_in'] = array_merge( $not_in, [ 'webmention' ] );
		}
	}

	public function exclude_webmentions_from_count( int|string $count, int $post_id ): int {
		if ( is_admin() ) {
			return (int) $count;
		}

		// Subtract webmentions from the count WordPress already calculated, rather
		// than re-counting all non-webmention comments — one query, same result.
		// Memoise per request so a listing page that renders the same post twice
		// only hits the DB once.
		static $webmention_counts = [];
		if ( ! isset( $webmention_counts[ $post_id ] ) ) {
			$webmention_counts[ $post_id ] = (int) get_comments( [
				'post_id' => $post_id,
				'type'    => 'webmention',
				'status'  => 'approve',
				'count'   => true,
			] );
		}

		return max( 0, (int) $count - $webmention_counts[ $post_id ] );
	}
}
