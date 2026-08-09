<?php
/**
 * Blocker 3 guard: non-sp_ player meta must survive a merge, WordPress internals
 * must not be copied.
 *
 * The old `if ( 0 !== strpos( $key, 'sp_' ) ) { continue; }` allow-list discarded
 * spt_email / spt_skill / spt_captain from the sibling sportspress-player-tools
 * plugin — the same spt_email the scanner reads to justify the merge.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output.

require_once __DIR__ . '/bootstrap.php';

spm_test_header( 'third-party meta survives, WP internals do not (blocker 3)' );

/*
 * Scenario 1: the primary lacks the third-party fields entirely.
 */
spm_reset(
	array(
		100 => array(
			'sp_team'       => array( '33' ),
			'_edit_lock'    => array( '1754700000:1' ),
			'_thumbnail_id' => array( '55' ),
		),
		200 => array(
			'spt_email'                      => array( 'player@example.test' ),
			'spt_skill'                      => array( '4' ),
			'spt_captain'                    => array( 'yes' ),
			'my_third_party_field'           => array( 'keep me' ),
			'_edit_lock'                     => array( '1754800000:9' ),
			'_edit_last'                     => array( '9' ),
			'_wp_old_slug'                   => array( 'stale-slug' ),
			'_wp_trash_meta_time'            => array( '1754800000' ),
			'_wp_trash_meta_status'          => array( 'publish' ),
			'_wp_trash_meta_comments_status' => array( 'a:0:{}' ),
			'_thumbnail_id'                  => array( '88' ),
		),
	)
);

$processor = new SP_Merge_Processor();
spm_invoke( $processor, 'merge_meta_data', 100, 200 );

spm_assert_equals( array( 'player@example.test' ), get_post_meta( 100, 'spt_email' ), 'spt_email is carried over' );
spm_assert_equals( array( '4' ), get_post_meta( 100, 'spt_skill' ), 'spt_skill is carried over' );
spm_assert_equals( array( 'yes' ), get_post_meta( 100, 'spt_captain' ), 'spt_captain is carried over' );
spm_assert_equals( array( 'keep me' ), get_post_meta( 100, 'my_third_party_field' ), 'unknown third-party meta is carried over' );

spm_assert_equals( array( '1754700000:1' ), get_post_meta( 100, '_edit_lock' ), '_edit_lock is left alone on the primary' );
spm_assert_equals( array(), get_post_meta( 100, '_edit_last' ), '_edit_last is not copied' );
spm_assert_equals( array(), get_post_meta( 100, '_wp_old_slug' ), '_wp_old_slug is not copied' );
spm_assert_equals( array(), get_post_meta( 100, '_wp_trash_meta_time' ), '_wp_trash_meta_time is not copied' );
spm_assert_equals( array(), get_post_meta( 100, '_wp_trash_meta_status' ), '_wp_trash_meta_status is not copied' );
spm_assert_equals( array(), get_post_meta( 100, '_wp_trash_meta_comments_status' ), '_wp_trash_meta_comments_status is not copied' );

// _thumbnail_id is owned by merge_featured_image(); copying it here would leave
// the primary with two competing rows.
spm_assert_equals( array( '55' ), get_post_meta( 100, '_thumbnail_id' ), '_thumbnail_id is not copied (merge_featured_image owns it)' );

/*
 * Scenario 2: the primary already holds its own value. Third-party fields are
 * single-valued by convention, so the duplicate must not add a competing row.
 */
spm_reset(
	array(
		100 => array( 'spt_email' => array( 'primary@example.test' ) ),
		200 => array( 'spt_email' => array( 'duplicate@example.test' ) ),
	)
);

$processor = new SP_Merge_Processor();
spm_invoke( $processor, 'merge_meta_data', 100, 200 );

spm_assert_equals(
	array( 'primary@example.test' ),
	get_post_meta( 100, 'spt_email' ),
	"the primary's own spt_email wins and is not duplicated"
);

/*
 * Scenario 3: merge_featured_image() still copies the image when the primary has
 * none, and still leaves exactly one row.
 */
spm_reset(
	array(
		100 => array(),
		200 => array( '_thumbnail_id' => array( '88' ) ),
	)
);

$processor = new SP_Merge_Processor();
spm_invoke( $processor, 'merge_meta_data', 100, 200 );
spm_invoke( $processor, 'merge_featured_image', 100, 200 );

spm_assert_equals(
	array( '88' ),
	get_post_meta( 100, '_thumbnail_id' ),
	'featured image is copied exactly once when the primary has none'
);

/*
 * Scenario 4: the explicit skip list is still honoured.
 */
spm_reset(
	array(
		100 => array(),
		200 => array(
			'sp_columns' => array( array( 'goals' ) ),
			'sp_number'  => array( '17' ),
		),
	)
);

$processor = new SP_Merge_Processor();
spm_invoke( $processor, 'merge_meta_data', 100, 200 );

spm_assert_equals( array(), get_post_meta( 100, 'sp_columns' ), 'sp_columns is still skipped' );
spm_assert_equals( array(), get_post_meta( 100, 'sp_number' ), 'sp_number is still skipped' );

spm_test_summary();
