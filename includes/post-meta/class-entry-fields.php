<?php
/**
 * Entry fields — the ready-to-read facts a theme binds to.
 *
 * Every value here is text a reader sees (formatted with the site's date and
 * locale settings, words translatable), served through the
 * `nop-indieweb/post-meta` Block Bindings source, so any block theme can lay a
 * kind out with core blocks: a Paragraph bound to `{"field":"visit_label"}`,
 * a Button bound to `source_url` / `source_label`, and so on. Empty string means
 * "nothing to show", so a theme can hide the block with :empty.
 *
 * The theme decides how things look; this decides what they say.
 */
declare( strict_types=1 );

namespace NOP\IndieWeb\Post_Meta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Entry_Fields {

	/** Field names this class answers (anything else falls through to the source's other fields). */
	public const FIELDS = [
		'subtitle', 'detail', 'display_title', 'post_number', 'permalink', 'day', 'date_time',
		'source_url', 'source_label',
		'cite_title', 'cite_excerpt', 'cite_image', 'cite_author', 'cite_site',
		'venue_category', 'visit_label', 'weather_label', 'country_short', 'city_line',
		'exercise_summary',
		'watch_released', 'watch_date',
		'listen_artist_year',
		'rsvp_answer', 'rsvp_event', 'rsvp_when', 'rsvp_time', 'rsvp_where', 'rsvp_image',
		'quote_text', 'quote_author', 'quote_work',
		'reply_count', 'reply_count_label', 'replies_label', 'repost_count',
	];

	/** @var array<int,array<string,string>> Values per post, worked out once a request. */
	private static array $cache = [];

	/**
	 * One field for one post, or null when the field isn't one of ours.
	 */
	public static function value( string $field, int $post_id ): ?string {
		if ( ! in_array( $field, self::FIELDS, true ) ) {
			return null;
		}
		if ( 'reply_count_label' === $field ) {
			return self::reply_count_label( $post_id );
		}
		if ( 'reply_count' === $field ) {
			return (string) self::reply_count( $post_id );
		}
		// Reposts (boosts, shares elsewhere) as a bare number; '' with none, so a block bound to it takes no room.
		if ( 'repost_count' === $field ) {
			$reposts = \NOP\IndieWeb\Webmention\Engagement_Counts::get( $post_id )['reposts'];
			return $reposts ? (string) $reposts : '';
		}
		// The same count, always worded: a reply count shown on its own (a spec row, a list) says so when there are none.
		if ( 'replies_label' === $field ) {
			return self::reply_count_label( $post_id ) ?: __( 'No replies', 'nop-indieweb' );
		}
		self::$cache[ $post_id ] ??= self::compute( $post_id );
		return self::$cache[ $post_id ][ $field ] ?? '';
	}

	/** US state names to their postal codes, for a city line. */
	private const US_STATES = [
		'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR', 'california' => 'CA', 'colorado' => 'CO',
		'connecticut' => 'CT', 'delaware' => 'DE', 'district of columbia' => 'DC', 'florida' => 'FL', 'georgia' => 'GA',
		'hawaii' => 'HI', 'idaho' => 'ID', 'illinois' => 'IL', 'indiana' => 'IN', 'iowa' => 'IA', 'kansas' => 'KS',
		'kentucky' => 'KY', 'louisiana' => 'LA', 'maine' => 'ME', 'maryland' => 'MD', 'massachusetts' => 'MA',
		'michigan' => 'MI', 'minnesota' => 'MN', 'mississippi' => 'MS', 'missouri' => 'MO', 'montana' => 'MT',
		'nebraska' => 'NE', 'nevada' => 'NV', 'new hampshire' => 'NH', 'new jersey' => 'NJ', 'new mexico' => 'NM',
		'new york' => 'NY', 'north carolina' => 'NC', 'north dakota' => 'ND', 'ohio' => 'OH', 'oklahoma' => 'OK',
		'oregon' => 'OR', 'pennsylvania' => 'PA', 'rhode island' => 'RI', 'south carolina' => 'SC', 'south dakota' => 'SD',
		'tennessee' => 'TN', 'texas' => 'TX', 'utah' => 'UT', 'vermont' => 'VT', 'virginia' => 'VA', 'washington' => 'WA',
		'west virginia' => 'WV', 'wisconsin' => 'WI', 'wyoming' => 'WY',
	];

