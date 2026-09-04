<?php
/**
 * `wp sp-merge batch` must run the exact same validate/preview/warning/confirm/
 * execute sequence `merge()` runs — via the shared `run_one_merge()` — once per
 * row of a CSV or JSON file, in file order, logging one JSON-Lines record per
 * row to `--log` immediately (not buffered), and only ever proceed past a
 * failed row when `--continue-on-error` was given.
 *
 * Every row here is built with `skip-preview` + `yes` in $assoc_args (the same
 * convention test-cli-merge.php uses) so scenarios stay focused on batch's own
 * row-sequencing and logging, not on preview rendering (already covered by
 * test-cli-preview.php) or confirmation prompting.
 *
 * Fixture files are written to sys_get_temp_dir() at run time and removed
 * afterwards — no committed fixtures, so nothing here is sensitive to where
 * the checkout lives.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable

require_once __DIR__ . '/lib-cli-mocks.php';

echo "wp sp-merge batch\n";

/**
 * Register (or replace) a player post the get_post() mock will serve.
 *
 * @param int    $id   Player ID.
 * @param string $name Post title.
 */
function sp_test_batch_set_player( int $id, string $name ): void {
	$GLOBALS['sp_posts'][ $id ] = (object) array(
		'ID'          => $id,
		'post_type'   => 'sp_player',
		'post_title'  => $name,
		'post_status' => 'publish',
	);
}

/**
 * Write a temp file with the given contents and extension, returning its path.
 *
 * @param string $contents  File contents.
 * @param string $extension Extension without the dot (e.g. 'csv', 'json').
 * @return string Absolute path.
 */
function sp_test_batch_write_file( string $contents, string $extension ): string {
	$path = sys_get_temp_dir() . '/sp-merge-batch-test-' . uniqid() . '.' . $extension;
	file_put_contents( $path, $contents );
	return $path;
}

/**
 * A fresh, writable temp path for a batch's --log, not yet created.
 *
 * @return string Absolute path.
 */
function sp_test_batch_log_path(): string {
	return sys_get_temp_dir() . '/sp-merge-batch-log-' . uniqid() . '.jsonl';
}

/**
 * Every WP_CLI-logged message at a given level, joined, for substring
 * assertions — mirrors test-cli-merge.php's helper of the same shape (not
 * shared between the two standalone test files, each run in its own process).
 *
 * @param string $level Log level: log, warning, success, ...
 * @return string
 */
function sp_test_batch_log_text( string $level = 'log' ): string {
	$lines = array();
	foreach ( $GLOBALS['spm_cli_log'] as $entry ) {
		if ( $level === $entry['level'] ) {
			$lines[] = is_string( $entry['message'] ) ? $entry['message'] : var_export( $entry['message'], true );
		}
	}
	return implode( "\n", $lines );
}

/**
 * Read a JSON-Lines file into an array of decoded rows.
 *
 * @param string $path Log file path.
 * @return array[]
 */
function sp_test_batch_read_log( string $path ): array {
	if ( ! file_exists( $path ) ) {
		return array();
	}
	$lines = array_filter( preg_split( '/\r\n|\r|\n/', file_get_contents( $path ) ), 'strlen' );
	return array_map(
		static function ( $line ) {
			return json_decode( $line, true );
		},
		$lines
	);
}

/** Standard three-row CSV: row 1 and 3 valid, row 2's duplicate (999) never registered. */
const SP_TEST_BATCH_CSV_WITH_FAILURE = "100,200\n101,999\n102,202\n";

/** Same three rows, valid ones only (no induced failure). */
const SP_TEST_BATCH_CSV_CLEAN = "100,200\n102,202\n";

/**
 * Seed the players every scenario below needs: 100/200, 101, 102/202. Player
 * 999 (the induced failure's duplicate) is deliberately left unregistered.
 */
function sp_test_batch_seed_players(): void {
	sp_test_batch_set_player( 100, 'Primary A' );
	sp_test_batch_set_player( 200, 'Duplicate A' );
	sp_test_batch_set_player( 101, 'Primary B' );
	sp_test_batch_set_player( 102, 'Primary C' );
	sp_test_batch_set_player( 202, 'Duplicate C' );
}

