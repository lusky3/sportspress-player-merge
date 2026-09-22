<?php
/**
 * SP_Merge_Processor::record_merge_history() writes a redirect-slug row and an
 * audit-trail row onto the primary before each duplicate is deleted, and
 * accumulates across repeated merges rather than clobbering.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output.

require_once __DIR__ . '/bootstrap.php';

spm_test_header( 'record_merge_history() writes the redirect slug and audit row' );

/* 1. A single call writes exactly one row of each, with the right fields. */
spm_reset();

$duplicate = (object) array(
	'ID'         => 200,
	'post_name'  => 'john-smith-2',
	'post_title' => 'John Smith',
);

$processor = new SP_Merge_Processor();
spm_invoke( $processor, 'record_merge_history', 100, $duplicate, 'merge_abc123' );

spm_assert_equals(
	array( 'john-smith-2' ),
	get_post_meta( 100, '_wp_old_slug' ),
	"the duplicate's old slug is written onto the primary, for WordPress core's own redirect"
);

$history = get_post_meta( 100, '_sp_merge_history' );
spm_assert_equals( 1, count( $history ), 'exactly one history row is written' );
spm_assert_equals(
	array(
		'duplicate_id' => 200,
		'title'        => 'John Smith',
		'slug'         => 'john-smith-2',
		'merged_at'    => gmdate( 'Y-m-d H:i:s' ),
		'backup_id'    => 'merge_abc123',
	),
	$history[0] ?? null,
	'the history row carries the duplicate identity, slug, title, timestamp and backup ID'
);

/* 2. A duplicate with no slug writes no redirect row, but still writes history. */
spm_reset();

$no_slug = (object) array(
	'ID'         => 201,
	'post_name'  => '',
	'post_title' => 'No Slug Player',
);

$processor = new SP_Merge_Processor();
spm_invoke( $processor, 'record_merge_history', 100, $no_slug, 'merge_def456' );

spm_assert_equals( array(), get_post_meta( 100, '_wp_old_slug' ), 'no redirect row is written for a blank slug' );
spm_assert_equals( 1, count( get_post_meta( 100, '_sp_merge_history' ) ), 'the history row is still written' );

/* 3. A post missing post_name/post_title entirely (an incomplete fixture, or a
 * third-party filter that stripped it) degrades to blank fields, not a fatal
 * error reading an undefined property.
 */
spm_reset();

$processor = new SP_Merge_Processor();
spm_invoke(
	$processor,
	'record_merge_history',
	100,
	(object) array( 'ID' => 202 ),
	'merge_ghi789'
);

spm_assert_equals( array(), get_post_meta( 100, '_wp_old_slug' ), 'no redirect row is written when post_name is absent' );
$history = get_post_meta( 100, '_sp_merge_history' );
spm_assert_equals( '', $history[0]['slug'] ?? null, 'the missing slug degrades to an empty string' );
spm_assert_equals( '', $history[0]['title'] ?? null, 'the missing title degrades to an empty string' );

/* 4. Two merges into the same primary accumulate; the second does not clobber
 * the first. add_post_meta(), not update_post_meta(), is what this depends on.
 */
spm_reset();

$processor = new SP_Merge_Processor();
spm_invoke(
	$processor,
	'record_merge_history',
	100,
	(object) array(
		'ID'         => 201,
		'post_name'  => 'first-dup',
		'post_title' => 'First Duplicate',
	),
	'merge_first'
);
spm_invoke(
	$processor,
	'record_merge_history',
	100,
	(object) array(
		'ID'         => 202,
		'post_name'  => 'second-dup',
		'post_title' => 'Second Duplicate',
	),
	'merge_second'
);

spm_assert_equals(
	array( 'first-dup', 'second-dup' ),
	get_post_meta( 100, '_wp_old_slug' ),
	'both old slugs are kept — the second merge does not overwrite the first'
);

$history = get_post_meta( 100, '_sp_merge_history' );
spm_assert_equals( 2, count( $history ), 'both history rows are kept' );
spm_assert_equals( 201, $history[0]['duplicate_id'] ?? null, 'the first entry names the first duplicate' );
spm_assert_equals( 202, $history[1]['duplicate_id'] ?? null, 'the second entry names the second duplicate' );

/* 5. End-to-end through execute_merge() itself — the real hook point, not just
 * the helper in isolation.
 */
spm_reset(
	array(
		100 => array(),
		200 => array(),
	)
);
$GLOBALS['spm_posts'][100] = (object) array(
	'ID'          => 100,
	'post_type'   => 'sp_player',
	'post_status' => 'publish',
	'post_name'   => 'primary-player',
	'post_title'  => 'Primary Player',
);
$GLOBALS['spm_posts'][200] = (object) array(
	'ID'          => 200,
	'post_type'   => 'sp_player',
	'post_status' => 'publish',
	'post_name'   => 'duplicate-player',
	'post_title'  => 'Duplicate Player',
);

$processor = new SP_Merge_Processor();
$result    = $processor->execute_merge( 100, array( 200 ) );

spm_assert_equals( true, $result['success'] ?? null, 'the merge succeeds' );
spm_assert_equals(
	array( 'duplicate-player' ),
	get_post_meta( 100, '_wp_old_slug' ),
	'execute_merge() itself writes the redirect row, not just the helper in isolation'
);

$history = get_post_meta( 100, '_sp_merge_history' );
spm_assert_equals( 1, count( $history ), 'execute_merge() writes exactly one history row' );
spm_assert_equals( 200, $history[0]['duplicate_id'] ?? null, 'the history row names the merged duplicate' );
spm_assert_equals(
	SPM_TEST_BACKUP_ID,
	$history[0]['backup_id'] ?? null,
	'the history row carries the real backup ID the merge produced'
);

spm_test_summary();
