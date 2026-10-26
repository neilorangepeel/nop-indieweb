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
		// pre_get_comments is do_action_ref_array() in core: the query is passed by
		// reference and no return value is read.
		add_action( 'pre_get_comments', [ $this, 'exclude_webmentions_from_default_query' ] );
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
		// If the caller already asked for specific types, respect that (type, or a type__in list: the response count asks
		// for comments and webmentions, and stripped of them on the page it disagreed with the REST endpoint's count,
		// offering "1 new response" that was not new).
		if ( ! empty( $query->query_vars['type'] ) || ! empty( $query->query_vars['type__in'] ) ) {
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
