<?php
/**
 * F-3 guard: a blank cell on the primary must not beat a real value on the
 * duplicate, and every resolved cell must be reported.
 *
 * The old deep_merge_arrays() gated on isset(), which is true for '' and 0.
 * SportsPress posts a value for every rendered cell — the statistics metabox
 * submits a fully rendered table — so the primary nearly always had an entry and
 * a blank one silently won. The lost values are hand-entered career statistics
 * with no other source.
 *
 * Emptiness is field-specific and the two conventions are opposites:
 *   - sp_leagues is saved with the 'int' sanitiser and both widgets post a
 *     non-positive value for "nothing selected" (-1 from the team dropdown's
 *     None option and from the unticked season checkbox), so <= 0 is blank.
 *   - sp_statistics / sp_metrics are saved with the 'text' sanitiser, so a blank
 *     cell is '' and a '0' is a deliberately entered zero — real data.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output.

require_once __DIR__ . '/bootstrap.php';

spm_test_header( 'blank cells never beat real values, and resolutions are reported (F-3)' );

/**
 * Merge one array meta key from duplicate into primary and return the result.
 *
 * @param string $key       Meta key.
 * @param mixed  $primary   Primary player's stored value.
 * @param mixed  $duplicate Duplicate player's stored value.
 * @return array{value: mixed, resolutions: array} Stored value after the merge and what it resolved.
 */
function spm_merge_field( string $key, $primary, $duplicate ): array {
	spm_reset(
		array(
			100 => array( $key => array( $primary ) ),
			200 => array( $key => array( $duplicate ) ),
		)
	);

	$processor = new SP_Merge_Processor();
	spm_invoke( $processor, 'merge_array_field', 100, 200, $key );

	return array(
		'value'       => get_post_meta( 100, $key, true ),
		'resolutions' => $processor->get_merge_resolutions(),
	);
}

/*
 * 1. sp_leagues: the primary's 0 (and -1) mean "no team selected", so the
 *    duplicate's real team ID must win. A real team ID on both sides that
 *    disagrees is a conflict the primary still wins.
 */
$leagues = spm_merge_field(
	'sp_leagues',
	array(
		7 => array(
			20 => 0,
			21 => -1,
			22 => 5,
			23 => 8,
		),
	),
	array(
		7 => array(
			20 => 3,
			21 => 4,
			22 => 5,
			23 => 9,
		),
	)
);

spm_assert_equals( 3, $leagues['value'][7][20], 'sp_leagues: a 0 on the primary loses to the duplicate\'s team ID' );
spm_assert_equals( 4, $leagues['value'][7][21], 'sp_leagues: a -1 on the primary loses to the duplicate\'s team ID' );
spm_assert_equals( 5, $leagues['value'][7][22], 'sp_leagues: identical team IDs are not a conflict' );
spm_assert_equals( 8, $leagues['value'][7][23], 'sp_leagues: two different real team IDs leave the primary\'s in place' );

$leagues_by_action = array(
	'filled'   => 0,
	'conflict' => 0,
);
foreach ( $leagues['resolutions'] as $resolution ) {
	++$leagues_by_action[ $resolution['action'] ];
}
spm_assert_equals( 2, $leagues_by_action['filled'], 'sp_leagues: both blank cells are reported as filled from the duplicate' );
spm_assert_equals( 1, $leagues_by_action['conflict'], 'sp_leagues: only the genuine disagreement is reported as a conflict' );

/*
 * 2. sp_statistics: an empty cell on the primary loses, but a '0' is a
 *    hand-entered zero and must survive — over-correcting here would let the
 *    duplicate's stale value overwrite a correct zero.
 */
$stats = spm_merge_field(
	'sp_statistics',
	array(
		7 => array(
			30 => array(
				'goals'   => '',
				'assists' => '0',
				'pims'    => '4',
				'shots'   => '11',
			),
		),
	),
	array(
		7 => array(
			30 => array(
				'goals'   => '12',
				'assists' => '7',
				'pims'    => '',
				'shots'   => '11',
			),
		),
	)
);

spm_assert_equals( '12', $stats['value'][7][30]['goals'], "sp_statistics: an empty primary cell takes the duplicate's value" );
spm_assert_equals( '0', $stats['value'][7][30]['assists'], "sp_statistics: the primary's hand-entered '0' is kept" );
spm_assert_equals( '4', $stats['value'][7][30]['pims'], 'sp_statistics: a blank duplicate cell never clears the primary' );
spm_assert_equals( '11', $stats['value'][7][30]['shots'], 'sp_statistics: identical values are left alone' );

