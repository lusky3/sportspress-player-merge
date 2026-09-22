<?php
/**
 * SP_Merge_Backup::revert() must remove the `_wp_old_slug` / `_sp_merge_history`
 * rows the reverted merge wrote onto the primary — and only those rows. A
 * primary that has absorbed more than one duplicate carries one row per
 * duplicate under each key, and reverting one merge must not disturb the
 * others still standing.
 *
 * Forward-only rollout: a backup created before this feature shipped has no
 * matching rows on the primary at all. Reverting it must still succeed.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable

require_once __DIR__ . '/lib-backup-mocks.php';

echo "Merge-history cleanup on revert\n";

/**
 * Build a minimal, revertible backup payload for one duplicate merged into a
 * primary, capturing just enough post_data for recreate_player() to work.
 *
 * @param int    $primary_id Primary player ID.
 * @param int    $dup_id     Duplicate player ID.
 * @param string $dup_slug   The duplicate's slug before it was merged away.
 * @param string $dup_title  The duplicate's title before it was merged away.
 * @return array
 */
function sp_test_history_payload( int $primary_id, int $dup_id, string $dup_slug, string $dup_title ): array {
	return array(
		'primary_id'        => $primary_id,
		'duplicate_ids'     => array( $dup_id ),
		'primary_backup'    => array(
			'meta_data'  => array(),
			'taxonomies' => array(),
		),
		'duplicate_backups' => array(
			$dup_id => array(
				'post_data'  => array(
					'post_name'  => $dup_slug,
					'post_title' => $dup_title,
				),
				'meta_data'  => array(),
				'taxonomies' => array(),
			),
		),
		'affected_events'   => array(),
		'affected_lists'    => array(),
		'value_hashes'      => array(
			'events'  => array(),
			'lists'   => array(),
			'primary' => array(),
		),
	);
}

$backup = new SP_Merge_Backup();

/*
 * 1. The primary absorbed two duplicates in the past (200 and 999). Reverting
 * the backup for 200 must remove only 200's rows and leave 999's alone.
 *
 * value_hashes/post_hashes are computed the same way a real merge produces
 * them: value_hashes captured before anything is written (here, before either
 * duplicate's history rows exist), post_hashes captured after — mirroring
 * mark_active() running after SP_Merge_Processor::record_merge_history() has
 * already written onto the primary. Skipping this and leaving both empty (as
 * a payload with nothing else to compare would) makes find_values_changed_since_merge()
 * flag the history rows themselves as an unexplained post-merge change, which
 * a real merge never triggers since its post_hashes already accounts for them.
 */
sp_test_reset();

$data = sp_test_history_payload( 100, 200, 'old-duplicate-slug', 'Old Duplicate' );
$data['value_hashes'] = sp_test_invoke( $backup, 'compute_value_hashes', array( $data ) );

// What 999's earlier merge, and this merge's own record_merge_history() call,
// actually wrote onto the primary.
sp_test_add_meta( 100, '_wp_old_slug', 'old-duplicate-slug' );
sp_test_add_meta( 100, '_wp_old_slug', 'other-duplicate-slug' );
sp_test_add_meta(
	100,
	'_sp_merge_history',
	array(
		'duplicate_id' => 200,
		'title'        => 'Old Duplicate',
		'slug'         => 'old-duplicate-slug',
		'merged_at'    => '2026-09-01 00:00:00',
		'backup_id'    => 'merge_1700000100_aaaaaaaa',
	)
);
sp_test_add_meta(
	100,
	'_sp_merge_history',
	array(
		'duplicate_id' => 999,
		'title'        => 'Other Duplicate',
		'slug'         => 'other-duplicate-slug',
		'merged_at'    => '2026-08-01 00:00:00',
		'backup_id'    => 'merge_1699999999_bbbbbbbb',
	)
);

$post_hashes = sp_test_invoke( $backup, 'compute_value_hashes', array( $data ) );

sp_test_seed_backup(
	'merge_1700000100_aaaaaaaa',
	$data,
	'active',
	array( 100, 200 ),
	$post_hashes
);

$GLOBALS['sp_insert_post_id'] = 200;

$result = $backup->revert( 'merge_1700000100_aaaaaaaa' );

sp_assert_same( true, $result['success'] ?? false, 'the revert succeeds' );
sp_assert_same(
	array( 'other-duplicate-slug' ),
	get_post_meta( 100, '_wp_old_slug' ),
	"the reverted duplicate's old-slug row is removed, the other duplicate's is kept"
);

$remaining = get_post_meta( 100, '_sp_merge_history' );
sp_assert_same( 1, count( $remaining ), 'exactly one history row remains' );
sp_assert_same( 999, $remaining[0]['duplicate_id'] ?? null, "the surviving row is the OTHER duplicate's, not the reverted one" );

/*
 * 2. A backup created before this feature shipped (forward-only rollout) has
 * no matching rows on the primary at all. Reverting it must not error.
 */
sp_test_reset();

sp_test_seed_backup(
	'merge_1700000200_cccccccc',
	sp_test_history_payload( 300, 400, 'legacy-duplicate-slug', 'Legacy Duplicate' ),
	'active',
	array( 300, 400 )
);

$GLOBALS['sp_insert_post_id'] = 400;

$result = $backup->revert( 'merge_1700000200_cccccccc' );

sp_assert_same( true, $result['success'] ?? false, 'reverting a pre-feature backup with no history rows still succeeds' );
sp_assert_same( array(), get_post_meta( 300, '_wp_old_slug' ), 'no old-slug row is created out of nowhere' );
sp_assert_same( array(), get_post_meta( 300, '_sp_merge_history' ), 'no history row is created out of nowhere' );

sp_test_done();
