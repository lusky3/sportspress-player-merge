<?php
/**
 * Regression guard for update_event_references(): a same-event collision must
 * not leave the primary listed twice, and a failed $wpdb->update() must roll
 * back the merge rather than proceed to delete the duplicate player.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output.

require_once __DIR__ . '/bootstrap.php';

spm_test_header( 'same-event collision dedup and $wpdb->update() failure handling (regression)' );

/*
 * Scenario 1: the simple-meta rewrite issues one dedup DELETE per key it just
 * rewrote (sp_player, sp_offense, sp_defense) — the cleanup that stops an
 * event which already listed both players under the same key from ending up
 * with two identical rows after the merge.
 */
spm_reset();

$processor = new SP_Merge_Processor();

$GLOBALS['wpdb']->col_queue = array( array() ); // event_ids for the serialized-meta pass; none needed here.

spm_invoke( $processor, 'update_event_references', 100, 200 );

$dedup_deletes = array_values(
	array_filter(
		$GLOBALS['wpdb']->queries,
		static function ( $sql ) {
			return 0 === strpos( trim( $sql ), 'DELETE p1 FROM' );
		}
	)
);

spm_assert_equals(
	3,
	count( $dedup_deletes ),
	'a dedup DELETE is issued for each simple meta key (sp_player, sp_offense, sp_defense)'
);

foreach ( $dedup_deletes as $sql ) {
	spm_assert(
		false !== strpos( $sql, 'p1.meta_id > p2.meta_id' ),
		'the dedup DELETE keeps the lower meta_id — the row a backup would have captured first'
	);
}

/*
 * Scenario 2: a $wpdb->update() failure (a real DB error, not "0 rows
 * matched") must not be swallowed. Left unchecked, the merge would proceed to
 * delete the duplicate player with its event references silently orphaned.
 */
spm_reset(
	array(
		100 => array( 'sp_team' => array( '33' ) ),
		200 => array( 'sp_team' => array( '44' ) ),
	)
);

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

// get_col() order: event_ids for update_event_references(). The merge throws
// before reaching update_player_list_references()'s own get_col() call.
$GLOBALS['wpdb']->col_queue = array( array( 300 ) );

// The first $wpdb->update() call in a merge is update_event_references()'s
// rewrite of the 'sp_player' key — arm it to report a DB failure.
$GLOBALS['wpdb']->update_return_queue = array( false );

$processor = new SP_Merge_Processor();
$escaped   = null;

try {
	$result = $processor->execute_merge( 100, array( 200 ) );
} catch ( Throwable $e ) {
	$escaped = $e;
	$result  = null;
}

spm_assert( null === $escaped, 'the failure does not escape execute_merge()' . ( $escaped ? ' (escaped: ' . get_class( $escaped ) . ')' : '' ) );

if ( null === $escaped ) {
	spm_assert_equals( false, $result['success'], 'the merge reports failure' );
	spm_assert( in_array( 200, $GLOBALS['spm_deleted'], true ) === false, 'the duplicate player is NOT deleted when the reference rewrite failed' );
	spm_assert( in_array( 'ROLLBACK', $GLOBALS['wpdb']->queries, true ), 'ROLLBACK was issued' );
}

spm_test_summary();
