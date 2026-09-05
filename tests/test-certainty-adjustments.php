<?php
/**
 * The certainty a duplicate group is reported with must be the same number
 * whether it is read in the browser or filtered on from the command line.
 *
 * The fuzzy name matcher scores names and nothing else. SP_Merge_Ajax has
 * always adjusted that score before showing it — a shared email boosts, a
 * shared current team boosts, and differing positions penalise down to a floor
 * of 50 — while `wp sp-merge scan --min-certainty` filtered the raw matcher
 * score. That is permissive in the dangerous direction on a command that
 * permanently deletes posts: a pair the browser demotes to "low confidence" and
 * leaves unchecked could still pass --min-certainty=90. Both paths now go
 * through SP_Merge_Validation::apply_certainty_adjustments().
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable

require_once __DIR__ . '/lib-cli-mocks.php';

echo "Shared certainty adjustments (scan and the browser agree)\n";

/**
 * Seed a two-player roster the matcher will group, with per-player signals.
 *
 * @param array $players List of array{id: int, name: string, position?: string, email?: string, team?: int}.
 */
function sp_test_seed_signal_roster( array $players ): void {
	$roster = array();

	foreach ( $players as $p ) {
		$roster[] = (object) array(
			'ID'         => $p['id'],
			'post_title' => $p['name'],
			'post_type'  => 'sp_player',
		);

		$GLOBALS['sp_posts'][ $p['id'] ] = (object) array(
			'ID'          => $p['id'],
			'post_type'   => 'sp_player',
			'post_title'  => $p['name'],
			'post_status' => 'publish',
		);

		if ( ! empty( $p['position'] ) ) {
			$GLOBALS['sp_player_terms'][ $p['id'] ]['sp_position'] = array( $p['position'] );
		}

		if ( ! empty( $p['email'] ) ) {
			sp_test_add_meta( $p['id'], 'spt_email', $p['email'] );
		}

		if ( ! empty( $p['team'] ) ) {
			sp_test_add_meta( $p['id'], 'sp_current_team', (string) $p['team'] );
			$GLOBALS['sp_posts'][ $p['team'] ] = (object) array(
				'ID'          => $p['team'],
				'post_type'   => 'sp_team',
				'post_title'  => 'Team ' . $p['team'],
				'post_status' => 'publish',
			);
		}
	}

	$GLOBALS['sp_scan_roster']     = $roster;
	$GLOBALS['sp_scan_total']      = count( $roster );
	$GLOBALS['sp_get_posts_calls'] = array();
}

/**
 * Rows logged by the most recent scan() call.
 *
 * @return array[]
 */
function sp_test_scan_rows(): array {
	foreach ( array_reverse( $GLOBALS['spm_cli_log'] ) as $entry ) {
		if ( 'table' === $entry['level'] ) {
			return $entry['message'];
		}
	}
	return array();
}

/**
 * Run the AJAX duplicate scan and return its groups payload.
 *
 * @return array
 */
function sp_test_ajax_groups(): array {
	$_POST['nonce'] = 'valid-nonce';

	try {
		( new SP_Merge_Ajax() )->find_duplicates();
	} catch ( SP_Test_Json_Response $response ) {
		return $response->ok ? ( $response->payload['groups'] ?? array() ) : array();
	}

	return array();
}

/* -------------------------------------------------------------------------
 * 1. apply_certainty_adjustments() itself: each signal, and the floor.
 * ---------------------------------------------------------------------- */

$plain = SP_Merge_Validation::apply_certainty_adjustments(
	array( 'certainty' => 70 ),
	array(
		array( 'email' => '', 'position' => '', 'team_id' => 0, 'certainty' => 70 ),
		array( 'email' => '', 'position' => '', 'team_id' => 0, 'certainty' => 70 ),
	)
);
sp_assert_same( 70, $plain['certainty'], 'no signals at all leaves the matcher score untouched' );