	/** A country's short form: a stored code as it is, else its ISO code from ICU's English names ("Ireland" → IE); the UK as "UK"; the name itself without intl or a match. */
	private static function country_code( string $country ): string {
		if ( 2 === strlen( $country ) && ctype_alpha( $country ) ) {
			$code = strtoupper( $country );
			return 'GB' === $code ? 'UK' : $code;
		}
		if ( '' === $country || ! class_exists( 'ResourceBundle' ) ) {
			return $country;
		}
		$names = \ResourceBundle::create( 'en', 'ICUDATA-region' );
		foreach ( $names ? $names['Countries'] : [] as $code => $name ) {
			if ( 2 === strlen( (string) $code ) && 0 === strcasecmp( (string) $name, $country ) ) {
				return 'GB' === $code ? 'UK' : (string) $code;
			}
		}
		return $country;
	}

	private static function meta( int $post_id, string $key ): string {
		return (string) get_post_meta( $post_id, 'nop_indieweb_' . $key, true );
	}

	private static function kind( int $post_id ): string {
		$terms = get_the_terms( $post_id, \NOP\IndieWeb\Kind\Kind_Taxonomy::TAXONOMY );
		return $terms && ! is_wp_error( $terms ) ? (string) $terms[0]->slug : '';
	}


	/** "5km Run", "25km Cycle", "800m Walk", "45min Yoga", or the activity alone. */
	public static function exercise_summary( int $post_id ): string {
		$type = self::meta( $post_id, 'exercise_type' );
		if ( '' === $type ) {
			return '';
		}
		$names = [
			'run'      => __( 'Run', 'nop-indieweb' ),
			'ride'     => __( 'Cycle', 'nop-indieweb' ),
			'walk'     => __( 'Walk', 'nop-indieweb' ),
			'hike'     => __( 'Hike', 'nop-indieweb' ),
			'swim'     => __( 'Swim', 'nop-indieweb' ),
			'rowing'   => __( 'Row', 'nop-indieweb' ),
			'strength' => __( 'Strength', 'nop-indieweb' ),
			'yoga'     => __( 'Yoga', 'nop-indieweb' ),
			'workout'  => __( 'Workout', 'nop-indieweb' ),
		];
		$name    = $names[ $type ] ?? ucfirst( str_replace( [ '-', '_' ], ' ', $type ) );
		$metres  = (float) self::meta( $post_id, 'exercise_distance_m' );
		$seconds = (int) self::meta( $post_id, 'exercise_duration_s' );
		if ( $metres >= 1000 ) {
			// One decimal, and none at all on a round number: 5km, not 5.0km.
			$amount = rtrim( rtrim( number_format( $metres / 1000, 1, '.', '' ), '0' ), '.' ) . 'km';
		} elseif ( $metres > 0 ) {
			$amount = (int) round( $metres ) . 'm';
		} elseif ( $seconds > 0 ) {
			$amount = max( 1, (int) round( $seconds / 60 ) ) . 'min';
		} else {
			return $name;
		}
		return $amount . ' ' . $name;
	}

	/** "2 replies": webmention replies and comments, as the Replies block lists them; '' with none. */
	/** Replies as the Replies block lists them: webmention replies and comments together. */
	private static function reply_count( int $post_id ): int {
		if ( ! function_exists( 'nop_wm_get_data' ) ) {
			require_once NOP_INDIEWEB_DIR . 'includes/webmention-render.php';
		}
		$data  = nop_wm_get_data( $post_id );
		$count = count( $data['replies'] );
		foreach ( $data['comment_children'] as $children ) {
			$count += count( (array) $children );
		}
		return $count;
	}

	private static function reply_count_label( int $post_id ): string {
		$count = self::reply_count( $post_id );
		/* translators: %d: number of replies */
		return $count ? sprintf( _n( '%d reply', '%d replies', $count, 'nop-indieweb' ), $count ) : '';
	}

