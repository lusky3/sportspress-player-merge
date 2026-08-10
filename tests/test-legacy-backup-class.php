<?php
/**
 * Blocker 1 guard: a version-mismatched backup class must degrade, not fatal.
 *
 * If classes/class-sp-merge-backup.php predates the status contract there is no
 * mark_failed() / mark_active(). The processor's method_exists() guards must skip
 * those calls — and must never fall back to delete_backup(), which would
 * disqualify the backup exactly when it is needed.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output.

define( 'SPM_LEGACY_BACKUP', true );

require_once __DIR__ . '/bootstrap.php';

spm_test_header( 'a backup class without mark_* degrades safely (blocker 1)' );

spm_assert( ! method_exists( 'SP_Merge_Backup', 'mark_failed' ), 'fixture backup class has no mark_failed()' );

spm_reset(
	array(
		100 => array( 'sp_team' => array( '33' ) ),
		200 => array( 'sp_team' => array( '44' ) ),
		400 => array(
			'sp_players' => array(
				array( 200 => array( 'number' => '17' ) ),
			),
		),
	)
);

$GLOBALS['spm_posts'][200]  = (object) array(
	'ID'        => 200,
	'post_type' => 'sp_player',
);
$GLOBALS['wpdb']->col_queue = array(
	array(),
	array( 400 ),
	array(),
);

spm_throw_on( 'update_post_meta', 400, 'TypeError' );

$processor = new SP_Merge_Processor();
$escaped   = null;

try {
	$result = $processor->execute_merge( 100, array( 200 ) );
} catch ( Throwable $e ) {
	$escaped = $e;
	$result  = null;
}

spm_assert( null === $escaped, 'no fatal from the missing mark_failed()' );

if ( null === $escaped ) {
	spm_assert_equals( false, $result['success'], 'the merge reports failure' );
	spm_assert( in_array( 'ROLLBACK', $GLOBALS['wpdb']->queries, true ), 'ROLLBACK was still issued' );
	spm_assert_equals(
		array( 'create_merge_backup' ),
		spm_backup_calls(),
		'delete_backup() is never used as a fallback — the row is left intact'
	);
	spm_assert_equals( SPM_TEST_BACKUP_ID, $result['backup_id'] ?? null, 'the retained backup ID is still returned' );
}

spm_test_summary();
