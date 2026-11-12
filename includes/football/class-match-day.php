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
 * kick-off, creates the match post from API-Football's fixture. Every #UTB note
 * posted while that match is on becomes a take: it is linked to the match and
 * stamped with the minute (from the clock, or typed: "67'"). Each take also
 * syncs the match from the API: score, half-time score, venue and the events
 * (goals, cards, subs, VAR), stored on the match post so the record stays on the
 * site. A take that starts with a score ("2-1") sets it when the API has none.
 * A scheduled job at full time syncs once more and posts the closing take.
 *
 * Data: API-Football (api-sports.io), key in the NOP_API_FOOTBALL_KEY constant
 * (wp-config.php). With no key nothing starts and #UTB notes stay plain notes.
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
			$match_id = self::create( $fixture, (int) $post->post_author );
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
		self::sync( $match_id );
	}

	/**
	 * Bring the match post up to date from the API: score, half-time score, venue,
	 * whether it has finished, and the events. At most once a minute unless forced.
	 */
	public static function sync( int $match_id, bool $force = false ): void {
		$fixture_id = (int) get_post_meta( $match_id, 'nop_indieweb_match_fixture_id', true );
		$lock       = 'nop_match_synced_' . $match_id;
		if ( ! $fixture_id || ( ! $force && get_transient( $lock ) ) ) {
			return;
		}
		set_transient( $lock, 1, MINUTE_IN_SECONDS );

		$raw = self::api( 'fixtures', [ 'id' => $fixture_id ] )[0] ?? null;
		if ( ! is_array( $raw ) ) {
			return;
		}
		$fixture = self::fixture_from_api( $raw );
		if ( ! $fixture ) {
			return;
		}
		foreach ( [ 'score' => 'score', 'ht' => 'ht_score', 'venue' => 'venue' ] as $from => $key ) {
			if ( '' !== $fixture[ $from ] ) {
				update_post_meta( $match_id, 'nop_indieweb_match_' . $key, $fixture[ $from ] );
			}
		}
		if ( $fixture['finished'] ) {
			update_post_meta( $match_id, 'nop_indieweb_match_status', 'ft' );
		}
		if ( isset( $raw['events'] ) && is_array( $raw['events'] ) ) {
			update_post_meta( $match_id, 'nop_indieweb_match_events', Match_Events::from_api( $raw['events'], $fixture['home_id'] ) );
		}
	}

	/**
	 * At full time: a last sync, mark the match finished, and post the closing
	 * take, which ends the social thread with the score and a link to the match page.
	 */
	public function full_time( int $match_id ): void {
		if ( self::KIND !== get_post_meta( $match_id, 'nop_indieweb_post_kind', true ) ) {
			return;
		}
		self::sync( $match_id, true );
		update_post_meta( $match_id, 'nop_indieweb_match_status', 'ft' );
		$kickoff = (int) get_post_meta( $match_id, 'nop_indieweb_match_kickoff', true );
		$score   = (string) get_post_meta( $match_id, 'nop_indieweb_match_score', true );

		// Once per match, and never for an old one (a test, or a late cron run).
		if ( get_post_meta( $match_id, 'nop_indieweb_match_ft_take', true ) || time() - $kickoff > DAY_IN_SECONDS ) {
			return;
		}
		$home = (string) get_post_meta( $match_id, 'nop_indieweb_match_home', true );
		$away = (string) get_post_meta( $match_id, 'nop_indieweb_match_away', true );
		$text = '' !== $score
			/* translators: The closing take. 1: home team, 2: score, e.g. 2–1, 3: away team, 4: link to the match page. */
			? sprintf( __( 'Full time: %1$s %2$s %3$s. Every take from the match: %4$s #UTB', 'nop-indieweb' ), $home, $score, $away, get_permalink( $match_id ) )
			/* translators: The closing take when the score isn't known. 1: home team, 2: away team, 3: link to the match page. */
			: sprintf( __( 'Full time: %1$s v %2$s. Every take from the match: %3$s #UTB', 'nop-indieweb' ), $home, $away, get_permalink( $match_id ) );

		$take = wp_insert_post( [
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_author'  => (int) get_post_field( 'post_author', $match_id ),
			'post_content' => '<!-- wp:paragraph -->' . "\n" . '<p>' . esc_html( $text ) . '</p>' . "\n" . '<!-- /wp:paragraph -->',
			'meta_input'   => [
				'nop_indieweb_match_id'     => $match_id,
				'nop_indieweb_match_minute' => 'FT',
			],
		], true );
		if ( is_wp_error( $take ) ) {
			\NOP\IndieWeb\nop_indieweb_log( 'Match: could not post the full-time take', [ 'error' => $take->get_error_message() ] );
			return;
		}
		wp_set_object_terms( $take, 'note', Kind_Taxonomy::TAXONOMY );
		update_post_meta( $match_id, 'nop_indieweb_match_ft_take', $take );
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
	 * A match's takes, in the order they were posted (the full-time take last).
	 *
	 * @return int[]
	 */
	public static function takes( int $match_id ): array {
		return array_map( 'intval', get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [ [ 'key' => 'nop_indieweb_match_id', 'value' => $match_id, 'type' => 'NUMERIC' ] ],
			'orderby'        => [ 'date' => 'ASC', 'ID' => 'ASC' ],
		] ) );
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
	public static function create( array $fixture, int $author = 0 ): int {
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
			'post_author'   => $author ? $author : get_current_user_id(),
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
				'nop_indieweb_match_venue'       => $fixture['venue'],
				'nop_indieweb_match_fixture_id'  => $fixture['id'],
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
	 * The team's fixture on the (UK) day of a moment. Cached for half an hour, a day
	 * without one included, so a run of #UTB notes on a free day costs one call.
	 *
	 * @return array{id:int,home:string,away:string,home_id:int,kickoff:int,competition:string,venue:string,score:string,ht:string,finished:bool}|null
	 */
	public static function fixture_on( int $time ): ?array {
		$day    = ( new \DateTimeImmutable( '@' . $time ) )->setTimezone( new \DateTimeZone( 'Europe/London' ) )->format( 'Y-m-d' );
		$key    = 'nop_match_fixture_' . $day;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached ? $cached : null;
		}
		$team = self::team_id();
		if ( ! $team ) {
			return null;
		}
		$found = self::api( 'fixtures', [ 'team' => $team, 'date' => $day, 'timezone' => 'Europe/London' ] );
		if ( null === $found ) {
			return null; // The API failed: don't cache, try again next time.
		}
		$fixture = is_array( $found[0] ?? null ) ? self::fixture_from_api( $found[0] ) : null;
		set_transient( $key, $fixture ?? [], 30 * MINUTE_IN_SECONDS );
		return $fixture;
	}

	/**
	 * One fixture from the API, in our shape. Null when it lacks a kick-off.
	 *
	 * @param array<mixed> $f A `response` item from /fixtures.
	 * @return array{id:int,home:string,away:string,home_id:int,kickoff:int,competition:string,venue:string,score:string,ht:string,finished:bool}|null
	 */
	private static function fixture_from_api( array $f ): ?array {
		$kickoff = strtotime( (string) ( $f['fixture']['date'] ?? '' ) );
		if ( ! $kickoff ) {
			return null;
		}
		$pair = fn( $h, $a ) => ( null !== $h && null !== $a ) ? (int) $h . '–' . (int) $a : '';
		return [
			'id'          => (int) ( $f['fixture']['id'] ?? 0 ),
			'home'        => (string) ( $f['teams']['home']['name'] ?? '' ),
			'away'        => (string) ( $f['teams']['away']['name'] ?? '' ),
			'home_id'     => (int) ( $f['teams']['home']['id'] ?? 0 ),
			'kickoff'     => $kickoff,
			'competition' => (string) ( $f['league']['name'] ?? '' ),
			'venue'       => (string) ( $f['fixture']['venue']['name'] ?? '' ),
			'score'       => $pair( $f['goals']['home'] ?? null, $f['goals']['away'] ?? null ),
			'ht'          => $pair( $f['score']['halftime']['home'] ?? null, $f['score']['halftime']['away'] ?? null ),
			'finished'    => in_array( (string) ( $f['fixture']['status']['short'] ?? '' ), [ 'FT', 'AET', 'PEN' ], true ),
		];
	}

	/** The team's API id: filter `nop_indieweb_match_team_id`, else looked up once by name and remembered. */
	private static function team_id(): int {
		$id = (int) apply_filters( 'nop_indieweb_match_team_id', (int) get_option( 'nop_indieweb_match_team_id', 0 ) );
		if ( $id ) {
			return $id;
		}
		$name = (string) apply_filters( 'nop_indieweb_match_team', 'Middlesbrough' );
		foreach ( self::api( 'teams', [ 'search' => $name ] ) ?? [] as $t ) {
			if ( is_array( $t ) && 0 === strcasecmp( (string) ( $t['team']['name'] ?? '' ), $name ) ) {
				$id = (int) ( $t['team']['id'] ?? 0 );
				update_option( 'nop_indieweb_match_team_id', $id, false );
				return $id;
			}
		}
		return 0;
	}

	/**
	 * One API-Football request. The `response` list, or null with no key or on any failure (logged).
	 *
	 * @param array<string,int|string> $params Query parameters.
	 * @return array<mixed>|null
	 */
	private static function api( string $path, array $params ): ?array {
		$key = (string) apply_filters( 'nop_indieweb_match_api_key', defined( 'NOP_API_FOOTBALL_KEY' ) ? (string) constant( 'NOP_API_FOOTBALL_KEY' ) : '' );
		if ( '' === $key ) {
			return null;
		}
		$response = wp_remote_get( 'https://v3.football.api-sports.io/' . $path . '?' . http_build_query( $params ), [
			'timeout' => 10,
			'headers' => [ 'x-apisports-key' => $key ],
		] );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			\NOP\IndieWeb\nop_indieweb_log( 'Match: API-Football request failed', [ 'path' => $path, 'error' => is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response ) ] );
			return null;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		// The API answers 200 with an `errors` object for a bad key, the daily limit or a season the plan doesn't cover.
		if ( ! is_array( $body ) ) {
			\NOP\IndieWeb\nop_indieweb_log( 'Match: API-Football answer unreadable', [ 'path' => $path ] );
			return null;
		}
		if ( ! empty( $body['errors'] ) ) {
			\NOP\IndieWeb\nop_indieweb_log( 'Match: API-Football error', [ 'path' => $path, 'errors' => $body['errors'] ] );
			return null;
		}
		return is_array( $body['response'] ?? null ) ? $body['response'] : null;
	}

	private static function has_hashtag( string $content ): bool {
		$tag = strtolower( (string) apply_filters( 'nop_indieweb_match_hashtag', 'UTB' ) );
		return in_array( $tag, array_map( 'strtolower', Hashtags::extract( $content ) ), true );
	}
}
