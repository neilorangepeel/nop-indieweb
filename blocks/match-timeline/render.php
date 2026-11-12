<?php
/**
 * Match Timeline: a Match post's takes and its automated events (goals, cards,
 * subs, VAR from API-Football), merged in minute order from kick-off to full
 * time. Takes are h-entries with their own permalinks (the minute links to the
 * take); what the site posts by itself (the events, the full-time take) is set
 * apart in bold, and events have no link.
 *
 * @var array    $attributes Block attributes.
 * @var WP_Block $block      Block instance.
 */

declare( strict_types=1 );

use NOP\IndieWeb\Football\Match_Day;
use NOP\IndieWeb\Football\Match_Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$nop_match_id = (int) ( $block->context['postId'] ?? get_the_ID() );
if ( ! $nop_match_id || 'match' !== get_post_meta( $nop_match_id, 'nop_indieweb_post_kind', true ) ) {
	return;
}

$nop_minute_label = static function ( string $minute ): string {
	return match ( true ) {
		'' === $minute                                => __( 'Pre-match', 'nop-indieweb' ),
		in_array( $minute, [ 'HT', 'FT' ], true )     => $minute,
		default                                       => $minute . '′',
	};
};

$nop_rows = [];

// The takes: an event comes before a take stamped with the same minute (you react to it). Here a take loses the
// minute and score typed at its start (the minute column and the events say them); the full-time take, which links
// to this page on social, reads just the result.
$nop_ft_take = (int) get_post_meta( $nop_match_id, 'nop_indieweb_match_ft_take', true );
$nop_result  = \NOP\IndieWeb\Post_Meta\Entry_Fields::value( 'match_score_line', $nop_match_id );
foreach ( Match_Day::takes( $nop_match_id ) as $nop_i => $nop_take ) {
	$nop_minute = (string) get_post_meta( $nop_take, 'nop_indieweb_match_minute', true );
	$nop_body   = $nop_take === $nop_ft_take
		/* translators: %s: the result, e.g. Wrexham 1–2 Middlesbrough */
		? '<p>' . esc_html( sprintf( __( 'Full time: %s.', 'nop-indieweb' ), (string) $nop_result ) ) . '</p>'
		: apply_filters( 'the_content', \NOP\IndieWeb\Football\Match_Clock::strip_typed_lead( (string) get_post_field( 'post_content', $nop_take ) ) );
	$nop_rows[] = [
		'key'  => Match_Events::sort_key( $nop_minute ) + 0.001,
		'html' => sprintf(
			'<li class="nop-match-timeline__take h-entry%6$s" id="take-%1$d"><a class="nop-match-timeline__minute u-url" href="%2$s"><time class="dt-published" datetime="%3$s">%4$s</time></a><div class="nop-match-timeline__text e-content">%5$s</div></li>',
			$nop_take,
			esc_url( (string) get_permalink( $nop_take ) ),
			esc_attr( (string) get_post_time( 'c', true, $nop_take ) ),
			esc_html( $nop_minute_label( $nop_minute ) ),
			$nop_body, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the post's own rendered content, or escaped above.
			$nop_take === $nop_ft_take ? ' is-automated' : ''
		),
	];
}

