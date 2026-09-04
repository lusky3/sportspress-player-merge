<?php
/**
 * `wp sp-merge backups list` / `wp sp-merge backups delete` must gate every
 * cross-user access the same way resolve_target_user() and the AJAX layer do:
 * --all-users on `list` escalates the required capability from edit_sp_players
 * to delete_sp_players; `delete` always requires delete_sp_players outright,
 * with no lower "delete your own backup" tier; and --user on either command
 * refuses via resolve_target_user() unless the caller holds delete_sp_players.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable

require_once __DIR__ . '/lib-cli-mocks.php';

echo "wp sp-merge backups list / backups delete\n";

/**
 * A minimal, valid backup payload — enough for get_recent_backups() to read
 * primary_name/duplicate_names back out, and for delete_backups() to remove.
 *
 * @param int $primary_id Primary player ID.
 * @param int $dup_id     Duplicate player ID.
 * @return array
 */
function sp_test_backup_payload( int $primary_id, int $dup_id ): array {
	return array(
		'primary_id'        => $primary_id,
		'primary_name'      => 'Player ' . $primary_id,
		'duplicate_ids'     => array( $dup_id ),
		'duplicate_names'   => array( $dup_id => 'Player ' . $dup_id ),
		'primary_backup'    => array(
			'meta_data'  => array(),
			'taxonomies' => array(),
		),
		'duplicate_backups' => array(),
		'affected_events'   => array(),
		'affected_lists'    => array(),
		'value_hashes'      => array( 'events' => array(), 'lists' => array(), 'primary' => array() ),
	);
}

/**
 * The payload of the last logged entry at a given level (format_items()'s
 * stub tags each render with its format as the level).
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

/* -------------------------------------------------------------------------
 * backups list
 * ---------------------------------------------------------------------- */

/* 1. --all-users requires delete_sp_players, even when edit_sp_players is held. */
sp_test_cli_reset();
$GLOBALS['sp_denied_caps'] = array( 'delete_sp_players' );

$threw = null;
try {
	( new SP_Merge_CLI_Backups() )->list( array(), array( 'all-users' => true ) );
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}
sp_assert( null !== $threw, '--all-users without delete_sp_players refuses' );
sp_assert_contains( 'Administrator', $threw ? $threw->getMessage() : '', 'the refusal names the required tier' );

/* 2. Without --all-users, edit_sp_players alone is enough for the caller's own list. */
sp_test_cli_reset();
$GLOBALS['sp_denied_caps'] = array( 'delete_sp_players' );
sp_test_seed_backup( 'merge_a', sp_test_backup_payload( 1, 2 ), 'active', null, null, 7 );

$threw = null;
try {
	( new SP_Merge_CLI_Backups() )->list( array(), array() );
} catch ( Throwable $e ) {
	$threw = $e;
}
sp_assert( null === $threw, 'listing your own backups needs only edit_sp_players' . ( $threw ? ' (' . $threw->getMessage() . ')' : '' ) );

$rows = sp_test_last_log( 'table' );
sp_assert( is_array( $rows ) && 1 === count( $rows ), 'exactly the current user\'s one backup is listed' );
sp_assert_same( 'merge_a', $rows[0]['id'] ?? null, 'the listed row is the current user\'s own backup' );

// duplicate_names comes back from get_recent_backups() as a PHP array; the
// table format must flatten it to a string rather than handing format_items()
// a cell it cannot render ("Array to string conversion").
sp_assert( is_string( $rows[0]['duplicate_names'] ?? null ), 'duplicate_names is flattened to a string for --format=table' );
sp_assert_same( 'Player 2', $rows[0]['duplicate_names'] ?? null, 'the flattened duplicate_names names the duplicate' );

// get_recent_backups() itself always carries user_id now (needed so an
// --all-users listing can show who owns each row); this test's own stub
// format_items() logs full rows regardless of the $fields list backups_list()
// passes, so which columns a real `wp` invocation would actually print is
// covered by reading the source, not asserted here — see test 6 below for the
// --all-users case this column exists for.
sp_assert_same( 7, $rows[0]['user_id'] ?? null, 'the row still carries its real owner, whether or not it is printed as a column' );

/* 3. Without edit_sp_players at all, listing even your own backups refuses. */
sp_test_cli_reset();
$GLOBALS['sp_denied_caps'] = array( 'edit_sp_players' );

$threw = null;
try {
	( new SP_Merge_CLI_Backups() )->list( array(), array() );
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}
sp_assert( null !== $threw, 'lacking edit_sp_players refuses even a self-scoped list' );

/* 4. --user targeting another user without delete_sp_players refuses via resolve_target_user(). */
sp_test_cli_reset();
$GLOBALS['sp_denied_caps']         = array( 'delete_sp_players' );
$GLOBALS['spm_cli_users']['id:42'] = 42;