/* -------------------------------------------------------------------------
 * 1. Clean batch (CSV): every row succeeds, log has one JSON line per row,
 *    in file order.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_batch_seed_players();

$csv_path = sp_test_batch_write_file( SP_TEST_BATCH_CSV_CLEAN, 'csv' );
$log_path = sp_test_batch_log_path();

( new SP_Merge_CLI() )->batch(
	array( $csv_path ),
	array(
		'skip-preview' => true,
		'yes'          => true,
		'log'          => $log_path,
	)
);

$log_rows = sp_test_batch_read_log( $log_path );
sp_assert( 2 === count( $log_rows ), 'a clean two-row batch logs exactly two lines' );
sp_assert( 100 === ( $log_rows[0]['primary_id'] ?? null ), 'the first logged row is the first file row (primary 100)' );
sp_assert( true === ( $log_rows[0]['success'] ?? null ), 'the first row succeeded' );
sp_assert( 1 === preg_match( '/merge_\d+_[a-zA-Z0-9]{8}/', (string) ( $log_rows[0]['backup_id'] ?? '' ) ), 'the first row logs a real backup ID' );
sp_assert( 102 === ( $log_rows[1]['primary_id'] ?? null ), 'the second logged row is the second file row (primary 102)' );
sp_assert( true === ( $log_rows[1]['success'] ?? null ), 'the second row succeeded' );
sp_assert( in_array( 200, $GLOBALS['sp_deleted_posts'], true ), 'row 1\'s duplicate was actually deleted' );
sp_assert( in_array( 202, $GLOBALS['sp_deleted_posts'], true ), 'row 2\'s duplicate was actually deleted' );

unlink( $csv_path );
unlink( $log_path );

/* -------------------------------------------------------------------------
 * 2. --stop-on-error (the default): halts after the first induced failure,
 *    the row after it is never processed.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_batch_seed_players();

$csv_path = sp_test_batch_write_file( SP_TEST_BATCH_CSV_WITH_FAILURE, 'csv' );
$log_path = sp_test_batch_log_path();

$threw = null;
try {
	( new SP_Merge_CLI() )->batch(
		array( $csv_path ),
		array(
			'skip-preview' => true,
			'yes'          => true,
			'log'          => $log_path,
		)
	);
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}

sp_assert( null !== $threw, 'stop-on-error (default) halts the batch via a fatal error' );
sp_assert_contains( 'row', strtolower( $threw ? $threw->getMessage() : '' ), 'the halt message describes what happened' );

$log_rows = sp_test_batch_read_log( $log_path );
sp_assert( 2 === count( $log_rows ), 'only the succeeding row and the failing row were logged, not the row after it' );
sp_assert( true === ( $log_rows[0]['success'] ?? null ), 'row 1 (primary 100) still succeeded before the halt' );
sp_assert( 101 === ( $log_rows[1]['primary_id'] ?? null ), 'row 2 (primary 101) is the logged failure' );
sp_assert( false === ( $log_rows[1]['success'] ?? null ), 'row 2 is logged as a failure' );
sp_assert( array_key_exists( 'backup_id', $log_rows[1] ) && null === $log_rows[1]['backup_id'], 'a failed row logs a null backup_id' );
sp_assert( ! in_array( 202, $GLOBALS['sp_deleted_posts'], true ), 'row 3 (primary 102) was never reached — its duplicate is untouched' );
sp_assert( null !== get_post( 202 ), 'row 3\'s duplicate player still exists' );

unlink( $csv_path );
unlink( $log_path );

/* -------------------------------------------------------------------------
 * 3. --continue-on-error: the same induced failure does not stop the batch;
 *    every row is processed and the summary counts both outcomes.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_batch_seed_players();

$csv_path = sp_test_batch_write_file( SP_TEST_BATCH_CSV_WITH_FAILURE, 'csv' );
$log_path = sp_test_batch_log_path();

$threw = null;
try {
	( new SP_Merge_CLI() )->batch(
		array( $csv_path ),
		array(
			'skip-preview'       => true,
			'yes'                => true,
			'continue-on-error'  => true,
			'log'                => $log_path,
		)
	);
} catch ( Throwable $e ) {
	$threw = $e;
}

sp_assert( null === $threw, 'continue-on-error does not halt on the induced failure' . ( $threw ? ' (' . $threw->getMessage() . ')' : '' ) );

$log_rows = sp_test_batch_read_log( $log_path );
sp_assert( 3 === count( $log_rows ), 'every row is logged under continue-on-error' );
sp_assert( true === ( $log_rows[0]['success'] ?? null ), 'row 1 succeeded' );
sp_assert( false === ( $log_rows[1]['success'] ?? null ), 'row 2 (the induced failure) failed' );
sp_assert( true === ( $log_rows[2]['success'] ?? null ), 'row 3 succeeded despite row 2\'s failure' );
sp_assert( in_array( 200, $GLOBALS['sp_deleted_posts'], true ), 'row 1\'s duplicate was deleted' );
sp_assert( in_array( 202, $GLOBALS['sp_deleted_posts'], true ), 'row 3\'s duplicate was deleted too — it was reached' );

$summary = sp_test_batch_log_text( 'log' );
sp_assert_contains( '2 succeeded', $summary, 'the final summary counts the two successes' );
sp_assert_contains( '1 failed', $summary, 'the final summary counts the one failure' );

unlink( $csv_path );
unlink( $log_path );

/* -------------------------------------------------------------------------
 * 4. --dry-run: log entries are produced, but the real merge path is never
 *    reached (no backup created, no post deleted).
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_batch_seed_players();

$csv_path = sp_test_batch_write_file( SP_TEST_BATCH_CSV_CLEAN, 'csv' );
$log_path = sp_test_batch_log_path();

( new SP_Merge_CLI() )->batch(
	array( $csv_path ),
	array(
		'skip-preview' => true,
		'yes'          => true,
		'dry-run'      => true,
		'log'          => $log_path,
	)
);

$log_rows = sp_test_batch_read_log( $log_path );
sp_assert( 2 === count( $log_rows ), 'dry-run still logs one line per row' );
sp_assert( true === ( $log_rows[0]['success'] ?? null ), 'a dry-run row reports success (nothing refused it)' );
sp_assert( array_key_exists( 'backup_id', $log_rows[0] ) && null === $log_rows[0]['backup_id'], 'a dry-run row never produces a real backup ID' );
sp_assert_contains( 'DRY RUN', (string) ( $log_rows[0]['message'] ?? '' ), 'a dry-run row explains what would have happened' );
sp_assert( 0 === count( $GLOBALS['wpdb']->backups ), 'dry-run never creates a real backup row — the merge path was never reached' );
sp_assert( array() === $GLOBALS['sp_deleted_posts'], 'dry-run never deletes a duplicate player' );

unlink( $csv_path );
unlink( $log_path );

/* -------------------------------------------------------------------------
 * 5. Missing --log errors immediately, before any input row is touched.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_batch_seed_players();

$csv_path = sp_test_batch_write_file( SP_TEST_BATCH_CSV_CLEAN, 'csv' );

$threw = null;
try {
	( new SP_Merge_CLI() )->batch( array( $csv_path ), array( 'skip-preview' => true, 'yes' => true ) );
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}

sp_assert( null !== $threw, 'a missing --log refuses the batch' );
sp_assert_contains( '--log is required', $threw ? $threw->getMessage() : '', 'the refusal names --log specifically' );
sp_assert( 0 === count( $GLOBALS['wpdb']->backups ), 'no backup was created — the file was never even read' );
sp_assert( array() === $GLOBALS['sp_deleted_posts'], 'no duplicate was deleted' );
sp_assert( null !== get_post( 200 ), 'row 1\'s duplicate still exists — nothing in the file was processed' );

unlink( $csv_path );

/* -------------------------------------------------------------------------
 * 6. Unrecognized extension with no --format errors; an explicit --format
 *    overrides a misleading extension.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_batch_seed_players();

$txt_path = sp_test_batch_write_file( SP_TEST_BATCH_CSV_CLEAN, 'txt' );
$log_path = sp_test_batch_log_path();

$threw = null;
try {
	( new SP_Merge_CLI() )->batch(
		array( $txt_path ),
		array( 'skip-preview' => true, 'yes' => true, 'log' => $log_path )
	);
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}
sp_assert( null !== $threw, 'an unrecognized extension without --format refuses the batch' );

( new SP_Merge_CLI() )->batch(
	array( $txt_path ),
	array( 'skip-preview' => true, 'yes' => true, 'log' => $log_path, 'format' => 'csv' )
);
$log_rows = sp_test_batch_read_log( $log_path );
sp_assert( 2 === count( $log_rows ), 'an explicit --format=csv reads a .txt file as CSV' );

unlink( $txt_path );
unlink( $log_path );

/* -------------------------------------------------------------------------
 * 7. JSON input: the {"primary_id":..,"duplicate_ids":[..]} shape parses and
 *    runs the same as CSV, including a duplicate_ids list of more than one ID.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_batch_set_player( 300, 'JSON Primary' );
sp_test_batch_set_player( 301, 'JSON Duplicate 1' );
sp_test_batch_set_player( 302, 'JSON Duplicate 2' );

$json_path = sp_test_batch_write_file(
	json_encode(
		array(
			array(
				'primary_id'    => 300,
				'duplicate_ids' => array( 301, 302 ),
			),
		)
	),
	'json'
);
$log_path = sp_test_batch_log_path();

( new SP_Merge_CLI() )->batch(
	array( $json_path ),
	array( 'skip-preview' => true, 'yes' => true, 'log' => $log_path )
);

$log_rows = sp_test_batch_read_log( $log_path );
sp_assert( 1 === count( $log_rows ), 'one JSON object logs one row' );
sp_assert( true === ( $log_rows[0]['success'] ?? null ), 'the JSON row succeeded' );
sp_assert( array( 301, 302 ) === ( $log_rows[0]['duplicate_ids'] ?? null ), 'both duplicate IDs from the JSON array are recorded, in order' );
sp_assert( in_array( 301, $GLOBALS['sp_deleted_posts'], true ) && in_array( 302, $GLOBALS['sp_deleted_posts'], true ), 'both JSON duplicates were actually merged and deleted' );

unlink( $json_path );
unlink( $log_path );

/* -------------------------------------------------------------------------
 * 8. Parser unit checks: CSV's `;`-joined duplicate_ids and the uniform
 *    array{primary_id:int, duplicate_ids:int[]} shape, independent of a full
 *    batch run.
 * ---------------------------------------------------------------------- */