// The events, worded for a reader.
$nop_events = get_post_meta( $nop_match_id, 'nop_indieweb_match_events', true );
$nop_teams  = [
	'home' => (string) get_post_meta( $nop_match_id, 'nop_indieweb_match_home', true ),
	'away' => (string) get_post_meta( $nop_match_id, 'nop_indieweb_match_away', true ),
];
foreach ( Match_Events::with_score( is_array( $nop_events ) ? $nop_events : [] ) as $nop_event ) {
	$nop_team   = esc_html( $nop_teams[ $nop_event['side'] ?? 'home' ] ?? '' );
	$nop_player = esc_html( (string) ( $nop_event['player'] ?? '' ) );
	$nop_assist = esc_html( (string) ( $nop_event['assist'] ?? '' ) );
	$nop_score  = esc_html( (string) ( $nop_event['score'] ?? '' ) );
	$nop_for    = esc_html( $nop_teams[ Match_Events::scoring_side( $nop_event ) ] ?? '' );
	$nop_text   = match ( $nop_event['type'] ?? '' ) {
		/* translators: 1: team, 2: scorer (the running score sits under the minute) */
		'goal'           => sprintf( __( 'Goal! %1$s. %2$s', 'nop-indieweb' ), $nop_team, $nop_player )
			/* translators: %s: the player who made the assist */
			. ( '' !== $nop_assist ? sprintf( __( ', assisted by %s', 'nop-indieweb' ), $nop_assist ) : '' ),
		/* translators: 1: team, 2: scorer */
		'penalty'        => sprintf( __( 'Penalty scored! %1$s. %2$s', 'nop-indieweb' ), $nop_team, $nop_player ),
		/* translators: 1: team the goal counts for, 2: the player who put it in his own net */
		'own-goal'       => sprintf( __( 'Own goal. %1$s. %2$s', 'nop-indieweb' ), $nop_for, $nop_player ),
		/* translators: 1: player, 2: team */
		'missed-penalty' => sprintf( __( 'Penalty missed: %1$s (%2$s)', 'nop-indieweb' ), $nop_player, $nop_team ),
		/* translators: 1: player, 2: team */
		'yellow'         => sprintf( __( 'Yellow card: %1$s (%2$s)', 'nop-indieweb' ), $nop_player, $nop_team ),
		/* translators: 1: player, 2: team */
		'second-yellow'  => sprintf( __( 'Second yellow, sent off: %1$s (%2$s)', 'nop-indieweb' ), $nop_player, $nop_team ),
		/* translators: 1: player, 2: team */
		'red'            => sprintf( __( 'Red card: %1$s (%2$s)', 'nop-indieweb' ), $nop_player, $nop_team ),
		/* translators: 1: team, 2: player coming on, 3: player going off */
		'sub'            => sprintf( __( 'Substitution, %1$s: %2$s on, %3$s off', 'nop-indieweb' ), $nop_team, $nop_assist, $nop_player ),
		/* translators: 1: team, 2: the decision, e.g. Goal cancelled */
		'var'            => sprintf( __( 'VAR, %1$s: %2$s', 'nop-indieweb' ), $nop_team, esc_html( (string) ( $nop_event['detail'] ?? '' ) ) ),
		default          => '',
	};
	if ( '' === $nop_text ) {
		continue;
	}
	$nop_minute = (string) ( $nop_event['minute'] ?? '' );
	$nop_rows[] = [
		'key'  => Match_Events::sort_key( $nop_minute ),
		'html' => sprintf(
			'<li class="nop-match-timeline__event is-%1$s"><span class="nop-match-timeline__minute">%2$s%4$s</span><p class="nop-match-timeline__text">%3$s</p></li>',
			esc_attr( (string) $nop_event['type'] ),
			esc_html( $nop_minute_label( $nop_minute ) ),
			wp_kses( $nop_text, [] ), // Its parts are escaped above; this keeps a translation from adding markup.
			// A goal carries the running score under its minute, so the left edge tells the match.
			Match_Events::is_goal( $nop_event ) ? '<span class="nop-match-timeline__score">' . $nop_score . '</span>' : ''
		),
	];
}

if ( ! $nop_rows ) {
	return;
}
usort( $nop_rows, fn( $a, $b ) => $a['key'] <=> $b['key'] ); // Stable (PHP 8): takes keep their posting order within a minute.

printf(
	'<ol %1$s>%2$s</ol>',
	get_block_wrapper_attributes( [ 'class' => 'nop-match-timeline', 'aria-label' => esc_attr__( 'The match, minute by minute', 'nop-indieweb' ) ] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	implode( '', array_column( $nop_rows, 'html' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
);
