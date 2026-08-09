<?php
/**
 * Blocker 6: revert must refuse to overwrite values edited after the merge.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable

require_once __DIR__ . '/lib-backup-mocks.php';

echo "Revert change-since-merge guard\n";

/**
 * Build a backup captured before a merge, then apply the merge's own changes.
 *
 * @param SP_Merge_Backup $backup Backup manager.
 * @return array{0: array, 1: array} Backup data and post-merge hashes.
 */
function sp_test_case( SP_Merge_Backup $backup ): array {
	sp_test_reset();

	// Pre-merge world: event 500 lists both players, 42 is the primary.
	sp_test_add_meta( 500, 'sp_players', array( 42 => array( 'goals' => '1' ), 43 => array( 'goals' => '2' ) ) );
	sp_test_add_meta( 42, 'sp_leagues', array( 1 => array( 'games' => '5' ) ) );
	sp_test_add_meta( 42, 'spt_email', 'captain@example.com' );
	sp_test_add_meta( 42, '_edit_lock', '1700000000:7' );

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

	// The merge itself: 43's goals fold into 42, and 43's photo lands on 42.
	update_post_meta( 500, 'sp_players', array( 42 => array( 'goals' => '3' ) ) );
	sp_test_add_meta( 42, '_thumbnail_id', '999' );

	$post_hashes = sp_test_invoke( $backup, 'compute_value_hashes', array( $data ) );

	return array( $data, $post_hashes );
}

$backup = new SP_Merge_Backup();

/* 1. Nothing changed since the merge: revert proceeds. */
list( $data, $post_hashes ) = sp_test_case( $backup );
sp_test_seed_backup( 'merge_1700000200_aaaaaaaa', $data, 'active', array( 42, 43, 500 ), $post_hashes );

$result = $backup->revert( 'merge_1700000200_aaaaaaaa' );

sp_assert_same( true, $result['success'], 'a revert of untouched data is allowed' );
sp_assert_same(
	array( 42 => array( 'goals' => '1' ), 43 => array( 'goals' => '2' ) ),
	get_post_meta( 500, 'sp_players', true ),
	'the pre-merge event lineup is restored'
);
sp_assert_same( 'reverted', $GLOBALS['wpdb']->backups[0]['status'], 'the backup is marked reverted' );

/* 2. A score sheet entered after the merge blocks the revert. */
list( $data, $post_hashes ) = sp_test_case( $backup );
update_post_meta( 500, 'sp_players', array( 42 => array( 'goals' => '3', 'assists' => '4' ) ) );
sp_test_seed_backup( 'merge_1700000210_bbbbbbbb', $data, 'active', array( 42, 43, 500 ), $post_hashes );

$result = $backup->revert( 'merge_1700000210_bbbbbbbb' );

sp_assert_same( false, $result['success'], 'a value changed after the merge blocks the revert' );
sp_assert_contains( 'post 500 (sp_players)', $result['message'] ?? '', 'the changed post and key are named' );
sp_assert_contains( 'override', $result['message'] ?? '', 'the operator is told an override exists' );
sp_assert_same(
	array( 42 => array( 'goals' => '3', 'assists' => '4' ) ),
	get_post_meta( 500, 'sp_players', true ),
	'the post-merge value is left untouched by the refused revert'
);

/* 3. The override flag lets the operator proceed deliberately. */
list( $data, $post_hashes ) = sp_test_case( $backup );
update_post_meta( 500, 'sp_players', array( 42 => array( 'goals' => '3', 'assists' => '4' ) ) );
sp_test_seed_backup( 'merge_1700000220_cccccccc', $data, 'active', array( 42, 43, 500 ), $post_hashes );

$result = $backup->revert( 'merge_1700000220_cccccccc', true );

sp_assert_same( true, $result['success'], 'the override flag forces the revert through' );

/* 4. Player meta edited after the merge blocks the revert too. */
list( $data, $post_hashes ) = sp_test_case( $backup );
update_post_meta( 42, 'spt_email', 'new-address@example.com' );
sp_test_seed_backup( 'merge_1700000230_dddddddd', $data, 'active', array( 42, 43, 500 ), $post_hashes );

$result = $backup->revert( 'merge_1700000230_dddddddd' );

sp_assert_same( false, $result['success'], 'player meta changed after the merge blocks the revert' );
sp_assert_contains( 'post 42 (spt_email)', $result['message'] ?? '', 'the changed player meta key is named' );

/* 5. Editor lock churn is ignored - it is on the deny list. */
list( $data, $post_hashes ) = sp_test_case( $backup );
update_post_meta( 42, '_edit_lock', '1700009999:9' );
sp_test_seed_backup( 'merge_1700000240_eeeeeeee', $data, 'active', array( 42, 43, 500 ), $post_hashes );

$result = $backup->revert( 'merge_1700000240_eeeeeeee' );

sp_assert_same( true, $result['success'], 'WordPress-internal meta churn does not block the revert' );

/* 6. A backup with no recorded hashes fails closed. */
list( $data, $post_hashes ) = sp_test_case( $backup );
unset( $data['value_hashes'] );
sp_test_seed_backup( 'merge_1700000250_ffffffff', $data, 'active', array( 42, 43, 500 ) );

$result = $backup->revert( 'merge_1700000250_ffffffff' );

sp_assert_same( false, $result['success'], 'a backup predating hash tracking fails closed' );
sp_assert_contains( 'cannot be verified', $result['message'] ?? '', 'the operator is told why it cannot be verified' );

sp_test_done();
