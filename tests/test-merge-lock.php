<?php
/**
 * One lock covers everything that rewrites merged player data, and the merge
 * re-checks its own selection once it holds that lock.
 *
 * Before `wp sp-merge batch` existed, a merge and a revert overlapping was a
 * theoretical race: one human, one browser tab. A batch now runs unattended for
 * a long time while a revert is one line in another shell, so
 * SP_Merge_Backup::revert() takes the same SP_Merge_Lock that
 * SP_Merge_Processor::execute_merge() takes, and refuses with code `locked`
 * rather than restoring event meta underneath a running merge.
 *
 * The same window shows up inside a merge: the caller validates the selection
 * before the lock is acquired, so a concurrent operation can delete a duplicate
 * in between. The delete loop's `if ( ! $post ) { continue; }` would then
 * silently no-op and the merge would report success having done nothing.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable

require_once __DIR__ . '/lib-cli-mocks.php';

echo "Shared merge lock and post-lock re-validation\n";

/**
 * Register (or replace) a published player post.
 *
 * @param int    $id   Player ID.
 * @param string $name Post title.
 */
function sp_test_lock_set_player( int $id, string $name ): void {
	$GLOBALS['sp_posts'][ $id ] = (object) array(
		'ID'          => $id,
		'post_type'   => 'sp_player',
		'post_title'  => $name,
		'post_status' => 'publish',
	);
}

/**
 * A minimal revertible backup payload.
 *
 * @param int $primary_id Primary player ID.
 * @param int $dup_id     Duplicate player ID.
 * @return array
 */
function sp_test_lock_payload( int $primary_id, int $dup_id ): array {
	return array(
		'primary_id'        => $primary_id,
		'duplicate_ids'     => array( $dup_id ),
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

/* -------------------------------------------------------------------------
 * 1. A revert refuses, with code `locked`, while the merge lock is held.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_seed_backup( 'merge_1700001000_aaaaaaaa', sp_test_lock_payload( 60, 61 ), 'active', array( 60, 61 ) );

// Somebody else — a batch row mid-merge — holds the lock right now.
set_transient( SP_Merge_Lock::LOCK_KEY, 99, 300 );

$result = ( new SP_Merge_Backup() )->revert( 'merge_1700001000_aaaaaaaa' );

sp_assert_same( false, $result['success'], 'a revert refuses while a merge holds the lock' );
sp_assert_same( 'locked', $result['code'] ?? null, 'the refusal is coded `locked`' );
sp_assert_same( 'active', $GLOBALS['wpdb']->backups[0]['status'], 'the backup is untouched — nothing was restored' );
sp_assert_same( 99, get_transient( SP_Merge_Lock::LOCK_KEY ), "the refused revert did not steal or release somebody else's lock" );

/* --force cannot override it: it overrides a human's later edits, not another
 * process writing the same rows right now. */
$forced = ( new SP_Merge_Backup() )->revert( 'merge_1700001000_aaaaaaaa', true );
sp_assert_same( 'locked', $forced['code'] ?? null, 'a `locked` refusal is not forcible' );

/* The CLI surfaces it as a plain error, exactly as it does a conflict. */
$threw = null;
try {
	( new SP_Merge_CLI() )->revert( array( 'merge_1700001000_aaaaaaaa' ), array( 'force' => true, 'yes' => true ) );
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}
sp_assert( null !== $threw, 'wp sp-merge revert reports the locked refusal as an error' );
sp_assert_contains( 'already in progress', $threw ? $threw->getMessage() : '', 'the operator is told to wait and retry' );

/* -------------------------------------------------------------------------
 * 2. With the lock free, the same revert completes — and leaves the lock free
 *    again, so a following merge is not blocked by it.
 * ---------------------------------------------------------------------- */

delete_transient( SP_Merge_Lock::LOCK_KEY );

$result = ( new SP_Merge_Backup() )->revert( 'merge_1700001000_aaaaaaaa' );

sp_assert_same( true, $result['success'], 'the same revert succeeds once the lock is free' );
sp_assert_same( 'reverted', $GLOBALS['wpdb']->backups[0]['status'], 'the backup row is marked reverted' );
sp_assert_same( false, get_transient( SP_Merge_Lock::LOCK_KEY ), 'the revert released the lock it took' );

/* -------------------------------------------------------------------------
 * 3. A merge refuses while the lock is held, and does not create a backup.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_lock_set_player( 100, 'Primary Player' );
sp_test_lock_set_player( 200, 'Duplicate Player' );

set_transient( SP_Merge_Lock::LOCK_KEY, 99, 300 );

$merge = ( new SP_Merge_Processor() )->execute_merge( 100, array( 200 ) );

sp_assert_same( false, $merge['success'], 'a merge refuses while the lock is held' );
sp_assert_contains( 'Another merge is in progress', (string) ( $merge['message'] ?? '' ), 'the refusal explains why' );
sp_assert_same( 0, count( $GLOBALS['wpdb']->backups ), 'no backup was created by the refused merge' );
sp_assert_same( array(), $GLOBALS['sp_deleted_posts'], 'and no duplicate was deleted' );
sp_assert_same( 99, get_transient( SP_Merge_Lock::LOCK_KEY ), "the refused merge left the holder's lock alone" );

/* -------------------------------------------------------------------------
 * 4. A selection that stopped being valid between validation and the lock is
 *    refused, not merged into nothing.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_lock_set_player( 100, 'Primary Player' );
sp_test_lock_set_player( 200, 'Duplicate Player' );

$validated = SP_Merge_Validation::validate_merge_selection( 100, array( 200 ) );
sp_assert_same( true, $validated['valid'], 'the selection validates while both players exist' );

// A concurrent operation — another batch run, or an admin merge in a browser —
// gets there first and deletes the duplicate.
unset( $GLOBALS['sp_posts'][200] );

$merge = ( new SP_Merge_Processor() )->execute_merge( $validated['primary_id'], $validated['duplicate_ids'] );

sp_assert_same( false, $merge['success'], 'the merge fails rather than silently merging a post that is gone' );
sp_assert_contains(
	'not found or not published',
	(string) ( $merge['message'] ?? '' ),
	'the failure says the selection is no longer valid'
);
sp_assert_same( 0, count( $GLOBALS['wpdb']->backups ), 'nothing was backed up for a merge that never ran' );
sp_assert_same( array(), $GLOBALS['sp_deleted_posts'], 'and nothing was deleted' );
sp_assert_same( false, get_transient( SP_Merge_Lock::LOCK_KEY ), 'the lock is released on the re-validation refusal' );

/* A still-valid selection is unaffected: the re-check is not in the way of a
 * normal merge. */
sp_test_cli_reset();
sp_test_lock_set_player( 100, 'Primary Player' );
sp_test_lock_set_player( 200, 'Duplicate Player' );

$merge = ( new SP_Merge_Processor() )->execute_merge( 100, array( 200 ) );

sp_assert_same( true, $merge['success'], 'a selection that is still valid merges as before' );
sp_assert( in_array( 200, $GLOBALS['sp_deleted_posts'], true ), 'the duplicate was really merged and deleted' );
sp_assert_same( false, get_transient( SP_Merge_Lock::LOCK_KEY ), 'the successful merge released the lock' );

sp_test_done();