$emailed = SP_Merge_Validation::apply_certainty_adjustments(
	array( 'certainty' => 65 ),
	array(
		array( 'email' => 'same@example.test', 'position' => '', 'team_id' => 0, 'certainty' => 65 ),
		array( 'email' => 'same@example.test', 'position' => '', 'team_id' => 0, 'certainty' => 65 ),
	)
);
sp_assert_same( 85, $emailed['certainty'], 'a shared email address adds 20' );
sp_assert_same( 85, $emailed['members'][0]['certainty'], "and the member holding it gets the same boost" );

$teamed = SP_Merge_Validation::apply_certainty_adjustments(
	array( 'certainty' => 65 ),
	array(
		array( 'email' => '', 'position' => '', 'team_id' => 4, 'certainty' => 65 ),
		array( 'email' => '', 'position' => '', 'team_id' => 4, 'certainty' => 65 ),
	)
);
sp_assert_same( 70, $teamed['certainty'], 'a shared current team adds 5' );

$mixed_team = SP_Merge_Validation::apply_certainty_adjustments(
	array( 'certainty' => 65 ),
	array(
		array( 'email' => '', 'position' => '', 'team_id' => 4, 'certainty' => 65 ),
		array( 'email' => '', 'position' => '', 'team_id' => 0, 'certainty' => 65 ),
	)
);
sp_assert_same( 65, $mixed_team['certainty'], 'a team boost needs every member on that team, not just one' );

$penalised = SP_Merge_Validation::apply_certainty_adjustments(
	array( 'certainty' => 100 ),
	array(
		array( 'email' => '', 'position' => 'Goalkeeper', 'team_id' => 0, 'certainty' => 100 ),
		array( 'email' => '', 'position' => 'Striker', 'team_id' => 0, 'certainty' => 100 ),
	)
);
sp_assert_same( 80, $penalised['certainty'], 'differing positions cost 20' );
sp_assert_same( 80, $penalised['members'][1]['certainty'], 'every member pays the position penalty' );

$floored = SP_Merge_Validation::apply_certainty_adjustments(
	array( 'certainty' => 55 ),
	array(
		array( 'email' => '', 'position' => 'Goalkeeper', 'team_id' => 0, 'certainty' => 55 ),
		array( 'email' => '', 'position' => 'Striker', 'team_id' => 0, 'certainty' => 55 ),
	)
);
sp_assert_same( 50, $floored['certainty'], 'the position penalty never pushes below the floor of 50' );

$partial_email = SP_Merge_Validation::apply_certainty_adjustments(
	array( 'certainty' => 70 ),
	array(
		array( 'email' => 'a@example.test', 'position' => '', 'team_id' => 0, 'certainty' => 70 ),
		array( 'email' => 'b@example.test', 'position' => '', 'team_id' => 0, 'certainty' => 70 ),
	)
);
sp_assert_same( 70, $partial_email['certainty'], 'two different email addresses are not a shared address' );

$null_member = SP_Merge_Validation::apply_certainty_adjustments(
	array( 'certainty' => 100 ),
	array(
		array( 'email' => '', 'position' => 'Goalkeeper', 'team_id' => 0, 'certainty' => null ),
		array( 'email' => '', 'position' => 'Striker', 'team_id' => 0, 'certainty' => 100 ),
	)
);
sp_assert_same( null, $null_member['members'][0]['certainty'], 'a member the matcher scored no confidence for stays unscored' );

