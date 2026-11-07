<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Football;

use NOP\IndieWeb\Kind\Hashtags;
use NOP\IndieWeb\Kind\Kind_Taxonomy;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Match posts and the takes that belong to them (ROADMAP.md: Match kind).
 *
 * A note with the match hashtag (#UTB), posted within an hour of a Boro
 * kick-off, creates the match post from the openfootball fixture list. Every
 * #UTB note posted while that match is on becomes a take: it is linked to the
 * match and stamped with the minute. Notes without the hashtag are left alone. A take that starts with a minute ("67'") or a
 * score ("2-1") sets them. A scheduled job at full time marks the match done.
 *
 * Fixtures: openfootball/football.json (public domain, no key). It has no live
 * data, so the minute comes from the clock and the score from the takes.
 */
class Match_Day {

	const KIND      = 'match';
	const FULL_TIME = 'nop_indieweb_match_full_time';

	/** Takes are accepted from an hour before kick-off to 150 minutes after it. */
	const BEFORE = HOUR_IN_SECONDS;
	const AFTER  = 150 * MINUTE_IN_SECONDS;

	public function register(): void {
		// After Kind_Taxonomy's mirror (10), so the kind meta is already set.
		add_action( 'set_object_terms', [ $this, 'maybe_take' ], 20, 4 );
		add_action( self::FULL_TIME, [ $this, 'full_time' ] );
	}

	/**
	 * @param int      $object_id Post ID.
	 * @param array    $terms     Terms being set (unused).
	 * @param array    $tt_ids    Term taxonomy IDs (unused).
	 * @param string   $taxonomy  Taxonomy slug.
	 */
	public function maybe_take( int $object_id, array $terms, array $tt_ids, string $taxonomy ): void {
		if ( Kind_Taxonomy::TAXONOMY !== $taxonomy
			|| 'note' !== get_post_meta( $object_id, 'nop_indieweb_post_kind', true )
			|| 'publish' !== get_post_status( $object_id )
			|| get_post_meta( $object_id, 'nop_indieweb_match_id', true ) ) {
			return;
		}

		$post = get_post( $object_id );
		if ( ! $post || ! self::has_hashtag( $post->post_content ) ) {
			return;
		}
		$time     = (int) get_post_time( 'U', true, $post );
		$match_id = self::match_at( $time );

		if ( ! $match_id ) {
			// Only a note posted now can start a match, never an imported old one.
			if ( abs( time() - $time ) > 6 * HOUR_IN_SECONDS ) {
				return;
			}
			$fixture = self::fixture_on( $time );
			if ( ! $fixture || $time < $fixture['kickoff'] - self::BEFORE || $time > $fixture['kickoff'] + self::AFTER ) {
				return;
			}
			$match_id = self::create( $fixture );
			if ( ! $match_id ) {
				return;
			}
		}

		$this->attach( $object_id, $match_id, $post->post_content, $time );
	}

	/** Link a take to its match, stamp the minute and apply any score it gives. */
	public function attach( int $take_id, int $match_id, string $content, int $time ): void {
		$kickoff = (int) get_post_meta( $match_id, 'nop_indieweb_match_kickoff', true );
		$minute  = Match_Clock::typed_minute( $content ) ?? Match_Clock::minute( $time - $kickoff );

		update_post_meta( $take_id, 'nop_indieweb_match_id', $match_id );
		update_post_meta( $take_id, 'nop_indieweb_match_minute', $minute );

		$score = Match_Clock::typed_score( $content );
		if ( null !== $score ) {
			update_post_meta( $match_id, 'nop_indieweb_match_score', $score );
		}
	}

	/** At full time: mark the match finished, and take openfootball's score if no take gave one. */
	public function full_time( int $match_id ): void {
		if ( self::KIND !== get_post_meta( $match_id, 'nop_indieweb_post_kind', true ) ) {
			return;
		}
		update_post_meta( $match_id, 'nop_indieweb_match_status', 'ft' );
		if ( '' !== (string) get_post_meta( $match_id, 'nop_indieweb_match_score', true ) ) {
			return;
		}
		$fixture = self::fixture_on( (int) get_post_meta( $match_id, 'nop_indieweb_match_kickoff', true ) );
		if ( $fixture && '' !== $fixture['score'] ) {
			update_post_meta( $match_id, 'nop_indieweb_match_score', $fixture['score'] );
		}
	}

	/** The match post whose window covers a moment, or 0. */
	public static function match_at( int $time ): int {
		$ids = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => [ [ 'taxonomy' => Kind_Taxonomy::TAXONOMY, 'field' => 'slug', 'terms' => self::KIND ] ],
			'meta_query'     => [ [
				'key'     => 'nop_indieweb_match_kickoff',
				'value'   => [ $time - self::AFTER, $time + self::BEFORE ],
				'compare' => 'BETWEEN',
				'type'    => 'NUMERIC',
			] ],
		] );
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * The posts a take could reply to on social, nearest first: up to five
	 * earlier takes of its match, then the match post. Empty for a non-take.
	 *
	 * @return int[]
	 */
	public static function thread_before( int $post_id ): array {
		$match = (int) get_post_meta( $post_id, 'nop_indieweb_match_id', true );
		if ( ! $match ) {
			return [];
		}
		$ids = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 5,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'post__not_in'   => [ $post_id ],
			'meta_query'     => [ [ 'key' => 'nop_indieweb_match_id', 'value' => $match, 'type' => 'NUMERIC' ] ],
			'date_query'     => [ [ 'column' => 'post_date_gmt', 'before' => get_post_field( 'post_date_gmt', $post_id ), 'inclusive' => true ] ],
			'orderby'        => [ 'date' => 'DESC', 'ID' => 'DESC' ],
		] );
		return array_merge( array_map( 'intval', $ids ), [ $match ] );
	}

	/** Create the match post for a fixture. Returns its ID, or 0. */
	public static function create( array $fixture ): int {
		$existing = self::match_at( $fixture['kickoff'] );
		if ( $existing ) {
			return $existing;
		}
		$date    = min( time(), $fixture['kickoff'] );
		$kickoff = wp_date( '00' === wp_date( 'i', $fixture['kickoff'] ) ? 'ga' : 'g.ia', $fixture['kickoff'] );
		/* translators: The match post, which opens the social thread. 1: home team, 2: away team, 3: competition, 4: kick-off time, e.g. 3pm. */
		$opener  = sprintf( __( '%1$s v %2$s, %3$s, kick-off %4$s. Thoughts as it happens in the replies. #UTB', 'nop-indieweb' ), $fixture['home'], $fixture['away'], $fixture['competition'], $kickoff );
		$id      = wp_insert_post( [
			'post_type'     => 'post',
			'post_status'   => 'publish',
			/* translators: 1: home team, 2: away team. */
			'post_title'    => sprintf( __( '%1$s v %2$s', 'nop-indieweb' ), $fixture['home'], $fixture['away'] ),
			'post_content'  => '<!-- wp:paragraph -->' . "\n" . '<p>' . esc_html( $opener ) . '</p>' . "\n" . '<!-- /wp:paragraph -->',
			'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $date ) ),
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $date ),
			'meta_input'    => [
				'nop_indieweb_match_home'        => $fixture['home'],
				'nop_indieweb_match_away'        => $fixture['away'],
				'nop_indieweb_match_kickoff'     => $fixture['kickoff'],
				'nop_indieweb_match_competition' => $fixture['competition'],
				'nop_indieweb_match_status'      => 'live',
			],
		], true );
		if ( is_wp_error( $id ) ) {
			\NOP\IndieWeb\nop_indieweb_log( 'Match: could not create the match post', [ 'error' => $id->get_error_message() ] );
			return 0;
		}
		wp_set_object_terms( $id, self::KIND, Kind_Taxonomy::TAXONOMY );
		wp_schedule_single_event( $fixture['kickoff'] + 3 * HOUR_IN_SECONDS, self::FULL_TIME, [ $id ] );
		return $id;
	}

	/**
	 * The team's fixture on the (UK) day of a moment, from openfootball.
	 *
	 * @return array{home:string,away:string,kickoff:int,competition:string,score:string}|null
	 */
	public static function fixture_on( int $time ): ?array {
		$tz    = new \DateTimeZone( 'Europe/London' );
		$day   = ( new \DateTimeImmutable( '@' . $time ) )->setTimezone( $tz )->format( 'Y-m-d' );
		$team  = (string) apply_filters( 'nop_indieweb_match_team', 'Middlesbrough FC' );
		$files = (array) apply_filters( 'nop_indieweb_match_leagues', [ 'en.2', 'en.1' ] );

		foreach ( $files as $file ) {
			$league = self::league( Match_Clock::season( $day ), (string) $file );
			foreach ( $league['matches'] as $m ) {
				if ( ! is_array( $m ) || ( $m['date'] ?? '' ) !== $day || ! in_array( $team, [ $m['team1'] ?? '', $m['team2'] ?? '' ], true ) ) {
					continue;
				}
				$kickoff = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $day . ' ' . ( $m['time'] ?? '15:00' ), $tz );
				if ( ! $kickoff ) {
					continue;
				}
				$ft = $m['score']['ft'] ?? null;
				return [
					'home'        => Match_Clock::team_name( (string) $m['team1'] ),
					'away'        => Match_Clock::team_name( (string) $m['team2'] ),
					'kickoff'     => $kickoff->getTimestamp(),
					'competition' => trim( (string) preg_replace( '/\s+\d{4}\/\d{2}$/', '', $league['name'] ) ),
					'score'       => is_array( $ft ) && 2 === count( $ft ) ? (int) $ft[0] . '–' . (int) $ft[1] : '',
				];
			}
		}
		return null;
	}

	/**
	 * One openfootball league file, cached for six hours.
	 *
	 * @return array{name:string,matches:array<mixed>}
	 */
	private static function league( string $season, string $file ): array {
		$key    = 'nop_match_league_' . md5( $season . $file );
		$league = get_transient( $key );
		if ( is_array( $league ) ) {
			return $league;
		}
		$league   = [ 'name' => '', 'matches' => [] ];
		$url      = sprintf( 'https://raw.githubusercontent.com/openfootball/football.json/master/%s/%s.json', rawurlencode( $season ), rawurlencode( $file ) );
		$response = wp_remote_get( $url, [ 'timeout' => 8 ] );
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $body ) && isset( $body['matches'] ) && is_array( $body['matches'] ) ) {
				$league = [ 'name' => (string) ( $body['name'] ?? '' ), 'matches' => $body['matches'] ];
			}
		}
		// A failed fetch is cached briefly so a run of takes doesn't retry it each time.
		set_transient( $key, $league, $league['matches'] ? 6 * HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
		return $league;
	}

	private static function has_hashtag( string $content ): bool {
		$tag = strtolower( (string) apply_filters( 'nop_indieweb_match_hashtag', 'UTB' ) );
		return in_array( $tag, array_map( 'strtolower', Hashtags::extract( $content ) ), true );
	}
}
