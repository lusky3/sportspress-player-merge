<?php
/**
 * Player recreation must fail loudly when WordPress ignores import_id.
 *
 * WordPress only honours import_id while the ID is free; otherwise the post is
 * created under a new ID and every restored reference orphans.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable

require_once __DIR__ . '/lib-backup-mocks.php';

echo "recreate_player() ID assertion\n";

$backup = new SP_Merge_Backup();

$player_backup = array(
	'post_data'  => array(
		'post_title'  => 'John Smith',
		'post_status' => 'publish',
		'post_author' => 1,
	),
	'meta_data'  => array( 'sp_leagues' => array( maybe_serialize( array( 1 => array( 'games' => '5' ) ) ) ) ),
	'taxonomies' => array(),
);

/* 1. WordPress hands back a different ID: abort. */
sp_test_reset();
$GLOBALS['sp_insert_post_id'] = 777;

$thrown = null;
try {
	sp_test_invoke( $backup, 'recreate_player', array( 555, $player_backup ) );
} catch ( Throwable $e ) {
	$thrown = $e;
}

sp_assert( $thrown instanceof Exception, 'recreate_player() throws when the ID differs' );
sp_assert_contains( '777', $thrown ? $thrown->getMessage() : '', 'the assigned ID is reported' );
sp_assert_contains( '555', $thrown ? $thrown->getMessage() : '', 'the original ID is reported' );
sp_assert_same( array( 777 ), $GLOBALS['sp_deleted_posts'], 'the stray post is removed' );
sp_assert_same( array(), get_post_meta( 555 ), 'no meta is written to the original ID' );
sp_assert_same( array(), get_post_meta( 777 ), 'no meta is written to the new ID' );

/* 2. WordPress honours import_id: restore proceeds. */
sp_test_reset();
$GLOBALS['sp_insert_post_id'] = 555;

$returned = sp_test_invoke( $backup, 'recreate_player', array( 555, $player_backup ) );

sp_assert_same( 555, $returned, 'recreate_player() returns the original ID on success' );
sp_assert_same(
	array( 1 => array( 'games' => '5' ) ),
	get_post_meta( 555, 'sp_leagues', true ),
	'the recreated player gets its meta back'
);

/* 3. A WP_Error still throws. */
sp_test_reset();
$GLOBALS['sp_insert_post_id'] = new WP_Error( 'fail', 'nope' );

$thrown = null;
try {
	sp_test_invoke( $backup, 'recreate_player', array( 555, $player_backup ) );
} catch ( Throwable $e ) {
	$thrown = $e;
}

sp_assert( $thrown instanceof Exception, 'a WP_Error from wp_insert_post() still throws' );

sp_test_done();
