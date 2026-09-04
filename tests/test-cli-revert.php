<?php
/**
 * `wp sp-merge revert` must reproduce SP_Merge_Backup::revert()'s refusal
 * contract exactly: an unforced revert against a backup whose captured values
 * changed refuses without ever prompting for confirmation (the refusal itself
 * IS the confirmation — the operator has to notice it and re-run with
 * --force); the same case with --force --yes discovers the same refusal via a
 * harmless first call, shows it, asks for confirmation, and only then reverts
 * for real; and a conflict-coded refusal (a later merge overlaps this one) is
 * never forceable, per SP_Merge_Backup::revert()'s own contract.
 *
 * Mirrors the fixtures in test-revert-force-contract.php exactly, so the two
 * suites can never quietly disagree about which refusal is forcible.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable

require_once __DIR__ . '/lib-cli-mocks.php';

echo "wp sp-merge revert\n";

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
 * Build a backup payload touching the given event IDs, plus a single row of
 * captured sp_players data on $event_id that a merge and, later, a human edit
 * can each independently change — exactly test-revert-force-contract.php's
 * sp_test_force_payload()/sp_test_changed_case() fixtures.
 *
 * @param SP_Merge_Backup $backup Backup manager, used to compute hashes.
 * @return array{0: string, 1: array, 2: array} Backup ID, backup data, post-merge hashes.
 */
function sp_test_values_changed_case( SP_Merge_Backup $backup ): array {
	sp_test_add_meta( 500, 'sp_players', array( 42 => array( 'goals' => '1' ), 43 => array( 'goals' => '2' ) ) );

	$data = array(
		'primary_id'        => 42,
		'duplicate_ids'     => array( 43 ),
		'primary_backup'    => array(
			'meta_data'  => get_post_meta( 42 ),
			'taxonomies' => array(),
		),
		'duplicate_backups' => array(),
		'affected_events'   => array(
			500 => array( 'sp_players' => get_post_meta( 500, 'sp_players', true ) ),
		),
		'affected_lists'    => array(),
	);

	$data['value_hashes'] = sp_test_invoke( $backup, 'compute_value_hashes', array( $data ) );

	// The merge.
	update_post_meta( 500, 'sp_players', array( 42 => array( 'goals' => '3' ) ) );
	$post_hashes = sp_test_invoke( $backup, 'compute_value_hashes', array( $data ) );

	// A score sheet entered afterwards, by a human — this is what the revert
	// must refuse to overwrite unless forced.
	update_post_meta( 500, 'sp_players', array( 42 => array( 'goals' => '3', 'assists' => '4' ) ) );

	$backup_id = 'merge_1700000900_' . substr( md5( uniqid( '', true ) ), 0, 8 );

	return array( $backup_id, $data, $post_hashes );
}

/**
 * Build a minimal, revertible payload touching the given event IDs, with no
 * changed-values guard in the way — for the conflict (dependency) scenario,
 * where the point is the *other* guard.
 *
 * @param int   $primary_id Primary player ID.
 * @param int   $dup_id     Duplicate player ID.
 * @param int[] $event_ids  Event IDs this backup touched.
 * @return array
 */
function sp_test_conflict_payload( int $primary_id, int $dup_id, array $event_ids ): array {
	$events = array();
	foreach ( $event_ids as $event_id ) {
		$events[ $event_id ] = array( 'sp_players' => array( $primary_id => array( 'goals' => '1' ) ) );
	}

	return array(
		'primary_id'        => $primary_id,
		'duplicate_ids'     => array( $dup_id ),
		'primary_backup'    => array( 'meta_data' => array() ),
		'duplicate_backups' => array(),
		'affected_events'   => $events,
		'affected_lists'    => array(),
		'value_hashes'      => array( 'events' => array(), 'lists' => array(), 'primary' => array() ),
	);
}

