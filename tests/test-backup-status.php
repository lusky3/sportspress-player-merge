<?php
/**
 * Blocker 1: a failed merge must leave a loadable backup behind.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable

require_once __DIR__ . '/lib-backup-mocks.php';

echo "Backup status lifecycle\n";

$payload = array(
	'primary_id'        => 42,
	'duplicate_ids'     => array( 43 ),
	'primary_backup'    => array( 'meta_data' => array() ),
	'duplicate_backups' => array(),
	'affected_events'   => array(),
	'affected_lists'    => array(),
);

$backup = new SP_Merge_Backup();

/* 1. A pending backup is loadable, and mark_active() promotes it. */
sp_test_reset();
sp_test_seed_backup( 'merge_1700000001_aaaaaaaa', $payload, 'pending', array( 42, 43 ) );

sp_assert(
	is_array( sp_test_invoke( $backup, 'load_backup_data', array( 'merge_1700000001_aaaaaaaa' ) ) ),
	'pending backup is loadable'
);
sp_assert_same( true, $backup->mark_active( 'merge_1700000001_aaaaaaaa' ), 'mark_active() promotes a pending backup' );
sp_assert_same( 'active', $GLOBALS['wpdb']->backups[0]['status'], 'status is active after mark_active()' );
sp_assert_same( false, $backup->mark_active( 'merge_1700000001_aaaaaaaa' ), 'mark_active() is a no-op once promoted' );

/* 2. mark_failed() keeps the backup loadable. */
sp_test_reset();
sp_test_seed_backup( 'merge_1700000002_bbbbbbbb', $payload, 'pending', array( 42, 43 ) );

sp_assert_same( true, $backup->mark_failed( 'merge_1700000002_bbbbbbbb' ), 'mark_failed() flags the backup' );
sp_assert_same( 'failed', $GLOBALS['wpdb']->backups[0]['status'], 'status is failed' );
sp_assert(
	is_array( sp_test_invoke( $backup, 'load_backup_data', array( 'merge_1700000002_bbbbbbbb' ) ) ),
	'a failed backup is still loadable'
);

/* 3. The processor's failure path must not disqualify the backup. */
sp_test_reset();
sp_test_seed_backup( 'merge_1700000003_cccccccc', $payload, 'pending', array( 42, 43 ) );
$GLOBALS['sp_user_meta']['sp_last_merge_backup'] = 'merge_1700000003_cccccccc';

$backup->delete_backup( 'merge_1700000003_cccccccc' );

sp_assert(
	is_array( sp_test_invoke( $backup, 'load_backup_data', array( 'merge_1700000003_cccccccc' ) ) ),
	'delete_backup() (called when a merge throws) leaves the backup loadable'
);
sp_assert_same( 'failed', $GLOBALS['wpdb']->backups[0]['status'], 'delete_backup() records failed, not reverted' );
sp_assert_same(
	'merge_1700000003_cccccccc',
	$GLOBALS['sp_user_meta']['sp_last_merge_backup'] ?? '',
	'the last-backup pointer survives so Revert Last Merge still works'
);

/* 4. A reverted backup stays refused. */
sp_test_reset();
sp_test_seed_backup( 'merge_1700000004_dddddddd', $payload, 'reverted', array( 42, 43 ) );

sp_assert_same(
	null,
	sp_test_invoke( $backup, 'load_backup_data', array( 'merge_1700000004_dddddddd' ) ),
	'a reverted backup is not loadable'
);
sp_assert_same( false, $backup->mark_failed( 'merge_1700000004_dddddddd' ), 'mark_failed() will not resurrect a reverted backup' );

/* 5. Backup-ID validation and user scoping are unchanged. */
sp_test_reset();
sp_test_seed_backup( 'merge_1700000005_eeeeeeee', $payload, 'active', array( 42 ), null, 99 );

sp_assert_same(
	null,
	sp_test_invoke( $backup, 'load_backup_data', array( 'merge_1700000005_eeeeeeee' ) ),
	'another user\'s backup is not loadable'
);
sp_assert_same(
	null,
	sp_test_invoke( $backup, 'load_backup_data', array( "merge_1' OR 1=1 --" ) ),
	'a malformed backup ID is rejected'
);

sp_test_done();
