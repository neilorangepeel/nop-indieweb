<?php
/**
 * Webmentions block — render helpers.
 *
 * Pulled out of render.php so they're declared exactly once per request via
 * require_once. WordPress includes render.php fresh on every block render,
 * so any top-level function declaration there would fatal on the second
 * render in the same request (e.g. a query loop containing this block).
 */
declare( strict_types=1 );

function nop_wm_avatar_wrap( array $entry, int $size ): string {
	$author     = $entry['author'] ?? '';
	$url        = esc_url( $entry['author_url'] ?? '' );
	$photo      = $entry['photo'] ?? '';
	$platform   = $entry['platform'] ?? '';
	$size_class = ' nop-webmentions__avatar--' . $size;
	$wrap_class = 'nop-webmentions__avatar-wrap'
	            . ( $platform ? ' nop-webmentions__avatar-wrap--' . sanitize_html_class( $platform ) : '' );

	if ( $photo ) {
		$avatar = '<img src="' . esc_url( $photo ) . '"'
		        . ' alt=""'
		        . ' class="nop-webmentions__avatar' . $size_class . '"'
		        . ' width="' . $size . '" height="' . $size . '"'
		        . ' loading="lazy">';
	} else {
		$icon_size = (int) round( $size * 0.55 );
		$avatar    = '<span class="nop-webmentions__avatar nop-webmentions__avatar--fallback' . $size_class . '">'
		           . '<svg viewBox="0 0 24 24" fill="currentColor"'
		           . ' width="' . $icon_size . '" height="' . $icon_size . '"'
		           . ' aria-hidden="true" focusable="false">'
		           . '<path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0 2c-5.3 0-9 2.4-9 5v1h18v-1c0-2.6-3.7-5-9-5z"/>'
		           . '</svg>'
		           . '</span>';
	}

	// The avatar column is marked aria-hidden at the call site — the author's
	// name in the reply-meta line provides the accessible label for the row.
	return '<div class="' . esc_attr( $wrap_class ) . '">'
	     . '<a href="' . $url . '" class="nop-webmentions__avatar-link"'
	     . ' tabindex="-1"'
	     . ' aria-hidden="true">'
	     . $avatar
	     . '</a>'
	     . '</div>';
}

function nop_wm_platform_tag( string $platform, bool $via_bridgy = false ): string {
	if ( ! $platform || 'site' === $platform ) {
		return '';
	}
	$labels = [
		'mastodon' => 'Mastodon',
		'bluesky'  => 'Bluesky',
	];
	$label = $labels[ $platform ] ?? ucfirst( $platform );
	$class = 'nop-webmentions__via nop-webmentions__via--' . sanitize_html_class( $platform );

	// A bridged reply lives on a different platform and was relayed via Bridgy.
	// Visible only when we know the platform — avoids "Unknown · via Bridgy".
	$show_bridgy = $via_bridgy && in_array( $platform, [ 'mastodon', 'bluesky' ], true );
	$bridgy_suffix = $show_bridgy
		? ' <span class="nop-webmentions__via-bridgy">· via Bridgy</span>'
		: '';
	$aria = $show_bridgy ? 'via ' . $label . ' (relayed via Bridgy)' : 'via ' . $label;

	return '<span class="' . esc_attr( $class ) . '" aria-label="' . esc_attr( $aria ) . '">' . esc_html( $label ) . '</span>' . $bridgy_suffix;
}

function nop_wm_reply_link( int|string $comment_id, int $post_id, string $below_element, string $author = '' ): string {
	$label = $author ? 'Reply to ' . $author : 'Reply';
	return '<a class="nop-webmentions__reply-link comment-reply-link" rel="nofollow"'
	     . ' href="#respond"'
	     . ' data-commentid="' . esc_attr( (string) $comment_id ) . '"'
	     . ' data-postid="' . $post_id . '"'
	     . ' data-belowelement="' . esc_attr( $below_element ) . '"'
	     . ' data-respondelement="respond"'
	     . ' aria-label="' . esc_attr( $label ) . '">'
	     . 'Reply'
	     . '</a>';
}

function nop_wm_liked_by( array $likes ): string {
	$count = count( $likes );
	if ( 0 === $count ) {
		return '';
	}
	$name = static function ( array $entry ): string {
		return '<strong>' . esc_html( $entry['author'] ) . '</strong>';
	};
	if ( 1 === $count ) {
		return 'Liked by ' . $name( $likes[0] );
	}
	if ( 2 === $count ) {
		return 'Liked by ' . $name( $likes[0] ) . ' and ' . $name( $likes[1] );
	}
	$others = $count - 2;
	return 'Liked by ' . $name( $likes[0] ) . ', ' . $name( $likes[1] )
	     . ' and ' . $others . ' ' . ( 1 === $others ? 'other' : 'others' );
}

