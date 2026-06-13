<?php
/**
 * Shared render library for the Responses blocks (reactions, replies,
 * comment-form).
 *
 * Holds the fetch/bucket logic and the markup helpers that the three
 * conversation blocks share. Loaded once per request via require_once from
 * each block's render.php, so the function declarations and the per-post
 * data fetch happen exactly once even when a query loop renders several of
 * these blocks on one page.
 */
declare( strict_types=1 );

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetch and bucket every response for a post — likes, reposts, replies, and
 * the WordPress comments nested under webmention replies — in a single pass.
 *
 * Memoized per request so the reactions, replies, and comment-form blocks all
 * share one fetch: the DB queries run once no matter how many of the three
 * blocks appear on the page.
 *
 * @return array{likes:array,reposts:array,replies:array,comment_children:array}
 */
function nop_wm_get_data( int $post_id ): array {
	static $cache = [];
	if ( isset( $cache[ $post_id ] ) ) {
		return $cache[ $post_id ];
	}

	if ( ! $post_id ) {
		return $cache[ $post_id ] = [ 'likes' => [], 'reposts' => [], 'replies' => [], 'comment_children' => [] ];
	}

	// ── Fetch all webmentions ─────────────────────────────────────────────────
	/**
	 * Maximum number of webmentions to fetch per post. Filterable via
	 * nop_indieweb_webmention_fetch_limit for high-traffic posts.
	 */
	$wm_limit = (int) apply_filters( 'nop_indieweb_webmention_fetch_limit', 100 );

	$webmentions = get_comments( [
		'post_id' => $post_id,
		'type'    => 'webmention',
		'status'  => 'approve',
		'number'  => $wm_limit,
		'orderby' => 'comment_date_gmt',
		'order'   => 'ASC',
	] );

	// ── Fetch all regular WordPress comments ──────────────────────────────────
	$wp_comments_limit = (int) apply_filters( 'nop_indieweb_wp_comments_fetch_limit', 100 );

	$wp_comments = get_comments( [
		'post_id' => $post_id,
		'type'    => 'comment',
		'status'  => 'approve',
		'number'  => $wp_comments_limit,
		'orderby' => 'comment_date_gmt',
		'order'   => 'ASC',
	] );

	// ── Sort webmentions into buckets ─────────────────────────────────────────
	$likes          = [];
	$reposts        = [];
	$replies        = [];
	$webmention_ids = [];

	foreach ( $webmentions as $wm ) {
		$type             = get_comment_meta( $wm->comment_ID, 'webmention_type', true );
		$webmention_ids[] = (int) $wm->comment_ID;
		$source           = get_comment_meta( $wm->comment_ID, 'webmention_source', true );

		$entry = [
			'id'         => $wm->comment_ID,
			'author'     => $wm->comment_author,
			'author_url' => $wm->comment_author_url,
			'photo'      => get_comment_meta( $wm->comment_ID, 'webmention_author_photo',  true ),
			'handle'     => get_comment_meta( $wm->comment_ID, 'webmention_author_handle', true ),
			'platform'   => get_comment_meta( $wm->comment_ID, 'webmention_platform',      true ),
			'via_bridgy' => $source && str_contains( $source, 'brid.gy' ),
			'source'     => $source ?: ( get_comment_meta( $wm->comment_ID, 'webmention_original_url', true ) ?: $wm->comment_author_url ),
			'content'    => $wm->comment_content,
			'date'       => $wm->comment_date_gmt,
		];

		if ( 'like' === $type ) {
			$likes[] = $entry;
		} elseif ( 'repost' === $type ) {
			$reposts[] = $entry;
		} else {
			$replies[] = $entry;
		}
	}

	// ── Distribute regular comments ───────────────────────────────────────────
	// Children of a webmention → nested below that webmention inline.
	// Everything else (top-level or comment→comment) → into the replies list.
	$comment_children = [];

	foreach ( $wp_comments as $c ) {
		$parent = (int) $c->comment_parent;

		if ( $parent > 0 && in_array( $parent, $webmention_ids, true ) ) {
			$comment_children[ $parent ][] = $c;
			continue;
		}

		$replies[] = [
			'id'         => $c->comment_ID,
			'author'     => $c->comment_author,
			'author_url' => $c->comment_author_url,
			'photo'      => get_avatar_url( $c, [ 'size' => 80 ] ),
			'handle'     => '',
			'platform'   => '',
			'source'     => $c->comment_author_url,
			'content'    => $c->comment_content,
			'date'       => $c->comment_date_gmt,
		];
	}

	usort( $replies, static fn( array $a, array $b ) => strcmp( $a['date'], $b['date'] ) );

	return $cache[ $post_id ] = [
		'likes'            => $likes,
		'reposts'          => $reposts,
		'replies'          => $replies,
		'comment_children' => $comment_children,
	];
}