$cli    = new SP_Merge_CLI();
$parsed = sp_test_invoke( $cli, 'parse_batch_csv', array( "101,205;309\n" ) );
sp_assert(
	array( array( 'primary_id' => 101, 'duplicate_ids' => array( 205, 309 ) ) ) === $parsed,
	'CSV parsing splits a `;`-joined duplicate_ids column into an int[]'
);

$parsed_json = sp_test_invoke(
	$cli,
	'parse_batch_json',
	array( json_encode( array( array( 'primary_id' => 101, 'duplicate_ids' => array( 205, 309 ) ) ) ) )
);
sp_assert(
	array( array( 'primary_id' => 101, 'duplicate_ids' => array( 205, 309 ) ) ) === $parsed_json,
	'JSON parsing produces the same uniform row shape as CSV'
);

/* -------------------------------------------------------------------------
 * 9. Malformed rows are refused, never silently coerced into a smaller merge.
 *
 *    `101,205,309` (commas instead of the documented `;`) used to parse as
 *    duplicate_ids [205], dropping 309 and permanently deleting one player
 *    while reporting success. A JSON "205;309" string used to become [205] the
 *    same way. Both are row failures now.
 * ---------------------------------------------------------------------- */

$cli = new SP_Merge_CLI();

$parsed = sp_test_invoke( $cli, 'parse_batch_csv', array( "101,205,309\n" ) );
sp_assert( isset( $parsed[0]['_parse_error'] ), 'a comma-separated duplicate list is refused, not silently truncated' );
sp_assert_same( array(), $parsed[0]['duplicate_ids'], 'the refused CSV row carries no duplicate IDs to merge' );
sp_assert_contains( '";"', (string) ( $parsed[0]['_parse_error'] ?? '' ), 'the CSV refusal names the `;` separator the file should have used' );