function nop_wm_time_ago( string $date_gmt ): string {
	$ts   = strtotime( $date_gmt );
	if ( ! $ts ) {
		return '';
	}
	$diff = time() - $ts;
	if ( $diff < 60 )      { return 'just now'; }
	if ( $diff < 3600 )    { return floor( $diff / 60 ) . 'm'; }
	if ( $diff < 86400 )   { return floor( $diff / 3600 ) . 'h'; }
	if ( $diff < 604800 )  { return floor( $diff / 86400 ) . 'd'; }
	if ( $diff < 2592000 ) { return floor( $diff / 604800 ) . 'w'; }
	return (string) wp_date( 'j M Y', $ts );
}

function nop_wm_time_label( string $date_gmt ): string {
	$ts   = strtotime( $date_gmt );
	if ( ! $ts ) {
		return '';
	}
	$diff = time() - $ts;
	if ( $diff < 60 )      { return 'just now'; }
	if ( $diff < 3600 )    { return floor( $diff / 60 ) . ' minutes ago'; }
	if ( $diff < 86400 )   { return floor( $diff / 3600 ) . ' hours ago'; }
	if ( $diff < 604800 )  { return floor( $diff / 86400 ) . ' days ago'; }
	if ( $diff < 2592000 ) { return floor( $diff / 604800 ) . ' weeks ago'; }
	return (string) wp_date( 'j F Y', $ts );
}

function nop_wm_render_empty_state( int $post_id ): void {
	$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-webmentions nop-webmentions--empty' ] );
	$message       = comments_open( $post_id )
		? __( 'Be the first to respond — comment below, or reply from your own site.', 'nop-indieweb' )
		: __( 'Be the first to respond — reply from your own site.', 'nop-indieweb' );
	echo '<div ' . $wrapper_attrs . '>';
	echo '<p class="nop-webmentions__empty">' . esc_html( $message ) . '</p>';
	echo nop_wm_render_comment_form( $post_id ); // phpcs:ignore
	echo '</div>';
}

/**
 * Compact inline comment form for the bottom of the Responses section.
 * Returns empty string when comments are closed on this post.
 *
 * Drops the "Website" field on purpose — IndieWeb visitors send a webmention,
 * casual visitors don't have a site to type in.
 */
function nop_wm_render_comment_form( int $post_id ): string {
	if ( ! comments_open( $post_id ) ) {
		return '';
	}

	$args = [
		'class_container'      => 'nop-webmentions__form',
		'class_form'           => 'nop-webmentions__form-form',
		'class_submit'         => 'nop-webmentions__form-submit',
		'title_reply'          => '',
		'title_reply_before'   => '',
		'title_reply_after'    => '',
		'comment_notes_before' => '',
		'comment_notes_after'  => '',
		'label_submit'         => __( 'Post comment', 'nop-indieweb' ),
		'comment_field'        => '<p class="nop-webmentions__form-field nop-webmentions__form-field--comment">'
			. '<label for="comment" class="screen-reader-text">' . esc_html__( 'Comment', 'nop-indieweb' ) . '</label>'
			. '<textarea id="comment" name="comment" rows="3" placeholder="' . esc_attr__( 'Add a comment…', 'nop-indieweb' ) . '" required></textarea>'
			. '</p>',
		'fields'               => [
			'author' => '<p class="nop-webmentions__form-field nop-webmentions__form-field--author">'
				. '<label for="author" class="screen-reader-text">' . esc_html__( 'Name', 'nop-indieweb' ) . '</label>'
				. '<input id="author" name="author" type="text" autocomplete="name" placeholder="' . esc_attr__( 'Your name', 'nop-indieweb' ) . '" required>'
				. '</p>',
			'email'  => '<p class="nop-webmentions__form-field nop-webmentions__form-field--email">'
				. '<label for="email" class="screen-reader-text">' . esc_html__( 'Email', 'nop-indieweb' ) . '</label>'
				. '<input id="email" name="email" type="email" autocomplete="email" placeholder="' . esc_attr__( 'Email (not published)', 'nop-indieweb' ) . '" required>'
				. '</p>',
		],
	];

	ob_start();
	comment_form( $args, $post_id );
	return (string) ob_get_clean();
}

function nop_wm_repost_icon(): string {
	return '<svg class="nop-webmentions__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" width="13" height="13"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>';
}
