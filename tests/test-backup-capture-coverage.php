<?php
/**
 * Regression guard: backup_affected_event_meta() must discover every post
 * SP_Merge_Processor::update_event_references() can rewrite, not just posts
 * with an sp_player row.
 *
 * update_event_references() rewrites sp_player, sp_offense AND sp_defense
 * with no post_id restriction, so a post referencing the duplicate only
 * through sp_offense/sp_defense — or only inside serialized meta
 * (sp_players/sp_timeline/sp_order/sp_stars) — was previously rewritten by
 * the merge and never captured here, leaving a revert unable to restore it.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable

require_once __DIR__ . '/lib-backup-mocks.php';

echo "Backup capture covers the merge's full simple-meta write surface\n";

sp_test_reset();

// A post referencing the duplicate ONLY via sp_offense (no sp_player row at all).
sp_test_add_meta( 501, 'sp_offense', '200' );

// A post referencing the duplicate ONLY via sp_defense.
sp_test_add_meta( 502, 'sp_defense', '200' );

// A post referencing the duplicate ONLY inside serialized sp_players meta —
// no simple meta row of any kind.
sp_test_add_meta( 503, 'sp_players', array( 9 => array( 200 => array( 'goals' => '1' ) ) ) );

// A control post that references an unrelated player — must not be captured.
sp_test_add_meta( 504, 'sp_offense', '999' );

$backup = new SP_Merge_Backup();
$affected = sp_test_invoke( $backup, 'backup_affected_event_meta', array( array( 200 ) ) );

sp_assert( isset( $affected[501] ), 'a post referencing the duplicate only via sp_offense is captured' );
sp_assert( isset( $affected[502] ), 'a post referencing the duplicate only via sp_defense is captured' );
sp_assert( isset( $affected[503] ), 'a post referencing the duplicate only inside serialized sp_players meta is captured' );
sp_assert( ! isset( $affected[504] ), 'a post referencing an unrelated player is not captured' );

sp_assert(
	isset( $affected[501]['_simple_sp_offense'] ) && '200' === $affected[501]['_simple_sp_offense'][0]['meta_value'],
	'the captured sp_offense row still records the original meta_value'
);
sp_assert(
	isset( $affected[503]['sp_players'] ),
	'the captured serialized sp_players value is the original structure'
);

sp_test_done();
