<?php
/**
 * Regression guard for the event-stat rewriting the audit confirmed sound:
 * the reserved `0` team-totals key, same-team numeric collision summing, and
 * timeline append/sort/dedupe.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output.

require_once __DIR__ . '/bootstrap.php';

spm_test_header( 'reserved 0 key, collision summing and timeline merging (regression)' );

spm_reset();

$processor = new SP_Merge_Processor();

/*
 * sp_players on an event: the outer key is the team ID (0 is the reserved
 * team-totals bucket) and the inner key is the player ID (0 is the reserved
 * team-totals row).
 */
$sp_players = array(
	0 => array(
		0 => array( 'goals' => '5' ),
	),
	9 => array(
		0   => array( 'goals' => '9' ),
		100 => array(
			'goals'  => '1',
			'status' => 'starter',
			'number' => '17',
		),
		200 => array(
			'goals'  => '2',
			'status' => 'sub',
			'number' => '42',
		),
	),
);

$merged = spm_invoke( $processor, 'replace_player_id_in_structure', $sp_players, 100, 200, 'sp_players' );

spm_assert_equals( array( 0 => array( 'goals' => '5' ) ), $merged[0], 'reserved team-totals bucket 0 is untouched' );
spm_assert_equals( array( 'goals' => '9' ), $merged[9][0], 'reserved player key 0 is untouched' );
spm_assert( ! isset( $merged[9][200] ), 'the duplicate player key is gone' );
spm_assert_equals( '3', $merged[9][100]['goals'], 'same-team numeric collision is summed' );
spm_assert_equals( 'starter', $merged[9][100]['status'], "the primary's status wins on collision" );
spm_assert_equals( '17', $merged[9][100]['number'], "the primary's number wins on collision" );

/*
 * The reserved key must survive even when the primary is not present on the
 * event at all — a plain rename of the duplicate key.
 */
$sp_players_rename = array(
	9 => array(
		0   => array( 'goals' => '9' ),
		200 => array( 'goals' => '2' ),
	),
);

$renamed = spm_invoke( $processor, 'replace_player_id_in_structure', $sp_players_rename, 100, 200, 'sp_players' );

spm_assert_equals( array( 'goals' => '9' ), $renamed[9][0], 'reserved key 0 survives a plain rename' );
spm_assert_equals( array( 'goals' => '2' ), $renamed[9][100], 'the duplicate row is renamed to the primary' );

/*
 * Scalar values: a literal "0" must never be mistaken for a player reference.
 */
$scalars = array(
	'team'   => '0',
	'player' => '200',
	'other'  => '300',
);

$scalar_result = spm_invoke( $processor, 'replace_player_id_in_structure', $scalars, 100, 200, 'sp_order' );

spm_assert_equals( '0', $scalar_result['team'], 'a scalar "0" is left alone' );
spm_assert_equals( '100', $scalar_result['player'], 'a scalar duplicate ID is rewritten' );
spm_assert_equals( '300', $scalar_result['other'], 'an unrelated scalar ID is left alone' );

/*
 * sp_timeline collisions append, sort and de-duplicate minutes.
 */
$sp_timeline = array(
	9 => array(
		0   => array( 'goal' => array( '5' ) ),
		100 => array( 'goal' => array( '12', '30' ) ),
		200 => array( 'goal' => array( '30', '3' ) ),
	),
);

$timeline = spm_invoke( $processor, 'replace_player_id_in_structure', $sp_timeline, 100, 200, 'sp_timeline' );

spm_assert_equals( array( 'goal' => array( '5' ) ), $timeline[9][0], 'reserved key 0 is untouched in the timeline' );
spm_assert_equals( array( '3', '12', '30' ), $timeline[9][100]['goal'], 'timeline minutes are appended, sorted and de-duplicated' );

spm_test_summary();