/**
 * Resolve the post being rendered, honouring ?post_id= only inside the editor
 * block-renderer request and only for a post the current user may edit.
 * Shared by the three blocks' render.php so their editor previews target the
 * post being authored rather than leaking arbitrary post data.
 */
function nop_wm_resolve_post_id( $block ): int {
	$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );

	$is_editor = defined( 'REST_REQUEST' ) && REST_REQUEST
		&& isset( $_GET['context'] ) && 'edit' === $_GET['context']; // phpcs:ignore WordPress.Security.NonceVerification

	if ( $is_editor && isset( $_GET['post_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$candidate = absint( $_GET['post_id'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $candidate && current_user_can( 'edit_post', $candidate ) ) {
			$post_id = $candidate;
		}
	}

	return $post_id;
}

/**
 * True when the current render is the editor's block-renderer request, used to
 * show sample content instead of an empty box in the site editor.
 */
function nop_wm_is_editor_preview(): bool {
	return defined( 'REST_REQUEST' ) && REST_REQUEST
		&& isset( $_GET['context'] ) && 'edit' === $_GET['context']; // phpcs:ignore WordPress.Security.NonceVerification
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
	$aria = $show_bridgy
		/* translators: %s: platform name e.g. Mastodon */
		? sprintf( __( 'via %s (relayed via Bridgy)', 'nop-indieweb' ), $label )
		/* translators: %s: platform name e.g. Mastodon */
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
	return sprintf(
		/* translators: 1: first author, 2: second author, 3: number of additional likers */
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

/**
 * Compact inline comment form for the bottom of the Responses section.
 * Returns empty string when comments are closed on this post.
 *
 * Custom form (not comment_form()) so the textarea appears before name/email —
 * engage first, ask for details after. Drops the Website and cookies-consent
 * fields: IndieWeb visitors send a webmention; casual visitors don't need them.
 *
 * $show_heading — false in the empty state (invitation text already labels it).
 * $force        — true in the editor preview to render regardless of whether
 *                 comments are open (the template editor has no real post).
 */
function nop_wm_render_comment_form( int $post_id, bool $show_heading = true, bool $force = false ): string {
	if ( ! $force && ! comments_open( $post_id ) ) {
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
		<a id="cancel-comment-reply-link" class="nop-webmentions__cancel-reply" href="<?php echo esc_url( $post_url . '#respond' ); ?>" style="display:none;"><?php esc_html_e( 'Cancel reply', 'nop-indieweb' ); ?></a>
		<form id="commentform" class="nop-webmentions__form-form" method="post" action="<?php echo esc_url( site_url( '/wp-comments-post.php' ) ); ?>">
			<?php if ( $logged_in && $user ) : ?>
			<p class="nop-webmentions__form-field nop-webmentions__form-logged-in logged-in-as">
				<?php
				printf(
					wp_kses(
						/* translators: 1: user display name, 2: logout URL */
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

function nop_wm_heart_icon(): string {
	return '<svg class="nop-reactions__icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false" width="14" height="14"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>';
}

/**
 * Representative sample responses for the editor preview, so each block shows
 * realistic content in the site editor (where there is no real post) instead
 * of an empty box. Avatars resolve to fallback silhouettes — no network.
 */
function nop_wm_sample_data(): array {
	$mk = static fn( string $author, string $handle, string $platform, string $content, string $date ): array => [
		'id'         => 0,
		'author'     => $author,
		'author_url' => '',
		'photo'      => '',
		'handle'     => $handle,
		'platform'   => $platform,
		'via_bridgy' => false,
		'source'     => '',
		'content'    => $content,
		'date'       => $date,
	];

	return [
		'likes' => [
			$mk( 'Jeremy Keith', '', 'mastodon', '', '2026-05-10 09:00:00' ),
			$mk( 'Tantek Çelik', '', 'mastodon', '', '2026-05-10 09:05:00' ),
			$mk( 'Sophie Koonin', '', 'bluesky', '', '2026-05-10 09:10:00' ),
			$mk( 'Chris Aldrich', '', 'mastodon', '', '2026-05-10 09:15:00' ),
		],
		'reposts' => [
			$mk( 'Aaron Parecki', '', 'mastodon', '', '2026-05-10 10:00:00' ),
			$mk( 'Marty McGuire', '', 'bluesky', '', '2026-05-10 10:05:00' ),
		],
		'replies' => [
			$mk( 'Jeremy Keith', '@adactio@mastodon.social', 'mastodon', 'Love seeing the IndieWeb principles put into practice like this. Your own site, your own data.', '2026-05-10 11:00:00' ),
			$mk( 'Sophie Koonin', '@localghost.dev', 'bluesky', 'This is exactly the kind of thing I want for my own site — brilliant work Neil.', '2026-05-10 11:30:00' ),
		],
		'comment_children' => [],
	];
}
