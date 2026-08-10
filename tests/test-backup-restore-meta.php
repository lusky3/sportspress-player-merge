<?php
/**
 * Revert must restore every captured meta key, not just the sp_ prefixed ones,
 * and must remove a featured image the merge copied onto the primary.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable

require_once __DIR__ . '/lib-backup-mocks.php';

echo "Player meta restore\n";

sp_test_reset();

$backup = new SP_Merge_Backup();

// What the primary looked like before the merge.
$captured = array(
	'sp_leagues'    => array( maybe_serialize( array( 1 => array( 'games' => '5' ) ) ) ),
	'spt_email'     => array( 'captain@example.com' ),
	'spt_skill'     => array( 'B' ),
	'_edit_lock'    => array( '1700000000:7' ),
	'_wp_old_slug'  => array( 'john-smith-2' ),
	'third_party'   => array( 'keep-me' ),
);

// What the merge left behind: the duplicate's email, photo and an extra league row.
sp_test_add_meta( 42, 'sp_leagues', array( 1 => array( 'games' => '9' ) ) );
sp_test_add_meta( 42, 'spt_email', 'duplicate@example.com' );
sp_test_add_meta( 42, 'spt_skill', 'C' );
sp_test_add_meta( 42, '_thumbnail_id', '999' );
sp_test_add_meta( 42, 'third_party', 'clobbered' );
sp_test_add_meta( 42, '_edit_lock', '1700005555:9' );
sp_test_add_meta( 42, '_wp_old_slug', 'john-smith-3' );

sp_test_invoke(
	$backup,
	'restore_player_data',
	array(
		42,
		array(
			'meta_data'  => $captured,
			'taxonomies' => array(),
		),
	)
);

sp_assert_same( 'captain@example.com', get_post_meta( 42, 'spt_email', true ), 'spt_email is restored' );
sp_assert_same( 'B', get_post_meta( 42, 'spt_skill', true ), 'spt_skill is restored' );
sp_assert_same( 'keep-me', get_post_meta( 42, 'third_party', true ), 'third-party meta is restored' );
sp_assert_same(
	array( 1 => array( 'games' => '5' ) ),
	get_post_meta( 42, 'sp_leagues', true ),
	'sp_ meta is still restored'
);
sp_assert_same(
	array(),
	get_post_meta( 42, '_thumbnail_id' ),
	'the featured image the merge copied over is removed'
);
sp_assert_same(
	'1700005555:9',
	get_post_meta( 42, '_edit_lock', true ),
	'the WordPress editor lock is left alone'
);
sp_assert_same(
	'john-smith-3',
	get_post_meta( 42, '_wp_old_slug', true ),
	'post-merge slug redirects are left alone'
);
sp_assert_same( 1, count( get_post_meta( 42, 'spt_email' ) ), 'restored meta is not duplicated' );

/* A captured featured image is put back. */
sp_test_reset();

sp_test_add_meta( 43, 'sp_leagues', array( 1 => array( 'games' => '1' ) ) );

sp_test_invoke(
	$backup,
	'restore_player_data',
	array(
		43,
		array(
			'meta_data'  => array( '_thumbnail_id' => array( '555' ) ),
			'taxonomies' => array(),
		),
	)
);

sp_assert_same( '555', get_post_meta( 43, '_thumbnail_id', true ), 'a captured featured image is restored' );

sp_test_done();
