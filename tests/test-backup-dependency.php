<?php
/**
 * Blocker 5: reverting an older merge must not silently rewind newer ones.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable

require_once __DIR__ . '/lib-backup-mocks.php';

echo "Revert ordering / overlap guard\n";

/**
 * Build a backup payload touching the given event IDs.
 *
 * @param int   $primary_id Primary player ID.
 * @param int   $dup_id     Duplicate player ID.
 * @param int[] $event_ids  Affected event IDs.
 * @return array
 */
function sp_test_payload( int $primary_id, int $dup_id, array $event_ids ): array {
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
		'value_hashes'      => array(
			'events'  => array(),
			'lists'   => array(),
			'primary' => array(),
		),
	);
}

$backup = new SP_Merge_Backup();

/* 1. Overlapping later backup blocks the revert and is named. */
sp_test_reset();
sp_test_seed_backup( 'merge_1700000010_aaaaaaaa', sp_test_payload( 10, 11, array( 500, 501 ) ), 'active', array( 10, 11, 500, 501 ) );
sp_test_seed_backup( 'merge_1700000020_bbbbbbbb', sp_test_payload( 20, 21, array( 501, 502 ) ), 'active', array( 20, 21, 501, 502 ) );

$result = $backup->revert( 'merge_1700000010_aaaaaaaa' );

sp_assert_same( false, $result['success'], 'reverting the older overlapping backup is refused' );
sp_assert_contains( 'must be reverted first', $result['message'] ?? '', 'the operator is told to revert the newer merge first' );
sp_assert_contains( 'merge_1700000020_bbbbbbbb', $result['message'] ?? '', 'the conflicting backup is named' );
sp_assert_contains( '501', $result['message'] ?? '', 'the shared post is named' );
sp_assert_same( 'active', $GLOBALS['wpdb']->backups[0]['status'], 'the refused backup keeps its status' );

/* 2. A newer, non-overlapping backup does not block. */
sp_test_reset();
sp_test_seed_backup( 'merge_1700000030_cccccccc', sp_test_payload( 30, 31, array( 600 ) ), 'active', array( 30, 31, 600 ) );
sp_test_seed_backup( 'merge_1700000040_dddddddd', sp_test_payload( 40, 41, array( 700 ) ), 'active', array( 40, 41, 700 ) );

$result = $backup->revert( 'merge_1700000030_cccccccc' );

sp_assert_not_contains( 'must be reverted first', $result['message'] ?? '', 'a disjoint later backup does not block the revert' );

/* 3. A later backup that was already reverted does not block. */
sp_test_reset();
sp_test_seed_backup( 'merge_1700000050_eeeeeeee', sp_test_payload( 50, 51, array( 800 ) ), 'active', array( 50, 51, 800 ) );
sp_test_seed_backup( 'merge_1700000060_ffffffff', sp_test_payload( 60, 61, array( 800 ) ), 'reverted', array( 60, 61, 800 ) );

$result = $backup->revert( 'merge_1700000050_eeeeeeee' );

sp_assert_not_contains( 'must be reverted first', $result['message'] ?? '', 'an already-reverted later backup does not block' );

/* 4. A later backup created by a different user still blocks. */
sp_test_reset();
sp_test_seed_backup( 'merge_1700000070_gggggggg', sp_test_payload( 70, 71, array( 900 ) ), 'active', array( 70, 71, 900 ) );
sp_test_seed_backup( 'merge_1700000080_hhhhhhhh', sp_test_payload( 80, 81, array( 900 ) ), 'active', array( 80, 81, 900 ), null, 99 );

$result = $backup->revert( 'merge_1700000070_gggggggg' );

sp_assert_contains( 'merge_1700000080_hhhhhhhh', $result['message'] ?? '', 'a later backup owned by another user still blocks' );

/* 5. A later row whose touched set cannot be determined fails closed. */
sp_test_reset();
sp_test_seed_backup( 'merge_1700000090_iiiiiiii', sp_test_payload( 90, 91, array( 950 ) ), 'active', array( 90, 91, 950 ) );
sp_test_seed_backup( 'merge_1700000100_jjjjjjjj', sp_test_payload( 100, 101, array( 950 ) ), 'active', null );
$GLOBALS['wpdb']->backups[1]['backup_data'] = 'not-json';

$result = $backup->revert( 'merge_1700000090_iiiiiiii' );

sp_assert_same( false, $result['success'], 'an unreadable later backup fails closed' );
sp_assert_contains( 'contents unreadable', $result['message'] ?? '', 'the unreadable backup is reported' );

/* 6. A pre-existing row with no touched set is back-filled from its own data. */
sp_test_reset();
sp_test_seed_backup( 'merge_1700000110_kkkkkkkk', sp_test_payload( 110, 111, array( 960 ) ), 'active', null );
sp_test_seed_backup( 'merge_1700000120_llllllll', sp_test_payload( 120, 121, array( 960 ) ), 'active', null );

$result = $backup->revert( 'merge_1700000110_kkkkkkkk' );

sp_assert_contains( 'merge_1700000120_llllllll', $result['message'] ?? '', 'overlap is detected for rows written before the column existed' );
sp_assert(
	null !== $GLOBALS['wpdb']->backups[0]['touched_posts'],
	'the derived touched-post set is back-filled onto the row'
);

sp_test_done();
