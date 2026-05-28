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

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
		'mastodon' => __( 'Mastodon', 'nop-indieweb' ),
		'bluesky'  => __( 'Bluesky',  'nop-indieweb' ),
	];
	$label = $labels[ $platform ] ?? ucfirst( $platform );
	$class = 'nop-webmentions__via nop-webmentions__via--' . sanitize_html_class( $platform );

	// A bridged reply lives on a different platform and was relayed via Bridgy.
	// Visible only when we know the platform — avoids "Unknown · via Bridgy".
	$show_bridgy = $via_bridgy && in_array( $platform, [ 'mastodon', 'bluesky' ], true );
	$bridgy_suffix = $show_bridgy
		? ' <span class="nop-webmentions__via-bridgy">· ' . esc_html__( 'via Bridgy', 'nop-indieweb' ) . '</span>'
		: '';
	/* translators: %s: platform name e.g. Mastodon */
	$aria = $show_bridgy
		? sprintf( __( 'via %s (relayed via Bridgy)', 'nop-indieweb' ), $label )
		: sprintf( __( 'via %s', 'nop-indieweb' ), $label );

	return '<span class="' . esc_attr( $class ) . '" aria-label="' . esc_attr( $aria ) . '">' . esc_html( $label ) . '</span>' . $bridgy_suffix;
}

function nop_wm_reply_link( int|string $comment_id, int $post_id, string $below_element, string $author = '' ): string {
	/* translators: %s: commenter's name */
	$label = $author ? sprintf( __( 'Reply to %s', 'nop-indieweb' ), $author ) : __( 'Reply', 'nop-indieweb' );
	return '<a class="nop-webmentions__reply-link comment-reply-link" rel="nofollow"'
	     . ' href="#respond"'
	     . ' data-commentid="' . esc_attr( (string) $comment_id ) . '"'
	     . ' data-postid="' . $post_id . '"'
	     . ' data-belowelement="' . esc_attr( $below_element ) . '"'
	     . ' data-respondelement="respond"'
	     . ' aria-label="' . esc_attr( $label ) . '">'
	     . esc_html__( 'Reply', 'nop-indieweb' )
	     . '</a>';
}

function nop_wm_liked_by( array $likes ): string {
	$count = count( $likes );
	if ( 0 === $count ) {
		return '';
	}
	// Link names to profiles so keyboard users can reach them, matching the
	// clickable avatars in the facepile that sighted users see.
	$name = static function ( array $entry ): string {
		$author = esc_html( $entry['author'] );
		$url    = esc_url( $entry['author_url'] ?? '' );
		return $url
			? '<a href="' . $url . '" target="_blank" rel="noopener noreferrer"><strong>' . $author . '</strong></a>'
			: '<strong>' . $author . '</strong>';
	};
	if ( 1 === $count ) {
		/* translators: %s: author name */
		return sprintf( __( 'Liked by %s', 'nop-indieweb' ), $name( $likes[0] ) );
	}
	if ( 2 === $count ) {
		/* translators: 1: first author, 2: second author */
		return sprintf( __( 'Liked by %1$s and %2$s', 'nop-indieweb' ), $name( $likes[0] ), $name( $likes[1] ) );
	}
	$others = $count - 2;
	/* translators: 1: first author, 2: second author, 3: number of additional likers */
	return sprintf(
		_n(
			'Liked by %1$s, %2$s and %3$d other',
			'Liked by %1$s, %2$s and %3$d others',
			$others,
			'nop-indieweb'
		),
		$name( $likes[0] ),
		$name( $likes[1] ),
		$others
	);
}

function nop_wm_time_ago( string $date_gmt ): string {
	$ts   = strtotime( $date_gmt );
	if ( ! $ts ) {
		return '';
	}
	$diff = time() - $ts;
	if ( $diff < MINUTE_IN_SECONDS )                     { return __( 'just now', 'nop-indieweb' ); }
	/* translators: %d: number of minutes */
	if ( $diff < HOUR_IN_SECONDS )                       { return sprintf( _x( '%dm', 'minutes abbreviation', 'nop-indieweb' ),  (int) floor( $diff / MINUTE_IN_SECONDS ) ); }
	/* translators: %d: number of hours */
	if ( $diff < DAY_IN_SECONDS )                        { return sprintf( _x( '%dh', 'hours abbreviation', 'nop-indieweb' ),    (int) floor( $diff / HOUR_IN_SECONDS ) ); }
	/* translators: %d: number of days */
	if ( $diff < WEEK_IN_SECONDS )                       { return sprintf( _x( '%dd', 'days abbreviation', 'nop-indieweb' ),     (int) floor( $diff / DAY_IN_SECONDS ) ); }
	/* translators: %d: number of weeks */
	if ( $diff < MONTH_IN_SECONDS )                      { return sprintf( _x( '%dw', 'weeks abbreviation', 'nop-indieweb' ),    (int) floor( $diff / WEEK_IN_SECONDS ) ); }
	return (string) wp_date( 'j M Y', $ts );
}