/* -------------------------------------------------------------------------
 * 1. Unforced revert against a "values changed" backup refuses outright — no
 *    confirmation is ever offered for an unforced call.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
$backup_manager = new SP_Merge_Backup();
list( $backup_id, $data, $post_hashes ) = sp_test_values_changed_case( $backup_manager );
sp_test_seed_backup( $backup_id, $data, 'active', array( 42, 43, 500 ), $post_hashes );

$threw = null;
try {
	( new SP_Merge_CLI() )->revert( array( $backup_id ), array( 'yes' => true ) );
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}

sp_assert( null !== $threw, 'an unforced revert against a values-changed backup refuses' );
sp_assert_contains( 'changed since this merge', $threw ? $threw->getMessage() : '', 'the refusal names the changed-values reason' );
sp_assert_same( 'active', $GLOBALS['wpdb']->backups[0]['status'], 'the refused backup keeps its status; nothing was reverted' );

/* -------------------------------------------------------------------------
 * 2. The same case, with --force --yes: a harmless dry run discovers the
 *    refusal, it is shown, confirmation is asked for (and granted via --yes),
 *    then the real forced call reverts.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
$backup_manager = new SP_Merge_Backup();
list( $backup_id, $data, $post_hashes ) = sp_test_values_changed_case( $backup_manager );
sp_test_seed_backup( $backup_id, $data, 'active', array( 42, 43, 500 ), $post_hashes );

$threw = null;
try {
	( new SP_Merge_CLI() )->revert( array( $backup_id ), array( 'force' => true, 'yes' => true ) );
} catch ( Throwable $e ) {
	$threw = $e;
}

sp_assert( null === $threw, 'a values-changed refusal forced with --yes reverts cleanly' . ( $threw ? ' (' . $threw->getMessage() . ')' : '' ) );
sp_assert_contains( 'changed since this merge', sp_test_last_log( 'warning' ) ?? '', 'the operator is shown what would be discarded before confirming' );
sp_assert_contains( 'using the override', (string) sp_test_last_log( 'success' ), 'the success message says the override was used' );
sp_assert_same(
	array( 42 => array( 'goals' => '1' ), 43 => array( 'goals' => '2' ) ),
	get_post_meta( 500, 'sp_players', true ),
	'the forced revert actually restored the pre-merge value'
);
sp_assert_same( 'reverted', $GLOBALS['wpdb']->backups[0]['status'], 'the backup row is marked reverted' );

/* -------------------------------------------------------------------------
 * 3. A conflict-coded refusal (a later merge overlaps this one) is never
 *    forceable — --force has no effect on it, per SP_Merge_Backup's own
 *    contract, and the CLI must not paper over that with its own retry.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_seed_backup( 'merge_1700000320_cccccccc', sp_test_conflict_payload( 10, 11, array( 500, 501 ) ), 'active', array( 10, 11, 500, 501 ) );
sp_test_seed_backup( 'merge_1700000330_dddddddd', sp_test_conflict_payload( 20, 21, array( 501, 502 ) ), 'active', array( 20, 21, 501, 502 ) );

$threw = null;
try {
	( new SP_Merge_CLI() )->revert( array( 'merge_1700000320_cccccccc' ), array( 'force' => true, 'yes' => true ) );
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}

sp_assert( null !== $threw, 'a conflict refusal is never overridden, even with --force' );
sp_assert_contains( 'later merge', $threw ? $threw->getMessage() : '', 'the refusal names the dependency reason' );
sp_assert_same( 'active', $GLOBALS['wpdb']->backups[0]['status'], 'the refused backup keeps its status' );

/* -------------------------------------------------------------------------
 * 4. A clean revert (nothing to override) succeeds on the very first call —
 *    no confirmation prompt at all, forced or not.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_seed_backup(
	'merge_1700000400_eeeeeeee',
	array(
		'primary_id'        => 60,
		'duplicate_ids'     => array( 61 ),
		'primary_backup'    => array(
			'meta_data'  => array(),
			'taxonomies' => array(),
		),
		'duplicate_backups' => array(),
		'affected_events'   => array(),
		'affected_lists'    => array(),
		'value_hashes'      => array( 'events' => array(), 'lists' => array(), 'primary' => array() ),
	),
	'active',
	array( 60, 61 )
);

$threw = null;
try {
	// No --yes at all: if this hit the confirm() stub it would throw.
	( new SP_Merge_CLI() )->revert( array( 'merge_1700000400_eeeeeeee' ), array() );
} catch ( Throwable $e ) {
	$threw = $e;
}

sp_assert( null === $threw, 'a clean revert with nothing to override needs no confirmation at all' . ( $threw ? ' (' . $threw->getMessage() . ')' : '' ) );
sp_assert_same( 'reverted', $GLOBALS['wpdb']->backups[0]['status'], 'the clean revert actually completed' );

/* -------------------------------------------------------------------------
 * 4b. --owner is resolved through the shared SP_Merge_Validation::
 *     resolve_target_user() — the same helper `backups list`/`backups
 *     delete` use, since this method used to be duplicated byte-for-byte on
 *     both SP_Merge_CLI and SP_Merge_CLI_Backups. Exercised here via
 *     `revert` so both call sites of the now-single shared implementation
 *     have direct test coverage.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
$GLOBALS['sp_denied_caps']         = array( 'delete_sp_players' );
$GLOBALS['spm_cli_users']['id:42'] = 42;

$threw = null;
try {
	( new SP_Merge_CLI() )->revert( array( 'merge_1700000000_ffffffff' ), array( 'owner' => '42', 'yes' => true ) );
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}
sp_assert( null !== $threw, '--owner targeting another user without delete_sp_players refuses' );
sp_assert_contains( 'Administrator', $threw ? $threw->getMessage() : '', 'the refusal names the required tier' );

sp_test_cli_reset();
$GLOBALS['spm_cli_users']['id:42'] = 42;
sp_test_seed_backup(
	'merge_1700000500_11111111',
	array(
		'primary_id'        => 70,
		'duplicate_ids'     => array( 71 ),
		'primary_backup'    => array(
			'meta_data'  => array(),
			'taxonomies' => array(),
		),
		'duplicate_backups' => array(),
		'affected_events'   => array(),
		'affected_lists'    => array(),
		'value_hashes'      => array( 'events' => array(), 'lists' => array(), 'primary' => array() ),
	),
	'active',
	array( 70, 71 ),
	null,
	42
);

$threw = null;
try {
	// delete_sp_players is held (not denied in this scenario), so --owner=42
	// (a different user than the current one, 7) is permitted.
	( new SP_Merge_CLI() )->revert( array( 'merge_1700000500_11111111' ), array( 'owner' => '42', 'yes' => true ) );
} catch ( Throwable $e ) {
	$threw = $e;
}
sp_assert( null === $threw, 'holding delete_sp_players, --owner targeting another user\'s backup succeeds' . ( $threw ? ' (' . $threw->getMessage() . ')' : '' ) );
sp_assert_same( 'reverted', $GLOBALS['wpdb']->backups[0]['status'], 'the cross-user backup was really reverted' );

/* -------------------------------------------------------------------------
 * 5. Missing arguments and insufficient permissions both refuse.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
$threw = null;
try {
	( new SP_Merge_CLI() )->revert( array(), array( 'yes' => true ) );
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}
sp_assert( null !== $threw, 'a missing backup ID refuses with a usage message' );

sp_test_cli_reset();
$GLOBALS['sp_denied_caps'] = array( 'manage_sportspress', 'delete_sp_players' );
$threw                     = null;
try {
	( new SP_Merge_CLI() )->revert( array( 'merge_1700000000_ffffffff' ), array( 'yes' => true ) );
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}
sp_assert( null !== $threw, 'lacking both manage_sportspress and delete_sp_players refuses the revert' );

sp_test_done();
