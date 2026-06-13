<?php
/**
 * Reactions block — server-side render.
 *
 * Merged single-row band: likes facepile and reposts facepile side by side,
 * each with a small count label (♥ 4 ●●●+1   ↺ 2 ●●). Low-information
 * reactions collapsed into one calm row, separate from the conversation.
 *
 * Front end: renders nothing when the post has no likes and no reposts.
 * Editor: shows sample reactions so the block is visible and designable in
 * the site editor.
 */
declare( strict_types=1 );

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once NOP_INDIEWEB_DIR . 'includes/webmention-render.php';

$max_avatars = max( 1, (int) ( $attributes['maxAvatars'] ?? 7 ) );
$show_counts = ! isset( $attributes['showCounts'] ) || (bool) $attributes['showCounts'];

$preview = nop_wm_is_editor_preview();
$post_id = nop_wm_resolve_post_id( $block );
$data    = nop_wm_get_data( $post_id );

$likes   = $data['likes'];
$reposts = $data['reposts'];

// Editor with no real reactions yet → sample band so the block isn't an empty
// box in the template editor.
if ( $preview && ! $likes && ! $reposts ) {
	$sample  = nop_wm_sample_data();
	$likes   = $sample['likes'];
	$reposts = $sample['reposts'];
}

if ( ! $likes && ! $reposts ) {
	return;
}

/**
 * Render one reaction group (icon + count + overlapping facepile).
 *
 * On the front end only real avatars appear — anonymous silhouettes add no
 * value to a facepile. In the editor preview the photo filter is relaxed so
 * the design is visible even though sample entries have no photos.
 */
$render_group = static function ( array $entries, string $icon, string $kind, string $aria, string $sr_html, bool $show_counts, int $max, bool $preview ): string {
	$count = count( $entries );
	if ( 0 === $count ) {
		return '';
	}

	$pool     = $preview ? $entries : array_values( array_filter( $entries, static fn( array $e ) => ! empty( $e['photo'] ) ) );
	$shown    = array_slice( $pool, 0, $max );
	$overflow = $count - count( $shown );

	$out = '<div class="nop-reactions__group nop-reactions__group--' . esc_attr( $kind ) . '">';

	// When a screen-reader sentence is supplied (likers' names), the visible
	// icon+count is decorative — hide it from AT and let the sentence speak.
	// Otherwise the count label carries the accessible label itself.
	if ( '' !== $sr_html ) {
		$out .= '<span class="nop-reactions__label" aria-hidden="true">';
	} else {
		$out .= '<span class="nop-reactions__label" aria-label="' . esc_attr( $aria ) . '">';
	}
	$out .= $icon;
	if ( $show_counts ) {
		$out .= '<span class="nop-reactions__count">' . esc_html( (string) $count ) . '</span>';
	}
	$out .= '</span>';

	if ( '' !== $sr_html ) {
		$out .= '<span class="screen-reader-text">' . $sr_html . '</span>';
	}

	if ( $shown ) {
		$out .= '<div class="nop-webmentions__facepile" aria-hidden="true">';
		foreach ( $shown as $entry ) {
			$out .= nop_wm_avatar_wrap( $entry, 28 );
		}
		if ( $overflow > 0 ) {
			$out .= '<div class="nop-webmentions__avatar-wrap">'
			      . '<span class="nop-webmentions__overflow nop-webmentions__avatar--28">+' . esc_html( (string) $overflow ) . '</span>'
			      . '</div>';
		}
		$out .= '</div>';
	}

	$out .= '</div>';
	return $out;
};

/* translators: %d: number of likes */
$likes_aria   = sprintf( _n( '%d like', '%d likes', count( $likes ), 'nop-indieweb' ), count( $likes ) );
/* translators: %d: number of reposts */
$reposts_aria = sprintf( _n( '%d repost', '%d reposts', count( $reposts ), 'nop-indieweb' ), count( $reposts ) );

// Likers get their names announced (matching the original facepile sentence);
// reposts keep the simple count label.
$likes_sr = $likes ? nop_wm_liked_by( $likes ) : '';

$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'nop-reactions' ] );
?>
<div <?php echo wp_kses_data( $wrapper_attrs ); ?>>
	<?php
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- helper output is escaped at source (avatar URLs, names, counts) and includes plugin-authored SVG constants
	echo $render_group( $likes, nop_wm_heart_icon(), 'likes', $likes_aria, $likes_sr, $show_counts, $max_avatars, $preview );
	echo $render_group( $reposts, nop_wm_repost_icon(), 'reposts', $reposts_aria, '', $show_counts, $max_avatars, $preview );
	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
</div>
