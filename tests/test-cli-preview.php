<?php
/**
 * `wp sp-merge preview` must expose the same data SP_Merge_Preview renders as
 * HTML — as a terminal report or, with --porcelain, a single OK/WARN line a
 * script can branch on.
 *
 * The array-field fixture mirrors tests/test-preview-array-conflicts.php: a
 * blank primary cell the duplicate fills, and a real disagreement the primary
 * wins, so the two tests can never quietly drift apart on what "a conflict"
 * means.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable

require_once __DIR__ . '/lib-cli-mocks.php';

echo "wp sp-merge preview\n";

/**
 * Register (or replace) a player post the get_post() mock will serve.
 *
 * @param int    $id   Player ID.
 * @param string $name Post title.
 */
function sp_test_set_player( int $id, string $name ): void {
	$GLOBALS['sp_posts'][ $id ] = (object) array(
		'ID'          => $id,
		'post_type'   => 'sp_player',
		'post_title'  => $name,
		'post_status' => 'publish',
	);
}

/**
 * The message of the last log entry at a given level.
 *
 * @param string $level Log level: log, warning, success, json, table, yaml...
 * @return mixed|null
 */
function sp_test_last_log( string $level = 'log' ) {
	foreach ( array_reverse( $GLOBALS['spm_cli_log'] ) as $entry ) {
		if ( $level === $entry['level'] ) {
			return $entry['message'];
		}
	}
	return null;
}

/**
 * Every logged message at a given level, joined, for substring assertions
 * against the whole non-porcelain report.
 *
 * @param string $level Log level.
 * @return string
 */
function sp_test_log_text( string $level = 'log' ): string {
	$lines = array();
	foreach ( $GLOBALS['spm_cli_log'] as $entry ) {
		if ( $level === $entry['level'] ) {
			$lines[] = is_string( $entry['message'] ) ? $entry['message'] : var_export( $entry['message'], true );
		}
	}
	return implode( "\n", $lines );
}

/* -------------------------------------------------------------------------
 * 1. A clean pair: --porcelain prints OK.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_set_player( 100, 'Primary Player' );
sp_test_set_player( 200, 'Duplicate Player' );

( new SP_Merge_CLI() )->preview( array( '100', '200' ), array( 'porcelain' => true ) );

sp_assert_same( 'OK', sp_test_last_log( 'log' ), '--porcelain prints OK for a clean pair' );
sp_assert_same( 1, count( $GLOBALS['spm_cli_log'] ), '--porcelain prints exactly one line and nothing else' );

/* -------------------------------------------------------------------------
 * 2. A same-event collision alone is enough for --porcelain to print WARN.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_set_player( 100, 'Primary Player' );
sp_test_set_player( 200, 'Duplicate Player' );
sp_test_seed_event( 9001, 100 );
sp_test_seed_event( 9001, 200 );

( new SP_Merge_CLI() )->preview( array( '100', '200' ), array( 'porcelain' => true ) );

sp_assert_same( 'WARN', sp_test_last_log( 'log' ), '--porcelain prints WARN when a same-event collision exists' );

/* -------------------------------------------------------------------------
 * 3. An array-field conflict alone is enough for --porcelain to print WARN,
 *    and the non-porcelain report names the resolved cells exactly.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_set_player( 100, 'Primary Player' );
sp_test_set_player( 200, 'Duplicate Player' );

sp_test_add_meta( 100, 'sp_statistics', array( 7 => array( 30 => array( 'goals' => '', 'assists' => '2' ) ) ) );
sp_test_add_meta( 100, 'sp_leagues', array( 7 => array( 30 => 0 ) ) );
sp_test_add_meta( 200, 'sp_statistics', array( 7 => array( 30 => array( 'goals' => '12', 'assists' => '<script>x</script>' ) ) ) );
sp_test_add_meta( 200, 'sp_leagues', array( 7 => array( 30 => 55 ) ) );

( new SP_Merge_CLI() )->preview( array( '100', '200' ), array( 'porcelain' => true ) );
sp_assert_same( 'WARN', sp_test_last_log( 'log' ), '--porcelain prints WARN when an array-field conflict exists' );

$GLOBALS['spm_cli_log'] = array();
( new SP_Merge_CLI() )->preview( array( '100', '200' ), array() );

$report = sp_test_log_text( 'log' ) . "\n" . sp_test_log_text( 'warning' );

sp_assert_contains( 'Primary Player', $report, 'the report names the primary player' );
sp_assert_contains( 'Duplicate Player', $report, 'the report names the duplicate player' );

// The gap the duplicate fills is named as such, with the value that will be used.
sp_assert_contains( 'sp_statistics[7][30][goals]', $report, 'the filled statistic cell is addressed exactly' );
sp_assert_contains( 'sp_leagues[7][30]', $report, 'the blank league cell is addressed exactly' );
sp_assert_contains( 'the duplicate\'s value "12" will be used', $report, "the filled cell says the duplicate's value will be used" );

// The conflict is reported as a discard, not as a union.
sp_assert_contains( 'sp_statistics[7][30][assists]', $report, 'the conflicting cell is addressed exactly' );
sp_assert_contains( 'keeping "2"', $report, "the conflict states the primary's kept value" );
sp_assert_contains( 'is discarded', $report, 'the conflict says the duplicate value is discarded' );

// Unlike the HTML preview, the CLI report does not escape values: this is a
// terminal, not a browser, and the raw value has to be recoverable as typed.
sp_assert_contains( '<script>x</script>', $report, 'the CLI report does not HTML-escape a value containing markup' );

/* -------------------------------------------------------------------------
 * 4. --format=json emits the whole structured payload, nested, via a single
 *    wp_json_encode() call rather than format_items()'s flat-row renderer.
 * ---------------------------------------------------------------------- */

