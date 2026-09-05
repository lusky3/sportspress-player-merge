<?php
/**
 * Duplicate-group formation: real-roster suffix stripping, all-pairs grouping
 * and minimum-certainty reporting.
 *
 * Covers audit blocker 7 (a group's badge showed the *highest* pairwise score
 * and members were only ever compared against the anchor) and the suffix regex
 * that left `Peter Kondo (Dup / Div 3)` parsing with surname `3)`.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Mocks must use the real WordPress function names.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Mocks mirror documented WordPress signatures.
// phpcs:disable WordPress.Files.FileName -- Harness file, not a class file.
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- Single-file harness by design.

require_once __DIR__ . '/bootstrap.php';

if ( ! function_exists( 'remove_accents' ) ) {
	function remove_accents( $text ) {
		return strtr(
			$text,
			array(
				'á' => 'a',
				'à' => 'a',
				'â' => 'a',
				'ä' => 'a',
				'é' => 'e',
				'è' => 'e',
				'ê' => 'e',
				'ë' => 'e',
				'í' => 'i',
				'î' => 'i',
				'ó' => 'o',
				'ô' => 'o',
				'ö' => 'o',
				'ú' => 'u',
				'ù' => 'u',
				'û' => 'u',
				'ç' => 'c',
				'ñ' => 'n',
			)
		);
	}
}

require_once dirname( __DIR__ ) . '/classes/class-sp-merge-name-matcher.php';

/**
 * Build a stand-in for the WP_Post objects find_groups() is handed.
 *
 * @param int    $id    Post ID.
 * @param string $title Post title.
 * @return object
 */
function spm_player( int $id, string $title ): object {
	return (object) array(
		'ID'         => $id,
		'post_title' => $title,
	);
}

spm_test_header( 'name matcher: suffix stripping, all-pairs grouping, minimum certainty' );

//
// 1. Every suffix form on the live roster normalises away.
//

$suffix_cases = array(
	'Peter Kondo (C)'                           => 'Peter Kondo',
	'Peter Kondo (G)'                           => 'Peter Kondo',
	'Peter Kondo (A)'                           => 'Peter Kondo',
	'Peter Kondo (dup)'                         => 'Peter Kondo',
	'Peter Kondo (Dup)'                         => 'Peter Kondo',
	'Peter Kondo (DUP)'                         => 'Peter Kondo',
	'Peter Kondo (Div4)'                        => 'Peter Kondo',
	'Peter Kondo (Div2)'                        => 'Peter Kondo',
	'Peter Kondo (goalie)'                      => 'Peter Kondo',
	'Peter Kondo (Skater)'                      => 'Peter Kondo',
	'Peter Kondo (Dup / Div 3)'                 => 'Peter Kondo',
	'Kelly Ward (dup /w Stephani King-Rankin)'  => 'Kelly Ward',
	'Robert Rabey (G) (DUP)'                    => 'Robert Rabey',
);

foreach ( $suffix_cases as $raw => $expected ) {
	spm_assert_equals( $expected, SP_Merge_Name_Matcher::preprocess( $raw ), "preprocess strips: {$raw}" );
}

// A name that is entirely parenthesised must survive — stripping it would leave nothing.
spm_assert_equals( '(John Smith)', SP_Merge_Name_Matcher::preprocess( '(John Smith)' ), 'a fully parenthesised name is left alone' );

// The unstripped form used to parse as first=peter last="3)".
$kondo = SP_Merge_Name_Matcher::parse_name( 'Peter Kondo (Dup / Div 3)' );
spm_assert_equals( 'peter', $kondo['first'], 'parse_name first name survives a spaced suffix' );
spm_assert_equals( 'kondo', $kondo['last'], 'parse_name surname is kondo, not "3)"' );

//
// 2. The three-way Kondo set forms ONE group of three.
//

$kondo_groups = SP_Merge_Name_Matcher::find_groups(
	array(
		spm_player( 101, 'Peter Kondo (C)' ),
		spm_player( 102, 'Peter Kondo (Dup / Div 3)' ),
		spm_player( 103, 'Peter Kondo' ),
	)
);

