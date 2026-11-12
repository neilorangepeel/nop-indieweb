<?php
/**
 * A #hashtag that names a subject files the post under that subject's tag (the theme's
 * STRUCTURE.md: tags are Subjects, a closed list).
 *
 * Inbound only: whatever made the post (the editor, /post, Micropub, an importer),
 * once it is saved its words are read and #UTB adds the tag Boro. Any other hashtag
 * stays a word in the post. Tags are only ever added, never removed, and are still
 * never appended as hashtags when syndicating.
 */
declare( strict_types=1 );

namespace NOP\IndieWeb\Kind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Hashtags {

	/**
	 * Each subject (its tag's name) and the names that file a post under it: tags merged into it and the
	 * hashtags that count. Matched without case, spaces or punctuation (#WebDesign is "web design").
	 * A new subject is a decision: add it to STRUCTURE.md in the theme too. Filter: nop_indieweb_subjects.
	 */
	public const SUBJECTS = [
		'Boro'        => [ 'UTB', 'BORO', 'Middlesbrough', 'Teestime', 'borolive', 'mfc', 'UTFB', 'RedArmy', 'YouAreMyBoro', 'borotweetup', 'Middesbrough', 'MiddlesbroughFC', 'LoveMiddlesbrough' ],
		'Design'      => [ 'Design', 'Logo', 'colour', 'Colours', 'poster', 'icon', 'Icons', 'grid', 'minimal', 'geometric', 'circles', 'pixel', '8bit', 'rainbow', 'WIP', 'portfolio', 'Art', 'circle', 'Designing', 'DesignThinking', 'DesignPackaging' ],
		'Ponydance'   => [ 'Ponydance', 'PonyPanto', 'poniesontour', 'pony', 'legopony', 'ponybois', 'poniesdontplayfootball', 'DanceMarathon', 'poinesontour', 'ponieontour', 'pondance', 'ponydanceco', 'ponydanceoff', 'ponydancethemovie', 'ponydanceworlddomination' ],
		'Web'         => [ 'WordPress', 'CSS', 'web', 'web design', 'webdesign', 'html', 'responsive', 'UX', 'a11y', 'accessibility', 'Open Source', 'Themes', 'block', 'website', 'Firefox', 'LazyWeb', 'WebDesignTrends2013', 'Websites' ],
		'Games'       => [ 'Nintendo', 'NintendoSwitch', '3DS', 'WiiU', 'ACNL', 'ACNH', 'AnimalCrossing', 'AnimalCrossingNewHorizons', 'BreathoftheWild', 'zelda', 'Pokemon', 'PokemonLetsGo', 'pokemongo', 'SmashBros', 'MarioKart', 'SuperMarioOdyssey', 'Miitomo', 'E3', 'skyrim', 'LANoire', 'Fable3', 'Fable3Kingmaker', 'Xbox', 'video game', 'AnimalCros', 'AnimalCrossingDirect', 'AnimalCrossingNewHorizions', 'Fable', 'Fable3IGNUK', 'Mario', 'mariokartisnotreallife', 'MiitomoStyle', 'Nintendo3DSDay', 'NintendoDirect', 'NintendoUKStore', 'PokemonSwordShield', 'SmashBrosUltimate', 'videogamesarenotreallife' ],
		'Performance' => [ 'Dance', 'theatre', 'Comedy', 'ComedyKitchen', 'circus', 'tanzio', 'blindful', 'ADLFringe', 'EdinburghFringe', 'Fringe', 'afringe', 'adfinge', 'adfringe', 'EdFringe', 'fringeclub', 'tazio' ],
		'Typography'  => [ 'type', 'Typography', 'futura', 'brandon text', 'brandon grotesque', 'Freefont', 'futura rounded', 'typeography', 'typography art' ],
		'Cycling'     => [ 'Cycling', 'RideOnBelfast', 'TDF', 'LeTour', 'letour2016', 'letour2017', 'bikes', 'fixed', 'seesense', 'BucketSaddle', 'ChargeBike', 'Bike', 'cyclinginhartlepool', 'CyclingRevolution', 'CyclingTips', 'SeeSenseICON2' ],
		'Sport'       => [ 'Football', 'Spurs', 'World Cup 2026', 'EURO2016', 'ThreeLions', 'Wimbledon', 'Wimbledon2014', 'Wimbeldon2022', 'Murray', 'olymics2012', 'Rio2016', 'TeamGB', 'Euro2012', 'Euro2016Final', 'WimbledonFinal', 'WorldCup' ],
		'WordCamp'    => [ 'WCBelfast', 'WordCamp', 'WCEU', 'WP15' ],
		'PEELED'      => [ 'PEELED' ],
		'Photography' => [ 'Photography', 'Film', 'bw', 'sunset', 'foodpic', 'Urban', 'Canon550D', 'NikonEM', 'photogrpahy', 'PhotographyForBeginners' ],
		'Lego'        => [ 'Lego' ],
		'Ding'        => [ 'Ding The Dachshund' ],
		'Cats'        => [ 'cats', 'cat', 'catsofinstagram' ],
		'Music'       => [ 'Music', 'thestrokes' ],
		'TV & film'   => [ 'Ghostbusters', 'Hollyoaks', 'peepshow', 'Apprentice', 'ItStartedWithJasonDonovan', 'Animation' ],
		'Charity'     => [ 'Movember', 'TyphoonAid', 'BacktheMAC', 'coke4lent', 'charitytuesday' ],
	];

	public function register(): void {
		add_action( 'wp_after_insert_post', [ $this, 'tag_post' ], 10, 2 );
	}

	public function tag_post( int $post_id, \WP_Post $post ): void {
		if ( 'post' !== $post->post_type || 'auto-draft' === $post->post_status || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$subjects = self::subjects_in( $post->post_content );
		if ( $subjects ) {
			wp_set_post_tags( $post_id, $subjects, true );
		}
	}

	/**
	 * The subjects a post's hashtags name, once each, in the order first named.
	 *
	 * @param array<string,string[]>|null $subjects Defaults to SUBJECTS, filtered.
	 * @return string[]
	 */
	public static function subjects_in( string $content, ?array $subjects = null ): array {
		$index = [];
		foreach ( $subjects ?? (array) apply_filters( 'nop_indieweb_subjects', self::SUBJECTS ) as $subject => $names ) {
			foreach ( [ $subject, ...$names ] as $name ) {
				$index[ self::key( (string) $name ) ] = (string) $subject;
			}
		}
		$found = [];
		foreach ( self::extract( $content ) as $tag ) {
			$subject = $index[ self::key( $tag ) ] ?? null;
			if ( null !== $subject ) {
				$found[ $subject ] = true;
			}
		}
		return array_keys( $found );
	}

	/** A name as it is matched: lower case, letters and digits only. */
	public static function key( string $name ): string {
		return (string) preg_replace( '/[^\p{L}\p{N}]/u', '', mb_strtolower( $name ) );
	}

	/**
	 * The hashtags in a post's words, once each (case-insensitively), without the #.
	 * A hashtag starts a word and begins with a letter: #1, a colour code in a style attribute and site.com/#anchor are not tags.
	 *
	 * @return string[]
	 */
	public static function extract( string $content ): array {
		$text = html_entity_decode( strip_tags( str_replace( '<', ' <', $content ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( ! preg_match_all( '/(?:^|[\s(])[#＃](\p{L}[\p{L}\p{N}_]*)/u', $text, $matches ) ) {
			return [];
		}
		$tags = [];
		foreach ( $matches[1] as $tag ) {
			$tags[ mb_strtolower( $tag ) ] ??= $tag;
		}
		return array_values( $tags );
	}
}
