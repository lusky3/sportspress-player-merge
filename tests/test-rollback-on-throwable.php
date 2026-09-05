<?php
/**
 * Blockers 1 and 2 guard: a Throwable raised mid-merge must reach the rollback
 * path, and the backup must be retained rather than disqualified.
 *
 * In PHP 8 a TypeError / ValueError is a Throwable but not an Exception, so the
 * old `catch ( Exception $e )` let it escape past the ROLLBACK entirely. The old
 * catch block also called delete_backup(), which marks the row `reverted` — the
 * one status load_backup_data() refuses.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output.

require_once __DIR__ . '/bootstrap.php';

spm_test_header( 'a Throwable mid-merge rolls back and retains the backup (blockers 1, 2)' );

/**
 * Build the fixture used by the failure scenarios.
 */
function spm_seed_merge_fixture(): void {
	spm_reset(
		array(
			100 => array( 'sp_team' => array( '33' ) ),
			200 => array(
				'sp_team'   => array( '44' ),
				'spt_email' => array( 'player@example.test' ),
			),
			// Event referencing the duplicate.
			300 => array(
				'sp_players' => array(
					array( 9 => array( 200 => array( 'goals' => '1' ) ) ),
				),
			),
			// sp_list referencing the duplicate.
			400 => array(
				'sp_players' => array(
					array( 200 => array( 'number' => '17' ) ),
				),
			),
		)
	);

	// Both players are registered, published: execute_merge() re-validates its
	// own selection once it holds the merge lock, so a fixture that never
	// registered the primary would now be refused before the merge starts.
	$GLOBALS['spm_posts'][100] = (object) array(
		'ID'          => 100,
		'post_type'   => 'sp_player',
		'post_status' => 'publish',
	);
	$GLOBALS['spm_posts'][200] = (object) array(
		'ID'          => 200,
		'post_type'   => 'sp_player',
		'post_status' => 'publish',
	);

	// get_col() order: events for the duplicate, sp_lists, then events for the
	// primary during the success-path cache clear.
	$GLOBALS['wpdb']->col_queue = array(
		array( 300 ),
		array( 400 ),
		array( 300 ),
	);
}

/*
 * Scenario 1: TypeError raised while rewriting the sp_list, after the event meta
 * has already been written. Mirrors a third-party hook on update_post_meta()
 * raising an Error part way through the transaction.
 */
spm_seed_merge_fixture();
spm_throw_on( 'update_post_meta', 400, 'TypeError' );

$processor = new SP_Merge_Processor();
$escaped   = null;

try {
	$result = $processor->execute_merge( 100, array( 200 ) );
} catch ( Throwable $e ) {
	$escaped = $e;
	$result  = null;
}

spm_assert( null === $escaped, 'TypeError does not escape execute_merge()' . ( $escaped ? ' (escaped: ' . get_class( $escaped ) . ')' : '' ) );

if ( null === $escaped ) {
	spm_assert_equals( false, $result['success'], 'the merge reports failure' );
	spm_assert( in_array( 'ROLLBACK', $GLOBALS['wpdb']->queries, true ), 'ROLLBACK was issued' );
	spm_assert( ! in_array( 'COMMIT', $GLOBALS['wpdb']->queries, true ), 'COMMIT was not issued' );

	spm_assert_equals(
		array( 'create_merge_backup', 'mark_failed' ),
		spm_backup_calls(),
		'the backup is marked failed, never deleted and never activated'
	);

	spm_assert_equals( SPM_TEST_BACKUP_ID, $result['backup_id'] ?? null, 'the retained backup ID is returned' );
	spm_assert(
		false !== strpos( $result['message'], SPM_TEST_BACKUP_ID ),
		'the retained backup ID is surfaced in the operator-facing message'
	);

	// ROLLBACK does not undo update_post_meta()'s object-cache writes.
	foreach ( array( 100, 200, 300, 400 ) as $purged_id ) {
		spm_assert(
			in_array( 'post_meta:' . $purged_id, $GLOBALS['spm_cache_purge'], true ),
			"post_meta cache purged for {$purged_id}"
		);
	}

	spm_assert_equals( array(), $GLOBALS['spm_deleted'], 'no duplicate player was deleted' );
	spm_assert_equals( false, get_transient( 'sp_merge_lock' ), 'the merge lock is released' );
}

/*
 * Scenario 2: a plain Exception must still behave exactly as before.
 */
spm_seed_merge_fixture();
spm_throw_on( 'update_post_meta', 400, 'RuntimeException' );

$processor = new SP_Merge_Processor();
$result    = $processor->execute_merge( 100, array( 200 ) );

spm_assert_equals( false, $result['success'], 'an Exception still reports failure' );
spm_assert( in_array( 'ROLLBACK', $GLOBALS['wpdb']->queries, true ), 'an Exception still triggers ROLLBACK' );
spm_assert_equals(
	array( 'create_merge_backup', 'mark_failed' ),
	spm_backup_calls(),
	'an Exception also retains the backup as failed'
);

/*
 * Scenario 3: the success path promotes the pending backup to active after COMMIT.
 */
spm_seed_merge_fixture();

$processor = new SP_Merge_Processor();
$result    = $processor->execute_merge( 100, array( 200 ) );

spm_assert_equals( true, $result['success'], 'a clean merge succeeds' );
spm_assert( in_array( 'COMMIT', $GLOBALS['wpdb']->queries, true ), 'COMMIT was issued' );
spm_assert_equals(
	array( 'create_merge_backup', 'mark_active' ),
	spm_backup_calls(),
	'the backup is promoted to active only after COMMIT'
);
spm_assert_equals( array( 200 ), $GLOBALS['spm_deleted'], 'the duplicate player was deleted' );

spm_test_summary();