spm_assert_equals( 1, count( $kondo_groups ), 'the Kondo records form a single group' );
spm_assert_equals( 3, count( $kondo_groups[0]['players'] ), 'that group has all three Kondo records, not two' );
spm_assert_equals( 100, $kondo_groups[0]['certainty'], 'three identical names are still 100' );
spm_assert_equals(
	array( 101, 102, 103 ),
	array_map( fn( $p ) => $p->ID, $kondo_groups[0]['players'] ),
	'every Kondo ID is in the group'
);

//
// 3. The group reports the MINIMUM pairwise score, and each member its own.
//
// The audit's verified case: [John Smith | John Smith | J. Smith] rendered as
// 100% High because the badge showed the best pair. J. Smith ↔ John Smith is 50.
//

$weak_groups = SP_Merge_Name_Matcher::find_groups(
	array(
		spm_player( 201, 'John Smith' ),
		spm_player( 202, 'John Smith' ),
		spm_player( 203, 'J. Smith' ),
	)
);

spm_assert_equals( 1, count( $weak_groups ), 'the Smith records form a single group' );
spm_assert_equals( 3, count( $weak_groups[0]['players'] ), 'all three Smith records are grouped' );
spm_assert_equals( 50, $weak_groups[0]['certainty'], 'the group reports the weakest pair (50), not the strongest (100)' );

foreach ( $weak_groups[0]['players'] as $player ) {
	spm_assert( isset( $player->certainty ), "member {$player->ID} carries its own certainty" );
}

/*
 * Each member's certainty must be that member's own weakest pairwise score,
 * recomputed here straight from the public compare() API.
 */
$expected_member_cert = array();
$titles               = array();
foreach ( $weak_groups[0]['players'] as $player ) {
	$titles[ $player->ID ] = $player->post_title;
}
foreach ( $titles as $member_id => $member_title ) {
	$worst = 100;
	foreach ( $titles as $other_id => $other_title ) {
		if ( $member_id === $other_id ) {
			continue;
		}
		$result = SP_Merge_Name_Matcher::compare(
			SP_Merge_Name_Matcher::parse_name( $member_title ),
			SP_Merge_Name_Matcher::parse_name( $other_title )
		);
		$worst  = min( $worst, (int) $result['certainty'] );
	}
	$expected_member_cert[ $member_id ] = $worst;
}

foreach ( $weak_groups[0]['players'] as $player ) {
	spm_assert_equals(
		$expected_member_cert[ $player->ID ],
		$player->certainty,
		"member {$player->ID} certainty is its own weakest pairwise score"
	);
}

spm_assert_equals( $expected_member_cert, $weak_groups[0]['certainties'], 'the per-ID certainty map matches' );
spm_assert_equals( $expected_member_cert, $weak_groups[0]['member_certainty'], 'the per-ID certainty alias matches' );
spm_assert_equals(
	min( $expected_member_cert ),
	$weak_groups[0]['certainty'],
	'the group certainty is the minimum across members'
);

// Existing keys must still be there for the rest of the plugin.
spm_assert( isset( $weak_groups[0]['scenario'] ), 'the group still carries a scenario' );
spm_assert( isset( $weak_groups[0]['players'] ), 'the group still carries players' );

//
// 4. All-pairs: matching the anchor is not enough to join a group.
//
// "J. Smith" matches both "John Smith" (initial) and "Jane Smith" (initial),
// but "John Smith" and "Jane Smith" do not match each other. Under anchor-only
// grouping all three landed in one group and one click staged Jane for deletion.
//

$john_vs_jane = SP_Merge_Name_Matcher::compare(
	SP_Merge_Name_Matcher::parse_name( 'John Smith' ),
	SP_Merge_Name_Matcher::parse_name( 'Jane Smith' )
);
spm_assert( ! $john_vs_jane['match'], 'John Smith and Jane Smith are not a pair (premise)' );

$all_pairs_groups = SP_Merge_Name_Matcher::find_groups(
	array(
		spm_player( 301, 'J. Smith' ),
		spm_player( 302, 'John Smith' ),
		spm_player( 303, 'Jane Smith' ),
	)
);