function nop_wm_time_label( string $date_gmt ): string {
	$ts   = strtotime( $date_gmt );
	if ( ! $ts ) {
		return '';
	}
	$diff = time() - $ts;
	if ( $diff < MINUTE_IN_SECONDS )  { return __( 'just now', 'nop-indieweb' ); }
	/* translators: %d: number of minutes */
	if ( $diff < HOUR_IN_SECONDS )    { return sprintf( _n( '%d minute ago', '%d minutes ago', (int) floor( $diff / MINUTE_IN_SECONDS ), 'nop-indieweb' ),  (int) floor( $diff / MINUTE_IN_SECONDS ) ); }
	/* translators: %d: number of hours */
	if ( $diff < DAY_IN_SECONDS )     { return sprintf( _n( '%d hour ago',   '%d hours ago',   (int) floor( $diff / HOUR_IN_SECONDS ),   'nop-indieweb' ),  (int) floor( $diff / HOUR_IN_SECONDS ) ); }
	/* translators: %d: number of days */
	if ( $diff < WEEK_IN_SECONDS )    { return sprintf( _n( '%d day ago',    '%d days ago',    (int) floor( $diff / DAY_IN_SECONDS ),    'nop-indieweb' ),  (int) floor( $diff / DAY_IN_SECONDS ) ); }
	/* translators: %d: number of weeks */
	if ( $diff < MONTH_IN_SECONDS )   { return sprintf( _n( '%d week ago',   '%d weeks ago',   (int) floor( $diff / WEEK_IN_SECONDS ),   'nop-indieweb' ),  (int) floor( $diff / WEEK_IN_SECONDS ) ); }
	return (string) wp_date( 'j F Y', $ts );
}

function nop_wm_render_empty_state( int $post_id ): void {
	$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-webmentions nop-webmentions--empty' ] );
	$message       = comments_open( $post_id )
		? __( 'Be the first to respond — comment below, or reply from your own site.', 'nop-indieweb' )
		: __( 'Be the first to respond — reply from your own site.', 'nop-indieweb' );
	echo '<div ' . $wrapper_attrs . '>';
	echo '<p class="nop-webmentions__empty">' . esc_html( $message ) . '</p>';
	echo nop_wm_render_comment_form( $post_id, false ); // phpcs:ignore
	echo '</div>';
}

/**
 * Compact inline comment form for the bottom of the Responses section.
 * Returns empty string when comments are closed on this post.
 *
 * Custom form (not comment_form()) so the textarea appears before name/email —
 * engage first, ask for details after. Drops the Website and cookies-consent
 * fields: IndieWeb visitors send a webmention; casual visitors don't need them.
 *
 * $show_heading — false in the empty state (invitation text already labels it).
 */
function nop_wm_render_comment_form( int $post_id, bool $show_heading = true ): string {
	if ( ! comments_open( $post_id ) ) {
		return '';
	}

	$post_url  = esc_url( (string) get_permalink( $post_id ) );
	$logged_in = is_user_logged_in();
	$commenter = wp_get_current_commenter();
	$user      = $logged_in ? wp_get_current_user() : null;

	ob_start();
	?>
	<div id="respond" class="nop-webmentions__form">
		<?php if ( $show_heading ) : ?>
		<p class="nop-webmentions__form-label"><?php esc_html_e( 'Leave a reply', 'nop-indieweb' ); ?></p>
		<?php endif; ?>
		<a id="cancel-comment-reply-link" class="nop-webmentions__cancel-reply" href="<?php echo $post_url; ?>#respond" style="display:none;"><?php esc_html_e( 'Cancel reply', 'nop-indieweb' ); ?></a>
		<form id="commentform" class="nop-webmentions__form-form" method="post" action="<?php echo esc_url( site_url( '/wp-comments-post.php' ) ); ?>">
			<?php if ( $logged_in && $user ) : ?>
			<p class="nop-webmentions__form-field nop-webmentions__form-logged-in logged-in-as">
				<?php
				printf(
					/* translators: 1: user display name, 2: logout URL */
					wp_kses(
						__( 'Logged in as <strong>%1$s</strong>. <a href="%2$s">Log out?</a>', 'nop-indieweb' ),
						[ 'strong' => [], 'a' => [ 'href' => [] ] ]
					),
					esc_html( $user->display_name ),
					esc_url( wp_logout_url( $post_url ) )
				);
				?>
			</p>
			<?php endif; ?>
			<p class="nop-webmentions__form-field nop-webmentions__form-field--comment">
				<label for="comment" class="screen-reader-text"><?php esc_html_e( 'Comment', 'nop-indieweb' ); ?></label>
				<textarea id="comment" name="comment" rows="3" placeholder="<?php esc_attr_e( 'Add a comment…', 'nop-indieweb' ); ?>" required></textarea>
			</p>
			<?php if ( ! $logged_in ) : ?>
			<p class="nop-webmentions__form-field nop-webmentions__form-field--author">
				<label for="author" class="screen-reader-text"><?php esc_html_e( 'Name', 'nop-indieweb' ); ?></label>
				<input id="author" name="author" type="text" value="<?php echo esc_attr( $commenter['comment_author'] ); ?>" autocomplete="name" placeholder="<?php esc_attr_e( 'Your name', 'nop-indieweb' ); ?>" required>
			</p>
			<p class="nop-webmentions__form-field nop-webmentions__form-field--email">
				<label for="email" class="screen-reader-text"><?php esc_html_e( 'Email', 'nop-indieweb' ); ?></label>
				<input id="email" name="email" type="email" value="<?php echo esc_attr( $commenter['comment_author_email'] ); ?>" autocomplete="email" placeholder="<?php esc_attr_e( 'Email (not published)', 'nop-indieweb' ); ?>" required>
			</p>
			<?php endif; ?>
			<input type="hidden" name="comment_post_ID" value="<?php echo esc_attr( (string) $post_id ); ?>">
			<input type="hidden" name="comment_parent" id="comment_parent" value="0">
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $post_url ); ?>">
			<p class="form-submit">
				<input name="submit" type="submit" id="submit" class="nop-webmentions__form-submit" value="<?php esc_attr_e( 'Post comment', 'nop-indieweb' ); ?>">
			</p>
		</form>
	</div>
	<?php
	return (string) ob_get_clean();
}

function nop_wm_repost_icon(): string {
	return '<svg class="nop-webmentions__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" width="13" height="13"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>';
}
