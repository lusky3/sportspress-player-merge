<?php
/**
 * WP-CLI Batch Subcommand Class
 *
 * Registers the `wp sp-merge batch` command.
 *
 * @package SportsPress_Player_Merge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Kept on its own class, registered at the `sp-merge batch` leaf (see
 * sportspress-player-merge.php), rather than as a `batch()` method on
 * SP_Merge_CLI: batch is the largest and most self-contained of the
 * subcommands — a file reader, two parsers, a log writer and a row loop that
 * nothing else uses — and carrying all of it on the class that also holds
 * scan/preview/merge/revert made that class the single biggest thing in the
 * plugin to read.
 *
 * The leaf verb is `__invoke()` rather than a `batch()` method for the same
 * reason SP_Merge_CLI_Backups names its methods `list()`/`delete()`: WP-CLI
 * maps a registered class's public methods to leaf subcommands beneath the
 * name it was registered under, so a `batch()` method on a class registered at
 * `sp-merge batch` would spell the command `wp sp-merge batch batch`. A class
 * with an `__invoke()` method is instead registered as that one leaf itself,
 * so the registration supplies the whole command path and this file supplies
 * exactly one command — which is all it has.
 */
/**
 * Run many merges from a file, one row per merge, from the command line.
 */
class SP_Merge_CLI_Batch {

	// Rows are processed strictly in file order with a plain sequential loop —
	// the shared `sp_merge_lock` (SP_Merge_Lock, taken by both
	// SP_Merge_Processor::execute_merge() and SP_Merge_Backup::revert()) already
	// serializes merges, so this deliberately adds no locking of its own.
	/**
	 * Run many merges from a CSV or JSON file, one row per merge, logging one
	 * JSON-Lines record per processed row.
	 *
	 * `--log` is mandatory rather than optional: the admin screen's backup list
	 * only shows the 10 most recent backups per page, so a batch of any real size
	 * needs its own externally recorded record of every backup ID it produced —
	 * without one, there is no way to find, and so no way to revert, most of a
	 * large batch's merges.
	 *
	 * `--yes` is mandatory here, unlike on `merge`. Confirmation is asked per row,
	 * and WP-CLI's confirm() exits 0 when it cannot read an answer — so without
	 * `--yes` a batch run from cron would exit successfully having merged nothing,
	 * with an all-but-empty log. Use `merge` when you want to confirm
	 * interactively.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the CSV or JSON input file. Must be a readable local file. CSV rows
	 * are `primary_id,duplicate_ids` with no header row, where duplicate_ids is
	 * `;`-joined (e.g. `101,205;309`) — a row with any other shape is rejected and
	 * logged as a row failure rather than run as a smaller merge. JSON is an array
	 * of `{"primary_id": 101, "duplicate_ids": [205, 309]}` objects, where
	 * duplicate_ids must really be an array of player IDs.
	 *
	 * [--input-format=<csv|json>]
	 * : Format of the <file> being read. Defaults to sniffing the file
	 * extension (.csv or .json); required when the extension is anything
	 * else. Named --input-format, not --format, because scan/preview/
	 * backups list all use --format to mean the opposite thing -- how
	 * *output* is rendered -- and this is the one subcommand reading a file.
	 *
	 * [--stop-on-error]
	 * : Halt on the first row that fails (after logging it). This is the default.
	 * Cannot be combined with --continue-on-error.
	 *
	 * [--continue-on-error]
	 * : Keep processing every row regardless of earlier failures. Cannot be
	 * combined with --stop-on-error.
	 *
	 * [--dry-run]
	 * : Run the preview and survivor-warning gate for every row, but never
	 * execute a merge. The log still gets one line per row.
	 *
	 * [--skip-preview]
	 * : Do not print the preview report before processing each row.
	 *
	 * [--force]
	 * : Proceed despite a survivor warning on any row (see `merge`'s --force).
	 *
	 * --yes
	 * : Required, unless --dry-run is also passed. Skip the confirmation prompt
	 * for every row. `batch` is not interactive — see above.
	 *
	 * --log=<path>
	 * : Required. Path to append one JSON-Lines record to per processed row. The
	 * parent directory must already exist and be writable.
	 *
	 * ## EXAMPLES
	 *
	 *     # players.csv contains, one row per merge, no header:
	 *     # 101,205;309
	 *     wp sp-merge batch players.csv --yes --log=/tmp/batch.log
	 *     wp sp-merge batch players.json --yes --continue-on-error --log=/tmp/batch.log
	 *     wp sp-merge batch players.csv --yes --dry-run --log=/tmp/batch.log
	 *
	 * @param array $args       Positional arguments: the input file path.
	 * @param array $assoc_args Associative arguments: input-format, stop-on-error,
	 *                          continue-on-error, dry-run, skip-preview, force,
	 *                          yes, log.
	 */
	public function __invoke( $args, $assoc_args ): void {
		$log_path = $this->require_batch_flags( $assoc_args );
		$rows     = $this->read_batch_rows( $args, $assoc_args );

		// Opened only once the input has been read and parsed, so a batch that
		// was never going to run does not leave an empty log file behind.
		$log_handle = $this->open_log( $log_path );

		$totals = $this->process_rows( $rows, $log_handle, $assoc_args );

		fclose( $log_handle );

		$this->report_totals( $totals, count( $rows ), $log_path, isset( $assoc_args['continue-on-error'] ) );
	}