$parsed = sp_test_invoke( $cli, 'parse_batch_csv', array( "101,20a\n" ) );
sp_assert( isset( $parsed[0]['_parse_error'] ), 'a non-numeric duplicate ID is refused' );

$parsed = sp_test_invoke( $cli, 'parse_batch_csv', array( "abc,205\n" ) );
sp_assert( isset( $parsed[0]['_parse_error'] ), 'a non-numeric primary ID is refused' );

$parsed_json = sp_test_invoke(
	$cli,
	'parse_batch_json',
	array( json_encode( array( array( 'primary_id' => 101, 'duplicate_ids' => '205;309' ) ) ) )
);
sp_assert( isset( $parsed_json[0]['_parse_error'] ), 'a JSON duplicate_ids string (not an array) is refused, not cast' );
sp_assert_same( array(), $parsed_json[0]['duplicate_ids'], 'the refused JSON row carries no duplicate IDs to merge' );
sp_assert_same( 101, $parsed_json[0]['primary_id'], 'the refused JSON row still records which primary the file named' );

$parsed_json = sp_test_invoke(
	$cli,
	'parse_batch_json',
	array( json_encode( array( array( 'primary_id' => 101, 'duplicate_ids' => array( 205, 'x' ) ) ) ) )
);
sp_assert( isset( $parsed_json[0]['_parse_error'] ), 'a JSON duplicate_ids array with a non-ID entry is refused whole' );

