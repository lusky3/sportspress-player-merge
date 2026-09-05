<?php
/**
 * Regression guard: cleanup_old_backups() must never delete a `failed`
 * backup, and its cutoff must be computed from the same clock created_at
 * was written with (current_time('mysql')), not the database server's own
 * NOW() — which can differ from the site's configured timezone.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable

require_once __DIR__ . '/lib-backup-mocks.php';

echo "Backup retention: failed backups survive, old active ones don't\n";

sp_test_reset();

$payload = array(
	'primary_id'        => 1,
	'duplicate_ids'     => array( 2 ),
	'primary_backup'    => array( 'meta_data' => array() ),
	'duplicate_backups' => array(),
	'affected_events'   => array(),
	'affected_lists'    => array(),
);

$old_date    = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( ( SP_MERGE_BACKUP_RETENTION_DAYS + 1 ) * DAY_IN_SECONDS ) );
$recent_date = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - DAY_IN_SECONDS );

sp_test_seed_backup( 'merge_old_active', $payload, 'active', null, null, 7, $old_date );
sp_test_seed_backup( 'merge_old_failed', $payload, 'failed', null, null, 7, $old_date );
sp_test_seed_backup( 'merge_old_reverted', $payload, 'reverted', null, null, 7, $old_date );
sp_test_seed_backup( 'merge_recent_active', $payload, 'active', null, null, 7, $recent_date );

$backup = new SP_Merge_Backup();
sp_test_invoke( $backup, 'cleanup_old_backups' );

$remaining = array_column( $GLOBALS['wpdb']->backups, 'backup_id' );

sp_assert( ! in_array( 'merge_old_active', $remaining, true ), 'an old ACTIVE backup is deleted' );
sp_assert( ! in_array( 'merge_old_reverted', $remaining, true ), 'an old REVERTED backup is deleted' );
sp_assert( in_array( 'merge_old_failed', $remaining, true ), 'an old FAILED backup survives — it is precisely the recovery point retention must not destroy' );
sp_assert( in_array( 'merge_recent_active', $remaining, true ), 'a recent backup within the retention window survives regardless of status' );

sp_test_done();
