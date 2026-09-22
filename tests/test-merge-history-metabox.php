<?php
/**
 * SP_Merge_History_Metabox only appears on a player that actually has merge
 * history, and renders every field escaped since it all came from a duplicate
 * player's user-entered title/slug at merge time.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Mocks must use the real WordPress function names.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Mocks mirror documented WordPress signatures.

require_once __DIR__ . '/bootstrap.php';

function esc_html__( $text, $domain = '' ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

$GLOBALS['spm_registered_meta_boxes'] = array();

function add_meta_box( $id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default' ) {
	$GLOBALS['spm_registered_meta_boxes'][] = array(
		'id'       => $id,
		'title'    => $title,
		'screen'   => $screen,
		'context'  => $context,
		'priority' => $priority,
	);
}

function mysql2date( $format, $date, $translate = true ) {
	if ( empty( $date ) ) {
		return '';
	}
	$timestamp = strtotime( (string) $date );
	if ( false === $timestamp ) {
		return (string) $date;
	}
	return gmdate( $format, $timestamp );
}

function get_option( $name, $default = false ) {
	if ( 'date_format' === $name ) {
		return 'Y-m-d';
	}
	if ( 'time_format' === $name ) {
		return 'H:i';
	}
	return $default;
}

require_once dirname( __DIR__ ) . '/classes/class-sp-merge-history-metabox.php';

spm_test_header( 'SP_Merge_History_Metabox registration and rendering' );

/* 1. A non-sp_player post type is ignored entirely. */
spm_reset();
$GLOBALS['spm_registered_meta_boxes'] = array();

$box = new SP_Merge_History_Metabox();
$box->register_meta_box( 'post', (object) array( 'ID' => 1 ) );

spm_assert_equals( array(), $GLOBALS['spm_registered_meta_boxes'], 'a non-sp_player screen registers nothing' );

/* 2. A player with no merge history gets no box — no clutter on the common case. */
spm_reset();
$GLOBALS['spm_registered_meta_boxes'] = array();

$box->register_meta_box( 'sp_player', (object) array( 'ID' => 100 ) );

spm_assert_equals( array(), $GLOBALS['spm_registered_meta_boxes'], 'a player with no history registers no box' );

/* 3. A player with merge history gets the box, on the right screen. */
spm_reset();
$GLOBALS['spm_registered_meta_boxes'] = array();
add_post_meta( 100, '_sp_merge_history', array( 'duplicate_id' => 200, 'title' => 'Dup', 'slug' => 'dup', 'merged_at' => '2026-09-01 10:00:00', 'backup_id' => 'merge_x' ) );

$box->register_meta_box( 'sp_player', (object) array( 'ID' => 100 ) );

spm_assert_equals( 1, count( $GLOBALS['spm_registered_meta_boxes'] ), 'a player with history registers exactly one box' );
spm_assert_equals( 'sp_player', $GLOBALS['spm_registered_meta_boxes'][0]['screen'] ?? null, 'the box is registered on the sp_player screen' );

/* 4. render() with no entries. */
spm_reset();
ob_start();
$box->render( (object) array( 'ID' => 100 ) );
$output = ob_get_clean();

spm_assert( false !== strpos( $output, 'No merge history recorded.' ), 'an empty history renders the empty-state message' );

/* 5. render() escapes a title containing HTML, and shows the duplicate ID and date. */
spm_reset();
add_post_meta(
	100,
	'_sp_merge_history',
	array(
		'duplicate_id' => 200,
		'title'        => '<script>alert(1)</script>',
		'slug'         => 'evil-slug',
		'merged_at'    => '2026-09-01 10:00:00',
		'backup_id'    => 'merge_x',
	)
);

ob_start();
$box->render( (object) array( 'ID' => 100 ) );
$output = ob_get_clean();

spm_assert( false === strpos( $output, '<script>' ), 'a raw <script> tag from a merged player title never reaches the page' );
spm_assert( false !== strpos( $output, '&lt;script&gt;' ), 'the title is HTML-escaped instead' );
spm_assert( false !== strpos( $output, '#200' ), 'the duplicate ID is shown' );
spm_assert( false !== strpos( $output, '2026-09-01 10:00' ), 'the merge date is shown, formatted' );

/* 6. render() with multiple entries shows all of them, newest first. */
spm_reset();
add_post_meta(
	100,
	'_sp_merge_history',
	array(
		'duplicate_id' => 200,
		'title'        => 'Older Duplicate',
		'slug'         => 'older-dup',
		'merged_at'    => '2026-08-01 10:00:00',
		'backup_id'    => 'merge_old',
	)
);
add_post_meta(
	100,
	'_sp_merge_history',
	array(
		'duplicate_id' => 201,
		'title'        => 'Newer Duplicate',
		'slug'         => 'newer-dup',
		'merged_at'    => '2026-09-01 10:00:00',
		'backup_id'    => 'merge_new',
	)
);

ob_start();
$box->render( (object) array( 'ID' => 100 ) );
$output = ob_get_clean();

spm_assert(
	strpos( $output, 'Newer Duplicate' ) < strpos( $output, 'Older Duplicate' ),
	'entries are shown newest-merged first'
);
spm_assert( false !== strpos( $output, 'Older Duplicate' ), 'the older entry is still shown, not dropped' );

spm_test_summary();
