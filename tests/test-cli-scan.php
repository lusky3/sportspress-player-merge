<?php
/**
 * `wp sp-merge scan` must run the real duplicate-name matcher headlessly, filter
 * by scenario and certainty before applying --limit, report event counts per
 * member, and say plainly when a roster larger than the scan's hard cap was
 * only partially seen.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable

require_once __DIR__ . '/lib-cli-mocks.php';

echo "wp sp-merge scan\n";

/**
 * Seed the roster the AJAX layer's get_posts() mock serves, with explicit
 * IDs/titles rather than the generic "Player N" names sp_test_seed_roster()
 * generates, so the name matcher actually forms duplicate groups.
 *
 * @param array $players List of array{id: int, name: string}.
 */
function sp_test_seed_named_roster( array $players ): void {
	$roster = array();
	foreach ( $players as $p ) {
		$roster[] = (object) array(
			'ID'         => $p['id'],
			'post_title' => $p['name'],
			'post_type'  => 'sp_player',
		);
	}

	$GLOBALS['sp_scan_roster']     = $roster;
	$GLOBALS['sp_scan_total']      = count( $roster );
	$GLOBALS['sp_get_posts_calls'] = array();
}

/**
 * Rows logged by the most recent scan() call, from its format_items() entry.
 *
 * @return array[]
 */
function sp_test_last_scan_rows(): array {
	foreach ( array_reverse( $GLOBALS['spm_cli_log'] ) as $entry ) {
		if ( 'table' === $entry['level'] ) {
			return $entry['message'];
		}
	}
	return array();
}

sp_test_cli_reset();

// Four duplicate pairs at four different certainties/scenarios, so filtering
// and limiting have something real to act on.
sp_test_seed_named_roster(
	array(
		array( 'id' => 1, 'name' => 'John Smith' ),    // exact, certainty 100.
		array( 'id' => 2, 'name' => 'John Smith' ),
		array( 'id' => 3, 'name' => 'Robert Jones' ),  // nickname, certainty 70.
		array( 'id' => 4, 'name' => 'Bob Jones' ),
		array( 'id' => 5, 'name' => 'Michael Brown' ), // first-name typo, certainty 65.
		array( 'id' => 6, 'name' => 'Michaal Brown' ),
		array( 'id' => 7, 'name' => 'Tom Reed' ),       // first/last reversal, certainty 50.
		array( 'id' => 8, 'name' => 'Reed Tom' ),
	)
);

// Player 1 appears in two events; everyone else appears in none.
sp_test_seed_event( 9001, 1 );
sp_test_seed_event( 9002, 1 );

/* 1. Defaults: every group, every member, sorted by certainty. */
( new SP_Merge_CLI() )->scan( array(), array() );

$rows = sp_test_last_scan_rows();
sp_assert_same( 8, count( $rows ), 'every duplicate pair is reported with no filters applied' );

$certainties = array_unique( array_column( $rows, 'group_certainty' ) );
sort( $certainties );
sp_assert_same( array( 50, 65, 70, 100 ), $certainties, 'all four certainty tiers are present' );

$john_row = current(
	array_filter(
		$rows,
		static function ( $row ) {
			return 1 === $row['player_id'];
		}
	)
);
sp_assert_same( 2, $john_row['events'] ?? null, "player 1's row carries its real event count" );

$summary = end( $GLOBALS['spm_cli_log'] );
sp_assert_same( 'log', $summary['level'], 'an untruncated scan logs a plain summary, not a warning' );
sp_assert_same( 'Scanned 8 of 8 players.', $summary['message'], 'the summary states scanned and total' );

/* 2. --min-certainty drops the weaker groups before --limit ever sees them. */
$GLOBALS['spm_cli_log'] = array();
( new SP_Merge_CLI() )->scan( array(), array( 'min-certainty' => 60 ) );

$rows = sp_test_last_scan_rows();
sp_assert_same( 6, count( $rows ), '--min-certainty=60 keeps the three tiers at or above the floor' );
sp_assert(
	! in_array( 50, array_column( $rows, 'group_certainty' ), true ),
	'the certainty-50 reversal group is filtered out'
);

/* 3. --limit applies after filtering, to the certainty-sorted groups. */
$GLOBALS['spm_cli_log'] = array();
( new SP_Merge_CLI() )->scan( array(), array( 'limit' => 2 ) );

$rows = sp_test_last_scan_rows();
sp_assert_same( 4, count( $rows ), '--limit=2 keeps only the top two groups (4 members)' );
$kept_certainties = array_unique( array_column( $rows, 'group_certainty' ) );
sort( $kept_certainties );
sp_assert_same( array( 70, 100 ), $kept_certainties, '--limit keeps the highest-certainty groups first' );

