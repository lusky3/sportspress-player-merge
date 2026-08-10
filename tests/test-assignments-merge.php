<?php
/**
 * Blocker 4 guard: sp_assignments must survive a merge.
 *
 * SportsPress stores sp_assignments as multiple rows of plain
 * "{league}_{season}_{team}" strings (see sportspress-player-assignments.php:74,
 * add_post_meta( $post_id, 'sp_assignments', $serialized, false )), not as a
 * serialized array. Routing it through merge_array_field() silently dropped every
 * assignment on the duplicate.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output.

require_once __DIR__ . '/bootstrap.php';

spm_test_header( 'sp_assignments survives a merge (blocker 4)' );

/*
 * Scenario 1: both players carry assignments — the primary must end up with the
 * union of the two sets.
 */
spm_reset(
	array(
		100 => array(
			'sp_assignments' => array( '11_22_33' ),
			'sp_team'        => array( '33' ),
		),
		200 => array(
			'sp_assignments' => array( '11_22_44', '55_66_77' ),
		),
	)
);

$processor = new SP_Merge_Processor();
spm_invoke( $processor, 'merge_meta_data', 100, 200 );

$assignments = get_post_meta( 100, 'sp_assignments' );
sort( $assignments );

spm_assert_equals(
	array( '11_22_33', '11_22_44', '55_66_77' ),
	$assignments,
	'union of primary and duplicate assignment rows is kept'
);

/*
 * Scenario 2: the primary has no assignments at all — the Peter Kondo case, where
 * the duplicate holds every league/season/team the player was ever rostered on.
 */
spm_reset(
	array(
		100 => array(
			'sp_team' => array( '33' ),
		),
		200 => array(
			'sp_assignments' => array( '11_22_44', '55_66_77' ),
		),
	)
);

$processor = new SP_Merge_Processor();
spm_invoke( $processor, 'merge_meta_data', 100, 200 );

$assignments = get_post_meta( 100, 'sp_assignments' );
sort( $assignments );

spm_assert_equals(
	array( '11_22_44', '55_66_77' ),
	$assignments,
	'duplicate assignments are copied when the primary has none'
);

/*
 * Scenario 3: identical assignment on both records must not be duplicated.
 */
spm_reset(
	array(
		100 => array( 'sp_assignments' => array( '11_22_33' ) ),
		200 => array( 'sp_assignments' => array( '11_22_33' ) ),
	)
);

$processor = new SP_Merge_Processor();
spm_invoke( $processor, 'merge_meta_data', 100, 200 );

spm_assert_equals(
	array( '11_22_33' ),
	get_post_meta( 100, 'sp_assignments' ),
	'an assignment held by both players is not duplicated'
);

/*
 * Scenario 4: the serialized-array fields must still take the deep-merge path.
 */
spm_reset(
	array(
		100 => array(
			'sp_statistics' => array( array( 2024 => array( 'goals' => '3' ) ) ),
		),
		200 => array(
			'sp_statistics' => array( array( 2023 => array( 'goals' => '7' ) ) ),
		),
	)
);

$processor = new SP_Merge_Processor();
spm_invoke( $processor, 'merge_meta_data', 100, 200 );

spm_assert_equals(
	array(
		2024 => array( 'goals' => '3' ),
		2023 => array( 'goals' => '7' ),
	),
	get_post_meta( 100, 'sp_statistics', true ),
	'sp_statistics is still deep-merged rather than unioned as rows'
);

spm_test_summary();
