<?php
/**
 * Wave 1 gave revert() a hardened refusal path but the UI still called it with
 * one argument, so a backup that legitimately needed forcing could not be
 * reverted at all. The UI now offers an override, but only after a refusal and
 * only for the one refusal an override may bypass.
 *
 * This pins the contract the AJAX layer keys on: which refusals are forcible.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable

require_once __DIR__ . '/lib-backup-mocks.php';

echo "Revert refusal codes / override scope\n";

/**
 * Build a backup payload touching the given event IDs.
 *
 * @param int   $primary_id Primary player ID.
 * @param int   $dup_id     Duplicate player ID.
 * @param int[] $event_ids  Affected event IDs.
 * @return array
 */
function sp_test_force_payload( int $primary_id, int $dup_id, array $event_ids ): array {
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

/**
 * Build a backup whose captured event value has been edited since the merge.
 *
 * @param SP_Merge_Backup $backup Backup manager.
 * @return array{0: array, 1: array} Backup data and post-merge hashes.
 */
function sp_test_changed_case( SP_Merge_Backup $backup ): array {
	sp_test_reset();

	sp_test_add_meta( 500, 'sp_players', array( 42 => array( 'goals' => '1' ), 43 => array( 'goals' => '2' ) ) );

	$data = array(
		'primary_id'        => 42,
		'duplicate_ids'     => array( 43 ),
		'primary_backup'    => array(
			'meta_data'  => get_post_meta( 42 ),
			'taxonomies' => array(),
		),
		'duplicate_backups' => array(),
		'affected_events'   => array(
			500 => array( 'sp_players' => get_post_meta( 500, 'sp_players', true ) ),
		),
		'affected_lists'    => array(),
	);

	$data['value_hashes'] = sp_test_invoke( $backup, 'compute_value_hashes', array( $data ) );

	// The merge.
	update_post_meta( 500, 'sp_players', array( 42 => array( 'goals' => '3' ) ) );
	$post_hashes = sp_test_invoke( $backup, 'compute_value_hashes', array( $data ) );

	// A score sheet entered afterwards, by a human.
	update_post_meta( 500, 'sp_players', array( 42 => array( 'goals' => '3', 'assists' => '4' ) ) );

	return array( $data, $post_hashes );
}

$backup = new SP_Merge_Backup();

/* 1. The changed-values refusal is tagged forcible. */
list( $data, $post_hashes ) = sp_test_changed_case( $backup );
sp_test_seed_backup( 'merge_1700000300_aaaaaaaa', $data, 'active', array( 42, 43, 500 ), $post_hashes );

$result = $backup->revert( 'merge_1700000300_aaaaaaaa' );

sp_assert_same( false, $result['success'], 'a value changed after the merge blocks the revert' );
sp_assert_same( 'values_changed', $result['code'] ?? '', 'the refusal is tagged values_changed so the UI can offer the override' );

/* 2. That refusal, and only that refusal, is cleared by the override. */
list( $data, $post_hashes ) = sp_test_changed_case( $backup );
sp_test_seed_backup( 'merge_1700000310_bbbbbbbb', $data, 'active', array( 42, 43, 500 ), $post_hashes );

$result = $backup->revert( 'merge_1700000310_bbbbbbbb', true );

sp_assert_same( true, $result['success'], 'the override clears the changed-values refusal' );
sp_assert_same(
	array( 42 => array( 'goals' => '1' ), 43 => array( 'goals' => '2' ) ),
	get_post_meta( 500, 'sp_players', true ),
	'the forced revert restores the pre-merge value'
);

/* 3. A dependency conflict is tagged separately and is NOT forcible. */
sp_test_reset();
sp_test_seed_backup( 'merge_1700000320_cccccccc', sp_test_force_payload( 10, 11, array( 500, 501 ) ), 'active', array( 10, 11, 500, 501 ) );
sp_test_seed_backup( 'merge_1700000330_dddddddd', sp_test_force_payload( 20, 21, array( 501, 502 ) ), 'active', array( 20, 21, 501, 502 ) );

$result = $backup->revert( 'merge_1700000320_cccccccc' );

sp_assert_same( false, $result['success'], 'an overlapping later merge blocks the revert' );
sp_assert_same( 'conflict', $result['code'] ?? '', 'the dependency refusal is tagged conflict, not values_changed' );

$forced = $backup->revert( 'merge_1700000320_cccccccc', true );

sp_assert_same( false, $forced['success'], 'the override does not bypass the dependency guard' );
sp_assert_same( 'conflict', $forced['code'] ?? '', 'a forced dependency refusal is still a conflict' );
sp_assert_same( 'active', $GLOBALS['wpdb']->backups[0]['status'], 'the refused backup keeps its status' );

/* 4. A missing backup is tagged so the UI never offers an override for it. */
sp_test_reset();

$result = $backup->revert( 'merge_1700000340_eeeeeeee' );

sp_assert_same( false, $result['success'], 'an unknown backup cannot be reverted' );
sp_assert_same( 'not_found', $result['code'] ?? '', 'an unknown backup is tagged not_found' );

sp_test_done();