$threw = null;
try {
	( new SP_Merge_CLI_Backups() )->list( array(), array( 'user' => '42' ) );
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}
sp_assert( null !== $threw, '--user targeting another user without delete_sp_players refuses' );
sp_assert_contains( 'Administrator', $threw ? $threw->getMessage() : '', 'the refusal names the required tier' );

/* 5. --status filters the (already fetched) rows client-side. */
sp_test_cli_reset();
sp_test_seed_backup( 'merge_active', sp_test_backup_payload( 3, 4 ), 'active', null, null, 7 );
sp_test_seed_backup( 'merge_reverted', sp_test_backup_payload( 5, 6 ), 'reverted', null, null, 7 );

( new SP_Merge_CLI_Backups() )->list( array(), array( 'status' => 'reverted' ) );
$rows = sp_test_last_log( 'table' );
sp_assert_same( 1, is_array( $rows ) ? count( $rows ) : null, '--status=reverted keeps only the reverted row' );
sp_assert_same( 'merge_reverted', $rows[0]['id'] ?? null, 'the surviving row is the reverted one' );

/* 6. --all-users, held by an admin, lists every owner's backups. */
sp_test_cli_reset();
sp_test_seed_backup( 'merge_owner_a', sp_test_backup_payload( 10, 11 ), 'active', null, null, 7 );
sp_test_seed_backup( 'merge_owner_b', sp_test_backup_payload( 20, 21 ), 'active', null, null, 42 );

( new SP_Merge_CLI_Backups() )->list( array(), array( 'all-users' => true ) );
$rows = sp_test_last_log( 'table' );
$ids  = is_array( $rows ) ? array_column( $rows, 'id' ) : array();
sort( $ids );
sp_assert_same( array( 'merge_owner_a', 'merge_owner_b' ), $ids, '--all-users lists backups from every owner' );

// --all-users is exactly the case an admin needs to see who owns what.
$owners = array();
foreach ( $rows as $row ) {
	$owners[ $row['id'] ] = $row['user_id'] ?? null;
}
sp_assert_same( 7, $owners['merge_owner_a'] ?? null, 'merge_owner_a is attributed to its real owner' );
sp_assert_same( 42, $owners['merge_owner_b'] ?? null, 'merge_owner_b is attributed to its real owner' );

/* -------------------------------------------------------------------------
 * backups delete
 * ---------------------------------------------------------------------- */

/* 7. delete always requires delete_sp_players, with no lower tier. */
sp_test_cli_reset();
$GLOBALS['sp_denied_caps'] = array( 'delete_sp_players' );

$threw = null;
try {
	( new SP_Merge_CLI_Backups() )->delete( array( 'merge_x' ), array( 'yes' => true ) );
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}
sp_assert( null !== $threw, 'deleting a backup without delete_sp_players refuses' );

/* 8. Held delete_sp_players, targeting another user's backup deletes it for real. */
sp_test_cli_reset();
$GLOBALS['spm_cli_users']['id:42'] = 42;
sp_test_seed_backup( 'merge_cross_user', sp_test_backup_payload( 30, 31 ), 'active', null, null, 42 );

( new SP_Merge_CLI_Backups() )->delete( array( 'merge_cross_user' ), array( 'user' => '42', 'yes' => true ) );

sp_assert_contains( 'permanently removes the only recovery path', (string) sp_test_last_log( 'warning' ), 'the reminder about losing the recovery path is printed' );
sp_assert_contains( '1 backup(s) deleted', (string) sp_test_last_log( 'success' ), 'the success message states the count deleted' );
sp_assert_same( 0, count( $GLOBALS['wpdb']->backups ), 'the targeted user\'s backup is actually gone' );

/* 9. Targeting another user's backup without delete_sp_players is unreachable —
 *    but delete already requires delete_sp_players outright, so this is really
 *    just confirming resolve_target_user() cannot be bypassed once inside. */
sp_test_cli_reset();
sp_test_seed_backup( 'merge_owner_only', sp_test_backup_payload( 40, 41 ), 'active', null, null, 7 );

$threw = null;
try {
	// Nonexistent target user id.
	( new SP_Merge_CLI_Backups() )->delete( array( 'merge_owner_only' ), array( 'user' => 'ghost', 'yes' => true ) );
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}
sp_assert( null !== $threw, 'an unresolvable --user value refuses rather than silently falling back to the caller' );
sp_assert_same( 1, count( $GLOBALS['wpdb']->backups ), 'nothing was deleted when the target user could not be resolved' );

/* 10. Missing backup IDs refuses with a usage message. */
sp_test_cli_reset();
$threw = null;
try {
	( new SP_Merge_CLI_Backups() )->delete( array(), array( 'yes' => true ) );
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}
sp_assert( null !== $threw, 'no backup IDs at all refuses with a usage message' );

sp_test_done();