	/**
	 * @return array<string,string>
	 */
	private static function compute( int $post_id ): array {
		$kind = self::kind( $post_id );
		$f    = [];

		$f['post_number'] = '#' . $post_id;
		// The entry's own page, for a button (core's post-data source needs the post in the block's context, which a Button has not).
		$f['permalink']   = (string) get_permalink( $post_id );
		$f['day']         = (string) get_the_date( 'j M Y', $post_id );
		$f['date_time']   = $f['day'] . ' · ' . get_the_date( 'H:i', $post_id );

		if ( '' !== self::meta( $post_id, 'weather_temp_c' ) ) {
			$summary            = self::meta( $post_id, 'weather_summary' );
			$f['weather_label'] = round( (float) self::meta( $post_id, 'weather_temp_c' ) ) . '°C' . ( $summary ? ' · ' . $summary : '' );
		}

		$title = html_entity_decode( (string) get_the_title( $post_id ), ENT_QUOTES );

		switch ( $kind ) {
			case 'checkin':
				$f['subtitle']     = self::meta( $post_id, 'venue_address' );
				$f['country_short']  = self::country_code( self::meta( $post_id, 'venue_country' ) );
				// "Belfast, UK", "Colorado Springs, CO": the city and, in the US, its state, elsewhere the country.
				$short          = $f['country_short'];
				$region         = self::meta( $post_id, 'venue_region' );
				$suffix         = 'US' === $short ? ( self::US_STATES[ strtolower( $region ) ] ?? ( 2 === strlen( $region ) ? strtoupper( $region ) : 'US' ) ) : $short;
				$f['city_line'] = implode( ', ', array_filter( [ self::meta( $post_id, 'venue_locality' ), $suffix ] ) );
				$f['source_url']   = self::meta( $post_id, 'checkin_url' ) ?: self::meta( $post_id, 'source_url' );
				$f['source_label'] = $f['source_url'] ? 'Swarm' : '';
				$visit             = (int) self::meta( $post_id, 'venue_visit_number' );
				if ( $visit ) {
					/* translators: %s: ordinal, e.g. 258th */
					$f['visit_label'] = $visit < 2 ? __( 'First visit', 'nop-indieweb' ) : sprintf( __( '%s visit', 'nop-indieweb' ), \NOP\IndieWeb\nop_indieweb_ordinal( $visit ) );
				}
				$categories = get_post_meta( $post_id, 'nop_indieweb_venue_categories', true );
				if ( is_array( $categories ) && $categories ) {
					$first               = reset( $categories );
					$f['venue_category'] = is_array( $first ) ? (string) ( $first['name'] ?? '' ) : (string) $first;
				}
				$venue = self::meta( $post_id, 'venue_name' );
				if ( ! $venue ) {
					$town  = self::meta( $post_id, 'venue_locality' ) ?: self::meta( $post_id, 'venue_region' );
					$venue = $town ? (string) preg_replace( '/, ' . preg_quote( $town, '/' ) . '$/', '', $title ) : '';
				}
				$f['display_title'] = $venue ?: $title;
				$f['detail']        = $f['visit_label'] ?? '';
				break;

			case 'exercise':
				$f['exercise_summary'] = self::exercise_summary( $post_id );
				$f['subtitle']         = (string) preg_replace( '/^[\d.]+k?m(in)? /', '', $f['exercise_summary'] );
				$f['source_url']       = self::meta( $post_id, 'exercise_source_url' );
				$f['source_label']     = $f['source_url'] ? ucfirst( self::meta( $post_id, 'service' ) ?: 'Strava' ) : '';
				$f['detail']           = $f['exercise_summary'];
				break;

			case 'watch':
				$f['subtitle']       = self::meta( $post_id, 'film_rewatch' ) ? __( 'Rewatch', 'nop-indieweb' ) : '';
				$f['watch_released'] = self::meta( $post_id, 'film_year' );
				$watched             = self::meta( $post_id, 'watch_date' );
				$f['watch_date']     = $watched ? (string) wp_date( 'j M Y', (int) strtotime( $watched ) ) : '';
				$f['source_url']     = self::meta( $post_id, 'source_url' );
				$f['source_label']   = $f['source_url'] ? 'Letterboxd' : '';
				break;

			case 'listen':
				// The post opens "Artist, Year." ("The Strokes, 2011.").
				$first = wp_strip_all_tags( (string) ( parse_blocks( (string) get_post_field( 'post_content', $post_id ) )[0]['innerHTML'] ?? '' ) );
				if ( preg_match( '/^\s*([^,.\n]{1,60}),\s*((?:19|20)\d{2})\.?\s*$/u', $first, $m ) ) {
					$f['listen_artist_year'] = $m[1] . ' · ' . $m[2];
				}
				$f['detail'] = $f['listen_artist_year'] ?? '';
				break;

			case 'rsvp':
				$answers           = [
					'yes'        => __( 'Going', 'nop-indieweb' ),
					'maybe'      => __( 'Maybe', 'nop-indieweb' ),
					'no'         => __( 'Not going', 'nop-indieweb' ),
					'interested' => __( 'Interested', 'nop-indieweb' ),
				];
				$f['rsvp_answer']  = $answers[ self::meta( $post_id, 'rsvp' ) ] ?? '';
				$f['subtitle']     = $f['rsvp_answer'];
				$f['detail']       = $f['rsvp_answer'];
				$f['source_url']   = self::meta( $post_id, 'in_reply_to' );
				$f['source_label'] = $f['source_url'] ? (string) wp_parse_url( $f['source_url'], PHP_URL_HOST ) : '';
				// The event, as its tile names it: its own name, else the title without the answer in front.
				$f['rsvp_event']    = self::meta( $post_id, 'rsvp_event_name' ) ?: trim( str_contains( $title, '·' ) ? substr( $title, strpos( $title, '·' ) + 2 ) : $title );
				$f['display_title'] = $f['rsvp_event'];
				$start              = self::meta( $post_id, 'rsvp_event_start' ) ? (int) strtotime( self::meta( $post_id, 'rsvp_event_start' ) ) : 0;
				$end                = self::meta( $post_id, 'rsvp_event_end' ) ? (int) strtotime( self::meta( $post_id, 'rsvp_event_end' ) ) : 0;
				if ( $start ) {
					$f['rsvp_when'] = wp_date( 'j M Y', $start ) . ( $end && wp_date( 'Y-m-d', $end ) !== wp_date( 'Y-m-d', $start ) ? ' – ' . wp_date( 'j M', $end ) : '' );
					$f['rsvp_time'] = (string) wp_date( 'H:i', $start );
				}
				$f['rsvp_where'] = self::meta( $post_id, 'rsvp_event_location' );
				// The event's own photo, as parsed from its page (a remote URL, not an attachment).
				$f['rsvp_image'] = self::meta( $post_id, 'rsvp_event_image' );
				break;

			case 'bookmark':
			case 'like':
			case 'reply':
			case 'repost':
			case 'quote':
				$keys = [ 'bookmark' => 'bookmark_of', 'like' => 'like_of', 'reply' => 'in_reply_to', 'repost' => 'repost_of', 'quote' => 'quote_of' ];
				$url  = self::meta( $post_id, $keys[ $kind ] ) ?: (string) get_post_meta( $post_id, '_nop_indieweb_cite_source', true );
				$host = $url ? (string) preg_replace( '/^www\./', '', (string) wp_parse_url( $url, PHP_URL_HOST ) ) : '';
				$f['source_url']   = $url;
				$f['source_label'] = $host;
				if ( 'quote' === $kind ) {
					[ $f['quote_text'], $f['quote_author'], $f['quote_work'] ] = self::quote( $post_id );
					$f['detail'] = $f['quote_author'];
					break;
				}
				$cite = self::meta( $post_id, 'cite_title' );
				if ( ! $cite && $url && preg_match( '#/(@[\w.]+)#', (string) wp_parse_url( $url, PHP_URL_PATH ), $m ) ) {
					// A Mastodon-style post has no title of its own: name it by its author.
					/* translators: %s: an @handle */
					$cite = sprintf( __( 'A post by %s', 'nop-indieweb' ), $m[1] );
				}
				$f['cite_title']    = $cite ?: $host;
				$f['cite_excerpt']  = self::meta( $post_id, 'cite_excerpt' );
				$f['cite_image']    = self::meta( $post_id, 'cite_image' );
				$f['cite_author']   = self::meta( $post_id, 'cite_author_name' );
				$f['cite_site']     = self::meta( $post_id, 'cite_site_name' ) ?: $host;
				// The kind label already says "Liked": the title is the thing itself.
				$f['display_title'] = $cite ?: (string) preg_replace( '/^[^·]{2,16}\s·\s/u', '', $title );
				$f['detail']        = $f['cite_site'];
				break;
		}

		// A note is untitled on purpose: name it by its opening words, never "(no title)".
		$f['display_title'] = ( $f['display_title'] ?? '' ) ?: ( '' !== trim( $title ) ? $title : wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ), 6, '…' ) );
		return $f;
	}

	/**
	 * A quote post's own Quote block: [ "the quotation", author, the work and year ].
	 *
	 * @return array{0:string,1:string,2:string}
	 */
	private static function quote( int $post_id ): array {
		foreach ( parse_blocks( (string) get_post_field( 'post_content', $post_id ) ) as $block ) {
			if ( 'core/quote' !== $block['blockName'] ) {
				continue;
			}
			// The cite reads "Author, Work, Year": the author, then the rest (the work and its year).
			$author = '';
			$work   = '';
			if ( preg_match( '#<cite[^>]*>(.*?)</cite>#is', (string) $block['innerHTML'], $m ) ) {
				$parts  = array_map( 'trim', explode( ',', trim( wp_strip_all_tags( $m[1] ) ), 2 ) );
				$author = $parts[0];
				$work   = $parts[1] ?? '';
			}
			$text = '';
			foreach ( $block['innerBlocks'] as $inner ) {
				$text .= ' ' . wp_strip_all_tags( (string) $inner['innerHTML'] );
			}
			$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
			return [ $text ? "\u{201C}" . $text . "\u{201D}" : '', $author, $work ];
		}
		return [ '', '', '' ];
	}
}
