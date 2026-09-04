<?php
/**
 * Task 2: cross-user backup access.
 *
 * A later WP-CLI command needs to list, revert, or delete a backup belonging
 * to a user other than the one currently acting, gated by delete_sp_players.
 * revert(), delete_backups() and get_recent_backups() must accept an explicit
 * owner without changing behavior for every existing caller, which never
 * passes one.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable

require_once __DIR__ . '/lib-backup-mocks.php';
require_once dirname( __DIR__ ) . '/classes/class-sp-merge-admin.php';

echo "Cross-user backup access\n";

/**
 * Build a minimal, revertible backup payload for a given primary/duplicate pair.
 *
 * Empty affected_events/affected_lists and pre-populated (empty) value_hashes
 * keep both the dependency guard and the changed-values guard out of the way,
 * so a revert() against this payload succeeds without needing $force.
 *
 * @param int $primary_id Primary player ID.
 * @param int $dup_id     Duplicate player ID.
 * @return array
 */
function sp_test_cross_user_payload( int $primary_id, int $dup_id ): array {
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
		'value_hashes'      => array(
			'events'  => array(),
			'lists'   => array(),
			'primary' => array(),
		),
	);
}

$backup = new SP_Merge_Backup();
$admin  = new SP_Merge_Admin();

/* -------------------------------------------------------------------------
 * revert()
 * ---------------------------------------------------------------------- */

/* 1. No owner override still reaches the current user's own backup. */
sp_test_reset();
sp_test_seed_backup( 'merge_1700001000_aaaaaaaa', sp_test_cross_user_payload( 10, 11 ), 'active', array( 10, 11 ), null, 7 );

$result = $backup->revert( 'merge_1700001000_aaaaaaaa' );

sp_assert_same( true, $result['success'], 'revert() with no owner override still reaches the current user\'s own backup' );
sp_assert_same( 'reverted', $GLOBALS['wpdb']->backups[0]['status'], 'the current user\'s row is marked reverted' );

/* 2. No owner override cannot reach a backup owned by someone else. */
sp_test_reset();
sp_test_seed_backup( 'merge_1700002000_bbbbbbbb', sp_test_cross_user_payload( 20, 21 ), 'active', array( 20, 21 ), null, 42 );

$result = $backup->revert( 'merge_1700002000_bbbbbbbb' );

sp_assert_same( false, $result['success'], 'revert() with no owner override cannot reach another user\'s backup' );
sp_assert_same( 'not_found', $result['code'] ?? '', 'the refusal is not_found' );
sp_assert_same( 'active', $GLOBALS['wpdb']->backups[0]['status'], 'the other user\'s backup is untouched' );

/* 3. An explicit owner reaches that user's backup. */
sp_test_reset();
sp_test_seed_backup( 'merge_1700003000_cccccccc', sp_test_cross_user_payload( 30, 31 ), 'active', array( 30, 31 ), null, 42 );

$result = $backup->revert( 'merge_1700003000_cccccccc', false, 42 );

sp_assert_same( true, $result['success'], 'revert() with an explicit owner reaches a different user\'s backup' );
sp_assert_same( 'reverted', $GLOBALS['wpdb']->backups[0]['status'], 'that owner\'s row is marked reverted' );

/* 4. An explicit owner does not reach a backup that doesn't exist under that owner. */
sp_test_reset();
sp_test_seed_backup( 'merge_1700004000_dddddddd', sp_test_cross_user_payload( 40, 41 ), 'active', array( 40, 41 ), null, 7 );

$result = $backup->revert( 'merge_1700004000_dddddddd', false, 42 );

sp_assert_same( false, $result['success'], 'revert() with an explicit owner does not reach a backup owned by someone else' );
sp_assert_same( 'not_found', $result['code'] ?? '', 'the refusal is not_found, not a permissions error' );
sp_assert_same( 'active', $GLOBALS['wpdb']->backups[0]['status'], 'the backup owned by a different user is untouched' );

/* -------------------------------------------------------------------------
 * delete_backups()
 * ---------------------------------------------------------------------- */

/* 5. No owner override still deletes the current user's own backup. */
sp_test_reset();
sp_test_seed_backup( 'merge_1700005000_eeeeeeee', sp_test_cross_user_payload( 50, 51 ), 'active', array( 50, 51 ), null, 7 );

$deleted = $backup->delete_backups( array( 'merge_1700005000_eeeeeeee' ) );