$parsed_json = sp_test_invoke(
	$cli,
	'parse_batch_json',
	array( json_encode( array( array( 'primary_id' => 101, 'duplicate_ids' => array() ) ) ) )
);
sp_assert( isset( $parsed_json[0]['_parse_error'] ), 'a JSON row with an empty duplicate_ids array is refused' );

// Numeric strings remain valid — a hand-written file quoting its IDs is fine.
$parsed_json = sp_test_invoke(
	$cli,
	'parse_batch_json',
	array( json_encode( array( array( 'primary_id' => '101', 'duplicate_ids' => array( '205', 309 ) ) ) ) )
);
sp_assert(
	array( array( 'primary_id' => 101, 'duplicate_ids' => array( 205, 309 ) ) ) === $parsed_json,
	'quoted numeric IDs still parse — only genuinely malformed input is refused'
);

/* A malformed row inside a real run is logged as a failure and, by default,
 * halts the batch exactly like any other failing row. */

sp_test_cli_reset();
sp_test_batch_seed_players();

$csv_path = sp_test_batch_write_file( "100,200\n101,205,309\n102,202\n", 'csv' );
$log_path = sp_test_batch_log_path();

$threw = null;
try {
	( new SP_Merge_CLI() )->batch(
		array( $csv_path ),
		array( 'skip-preview' => true, 'yes' => true, 'log' => $log_path )
	);
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}

sp_assert( null !== $threw, 'a malformed row halts the batch under the default --stop-on-error' );

$log_rows = sp_test_batch_read_log( $log_path );
sp_assert_same( 2, count( $log_rows ), 'the malformed row is logged, and the row after it is never processed' );
sp_assert_same( false, $log_rows[1]['success'] ?? null, 'the malformed row is logged as a failure' );
sp_assert_contains( 'Malformed row 2', (string) ( $log_rows[1]['message'] ?? '' ), 'the logged failure says which row was malformed' );
sp_assert( ! in_array( 309, $GLOBALS['sp_deleted_posts'], true ), 'nothing from the malformed row was merged' );
sp_assert( ! in_array( 205, $GLOBALS['sp_deleted_posts'], true ), 'not even the part of the malformed row that parsed cleanly' );

unlink( $csv_path );
unlink( $log_path );