	/**
	 * Refuse the run outright for anything wrong with the flags themselves,
	 * before a single row — or even the input file's name — is looked at.
	 *
	 * @param array $assoc_args Associative arguments as passed to __invoke().
	 * @return string The validated --log path.
	 */
	private function require_batch_flags( array $assoc_args ): string {
		if ( ! current_user_can( 'manage_sportspress' ) && ! current_user_can( 'delete_sp_players' ) ) {
			\WP_CLI::error( __( 'Insufficient permissions.', 'sportspress-player-merge' ) );
		}

		// Checked before anything else, and before a single row is read: WP-CLI's
		// confirm() calls a bare exit(0) when it cannot read a `y` — which is what
		// fgets(STDIN) returns in a non-TTY context like cron — so an unattended
		// `batch` without --yes would exit *successfully* having merged nothing.
		// --dry-run is exempt: it never reaches confirm() (run_one_merge() returns
		// before that point whenever 'dry-run' is set), so there is nothing for
		// --yes to confirm, and requiring it anyway would just be busywork for an
		// operator checking what a batch would do.
		if ( ! isset( $assoc_args['dry-run'] ) && ! isset( $assoc_args['yes'] ) ) {
			\WP_CLI::error( __( '--yes is required for batch — it is not interactive. Use merge to confirm a single operation interactively.', 'sportspress-player-merge' ) );
		}

		if ( isset( $assoc_args['stop-on-error'] ) && isset( $assoc_args['continue-on-error'] ) ) {
			\WP_CLI::error( __( 'Pass either --stop-on-error or --continue-on-error, not both.', 'sportspress-player-merge' ) );
		}

		$log_path = $assoc_args['log'] ?? null;
		if ( null === $log_path || '' === $log_path ) {
			\WP_CLI::error( __( '--log is required.', 'sportspress-player-merge' ) );
		}

		return (string) $log_path;
	}

	/**
	 * Read and parse the input file into the uniform row shape run_one_merge()
	 * expects.
	 *
	 * @param array $args       Positional arguments: the input file path.
	 * @param array $assoc_args Associative arguments: input-format.
	 * @return array{primary_id: int, duplicate_ids: int[], _parse_error?: string}[]
	 */
	private function read_batch_rows( array $args, array $assoc_args ): array {
		$file = $args[0] ?? null;
		if ( null === $file ) {
			\WP_CLI::error( __( 'Usage: wp sp-merge batch <file> --yes --log=<path>', 'sportspress-player-merge' ) );
		}

		// is_file() is false for every stream-wrapper path (http://, php://, ...),
		// so with PHP's default allow_url_fopen this is what stops a URL in the
		// <file> argument from fetching a merge list over the network — and it
		// turns a mistyped local path into this clean error instead of a raw
		// file_get_contents() warning followed by one.
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			\WP_CLI::error( sprintf( /* translators: %s: file path */ __( 'Cannot read input file: %s', 'sportspress-player-merge' ), $file ) );
		}