$stat_actions = array();
foreach ( $stats['resolutions'] as $resolution ) {
	$stat_actions[ SP_Merge_Processor::format_resolution_path( $resolution ) ] = $resolution['action'];
}

spm_assert_equals(
	array(
		'sp_statistics[7][30][goals]'   => 'filled',
		'sp_statistics[7][30][assists]' => 'conflict',
	),
	$stat_actions,
	'sp_statistics: exactly the gap and the conflict are reported, addressed by cell'
);

/*
 * 3. A conflict report has to identify what was discarded well enough to type it
 *    back in by hand.
 */
$conflict = null;
foreach ( $stats['resolutions'] as $resolution ) {
	if ( 'conflict' === $resolution['action'] ) {
		$conflict = $resolution;
	}
}

spm_assert( is_array( $conflict ), 'a conflict report is produced' );
spm_assert_equals( 'sp_statistics', $conflict['meta_key'], 'the report names the meta key' );
spm_assert_equals( array( 7, 30, 'assists' ), $conflict['path'], 'the report carries the full key path' );
spm_assert_equals( 200, $conflict['duplicate_id'], 'the report names the duplicate the value came from' );
spm_assert_equals( '0', $conflict['kept'], 'the report states the kept value' );
spm_assert_equals( '7', $conflict['discarded'], 'the report states the discarded value' );

/*
 * 4. sp_metrics is a flat [slug] => text map saved with the same 'text'
 *    sanitiser as the statistics, so '' is blank and '0' is data.
 */
$metrics = spm_merge_field(
	'sp_metrics',
	array(
		'height' => '',
		'weight' => '0',
	),
	array(
		'height' => '180',
		'weight' => '82',
	)
);

spm_assert_equals( '180', $metrics['value']['height'], "sp_metrics: an empty primary metric takes the duplicate's value" );
spm_assert_equals( '0', $metrics['value']['weight'], "sp_metrics: the primary's '0' is kept and the conflict reported" );
$metric_conflicts = 0;
foreach ( $metrics['resolutions'] as $resolution ) {
	if ( 'conflict' === $resolution['action'] ) {
		++$metric_conflicts;
	}
}
spm_assert_equals( 1, $metric_conflicts, 'sp_metrics: the kept zero is reported as a conflict' );

/*
 * 5. Recursion still reaches new branches: a league the primary has never played
 *    in, and a season inside a league it has.
 */
$nested = spm_merge_field(
	'sp_statistics',
	array(
		7 => array(
			30 => array( 'goals' => '3' ),
		),
	),
	array(
		7 => array(
			31 => array( 'goals' => '6' ),
		),
		8 => array(
			40 => array( 'goals' => '9' ),
		),
	)
);

spm_assert_equals( '3', $nested['value'][7][30]['goals'], 'recursion: the primary keeps its own season' );
spm_assert_equals( '6', $nested['value'][7][31]['goals'], 'recursion: a new season inside a shared league is added' );
spm_assert_equals( '9', $nested['value'][8][40]['goals'], 'recursion: a whole new league is added' );
spm_assert_equals( array(), $nested['resolutions'], 'recursion: filling absent keys is a union, not a resolution' );

/*
 * 6. Regression guard: numerically-indexed arrays (timeline minutes) still
 *    append-unique rather than falling into the scalar precedence rule.
 */
$processor = new SP_Merge_Processor();
$appended  = spm_invoke(
	$processor,
	'deep_merge_arrays',
	array( 'goal' => array( '12', '30' ) ),
	array( 'goal' => array( '30', '3' ) ),
	array(
		'meta_key'     => 'sp_statistics',
		'duplicate_id' => 200,
	)
);

spm_assert_equals( array( '12', '30', '3' ), $appended['goal'], 'numerically-indexed arrays still append-unique' );
spm_assert_equals( array(), $processor->get_merge_resolutions(), 'an append is not reported as a conflict' );

/*
 * 7. The dry run the preview uses must agree with the merge, and must not leave
 *    resolutions behind on the processor.
 */
$dry = $processor->preview_array_field_merge(
	'sp_statistics',
	array( 7 => array( 30 => array( 'goals' => '' ) ) ),
	array( 7 => array( 30 => array( 'goals' => '12' ) ) ),
	200
);

spm_assert_equals( '12', $dry['merged'][7][30]['goals'], 'the dry run produces the same merged value' );
spm_assert_equals( 1, count( $dry['resolutions'] ), 'the dry run reports the resolution' );
spm_assert_equals( array(), $processor->get_merge_resolutions(), 'the dry run does not pollute the recorded resolutions' );

spm_test_summary();