spm_assert_equals( 1, count( $all_pairs_groups ), 'only one group forms' );
spm_assert_equals( 2, count( $all_pairs_groups[0]['players'] ), 'the group has two members, not three' );

$grouped_ids = array_map( fn( $p ) => $p->ID, $all_pairs_groups[0]['players'] );
spm_assert_equals( array( 301, 302 ), $grouped_ids, 'Jane Smith did not join on the strength of the anchor alone' );

//
// 5. Confirmed-sound behaviour must not regress.
//

$nickname = SP_Merge_Name_Matcher::compare(
	SP_Merge_Name_Matcher::parse_name( 'Robert Tremblay' ),
	SP_Merge_Name_Matcher::parse_name( 'Bob Tremblay' )
);
spm_assert_equals( 70, $nickname['certainty'], 'nickname canonicalisation still scores 70' );

$accented = SP_Merge_Name_Matcher::compare(
	SP_Merge_Name_Matcher::parse_name( 'Andre Cote' ),
	SP_Merge_Name_Matcher::parse_name( 'André Côté' )
);
spm_assert( $accented['match'], 'accent folding still matches' );
spm_assert_equals( 100, $accented['certainty'], 'accent folding still scores 100' );

$exact = SP_Merge_Name_Matcher::compare(
	SP_Merge_Name_Matcher::parse_name( 'Peter Kondo' ),
	SP_Merge_Name_Matcher::parse_name( 'Peter Kondo' )
);
spm_assert_equals( 100, $exact['certainty'], 'exact matches still score 100' );

$oconnor = SP_Merge_Name_Matcher::compare(
	SP_Merge_Name_Matcher::parse_name( "Sean O'Connor" ),
	SP_Merge_Name_Matcher::parse_name( 'Sean OConnor' )
);
spm_assert( $oconnor['match'], 'surname particle normalisation still matches' );

/*
 * Regression: a single-word particle (van, de, le, ...) directly before the
 * surname must be recognized as part of it the same way a two-word particle
 * like "van der" already is. Before the fix, "Kim Van Horn" (last = "horn",
 * the "van" discarded into $middle) and "Kim VanHorn" (last = "vanhorn")
 * parsed to different surnames and never matched at all. Folding the
 * particle onto $last in parse_name() makes both spellings parse to the
 * identical surname "vanhorn", so this is now caught as scenario 1 (exact),
 * not merely scenario 6 (normalization) — a stronger result than a
 * normalization match, and correctly so: these are unambiguously the same
 * parsed name, not two different surnames that happen to normalize alike.
 */
$van_horn = SP_Merge_Name_Matcher::compare(
	SP_Merge_Name_Matcher::parse_name( 'Kim Van Horn' ),
	SP_Merge_Name_Matcher::parse_name( 'Kim VanHorn' )
);
spm_assert( $van_horn['match'], 'a single-word particle (Van Horn / VanHorn) is folded into the same surname' );
spm_assert_equals( 100, $van_horn['certainty'], 'it scores as an exact match once folded, not a low-confidence typo' );

// The existing two-word particle case must still work identically afterwards.
$van_der_berg = SP_Merge_Name_Matcher::compare(
	SP_Merge_Name_Matcher::parse_name( 'Johan van der Berg' ),
	SP_Merge_Name_Matcher::parse_name( 'Johan VanDerBerg' )
);
spm_assert( $van_der_berg['match'], 'a two-word particle (van der Berg / VanDerBerg) still normalizes to the same surname' );
spm_assert_equals( 95, $van_der_berg['certainty'], 'it still scores as a normalization match (last = "berg" vs "vanderberg" before normalizing)' );

// A Quebec/Acadian surname the matcher's own nickname table is built for.
$le_blanc = SP_Merge_Name_Matcher::compare(
	SP_Merge_Name_Matcher::parse_name( 'Marie Le Blanc' ),
	SP_Merge_Name_Matcher::parse_name( 'Marie LeBlanc' )
);
spm_assert( $le_blanc['match'], 'Le Blanc / LeBlanc is folded into the same surname' );
spm_assert_equals( 100, $le_blanc['certainty'], 'Le Blanc / LeBlanc scores as an exact match, not a levenshtein-typo guess' );

spm_test_summary();