$GLOBALS['spm_cli_log'] = array();
( new SP_Merge_CLI() )->preview( array( '100', '200' ), array( 'format' => 'json' ) );

$json    = sp_test_last_log( 'log' );
$decoded = json_decode( (string) $json, true );

sp_assert( is_array( $decoded ), '--format=json logs a single decodable JSON payload' );
sp_assert_same( 100, $decoded['primary']['id'] ?? null, 'the JSON payload carries the primary player id' );
sp_assert_same( 1, count( $decoded['array_field_conflicts'] ?? array() ), 'the JSON payload carries the conflict resolution' );
sp_assert_same( 2, count( $decoded['array_field_filled'] ?? array() ), 'the JSON payload carries both filled resolutions (goals and the blank league cell)' );

/* -------------------------------------------------------------------------
 * 4b. Event counts come from real sp_event posts only, and match what
 *     `wp sp-merge scan` reports for the same player.
 *
 *     sp_list posts carry 'sp_player' meta rows too (that is how SportsPress
 *     stores squad lists), so counting raw meta rows made preview's Events row
 *     disagree with scan's events column — and made two players who merely
 *     share a squad list look like a same-event collision, i.e. a spurious
 *     WARN out of --porcelain.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_set_player( 100, 'John Smith' );
sp_test_set_player( 200, 'John Smith' );

// A roster the duplicate scan can actually form a group from.
$GLOBALS['sp_scan_roster'] = array(
	(object) array( 'ID' => 100, 'post_title' => 'John Smith', 'post_type' => 'sp_player' ),
	(object) array( 'ID' => 200, 'post_title' => 'John Smith', 'post_type' => 'sp_player' ),
);
$GLOBALS['sp_scan_total']  = 2;

sp_test_seed_event( 9001, 100 );
sp_test_seed_event( 9002, 100 );
sp_test_seed_event( 9003, 200 );

// Both players sit on the same squad list — not an event, and not a collision.
sp_test_seed_list_membership( 7001, 100 );
sp_test_seed_list_membership( 7001, 200 );

$GLOBALS['spm_cli_log'] = array();
( new SP_Merge_CLI() )->preview( array( '100', '200' ), array( 'format' => 'json' ) );
$decoded = json_decode( (string) sp_test_last_log( 'log' ), true );

sp_assert_same( 2, $decoded['events']['primary'] ?? null, "the primary's event count excludes its sp_list membership" );
sp_assert_same( 1, $decoded['events']['duplicates'] ?? null, "the duplicate's event count excludes its sp_list membership" );
sp_assert_same( 3, $decoded['events']['result'] ?? null, 'the merged total is the sum of the two real event counts' );
sp_assert_same( 0, $decoded['collision_count'] ?? null, 'sharing a squad list is not a same-event collision' );

$GLOBALS['spm_cli_log'] = array();
( new SP_Merge_CLI() )->preview( array( '100', '200' ), array( 'porcelain' => true ) );
sp_assert_same( 'OK', sp_test_last_log( 'log' ), 'and so does not produce a spurious --porcelain WARN' );

// The same number, read the way `scan` reads it.
$GLOBALS['spm_cli_log'] = array();
( new SP_Merge_CLI() )->scan( array(), array() );

$scan_rows  = null;
foreach ( array_reverse( $GLOBALS['spm_cli_log'] ) as $entry ) {
	if ( 'table' === $entry['level'] ) {
		$scan_rows = $entry['message'];
		break;
	}
}
$scan_events = array();
foreach ( (array) $scan_rows as $row ) {
	$scan_events[ $row['player_id'] ] = $row['events'];
}

sp_assert_same( 2, $scan_events[100] ?? null, "scan reports the same event count for the primary as preview's Events row" );
sp_assert_same( 1, $scan_events[200] ?? null, 'scan reports the same event count for the duplicate' );

/* A real same-event collision is still detected. */
sp_test_cli_reset();
sp_test_set_player( 100, 'Primary Player' );
sp_test_set_player( 200, 'Duplicate Player' );
$GLOBALS['sp_posts'][9001] = (object) array(
	'ID'          => 9001,
	'post_type'   => 'sp_event',
	'post_title'  => 'Match Day',
	'post_status' => 'publish',
);
sp_test_seed_event( 9001, 100 );
sp_test_seed_event( 9001, 200 );