/* 4. --scenario is an exact match against the group's scenario key. */
$GLOBALS['spm_cli_log'] = array();
( new SP_Merge_CLI() )->scan( array(), array( 'scenario' => 'typo' ) );

$rows = sp_test_last_scan_rows();
sp_assert_same( 2, count( $rows ), '--scenario=typo keeps only the typo group' );
sp_assert_same(
	array( 'typo', 'typo' ),
	array_column( $rows, 'scenario' ),
	'every row from the filtered scan carries the requested scenario'
);

/* 5. A roster smaller than the published total is scanned in full but reported
 *    as truncated (mirrors tests/test-scan-coverage.php's own truncation case,
 *    without paying for find_groups() over a multi-thousand-player roster).
 */
sp_test_cli_reset();
sp_test_seed_roster( 40, 1, 60 );
$GLOBALS['spm_cli_log'] = array();

( new SP_Merge_CLI() )->scan( array(), array() );

$summary = end( $GLOBALS['spm_cli_log'] );
sp_assert_same( 'warning', $summary['level'], 'a truncated scan warns instead of logging quietly' );
sp_assert_contains( '40 of 60', $summary['message'], 'the warning states how much of the roster was actually scanned' );
sp_assert_contains( '20 were skipped', $summary['message'], 'the warning states how many players were skipped' );

/* 5b. --min-certainty/--limit reject garbage instead of silently misreading it:
 *     (int) casts "abc" to 0 and "-1" to -1, so these used to filter as if
 *     --min-certainty=0 and produce an empty, unexplained result rather than
 *     refusing outright. */
sp_test_cli_reset();
sp_test_seed_named_roster(
	array(
		array( 'id' => 1, 'name' => 'John Smith' ),
		array( 'id' => 2, 'name' => 'John Smith' ),
	)
);

$threw = null;
try {
	( new SP_Merge_CLI() )->scan( array(), array( 'min-certainty' => 'abc' ) );
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}
sp_assert( null !== $threw, '--min-certainty=abc refuses instead of filtering as if it were 0' );
sp_assert_contains( '--min-certainty', $threw ? $threw->getMessage() : '', 'the refusal names --min-certainty' );

$threw = null;
try {
	( new SP_Merge_CLI() )->scan( array(), array( 'min-certainty' => '101' ) );
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}
sp_assert( null !== $threw, '--min-certainty=101 refuses as out of range' );

$threw = null;
try {
	( new SP_Merge_CLI() )->scan( array(), array( 'limit' => '-1' ) );
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}
sp_assert( null !== $threw, '--limit=-1 refuses instead of silently producing an empty result' );
sp_assert_contains( '--limit', $threw ? $threw->getMessage() : '', 'the refusal names --limit' );

$threw = null;
try {
	( new SP_Merge_CLI() )->scan( array(), array( 'limit' => '0' ) );
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}
sp_assert( null !== $threw, '--limit=0 refuses — a limit has to keep at least one group' );

/* A valid --min-certainty/--limit pair still runs normally. */
$GLOBALS['spm_cli_log'] = array();
$threw                  = null;
try {
	( new SP_Merge_CLI() )->scan( array(), array( 'min-certainty' => '0', 'limit' => '1' ) );
} catch ( Throwable $e ) {
	$threw = $e;
}
sp_assert( null === $threw, 'a valid --min-certainty/--limit still runs' . ( $threw ? ' (' . $threw->getMessage() . ')' : '' ) );

/* 5c. A typo'd --scenario refuses instead of silently returning zero rows. */
$threw = null;
try {
	( new SP_Merge_CLI() )->scan( array(), array( 'scenario' => 'exakt' ) );
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}
sp_assert( null !== $threw, "--scenario=exakt refuses instead of silently returning zero rows" );
sp_assert_contains( '--scenario', $threw ? $threw->getMessage() : '', 'the refusal names --scenario' );
sp_assert_contains( 'exakt', $threw ? $threw->getMessage() : '', 'the refusal echoes back the bad value' );

/* A valid --scenario still runs normally. */
$GLOBALS['spm_cli_log'] = array();
$threw                  = null;
try {
	( new SP_Merge_CLI() )->scan( array(), array( 'scenario' => 'typo' ) );
} catch ( Throwable $e ) {
	$threw = $e;
}
sp_assert( null === $threw, 'a valid --scenario still runs' . ( $threw ? ' (' . $threw->getMessage() . ')' : '' ) );

/* 6. Lacking edit_sp_players refuses the scan outright, before touching the roster. */
sp_test_cli_reset();
$GLOBALS['sp_denied_caps'] = array( 'edit_sp_players' );

$threw = null;
try {
	( new SP_Merge_CLI() )->scan( array(), array() );
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}
sp_assert( null !== $threw, 'lacking edit_sp_players refuses the scan' );

sp_test_done();