sp_assert_same( 1, $deleted, 'delete_backups() with no owner override deletes the current user\'s own backup' );
sp_assert_same( 0, count( $GLOBALS['wpdb']->backups ), 'the row is gone' );

/* 6. No owner override cannot delete a backup owned by someone else. */
sp_test_reset();
sp_test_seed_backup( 'merge_1700006000_ffffffff', sp_test_cross_user_payload( 60, 61 ), 'active', array( 60, 61 ), null, 42 );

$deleted = $backup->delete_backups( array( 'merge_1700006000_ffffffff' ) );

sp_assert_same( 0, $deleted, 'delete_backups() with no owner override cannot delete another user\'s backup' );
sp_assert_same( 1, count( $GLOBALS['wpdb']->backups ), 'the other user\'s row survives' );

/* 7. An explicit owner deletes that user's backup. */
sp_test_reset();
sp_test_seed_backup( 'merge_1700007000_gggggggg', sp_test_cross_user_payload( 70, 71 ), 'active', array( 70, 71 ), null, 42 );

$deleted = $backup->delete_backups( array( 'merge_1700007000_gggggggg' ), 42 );

sp_assert_same( 1, $deleted, 'delete_backups() with an explicit owner deletes a different user\'s backup' );
sp_assert_same( 0, count( $GLOBALS['wpdb']->backups ), 'the row is gone' );

/* 8. An explicit owner does not delete a backup that doesn't exist under that owner. */
sp_test_reset();
sp_test_seed_backup( 'merge_1700008000_hhhhhhhh', sp_test_cross_user_payload( 80, 81 ), 'active', array( 80, 81 ), null, 7 );

$deleted = $backup->delete_backups( array( 'merge_1700008000_hhhhhhhh' ), 42 );

sp_assert_same( 0, $deleted, 'delete_backups() with an explicit owner does not reach a backup owned by someone else' );
sp_assert_same( 1, count( $GLOBALS['wpdb']->backups ), 'the untouched owner\'s row survives' );

/* -------------------------------------------------------------------------
 * SP_Merge_Admin::get_recent_backups()
 * ---------------------------------------------------------------------- */

sp_test_reset();
sp_test_seed_backup( 'merge_1700010000_a1a1a1a1', sp_test_cross_user_payload( 200, 201 ), 'active', array( 200, 201 ), null, 7 );
sp_test_seed_backup( 'merge_1700010010_b2b2b2b2', sp_test_cross_user_payload( 210, 211 ), 'active', array( 210, 211 ), null, 42 );
sp_test_seed_backup( 'merge_1700010020_c3c3c3c3', sp_test_cross_user_payload( 220, 221 ), 'active', array( 220, 221 ), null, 99 );

/* 9. Neither $user_id nor $all_users given: only the current user's own rows. */
$own = $admin->get_recent_backups();

sp_assert_same( 1, count( $own ), 'get_recent_backups() with no args returns only the current user\'s backups' );
sp_assert_same( 'merge_1700010000_a1a1a1a1', $own[0]['id'] ?? '', 'the current user\'s own backup is the one returned' );

/* 10. An explicit $user_id scopes to that owner, not the current user. */
$other = $admin->get_recent_backups( user_id: 42 );

sp_assert_same( 1, count( $other ), 'get_recent_backups() with an explicit user_id returns only that owner\'s backups' );
sp_assert_same( 'merge_1700010010_b2b2b2b2', $other[0]['id'] ?? '', 'the requested owner\'s backup is the one returned' );

/* 11. $all_users = true returns rows across every seeded owner. */
$all     = $admin->get_recent_backups( all_users: true );
$all_ids = $all ? array_column( $all, 'id' ) : array();
sort( $all_ids );

sp_assert_same( 3, count( $all ), 'get_recent_backups( all_users: true ) returns backups from every owner' );
sp_assert_same(
	array( 'merge_1700010000_a1a1a1a1', 'merge_1700010010_b2b2b2b2', 'merge_1700010020_c3c3c3c3' ),
	$all_ids,
	'all three owners\' backups are present'
);

/* 12. $all_users = true wins even when $user_id is also passed. */
$all_with_user     = $admin->get_recent_backups( user_id: 42, all_users: true );
$all_with_user_ids = $all_with_user ? array_column( $all_with_user, 'id' ) : array();
sort( $all_with_user_ids );

sp_assert_same( $all_ids, $all_with_user_ids, 'all_users ignores a simultaneously-passed user_id' );

sp_test_done();