$GLOBALS['spm_cli_log'] = array();
( new SP_Merge_CLI() )->preview( array( '100', '200' ), array( 'format' => 'json' ) );
$decoded = json_decode( (string) sp_test_last_log( 'log' ), true );
sp_assert_same( 1, $decoded['collision_count'] ?? null, 'two players in one real sp_event are still a collision' );

/* -------------------------------------------------------------------------
 * 4c. A Throwable out of the preview walk degrades to a warning; `preview`
 *     still exits 0, as its documented contract requires.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_set_player( 100, 'Primary Player' );
sp_test_set_player( 200, 'Duplicate Player' );
sp_test_throw_on( 'get_post_meta', 100, 'TypeError' );

$GLOBALS['spm_cli_log'] = array();
$threw                  = null;
try {
	( new SP_Merge_CLI() )->preview( array( '100', '200' ), array() );
} catch ( Throwable $e ) {
	$threw = $e;
}

sp_assert( null === $threw, 'a TypeError from the preview walk does not escape preview()' . ( $threw ? ' (' . get_class( $threw ) . ')' : '' ) );
sp_assert_contains( 'injected TypeError', (string) sp_test_last_log( 'warning' ), 'the operator is told what went wrong' );

/* -------------------------------------------------------------------------
 * 5. Lacking edit_sp_players refuses the preview outright.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_set_player( 100, 'Primary Player' );
sp_test_set_player( 200, 'Duplicate Player' );
$GLOBALS['sp_denied_caps'] = array( 'edit_sp_players' );

$threw = null;
try {
	( new SP_Merge_CLI() )->preview( array( '100', '200' ), array() );
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}
sp_assert( null !== $threw, 'lacking edit_sp_players refuses the preview' );

sp_test_done();