/* -------------------------------------------------------------------------
 * 2. `wp sp-merge scan` reports and filters on the adjusted score.
 *
 *    Two exact-name matches (raw 100) at different positions: the browser has
 *    always shown 80, and --min-certainty=90 used to let them through at 100.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_seed_signal_roster(
	array(
		array( 'id' => 1, 'name' => 'John Smith', 'position' => 'Goalkeeper' ),
		array( 'id' => 2, 'name' => 'John Smith', 'position' => 'Striker' ),
	)
);

$GLOBALS['spm_cli_log'] = array();
( new SP_Merge_CLI() )->scan( array(), array() );
$rows = sp_test_scan_rows();

sp_assert_same( 2, count( $rows ), 'the pair is still reported' );
sp_assert_same( 80, $rows[0]['group_certainty'] ?? null, 'scan reports the position-penalised score, not the raw 100' );
sp_assert_same( 80, $rows[0]['member_certainty'] ?? null, 'the per-member score is penalised the same way' );

$GLOBALS['spm_cli_log'] = array();
( new SP_Merge_CLI() )->scan( array(), array( 'min-certainty' => 90 ) );
sp_assert_same( array(), sp_test_scan_rows(), '--min-certainty=90 no longer lets a demoted pair through' );

$GLOBALS['spm_cli_log'] = array();
( new SP_Merge_CLI() )->scan( array(), array( 'min-certainty' => 80 ) );
sp_assert_same( 2, count( sp_test_scan_rows() ), 'and the pair is still reachable at its real, adjusted score' );

// The browser reads exactly the same number for exactly the same roster.
$groups = sp_test_ajax_groups();
sp_assert_same( 1, count( $groups ), 'the AJAX scan finds the same single group' );
sp_assert_same( 80, $groups[0]['certainty'] ?? null, 'the browser and the CLI report the identical adjusted score' );
sp_assert_same( 80, $groups[0]['players'][0]['certainty'] ?? null, 'and the identical per-member score' );

/* -------------------------------------------------------------------------
 * 3. The boosts travel the same way: a shared email lifts a weak typo match
 *    for both entry points.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_seed_signal_roster(
	array(
		array( 'id' => 5, 'name' => 'Michael Brown', 'email' => 'mb@example.test', 'team' => 90 ),
		array( 'id' => 6, 'name' => 'Michaal Brown', 'email' => 'mb@example.test', 'team' => 90 ),
	)
);

$GLOBALS['spm_cli_log'] = array();
( new SP_Merge_CLI() )->scan( array(), array() );
$rows = sp_test_scan_rows();

// Raw typo score 65, +20 shared email, +5 shared team.
sp_assert_same( 90, $rows[0]['group_certainty'] ?? null, 'scan applies the email and team boosts too' );

$groups = sp_test_ajax_groups();
sp_assert_same( 90, $groups[0]['certainty'] ?? null, 'the browser reports the same boosted score' );

$GLOBALS['spm_cli_log'] = array();
( new SP_Merge_CLI() )->scan( array(), array( 'min-certainty' => 90 ) );
sp_assert_same( 2, count( sp_test_scan_rows() ), 'a boosted group is reachable at its boosted score, not only its raw one' );

/* -------------------------------------------------------------------------
 * 4. --limit keeps the strongest groups by the adjusted score, since the
 *    adjustments can reorder the matcher's own ranking.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_seed_signal_roster(
	array(
		// Exact match (100), demoted to 80 by differing positions.
		array( 'id' => 11, 'name' => 'David Jones', 'position' => 'Goalkeeper' ),
		array( 'id' => 12, 'name' => 'David Jones', 'position' => 'Striker' ),
		// Typo match (65), boosted to 85 by a shared email.
		array( 'id' => 13, 'name' => 'Steven Clark', 'email' => 'sc@example.test' ),
		array( 'id' => 14, 'name' => 'Stevan Clark', 'email' => 'sc@example.test' ),
	)
);

$GLOBALS['spm_cli_log'] = array();
( new SP_Merge_CLI() )->scan( array(), array( 'limit' => 1 ) );
$rows = sp_test_scan_rows();

sp_assert_same( 2, count( $rows ), '--limit=1 keeps one group (two members)' );
sp_assert_same( 85, $rows[0]['group_certainty'] ?? null, 'the kept group is the strongest by adjusted score' );
sp_assert_same(
	array( 13, 14 ),
	array_column( $rows, 'player_id' ),
	'the boosted typo pair outranks the demoted exact pair, as it does in the browser'
);

sp_test_done();