/* -------------------------------------------------------------------------
 * 10. batch is not interactive: without --yes it refuses immediately rather
 *     than exiting 0 out of WP_CLI::confirm() having merged nothing.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_batch_seed_players();

$csv_path = sp_test_batch_write_file( SP_TEST_BATCH_CSV_CLEAN, 'csv' );
$log_path = sp_test_batch_log_path();

$threw = null;
try {
	( new SP_Merge_CLI() )->batch( array( $csv_path ), array( 'skip-preview' => true, 'log' => $log_path ) );
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}

sp_assert( null !== $threw, 'batch without --yes refuses' );
sp_assert_contains( '--yes is required', $threw ? $threw->getMessage() : '', 'the refusal names --yes' );
sp_assert_same( array(), sp_test_batch_read_log( $log_path ), 'not a single row was processed or logged' );
sp_assert_same( 0, count( $GLOBALS['wpdb']->backups ), 'no backup was created' );
sp_assert_same( array(), $GLOBALS['sp_deleted_posts'], 'no duplicate was deleted' );

unlink( $csv_path );

/* -------------------------------------------------------------------------
 * 11. The input path must be a readable local file: a URL (or any other
 *     stream wrapper) is refused before anything is fetched, and a missing
 *     path gets one clean error instead of a raw PHP warning.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_batch_seed_players();
$log_path = sp_test_batch_log_path();

foreach ( array( 'https://example.test/merges.csv', 'php://input', sys_get_temp_dir() . '/sp-merge-does-not-exist.csv' ) as $bad_path ) {
	$threw = null;
	try {
		( new SP_Merge_CLI() )->batch(
			array( $bad_path ),
			array( 'skip-preview' => true, 'yes' => true, 'log' => $log_path, 'format' => 'csv' )
		);
	} catch ( SP_Test_CLI_Error $e ) {
		$threw = $e;
	}

	sp_assert( null !== $threw, "a non-file input path is refused: {$bad_path}" );
	sp_assert_contains( 'Cannot read input file', $threw ? $threw->getMessage() : '', 'the refusal is the input-file check, not a later parse error' );
}

/* An unwritable --log directory is refused the same way. */
$csv_path = sp_test_batch_write_file( SP_TEST_BATCH_CSV_CLEAN, 'csv' );

$threw = null;
try {
	( new SP_Merge_CLI() )->batch(
		array( $csv_path ),
		array( 'skip-preview' => true, 'yes' => true, 'log' => sys_get_temp_dir() . '/sp-merge-no-such-dir/batch.log' )
	);
} catch ( SP_Test_CLI_Error $e ) {
	$threw = $e;
}
sp_assert( null !== $threw, 'a --log path whose directory does not exist is refused' );
sp_assert_contains( 'Cannot write log file', $threw ? $threw->getMessage() : '', 'the refusal names the log file' );

unlink( $csv_path );

/* -------------------------------------------------------------------------
 * 12. A Throwable out of the preview walk is a row failure, not a process
 *     fatal: --continue-on-error skips past it and the later rows still run.
 *
 *     Malformed decade-old serialized postmeta raises a TypeError inside
 *     deep_merge_arrays()/preview_array_field_merge(), and a TypeError is not
 *     an Exception in PHP 8 — the same hazard SP_Merge_Processor and every
 *     AJAX handler already catch as Throwable.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_batch_set_player( 100, 'Primary A' );
sp_test_batch_set_player( 200, 'Duplicate A' );
sp_test_batch_set_player( 101, 'Primary B' );
sp_test_batch_set_player( 201, 'Duplicate B' );
sp_test_batch_set_player( 102, 'Primary C' );
sp_test_batch_set_player( 202, 'Duplicate C' );

// Row 2's preview blows up on the first meta read for its primary.
sp_test_throw_on( 'get_post_meta', 101, 'TypeError' );

$csv_path = sp_test_batch_write_file( "100,200\n101,201\n102,202\n", 'csv' );
$log_path = sp_test_batch_log_path();

$threw = null;
try {
	// Deliberately no --skip-preview: the preview walk is the hazard.
	( new SP_Merge_CLI() )->batch(
		array( $csv_path ),
		array( 'yes' => true, 'continue-on-error' => true, 'log' => $log_path )
	);
} catch ( Throwable $e ) {
	$threw = $e;
}

sp_assert( null === $threw, 'a TypeError from the preview walk does not escape batch()' . ( $threw ? ' (' . get_class( $threw ) . ': ' . $threw->getMessage() . ')' : '' ) );

$log_rows = sp_test_batch_read_log( $log_path );
sp_assert_same( 3, count( $log_rows ), 'every row is still logged' );
sp_assert_same( true, $log_rows[0]['success'] ?? null, 'row 1 succeeded before the bad row' );
sp_assert_same( false, $log_rows[1]['success'] ?? null, 'the throwing row is logged as a failure' );
sp_assert_contains( 'injected TypeError', (string) ( $log_rows[1]['message'] ?? '' ), 'the failure records what actually went wrong' );
sp_assert_same( true, $log_rows[2]['success'] ?? null, 'row 3 still ran — the process was never killed' );
sp_assert( in_array( 202, $GLOBALS['sp_deleted_posts'], true ), "row 3's duplicate was merged and deleted" );
sp_assert( ! in_array( 201, $GLOBALS['sp_deleted_posts'], true ), "the failed row's duplicate is untouched" );

unlink( $csv_path );
unlink( $log_path );

/* -------------------------------------------------------------------------
 * 13. A merge that fails after its backup was created logs that backup ID.
 *
 *     execute_merge() retains the backup on failure precisely so it can be
 *     used to verify or restore; for a batch row the log is the only place
 *     that ID is ever written down.
 * ---------------------------------------------------------------------- */

