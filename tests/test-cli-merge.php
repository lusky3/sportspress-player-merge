<?php
/**
 * `wp sp-merge merge` must run the same validation and survivor-warning checks
 * the AJAX layer runs, refuse a survivor warning unless --force overrides it,
 * and on success drive the real SP_Merge_Processor/SP_Merge_Backup pipeline —
 * producing a real backup ID and printing every cell-level resolution.
 *
 * `--skip-preview` is used throughout: the preview report itself is already
 * covered end to end by test-cli-preview.php (render_preview_data() is the
 * exact same method), so these scenarios stay focused on merge()'s own
 * warning/confirm/execute sequencing.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable

require_once __DIR__ . '/lib-cli-mocks.php';

echo "wp sp-merge merge\n";

/**
 * Register (or replace) a player post the get_post() mock will serve.
 *
 * @param int    $id   Player ID.
 * @param string $name Post title.
 */
function sp_test_set_player( int $id, string $name ): void {
	$GLOBALS['sp_posts'][ $id ] = (object) array(
		'ID'          => $id,
		'post_type'   => 'sp_player',
		'post_title'  => $name,
		'post_status' => 'publish',
	);
}

/**
 * The message of the last log entry at a given level.
 *
 * @param string $level Log level.
 * @return mixed|null
 */
function sp_test_last_log( string $level ) {
	foreach ( array_reverse( $GLOBALS['spm_cli_log'] ) as $entry ) {
		if ( $level === $entry['level'] ) {
			return $entry['message'];
		}
	}
	return null;
}

/**
 * Every logged message at a given level, joined, for substring assertions.
 *
 * @param string $level Log level: log, warning, success, ...
 * @return string
 */
function sp_test_log_text( string $level = 'log' ): string {
	$lines = array();
	foreach ( $GLOBALS['spm_cli_log'] as $entry ) {
		if ( $level === $entry['level'] ) {
			$lines[] = is_string( $entry['message'] ) ? $entry['message'] : var_export( $entry['message'], true );
		}
	}
	return implode( "\n", $lines );
}

/* -------------------------------------------------------------------------
 * 1. Happy path: no survivor warning, backup_id produced, every resolution
 *    (filled and conflict) printed.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_set_player( 100, 'Primary Player' );
sp_test_set_player( 200, 'Duplicate Player' );

// A blank cell the duplicate fills, and a real conflict the primary keeps —
// mirrors the same fixture test-cli-preview.php uses, so the two commands can
// never quietly describe the same selection differently.
sp_test_add_meta( 100, 'sp_statistics', array( 7 => array( 30 => array( 'goals' => '', 'assists' => '2' ) ) ) );
sp_test_add_meta( 200, 'sp_statistics', array( 7 => array( 30 => array( 'goals' => '12', 'assists' => '9' ) ) ) );

( new SP_Merge_CLI() )->merge( array( '100', '200' ), array( 'skip-preview' => true, 'yes' => true ) );

$success = sp_test_last_log( 'success' );
sp_assert( null !== $success, 'a clean merge logs a success message' );
sp_assert( 1 === preg_match( '/merge_\d+_[a-zA-Z0-9]{8}/', (string) $success ), 'the success message names a real backup ID' );

$log = sp_test_log_text( 'log' );
sp_assert_contains( 'sp_statistics[7][30][goals]', $log, 'the filled cell is addressed exactly' );
sp_assert_contains( 'the duplicate\'s value "12" was used', $log, 'the filled cell says the duplicate value was used' );
sp_assert_contains( 'sp_statistics[7][30][assists]', $log, 'the conflicting cell is addressed exactly' );
sp_assert_contains( 'keeping "2"', $log, 'the conflict states the value that was kept' );
sp_assert_contains( 'discarding "9"', $log, 'the conflict states the value that was discarded' );

// wp_delete_post() was really called (with force) — this exercised the real
// processor, not a stub.
sp_assert( in_array( 200, $GLOBALS['sp_deleted_posts'], true ), 'the duplicate player was actually deleted' );

/* -------------------------------------------------------------------------
 * 2. A survivor warning without --force halts before anything is written.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_set_player( 100, 'Primary Player' );
sp_test_set_player( 200, 'Duplicate Player' );

// Primary has no history at all; the duplicate has plenty — the "keeping the
// emptier record" case survivor_warnings() exists to catch.
sp_test_seed_event( 9001, 200 );
sp_test_seed_event( 9002, 200 );
sp_test_seed_event( 9003, 200 );

$threw = null;
try {
	( new SP_Merge_CLI() )->merge( array( '100', '200' ), array( 'skip-preview' => true, 'yes' => true ) );
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}

sp_assert( null !== $threw, 'a survivor warning without --force halts the merge' );
sp_assert_contains( 'Re-run with --force to override', $threw ? $threw->getMessage() : '', 'the halt message points at --force' );
// get_the_title() is mocked as a fixed "Player {id}" regardless of the seeded
// post_title, so the warning is asserted against that, not the display name.
sp_assert_contains( 'Player 200', sp_test_log_text( 'warning' ), 'the warning names the duplicate with the outsized history' );

// Nothing was executed: the duplicate player still exists.
sp_assert( null !== get_post( 200 ), 'the duplicate player is untouched when the merge is refused' );

/* -------------------------------------------------------------------------
 * 3. The same warning, with --force --yes, proceeds — without ever hitting
 *    the mocked confirm()'s throw-when-uninitialized path.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_set_player( 100, 'Primary Player' );
sp_test_set_player( 200, 'Duplicate Player' );
sp_test_seed_event( 9001, 200 );
sp_test_seed_event( 9002, 200 );
sp_test_seed_event( 9003, 200 );

$threw = null;
try {
	( new SP_Merge_CLI() )->merge( array( '100', '200' ), array( 'skip-preview' => true, 'force' => true, 'yes' => true ) );
} catch ( Throwable $e ) {
	$threw = $e;
}

sp_assert( null === $threw, 'a survivor warning overridden with --force --yes does not throw' . ( $threw ? ' (' . $threw->getMessage() . ')' : '' ) );
sp_assert_contains( 'Player 200', sp_test_log_text( 'warning' ), 'the warning is still shown even though it is being overridden' );
sp_assert( null !== sp_test_last_log( 'success' ), 'the forced merge still reports success' );
sp_assert( in_array( 200, $GLOBALS['sp_deleted_posts'], true ), 'the duplicate player was actually deleted once forced' );

/* -------------------------------------------------------------------------
 * 4. Insufficient permissions and a missing-argument usage error both refuse
 *    before anything runs.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
$GLOBALS['sp_denied_caps'] = array( 'manage_sportspress', 'delete_sp_players' );

$threw = null;
try {
	( new SP_Merge_CLI() )->merge( array( '100', '200' ), array( 'skip-preview' => true, 'yes' => true ) );
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}
sp_assert( null !== $threw, 'lacking both manage_sportspress and delete_sp_players refuses the merge' );

sp_test_cli_reset();
$threw = null;
try {
	( new SP_Merge_CLI() )->merge( array( '100' ), array( 'skip-preview' => true, 'yes' => true ) );
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}
sp_assert( null !== $threw, 'a primary with no duplicates refuses with a usage message' );

sp_test_done();
