<?php
/**
 * Fuzzy name matching for duplicate player detection.
 *
 * Implements 14 matching scenarios with tiered confidence scoring.
 *
 * @package SportsPress_Player_Merge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SP_Merge_Name_Matcher {

	/**
	 * Nickname/diminutive equivalence groups.
	 * Includes transliteration variants and French/English bilingual pairs (#2, #12).
	 *
	 * @var array<string, string[]>
	 */
	private static array $nickname_groups = array(
		// English diminutives.
		'richard'   => array( 'rich', 'rick', 'ricky', 'dick', 'dickie' ),
		'robert'    => array( 'rob', 'robbie', 'bob', 'bobby', 'bert' ),
		'william'   => array( 'will', 'willy', 'bill', 'billy', 'liam' ),
		'thomas'    => array( 'tom', 'tommy' ),
		'james'     => array( 'jim', 'jimmy', 'jamie' ),
		'john'      => array( 'jon', 'johnny', 'jack' ),
		'joseph'    => array( 'joe', 'joey' ),
		'michael'   => array( 'mike', 'mikey', 'mick', 'mickey' ),
		'david'     => array( 'dave', 'davey' ),
		'daniel'    => array( 'dan', 'danny' ),
		'matthew'   => array( 'matt', 'matty' ),
		'anthony'   => array( 'tony', 'ant' ),
		'edward'    => array( 'ed', 'eddie', 'ted', 'teddy', 'ned' ),
		'charles'   => array( 'charlie', 'chuck', 'chas' ),
		'christopher' => array( 'chris', 'kit' ),
		'nicholas'  => array( 'nick', 'nicky' ),
		'patrick'   => array( 'pat', 'paddy' ),
		'timothy'   => array( 'tim', 'timmy' ),
		'stephen'   => array( 'steve', 'stevie', 'steven' ),
		'benjamin'  => array( 'ben', 'benny' ),
		'alexander' => array( 'alex', 'al', 'xander' ),
		'andrew'    => array( 'andy', 'drew' ),
		'jonathan'  => array( 'jon', 'jonny', 'nathan' ),
		'samuel'    => array( 'sam', 'sammy' ),
		'joshua'    => array( 'josh' ),
		'nathaniel' => array( 'nate', 'nathan', 'nat' ),
		'zachary'   => array( 'zach', 'zack' ),
		'gregory'   => array( 'greg' ),
		'raymond'   => array( 'ray' ),
		'lawrence'  => array( 'larry', 'laurie' ),
		'kenneth'   => array( 'ken', 'kenny' ),
		'ronald'    => array( 'ron', 'ronnie' ),
		'donald'    => array( 'don', 'donnie' ),
		'gerald'    => array( 'gerry', 'jerry' ),
		'douglas'   => array( 'doug' ),
		'phillip'   => array( 'phil' ),
		'frederick' => array( 'fred', 'freddy', 'freddie' ),
		'elizabeth' => array( 'liz', 'lizzy', 'beth', 'betty', 'eliza' ),
		'catherine' => array( 'cathy', 'kate', 'katie', 'cat', 'katherine', 'kathryn' ),
		'jennifer'  => array( 'jen', 'jenny' ),
		'margaret'  => array( 'maggie', 'meg', 'peggy', 'marge' ),
		'patricia'  => array( 'pat', 'patty', 'trish' ),
		'deborah'   => array( 'deb', 'debbie' ),
		'stephanie' => array( 'steph' ),
		'victoria'  => array( 'vicky', 'tori' ),
		'alexandra' => array( 'alex', 'lexi' ),
		'rebecca'   => array( 'becca', 'becky' ),
		'jessica'   => array( 'jess', 'jessie' ),
		'christina' => array( 'chris', 'tina', 'christine' ),
		'stacy'     => array( 'stacey', 'anastasia' ),

		// Transliteration variants.
		'sergei'    => array( 'sergey', 'serge' ),
		'muhammad'  => array( 'mohammed', 'mohamed', 'mohammad' ),
		'vladimir'  => array( 'vlad' ),
		'dmitri'    => array( 'dmitry', 'dimitri' ),
		'yuri'      => array( 'yury' ),
		'nikolai'   => array( 'nikolay', 'nickolai' ),
		'aleksei'   => array( 'alexei', 'alexey', 'aleksey' ),
		'evgeni'    => array( 'evgeny', 'yevgeni', 'yevgeny' ),

		// French/English bilingual equivalents (#12).
		'marc'      => array( 'mark', 'marcus' ),
		'denis'     => array( 'dennis' ),
		'michel'    => array( 'michael', 'mike' ),
		'pierre'    => array( 'peter', 'pete' ),
		'jacques'   => array( 'jack', 'james' ),
		'jean'      => array( 'john' ),
		'francois'  => array( 'francis', 'frank' ),
		'guillaume' => array( 'william', 'will', 'bill' ),
		'louis'     => array( 'lewis' ),
		'henri'     => array( 'henry' ),
		'andre'     => array( 'andrew', 'andy' ),
		'philippe'  => array( 'phillip', 'phil' ),
		'rene'      => array( 'renee' ),
		'claude'    => array( 'claud' ),
		'luc'       => array( 'luke', 'lucas' ),
		'etienne'   => array( 'stephen', 'steve', 'steven' ),
		'benoit'    => array( 'benedict', 'ben' ),
		'mathieu'   => array( 'matthew', 'matt' ),
		'sebastien' => array( 'sebastian' ),
		'nicolas'   => array( 'nicholas', 'nick' ),
		'olivier'   => array( 'oliver' ),
		'maxime'    => array( 'max', 'maximilian' ),
		'antoine'   => array( 'anthony', 'tony' ),
		'gabriel'   => array( 'gabe' ),
		'raphael'   => array( 'ralph' ),
		'dominique' => array( 'dominic', 'dom' ),
		'yves'      => array( 'ives' ),
		'alain'     => array( 'alan', 'allan', 'allen' ),
		'gaetan'    => array( 'guy' ),
		'rejean'    => array( 'ray' ),
		'normand'   => array( 'norman', 'norm' ),
	);

	/**
	 * French compound first names (#14).
	 *
	 * @var string[]
	 */
	private static array $french_compounds = array(
		'jean-pierre', 'jean-paul', 'jean-marc', 'jean-francois', 'jean-louis',
		'jean-claude', 'jean-michel', 'jean-philippe', 'jean-sebastien', 'jean-christophe',
		'jean-luc', 'jean-guy', 'jean-yves', 'jean-rene', 'jean-daniel',
		'jean-simon', 'jean-benoit', 'jean-nicolas', 'jean-gabriel', 'jean-denis',
		'pierre-luc', 'pierre-olivier', 'pierre-alexandre', 'pierre-marc',
		'marc-andre', 'marc-antoine', 'marc-olivier',
		'louis-philippe', 'louis-charles',
		'marie-eve', 'marie-claude', 'marie-pier', 'marie-helene', 'marie-josee',
		'marie-france', 'marie-andree', 'marie-christine', 'marie-pierre',
		'anne-marie', 'anne-sophie',
	);

	/**
	 * Suffix patterns to strip (#7).
	 *
	 * @var string
	 */
	private static string $suffix_pattern = '/\s+(\(?jr\)?|\(?sr\)?|ii|iii|iv|2nd|3rd)\.?$/i';

	/**
	 * Reverse lookup: nickname → canonical key. Built once.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $nickname_lookup = null;

	/**
	 * Build the reverse nickname lookup table.
	 */
	private static function build_nickname_lookup(): void {
		if ( null !== self::$nickname_lookup ) {
			return;
		}
		self::$nickname_lookup = array();
		foreach ( self::$nickname_groups as $canonical => $variants ) {
			self::$nickname_lookup[ $canonical ] = $canonical;
			foreach ( $variants as $v ) {
				// First mapping wins (handles overlaps like 'jack' → john vs jacques).
				if ( ! isset( self::$nickname_lookup[ $v ] ) ) {
					self::$nickname_lookup[ $v ] = $canonical;
				}
			}
		}
	}

	/**
	 * Preprocess a raw name: trim, collapse whitespace, normalize unicode.
	 *
	 * @param string $name Raw name.
	 * @return string Cleaned name.
	 */
	public static function preprocess( string $name ): string {
		// Normalize unicode to NFC.
		if ( function_exists( 'normalizer_normalize' ) ) {
			$name = normalizer_normalize( $name, Normalizer::FORM_C ) ?: $name;
		}
		// Normalize various apostrophe/quote chars to ASCII apostrophe.
		$name = str_replace( array( "\u{2019}", "\u{2018}", "\u{02BC}", "\u{0060}" ), "'", $name );
		// Strip SportsPress position abbreviations like (G), (C), (D), (F), (LW), (RW), (dup).
		$name = preg_replace( '/\s*\([A-Za-z\/]+\)\s*$/', '', $name );
		// Collapse whitespace.
		$name = preg_replace( '/\s+/', ' ', trim( $name ) );
		return $name;
	}

	/**
	 * Remove accents (#6).
	 *
	 * @param string $str Input string.
	 * @return string ASCII-folded string.
	 */
	private static function strip_accents( string $str ): string {
		return remove_accents( $str );
	}

	/**
	 * Normalize surname prefixes/particles (#4).
	 * O'Connor→oconnor, MacBeth→mcbeth, van der Berg→vanderberg, de la Cruz→delacruz.
	 *
	 * @param string $surname Lowercase surname.
	 * @return string Normalized surname.
	 */
	private static function normalize_surname( string $surname ): string {
		// Strip apostrophes: O'Connor → oconnor.
		$s = str_replace( "'", '', $surname );
		// Mac → mc.
		if ( str_starts_with( $s, 'mac' ) && strlen( $s ) > 4 ) {
			$s = 'mc' . substr( $s, 3 );
		}
		// Remove particles.
		$s = preg_replace( '/^(van|von|de|du|le|la|del|della|di|el|al)\s+/', '', $s );
		$s = preg_replace( '/^(van|von|de|du|le|la|del|della|di|el|al)(der|den|het|la|les)\s*/', '', $s );
		// Remove remaining spaces (van der Berg → vanderberg already handled above, catch stragglers).
		$s = str_replace( ' ', '', $s );
		return $s;
	}

	/**
	 * Parse a full name into first and last parts.
	 *
	 * @param string $full_name Preprocessed full name.
	 * @return array{first: string, last: string, middle: string, original: string}
	 */
	public static function parse_name( string $full_name ): array {
		$name = self::preprocess( $full_name );
		$lower = strtolower( $name );

		// Strip suffixes (#7).
		$lower = preg_replace( self::$suffix_pattern, '', $lower );
		// Also strip parenthesized suffixes mid-name: "Peter (Jr) Austin" → "Peter Austin".
		$lower = preg_replace( '/\s*\((?:jr|sr|ii|iii|iv)\)\s*/i', ' ', $lower );
		$lower = trim( $lower );

		// Handle "Last, First" format (#8).
		if ( str_contains( $lower, ',' ) ) {
			$parts = explode( ',', $lower, 2 );
			$lower = trim( $parts[1] ) . ' ' . trim( $parts[0] );
		}

		// Strip accents (#6).
		$lower = strtolower( self::strip_accents( $lower ) );

		// Split into parts.
		$parts = explode( ' ', $lower );

		if ( count( $parts ) === 1 ) {
			return array(
				'first'    => $parts[0],
				'last'     => '',
				'middle'   => '',
				'original' => $full_name,
			);
		}

		$first  = $parts[0];
		$last   = end( $parts );
		$middle = count( $parts ) > 2 ? implode( ' ', array_slice( $parts, 1, -1 ) ) : '';

		return array(
			'first'    => $first,
			'last'     => $last,
			'middle'   => $middle,
			'original' => $full_name,
		);
	}

	/**
	 * Get the canonical nickname key for a first name.
	 *
	 * @param string $first Lowercase first name (accent-stripped).
	 * @return string Canonical key or original if no match.
	 */
	private static function get_nickname_canonical( string $first ): string {
		self::build_nickname_lookup();
		return self::$nickname_lookup[ $first ] ?? $first;
	}

	/**
	 * Check if two first names are nickname-equivalent (#2, #12).
	 *
	 * @param string $a First name A (lowercase, no accents).
	 * @param string $b First name B (lowercase, no accents).
	 * @return bool
	 */
	private static function is_nickname_match( string $a, string $b ): bool {
		if ( $a === $b ) {
			return true;
		}
		return self::get_nickname_canonical( $a ) === self::get_nickname_canonical( $b );
	}

	/**
	 * Check if a name is a French compound first name (#14).
	 *
	 * @param string $name Lowercase name (may contain hyphen or space).
	 * @return bool
	 */
	private static function is_french_compound( string $name ): bool {
		$normalized = str_replace( ' ', '-', $name );
		return in_array( $normalized, self::$french_compounds, true );
	}

	/**
	 * Get all first-name variants for matching (handles French compounds #14).
	 * "jean-pierre" → ["jean-pierre", "jeanpierre", "jean pierre", "jean"]
	 *
	 * @param string $first Lowercase first name.
	 * @return string[] Possible normalized forms.
	 */
	private static function get_first_name_variants( string $first ): array {
		$variants = array( $first );
		// Hyphenated → space and joined.
		if ( str_contains( $first, '-' ) ) {
			$variants[] = str_replace( '-', ' ', $first );
			$variants[] = str_replace( '-', '', $first );
			// First part only (Jean-Pierre → Jean).
			$variants[] = explode( '-', $first )[0];
		}
		if ( str_contains( $first, ' ' ) ) {
			$variants[] = str_replace( ' ', '-', $first );
			$variants[] = str_replace( ' ', '', $first );
			$variants[] = explode( ' ', $first )[0];
		}
		return array_unique( $variants );
	}

	/**
	 * Compare two parsed names and return a match result.
	 *
	 * @param array $a Parsed name A.
	 * @param array $b Parsed name B.
	 * @return array{match: bool, certainty: int, scenario: string}
	 */
	public static function compare( array $a, array $b ): array {
		$no_match = array( 'match' => false, 'certainty' => 0, 'scenario' => '' );

		$first_a = $a['first'];
		$first_b = $b['first'];
		$last_a  = $a['last'];
		$last_b  = $b['last'];

		// If either has no last name, can't reliably match.
		if ( '' === $last_a || '' === $last_b ) {
			// Single-word names: exact match only.
			if ( $first_a === $first_b && '' === $last_a && '' === $last_b ) {
				return array( 'match' => true, 'certainty' => 100, 'scenario' => 'exact' );
			}
			return $no_match;
		}

		// Normalize surnames for comparison.
		$norm_last_a = self::normalize_surname( $last_a );
		$norm_last_b = self::normalize_surname( $last_b );

		// --- SCENARIO 1: Exact match ---
		if ( $first_a === $first_b && $last_a === $last_b ) {
			return array( 'match' => true, 'certainty' => 100, 'scenario' => 'exact' );
		}

		// --- SCENARIO 6+4: Accent/prefix normalization produces exact match ---
		if ( $first_a === $first_b && $norm_last_a === $norm_last_b && $last_a !== $last_b ) {
			return array( 'match' => true, 'certainty' => 95, 'scenario' => 'normalization' );
		}

		// --- Check last name similarity for remaining scenarios ---
		$last_names_match = ( $norm_last_a === $norm_last_b );
		$last_name_typo   = false;
		if ( ! $last_names_match ) {
			$max_len = max( strlen( $norm_last_a ), strlen( $norm_last_b ) );
			$max_lev = $max_len >= 6 ? 2 : 1;
			$lev     = levenshtein( $norm_last_a, $norm_last_b );
			if ( $lev <= $max_lev && $lev > 0 && $max_len >= 4 ) {
				$last_name_typo = true;
			}
		}

		// --- SCENARIO 13: Compound last name partial entry ---
		$compound_last_match = false;
		if ( ! $last_names_match && ! $last_name_typo ) {
			// Check if one last name is a component of the other (hyphenated).
			$parts_a = preg_split( '/[-\s]/', $norm_last_a );
			$parts_b = preg_split( '/[-\s]/', $norm_last_b );
			if ( count( $parts_a ) > 1 && in_array( $norm_last_b, $parts_a, true ) ) {
				$compound_last_match = true;
			} elseif ( count( $parts_b ) > 1 && in_array( $norm_last_a, $parts_b, true ) ) {
				$compound_last_match = true;
			}
		}

		// If last names don't match in any way, check name reversal (#8).
		if ( ! $last_names_match && ! $last_name_typo && ! $compound_last_match ) {
			// Scenario 8: first/last reversal (without comma — already handled in parse).
			if ( $first_a === $last_b && $last_a === $first_b ) {
				return array( 'match' => true, 'certainty' => 50, 'scenario' => 'reversal' );
			}
			$norm_first_a_as_last = self::normalize_surname( $first_a );
			$norm_first_b_as_last = self::normalize_surname( $first_b );
			if ( $norm_first_a_as_last === $norm_last_b && $norm_last_a === $norm_first_b_as_last ) {
				return array( 'match' => true, 'certainty' => 50, 'scenario' => 'reversal' );
			}
			return $no_match;
		}

		// From here, last names are similar enough. Check first names.

		// --- SCENARIO 2/12: Nickname/bilingual match ---
		$first_variants_a = self::get_first_name_variants( $first_a );
		$first_variants_b = self::get_first_name_variants( $first_b );
		foreach ( $first_variants_a as $va ) {
			foreach ( $first_variants_b as $vb ) {
				if ( self::is_nickname_match( $va, $vb ) ) {
					if ( $last_names_match ) {
						// Direct first name equivalence.
						if ( $va === $vb ) {
							// Scenario 14: French compound variant matched.
							return array( 'match' => true, 'certainty' => 90, 'scenario' => 'french_compound' );
						}
						return array( 'match' => true, 'certainty' => 70, 'scenario' => 'nickname' );
					}
					if ( $last_name_typo ) {
						return array( 'match' => true, 'certainty' => 60, 'scenario' => 'nickname+typo' );
					}
					if ( $compound_last_match ) {
						return array( 'match' => true, 'certainty' => 60, 'scenario' => 'nickname+compound' );
					}
				}
			}
		}

		// --- SCENARIO 14: French compound first name partial ---
		if ( $last_names_match || $compound_last_match ) {
			foreach ( $first_variants_a as $va ) {
				if ( $va === $first_b || in_array( $first_b, self::get_first_name_variants( $va ), true ) ) {
					$cert = $last_names_match ? 85 : 55;
					return array( 'match' => true, 'certainty' => $cert, 'scenario' => 'french_compound' );
				}
			}
			foreach ( $first_variants_b as $vb ) {
				if ( $vb === $first_a || in_array( $first_a, self::get_first_name_variants( $vb ), true ) ) {
					$cert = $last_names_match ? 85 : 55;
					return array( 'match' => true, 'certainty' => $cert, 'scenario' => 'french_compound' );
				}
			}
		}

		// --- SCENARIO 5/11: Typo in first name ---
		if ( $last_names_match || $compound_last_match ) {
			$max_first_len = max( strlen( $first_a ), strlen( $first_b ) );
			$max_first_lev = $max_first_len >= 6 ? 2 : 1;
			$lev_first     = levenshtein( $first_a, $first_b );
			if ( $lev_first <= $max_first_lev && $lev_first > 0 && $max_first_len >= 4 ) {
				$cert = $last_names_match ? 65 : 50;
				return array( 'match' => true, 'certainty' => $cert, 'scenario' => 'typo' );
			}
		}

		// --- SCENARIO 5: Typo in last name with exact first ---
		if ( $last_name_typo && $first_a === $first_b ) {
			return array( 'match' => true, 'certainty' => 65, 'scenario' => 'typo' );
		}

		// --- SCENARIO 10: Initial match (J. Smith ↔ John Smith) ---
		if ( $last_names_match || $last_name_typo || $compound_last_match ) {
			$init_a = ( strlen( $first_a ) <= 2 && str_ends_with( $first_a, '.' ) ) ? rtrim( $first_a, '.' ) : ( strlen( $first_a ) === 1 ? $first_a : '' );
			$init_b = ( strlen( $first_b ) <= 2 && str_ends_with( $first_b, '.' ) ) ? rtrim( $first_b, '.' ) : ( strlen( $first_b ) === 1 ? $first_b : '' );

			if ( '' !== $init_a && str_starts_with( $first_b, $init_a ) ) {
				return array( 'match' => true, 'certainty' => 50, 'scenario' => 'initial' );
			}
			if ( '' !== $init_b && str_starts_with( $first_a, $init_b ) ) {
				return array( 'match' => true, 'certainty' => 50, 'scenario' => 'initial' );
			}
		}

		// --- SCENARIO 9: Middle name inclusion/exclusion ---
		if ( $last_names_match ) {
			// "John Michael Smith" vs "John Smith" — first names match, one has middle.
			if ( $first_a === $first_b && ( $a['middle'] !== '' || $b['middle'] !== '' ) ) {
				return array( 'match' => true, 'certainty' => 60, 'scenario' => 'middle_name' );
			}
			// First name of one matches "first middle" of other.
			if ( '' !== $a['middle'] && ( $a['first'] . ' ' . $a['middle'] === $first_b || $a['first'] . $a['middle'] === $first_b ) ) {
				return array( 'match' => true, 'certainty' => 55, 'scenario' => 'middle_name' );
			}
			if ( '' !== $b['middle'] && ( $b['first'] . ' ' . $b['middle'] === $first_a || $b['first'] . $b['middle'] === $first_a ) ) {
				return array( 'match' => true, 'certainty' => 55, 'scenario' => 'middle_name' );
			}
		}

		// --- SCENARIO 13: Compound last name with exact first ---
		if ( $compound_last_match && $first_a === $first_b ) {
			return array( 'match' => true, 'certainty' => 60, 'scenario' => 'compound_last' );
		}

		return $no_match;
	}

	/**
	 * Find all duplicate groups from a list of players.
	 *
	 * @param array $players Array of WP_Post objects (sp_player).
	 * @return array Array of groups: [ [ 'players' => [...], 'certainty' => int, 'scenario' => string ], ... ]
	 */
	public static function find_groups( array $players ): array {
		$parsed = array();
		foreach ( $players as $player ) {
			$parsed[] = array(
				'post'   => $player,
				'parsed' => self::parse_name( $player->post_title ),
			);
		}

		$groups  = array();
		$matched = array(); // Track player IDs already grouped.

		$count = count( $parsed );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( isset( $matched[ $parsed[ $i ]['post']->ID ] ) ) {
				continue;
			}

			$group     = array( $parsed[ $i ] );
			$best_cert = 0;
			$best_scen = '';

			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( isset( $matched[ $parsed[ $j ]['post']->ID ] ) ) {
					continue;
				}

				$result = self::compare( $parsed[ $i ]['parsed'], $parsed[ $j ]['parsed'] );
				if ( $result['match'] ) {
					$group[] = $parsed[ $j ];
					$matched[ $parsed[ $j ]['post']->ID ] = true;
					if ( $result['certainty'] > $best_cert ) {
						$best_cert = $result['certainty'];
						$best_scen = $result['scenario'];
					}
				}
			}

			if ( count( $group ) >= 2 ) {
				$matched[ $parsed[ $i ]['post']->ID ] = true;
				$groups[] = array(
					'players'   => array_map( fn( $g ) => $g['post'], $group ),
					'certainty' => $best_cert,
					'scenario'  => $best_scen,
				);
			}
		}

		// Sort by certainty descending.
		usort( $groups, fn( $a, $b ) => $b['certainty'] - $a['certainty'] );

		return $groups;
	}
}