		$format = $assoc_args['input-format'] ?? $this->sniff_batch_format( $file );
		if ( null === $format ) {
			\WP_CLI::error( __( 'Cannot determine input format: pass --input-format=<csv|json>, or use a .csv/.json file extension.', 'sportspress-player-merge' ) );
		}

		$contents = file_get_contents( $file );
		if ( false === $contents ) {
			\WP_CLI::error( sprintf( /* translators: %s: file path */ __( 'Could not read file: %s', 'sportspress-player-merge' ), $file ) );
		}

		return 'csv' === $format ? $this->parse_batch_csv( $contents ) : $this->parse_batch_json( $contents );
	}

	/**
	 * Open the batch log for appending.
	 *
	 * @param string $log_path Validated --log path.
	 * @return resource Open append-mode log handle.
	 */
	private function open_log( string $log_path ) {
		// Checked before fopen() for the same reason as the input file: a typo'd
		// --log directory should be one clean error, not a PHP warning.
		$log_dir = dirname( $log_path );
		if ( ! is_dir( $log_dir ) || ! ( is_writable( $log_path ) || is_writable( $log_dir ) ) ) {
			\WP_CLI::error( sprintf( /* translators: %s: log file path */ __( 'Cannot write log file: %s (its directory must exist, and the log must be creatable or writable)', 'sportspress-player-merge' ), $log_path ) );
		}

		$log_handle = fopen( $log_path, 'a' );
		if ( false === $log_handle ) {
			\WP_CLI::error( sprintf( /* translators: %s: log file path */ __( 'Could not open log file for writing: %s', 'sportspress-player-merge' ), $log_path ) );
		}

		return $log_handle;
	}

	/**
	 * Run every row in file order, logging each outcome as it happens.
	 *
	 * Stops early — leaving the remaining rows unprocessed, which the caller
	 * reports on — unless --continue-on-error was passed.
	 *
	 * @param array    $rows       Parsed input rows.
	 * @param resource $log_handle Open append-mode log handle.
	 * @param array    $assoc_args Associative arguments, passed through to each row's merge.
	 * @return array{processed: int, succeeded: int, failed: int}
	 */
	private function process_rows( array $rows, $log_handle, array $assoc_args ): array {
		$continue_on_error = isset( $assoc_args['continue-on-error'] );
		$processed         = 0;
		$succeeded         = 0;
		$failed            = 0;

		foreach ( $rows as $row ) {
			$outcome = $this->run_row( $row, $assoc_args );

			// Written and flushed immediately, one row at a time: a crash mid-batch
			// must not lose the backup IDs of rows that already finished.
			$this->write_log_row( $log_handle, $row, $outcome );

			++$processed;

			// Every row primes post/term caches that nothing in the same PHP
			// process ever flushes, so a large batch's runtime cache grows
			// unbounded. wp_cache_flush_runtime() (WP 6.0+, already required)
			// clears only that in-process cache — a persistent object cache
			// (Redis/Memcached), if configured, is untouched.
			if ( 0 === $processed % 50 ) {
				wp_cache_flush_runtime();
			}

			if ( $outcome['success'] ) {
				++$succeeded;
				continue;
			}

			++$failed;
			\WP_CLI::warning(
				sprintf(
					/* translators: 1: row number, 2: primary player ID, 3: failure message */
					__( 'Row %1$d (primary #%2$d): %3$s', 'sportspress-player-merge' ),
					$processed,
					$row['primary_id'],
					$outcome['message']
				)
			);

			if ( ! $continue_on_error ) {
				break;
			}
		}

		return array(
			'processed' => $processed,
			'succeeded' => $succeeded,
			'failed'    => $failed,
		);
	}

	/**
	 * Run one parsed row through the same merge sequence `merge` runs.
	 *
	 * A row the parser refused never reaches run_one_merge(): it is a row failure
	 * like any other, so it is logged, warned about, and respects
	 * --stop-on-error / --continue-on-error — rather than being silently coerced
	 * into a smaller merge than the file asked for.
	 *
	 * @param array $row        One parsed input row.
	 * @param array $assoc_args Associative arguments, passed through to the merge.
	 * @return array{success: bool, backup_id: ?string, message: ?string}
	 */
	private function run_row( array $row, array $assoc_args ): array {
		if ( isset( $row['_parse_error'] ) ) {
			return array(
				'success'   => false,
				'backup_id' => null,
				'message'   => $row['_parse_error'],
			);
		}

		return SP_Merge_CLI::run_one_merge( $row['primary_id'], $row['duplicate_ids'], $assoc_args );
	}

	/**
	 * Print the run's summary, then end the command the way its outcome demands.
	 *
	 * The summary is logged before any fatal error() below: it must be visible
	 * (and the batch's log file already closed) regardless of which branch this
	 * ends in.
	 *
	 * @param array  $totals            process_rows()'s counters.
	 * @param int    $total             Rows the input file yielded.
	 * @param string $log_path          The --log path, named in the failure warning.
	 * @param bool   $continue_on_error Whether --continue-on-error was passed.
	 */
	private function report_totals( array $totals, int $total, string $log_path, bool $continue_on_error ): void {
		$processed = $totals['processed'];
		$failed    = $totals['failed'];

		\WP_CLI::log(
			sprintf(
				/* translators: 1: rows processed, 2: total rows, 3: rows succeeded, 4: rows failed, 5: rows remaining */
				__( 'Processed %1$d of %2$d row(s): %3$d succeeded, %4$d failed, %5$d remaining.', 'sportspress-player-merge' ),
				$processed,
				$total,
				$totals['succeeded'],
				$failed,
				$total - $processed
			)
		);

		if ( ! $continue_on_error && $failed > 0 ) {
			\WP_CLI::error(
				sprintf(
					/* translators: 1: row the batch halted after, 2: rows remaining unprocessed */
					__( 'Batch halted after row %1$d failed. %2$d row(s) remaining unprocessed.', 'sportspress-player-merge' ),
					$processed,
					$total - $processed
				)
			);
		}

		if ( $failed > 0 ) {
			\WP_CLI::warning( sprintf( /* translators: 1: number of failed rows, 2: log file path */ __( '%1$d row(s) failed. See %2$s for details.', 'sportspress-player-merge' ), $failed, $log_path ) );
			return;
		}

		\WP_CLI::success( sprintf( /* translators: %d: number of rows succeeded */ __( 'Batch completed: %d row(s) succeeded.', 'sportspress-player-merge' ), $totals['succeeded'] ) );
	}

	/**
	 * Append one row's JSON-Lines record to the batch log, and flush it.
	 *
	 * wp_json_encode() returns false on an encoding failure — invalid UTF-8 in a
	 * legacy player title reaching this through an error `message` is enough — and
	 * `false . "\n"` writes a blank line, silently losing the one record of what
	 * happened to that row. A minimal ASCII-safe record is written instead: the
	 * IDs are always encodable, so the row remains traceable and, if it produced a
	 * backup, revertible.
	 *
	 * @param resource $log_handle Open append-mode log handle.
	 * @param array    $row        Parsed input row.
	 * @param array    $outcome    run_one_merge()'s result for that row.
	 */
	private function write_log_row( $log_handle, array $row, array $outcome ): void {
		$record = array(
			'primary_id'    => $row['primary_id'],
			'duplicate_ids' => $row['duplicate_ids'],
			'success'       => $outcome['success'],
			'backup_id'     => $outcome['backup_id'],
			'message'       => $outcome['message'],
		);

		$encoded = wp_json_encode( $record );

		if ( false === $encoded ) {
			$encoded = wp_json_encode(
				array(
					'primary_id'    => $row['primary_id'],
					'duplicate_ids' => $row['duplicate_ids'],
					'success'       => $outcome['success'],
					'backup_id'     => $outcome['backup_id'],
					'message'       => 'This row\'s real result could not be JSON-encoded (invalid UTF-8); IDs and outcome preserved.',
				)
			);
		}

		if ( false === $encoded ) {
			// Only reachable if even the IDs cannot be encoded, which they always
			// can — but a blank line in this log is never acceptable.
			$encoded = '{"success":false,"message":"unencodable batch row"}';
		}

		fwrite( $log_handle, $encoded . "\n" );
		fflush( $log_handle );
	}

	/**
	 * Sniff a batch input format from its file extension.
	 *
	 * @param string $file Input file path.
	 * @return string|null 'csv', 'json', or null when the extension is unrecognized.
	 */
	private function sniff_batch_format( string $file ): ?string {
		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

		if ( 'csv' === $ext ) {
			return 'csv';
		}

		if ( 'json' === $ext ) {
			return 'json';
		}

		return null;
	}

	/**
	 * Parse batch CSV input into the uniform row shape run_one_merge() expects.
	 *
	 * Row shape: `primary_id,duplicate_ids`, duplicate_ids `;`-joined
	 * (e.g. `101,205;309`). No header row.
	 *
	 * The raw duplicate-IDs field is validated *before* it is turned into IDs,
	 * because absint() is far too forgiving to be a validator here: a row written
	 * `101,205,309` (commas, the mistake anyone would make) used to parse as
	 * `$fields[1] === '205'`, silently dropping 309 and executing a smaller,
	 * different merge than the file specified — and reporting success. A row that
	 * is not exactly two fields, or whose second field is not `;`-separated digit
	 * runs, is refused and carried through as a `_parse_error` row so the run
	 * logs it as a failure instead.
	 *
	 * @param string $contents Raw file contents.
	 * @return array{primary_id: int, duplicate_ids: int[], _parse_error?: string}[]
	 */
	private function parse_batch_csv( string $contents ): array {
		$rows   = array();
		$number = 0;

		foreach ( preg_split( '/\r\n|\r|\n/', trim( $contents ) ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			++$number;
			// $escape passed explicitly ('' disables backslash-escaping): PHP 8.4
			// deprecates relying on str_getcsv()'s implicit default, and a plain
			// numeric-ID file has no legitimate use for backslash escaping anyway.
			$fields = array_map( 'trim', str_getcsv( $line, ',', '"', '' ) );

			if ( 2 !== count( $fields ) ) {
				$rows[] = $this->malformed_batch_row(
					absint( $fields[0] ),
					sprintf(
						/* translators: 1: row number, 2: fields found, 3: raw row text */
						__( 'Malformed row %1$d: expected exactly 2 comma-separated fields (primary_id,duplicate_ids), found %2$d in "%3$s". Join multiple duplicate IDs with ";", not ",".', 'sportspress-player-merge' ),
						$number,
						count( $fields ),
						$line
					)
				);
				continue;
			}

			if ( 1 !== preg_match( '/^\d+$/', $fields[0] ) ) {
				$rows[] = $this->malformed_batch_row(
					0,
					sprintf( /* translators: 1: row number, 2: raw primary_id value */ __( 'Malformed row %1$d: primary_id "%2$s" is not a player ID.', 'sportspress-player-merge' ), $number, $fields[0] )
				);
				continue;
			}

			if ( 1 !== preg_match( '/^\d+(;\d+)*$/', $fields[1] ) ) {
				$rows[] = $this->malformed_batch_row(
					absint( $fields[0] ),
					sprintf( /* translators: 1: row number, 2: raw duplicate_ids value */ __( 'Malformed row %1$d: duplicate_ids "%2$s" must be one player ID, or several joined with ";".', 'sportspress-player-merge' ), $number, $fields[1] )
				);
				continue;
			}

			$rows[] = array(
				'primary_id'    => absint( $fields[0] ),
				'duplicate_ids' => array_map( 'absint', explode( ';', $fields[1] ) ),
			);
		}

		return $rows;
	}

	/**
	 * Build the row the log records for input the parser refused.
	 *
	 * Deliberately not a best-effort recovery: whatever the row meant, executing
	 * some subset of it is worse than refusing it, because the subset merge is
	 * still a permanent deletion and it would report success.
	 *
	 * @param int    $primary_id Primary ID if one could be read, else 0 — logged for traceability only.
	 * @param string $message    Operator-facing description of what was wrong.
	 * @return array{primary_id: int, duplicate_ids: int[], _parse_error: string}
	 */
	private function malformed_batch_row( int $primary_id, string $message ): array {
		return array(
			'primary_id'    => $primary_id,
			'duplicate_ids' => array(),
			'_parse_error'  => $message,
		);
	}

	/**
	 * Parse batch JSON input into the uniform row shape run_one_merge() expects.
	 *
	 * Shape: an array of `{"primary_id": 101, "duplicate_ids": [205, 309]}`
	 * objects. `duplicate_ids` has to actually be an array of player IDs: the old
	 * `(array) $entry['duplicate_ids']` accepted a string, so a hand-written
	 * `"duplicate_ids": "205;309"` became `absint( '205;309' )` — a single ID of
	 * 205, dropping 309 and executing a smaller, different merge than the file
	 * specified. As with the CSV parser, a refused row becomes a `_parse_error`
	 * row that the run logs as a failure.
	 *
	 * @param string $contents Raw file contents.
	 * @return array{primary_id: int, duplicate_ids: int[], _parse_error?: string}[]
	 */
	private function parse_batch_json( string $contents ): array {
		$decoded = json_decode( $contents, true );

		if ( ! is_array( $decoded ) ) {
			\WP_CLI::error( __( 'Invalid JSON input: expected an array of {"primary_id":.., "duplicate_ids":[..]} objects.', 'sportspress-player-merge' ) );
		}

		$rows   = array();
		$number = 0;

		foreach ( $decoded as $entry ) {
			++$number;

			if ( ! is_array( $entry ) ) {
				$rows[] = $this->malformed_batch_row(
					0,
					sprintf( /* translators: %d: row number */ __( 'Malformed row %d: expected a {"primary_id":.., "duplicate_ids":[..]} object.', 'sportspress-player-merge' ), $number )
				);
				continue;
			}

			if ( ! $this->is_player_id( $entry['primary_id'] ?? null ) ) {
				$rows[] = $this->malformed_batch_row(
					0,
					sprintf( /* translators: %d: row number */ __( 'Malformed row %d: primary_id must be a positive player ID.', 'sportspress-player-merge' ), $number )
				);
				continue;
			}

			$duplicates = $entry['duplicate_ids'] ?? null;

			if ( ! is_array( $duplicates ) || empty( $duplicates ) ) {
				$rows[] = $this->malformed_batch_row(
					absint( $entry['primary_id'] ),
					sprintf(
						/* translators: 1: row number, 2: what duplicate_ids actually was */
						__( 'Malformed row %1$d: duplicate_ids must be a non-empty array of player IDs, got %2$s.', 'sportspress-player-merge' ),
						$number,
						is_string( $duplicates ) ? '"' . $duplicates . '"' : gettype( $duplicates )
					)
				);
				continue;
			}

			$invalid = array_filter(
				$duplicates,
				function ( $value ): bool {
					return ! $this->is_player_id( $value );
				}
			);

			if ( ! empty( $invalid ) ) {
				$rows[] = $this->malformed_batch_row(
					absint( $entry['primary_id'] ),
					sprintf( /* translators: %d: row number */ __( 'Malformed row %d: every duplicate_ids entry must be a positive player ID.', 'sportspress-player-merge' ), $number )
				);
				continue;
			}

			$rows[] = array(
				'primary_id'    => absint( $entry['primary_id'] ),
				'duplicate_ids' => array_values( array_map( 'absint', $duplicates ) ),
			);
		}

		return $rows;
	}

	/**
	 * Is this raw JSON value a usable player ID?
	 *
	 * A positive integer, or a string of nothing but digits denoting one. Anything
	 * else — a float, a list, "205;309", "12abc" — is rejected rather than run
	 * through absint(), which would turn most of them into some other number.
	 *
	 * @param mixed $value Raw value from the decoded JSON.
	 * @return bool
	 */
	private function is_player_id( $value ): bool {
		if ( is_int( $value ) ) {
			return $value > 0;
		}

		if ( is_string( $value ) ) {
			return 1 === preg_match( '/^\d+$/', $value ) && absint( $value ) > 0;
		}

		return false;
	}
}