sp_test_cli_reset();
sp_test_batch_set_player( 100, 'Primary A' );
sp_test_batch_set_player( 200, 'Duplicate A' );

// Something for merge_meta_data() to copy across, so the merge reaches a write.
sp_test_add_meta( 200, 'sp_nationality', 'Brazil' );

// The write itself throws — after create_merge_backup() has already run.
sp_test_throw_on( 'add_post_meta', 100, 'TypeError' );

$csv_path = sp_test_batch_write_file( "100,200\n", 'csv' );
$log_path = sp_test_batch_log_path();

$threw = null;
try {
	( new SP_Merge_CLI() )->batch(
		array( $csv_path ),
		array( 'skip-preview' => true, 'yes' => true, 'continue-on-error' => true, 'log' => $log_path )
	);
} catch ( Throwable $e ) {
	$threw = $e;
}

$log_rows = sp_test_batch_read_log( $log_path );
sp_assert_same( 1, count( $log_rows ), 'the failing row is logged' );
sp_assert_same( false, $log_rows[0]['success'] ?? null, 'the row is logged as a failure' );
sp_assert(
	1 === preg_match( '/merge_\d+_[a-zA-Z0-9]{8}/', (string) ( $log_rows[0]['backup_id'] ?? '' ) ),
	'the retained backup ID reaches the log — it is the only record of the recovery path'
);
sp_assert_same( 'failed', $GLOBALS['wpdb']->backups[0]['status'] ?? null, 'the retained backup really is the failed one' );
sp_assert( ! in_array( 200, $GLOBALS['sp_deleted_posts'], true ), 'nothing was deleted by the failed merge' );

unlink( $csv_path );
unlink( $log_path );

/* -------------------------------------------------------------------------
 * 14. A row whose result cannot be JSON-encoded still produces a real log
 *     line: `false . "\n"` would write a blank line and lose the row.
 * ---------------------------------------------------------------------- */

$log_path = sp_test_batch_log_path();
$handle   = fopen( $log_path, 'a' );

sp_test_invoke(
	new SP_Merge_CLI(),
	'write_log_row',
	array(
		$handle,
		array( 'primary_id' => 100, 'duplicate_ids' => array( 200 ) ),
		array(
			'success'   => false,
			'backup_id' => 'merge_1700000000_abcd1234',
			// Invalid UTF-8, as a legacy player title reaching the log through an
			// error message can be.
			'message'   => "bad title \xB1\x31",
		),
	)
);
fclose( $handle );

$log_rows = sp_test_batch_read_log( $log_path );
sp_assert_same( 1, count( $log_rows ), 'an unencodable row still writes exactly one line' );
sp_assert( is_array( $log_rows[0] ), 'and that line is valid JSON, not a blank' );
sp_assert_same( 100, $log_rows[0]['primary_id'] ?? null, 'the primary ID survives the fallback record' );
sp_assert_same( array( 200 ), $log_rows[0]['duplicate_ids'] ?? null, 'the duplicate IDs survive the fallback record' );
sp_assert_same( 'merge_1700000000_abcd1234', $log_rows[0]['backup_id'] ?? null, 'the backup ID — the recovery path — survives the fallback record' );
sp_assert_contains( 'could not be JSON-encoded', (string) ( $log_rows[0]['message'] ?? '' ), 'the fallback record says why the real result is missing' );

unlink( $log_path );

sp_test_done();
