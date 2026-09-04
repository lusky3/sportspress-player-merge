<?php
/**
 * WP-CLI Command Class
 *
 * Registers the wp sp-merge WP-CLI command family.
 *
 * @package SportsPress_Player_Merge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the wp sp-merge WP-CLI command family.
 *
 * `scan` runs the duplicate-name scan headless and prints the matched groups.
 * `preview` runs SP_Merge_Preview's data comparison for an explicit selection
 * without merging anything.
 */
class SP_Merge_CLI {

	/**
	 * Fields printed for each scanned group member, in column order.
	 *
	 * @var string[]
	 */
	private const SCAN_FIELDS = array( 'group_certainty', 'scenario', 'player_id', 'name', 'member_certainty', 'events' );

	/**
	 * Scan the roster for duplicate players.
	 *
	 * Runs the same fuzzy name matcher the admin screen's duplicate scan uses,
	 * headless: no HTTP request, no nonce, nothing rendered but the result. A
	 * League Manager auditing ten years of import debris cares about a handful of
	 * scenarios and a certainty floor, not the whole 2000-row table the admin
	 * screen shows by default — those are exactly what this command filters on.
	 *
	 * ## OPTIONS
	 *
	 * [--min-certainty=<0-100>]
	 * : Only report groups at or above this certainty. Default 0 (every group).
	 *
	 * [--scenario=<name>]
	 * : Only report groups matched by this exact scenario (e.g. "exact", "nickname", "typo").
	 *
	 * [--limit=<n>]
	 * : Maximum number of groups to report, after filtering. Default 50 — the
	 * same cap the admin screen's scan applies.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp sp-merge scan --min-certainty=70 --format=csv
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments: min-certainty, scenario, limit, format.
	 */
	public function scan( $args, $assoc_args ): void {
		$min_certainty = isset( $assoc_args['min-certainty'] ) ? (int) $assoc_args['min-certainty'] : 0;
		$scenario      = $assoc_args['scenario'] ?? null;
		$limit         = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 50;
		$format        = $assoc_args['format'] ?? 'table';

		$scan   = ( new SP_Merge_Ajax() )->collect_scan_players();
		$groups = SP_Merge_Name_Matcher::find_groups( $scan['players'] );

		$groups = array_values(
			array_filter(
				$groups,
				static function ( $group ) use ( $scenario, $min_certainty ) {
					if ( null !== $scenario && ( $group['scenario'] ?? '' ) !== $scenario ) {
						return false;
					}
					return (int) ( $group['certainty'] ?? 0 ) >= $min_certainty;
				}
			)
		);

		$groups = array_slice( $groups, 0, $limit );

		$rows = $this->flatten_scan_groups( $groups );

		\WP_CLI\Utils\format_items( $format, $rows, self::SCAN_FIELDS );

		if ( $scan['truncated'] ) {
			\WP_CLI::warning(
				sprintf(
					'Scanned %1$d of %2$d players; %3$d were skipped.',
					$scan['scanned'],
					$scan['total'],
					$scan['total'] - $scan['scanned']
				)
			);
		} else {
			\WP_CLI::log( sprintf( 'Scanned %1$d of %2$d players.', $scan['scanned'], $scan['total'] ) );
		}
	}

	/**
	 * Flatten name-matcher groups to one row per member, with event counts.
	 *
	 * @param array $groups Groups from SP_Merge_Name_Matcher::find_groups(), already filtered and limited.
	 * @return array[] One row per group member.
	 */
	private function flatten_scan_groups( array $groups ): array {
		$player_ids = array();
		foreach ( $groups as $group ) {
			foreach ( $group['players'] as $member ) {
				$player_ids[] = (int) $member->ID;
			}
		}

		$event_counts = SP_Merge_Validation::get_event_counts( $player_ids );

		$rows = array();
		foreach ( $groups as $group ) {
			$group_certainty = (int) ( $group['certainty'] ?? 0 );
			$scenario        = (string) ( $group['scenario'] ?? '' );

			foreach ( $group['players'] as $member ) {
				$player_id = (int) $member->ID;

				$rows[] = array(
					'group_certainty'  => $group_certainty,
					'scenario'         => $scenario,
					'player_id'        => $player_id,
					'name'             => (string) $member->post_title,
					'member_certainty' => $member->certainty ?? ( $group['member_certainty'][ $player_id ] ?? null ),
					'events'           => $event_counts[ $player_id ] ?? 0,
				);
			}
		}

		return $rows;
	}

	/**
	 * Preview a merge without executing it.
	 *
	 * Deliberately does not run SP_Merge_Validation::validate_merge_selection()
	 * first: this command is read-only and informational, so an operator can
	 * point it at any two IDs to see what SP_Merge_Preview would show, including
	 * IDs that would fail validation, without that failing loudly. Always exits
	 * 0 — nothing here is a WP-CLI error condition.
	 *
	 * ## OPTIONS
	 *
	 * <primary-id>
	 * : ID of the player that will survive the merge.
	 *
	 * <duplicate-id>...
	 * : ID(s) of the player(s) that would be merged into the primary and deleted.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format. json/yaml emit the full nested
	 * payload; table/csv are not supported here (there is no flat row to
	 * render) and fall back to the human-readable report.
	 * ---
	 * options:
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * [--porcelain]
	 * : Print only OK (no conflicts, no collisions) or WARN (either present),
	 * and nothing else. For scripting: check the exit status is always 0, so
	 * branch on this line, not on the exit code.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sp-merge preview 123 456 789
	 *     wp sp-merge preview 123 456 --porcelain
	 *
	 * @param array $args       Positional arguments: primary ID, then duplicate ID(s).
	 * @param array $assoc_args Associative arguments: format, porcelain.
	 */
	public function preview( $args, $assoc_args ): void {
		$primary_id    = absint( $args[0] ?? 0 );
		$duplicate_ids = array_map( 'absint', array_slice( $args, 1 ) );
		$format        = $assoc_args['format'] ?? null;
		$porcelain     = isset( $assoc_args['porcelain'] );

		$data = ( new SP_Merge_Preview() )->generate_data( $primary_id, $duplicate_ids );

		$has_warning = $data['collision_count'] > 0 || ! empty( $data['array_field_conflicts'] );

		if ( $porcelain ) {
			\WP_CLI::log( $has_warning ? 'WARN' : 'OK' );
			return;
		}

		if ( 'json' === $format ) {
			// Nested payload: format_items() only knows how to render flat rows.
			\WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( 'yaml' === $format ) {
			\WP_CLI\Utils\format_items( 'yaml', array( $data ), array_keys( $data ) );
			return;
		}

		$this->render_preview_data( $data );
	}

	/**
	 * Render the structured preview data as a human-readable report.
	 *
	 * Shared by `preview` (its default, non-json/yaml output) and `merge` (its
	 * pre-execution preview step) so the two commands can never describe the
	 * same selection differently.
	 *
	 * @param array $data Return value of SP_Merge_Preview::generate_data().
	 */
	private function render_preview_data( array $data ): void {
		$duplicate_names = implode(
			', ',
			array_map(
				static function ( $duplicate ) {
					return sprintf( '%s (#%d)', $duplicate['name'], $duplicate['id'] );
				},
				$data['duplicates']
			)
		);

		\WP_CLI::log( sprintf( 'Primary:    %s (#%d)', $data['primary']['name'], $data['primary']['id'] ) );
		\WP_CLI::log( sprintf( 'Duplicates: %s', $duplicate_names ) );
		\WP_CLI::log( '' );

		$this->render_comparison_section( 'Current team', $data['current_team'] );
		$this->render_comparison_section( 'Past team(s)', $data['past_teams'] );

		foreach ( $data['taxonomies'] as $taxonomy ) {
			$this->render_comparison_section( $taxonomy['label'], $taxonomy );
		}

		\WP_CLI::log(
			sprintf(
				'Events: primary %d, duplicates %d, result %d',
				$data['events']['primary'],
				$data['events']['duplicates'],
				$data['events']['result']
			)
		);

		if ( $data['collision_count'] > 0 ) {
			\WP_CLI::log( '' );
			\WP_CLI::warning(
				sprintf(
					'%d event(s) contain both the primary and duplicate player(s). Performance stats in those events will be combined.',
					$data['collision_count']
				)
			);
		}

		$this->render_resolution_section(
			$data['array_field_filled'],
			"%d value(s) will be taken from a duplicate because the primary's cell is blank:",
			true
		);

		$this->render_resolution_section(
			$data['array_field_conflicts'],
			"%d value(s) differ between the players. The primary's value is kept and the duplicate's is discarded:",
			false
		);
	}

	/**
	 * Render one primary/duplicates/result comparison row.
	 *
	 * @param string $label Section label.
	 * @param array  $row   array{primary: mixed, duplicates: array, result: array}.
	 */
	private function render_comparison_section( string $label, array $row ): void {
		$primary = $row['primary'];
		$primary = is_array( $primary ) ? implode( ', ', $primary ) : ( $primary ?? '' );

		\WP_CLI::log( sprintf( '%s:', $label ) );
		\WP_CLI::log( sprintf( '  Primary:    %s', '' === $primary ? 'None' : $primary ) );
		\WP_CLI::log( sprintf( '  Duplicates: %s', empty( $row['duplicates'] ) ? 'None' : implode( ', ', $row['duplicates'] ) ) );
		\WP_CLI::log( sprintf( '  Result:     %s', empty( $row['result'] ) ? 'None' : implode( ', ', $row['result'] ) ) );
	}

	/**
	 * Render the full filled/conflict resolution list for one action kind.
	 *
	 * Unlike SP_Merge_Preview's HTML table, nothing here is capped: a terminal
	 * report has no screen-space reason to summarise a ten-season merge.
	 *
	 * @param array  $resolutions Resolutions of a single kind.
	 * @param string $heading     sprintf() template for the section heading, taking the count.
	 * @param bool   $is_filled   True for cells taken from the duplicate, false for discarded values.
	 */
	private function render_resolution_section( array $resolutions, string $heading, bool $is_filled ): void {
		if ( empty( $resolutions ) ) {
			return;
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( sprintf( $heading, count( $resolutions ) ) );

		foreach ( $resolutions as $resolution ) {
			$address = SP_Merge_Processor::format_resolution_path( $resolution );
			$kept    = SP_Merge_Processor::format_resolution_value( $resolution['kept'] );

			if ( $is_filled ) {
				\WP_CLI::log(
					sprintf(
						'  %1$s — the duplicate\'s value "%2$s" will be used (player %3$d)',
						$address,
						$kept,
						(int) $resolution['duplicate_id']
					)
				);
			} else {
				\WP_CLI::log(
					sprintf(
						'  %1$s — keeping "%2$s", discarding "%3$s" (player %4$d)',
						$address,
						$kept,
						SP_Merge_Processor::format_resolution_value( $resolution['discarded'] ),
						(int) $resolution['duplicate_id']
					)
				);
			}
		}
	}

	/**
	 * Execute a merge from the command line.
	 *
	 * Runs the same preview and survivor-warning checks the admin screen shows,
	 * unless explicitly skipped, so an operator scripting this cannot execute a
	 * selection they never had a chance to see. `--force` only ever overrides the
	 * survivor warning; it never skips the preview, and it never implies `--yes`
	 * — a forced merge still stops for confirmation unless `--yes` is also given.
	 *
	 * ## OPTIONS
	 *
	 * <primary-id>
	 * : ID of the player that will survive the merge.
	 *
	 * <duplicate-id>...
	 * : ID(s) of the player(s) that will be merged into the primary and deleted.
	 *
	 * [--skip-preview]
	 * : Do not print the preview report before asking for confirmation.
	 *
	 * [--force]
	 * : Proceed despite a survivor warning (the chosen primary holds
	 * substantially less history than a duplicate). Has no effect when there is
	 * no warning to override, and does not skip the confirmation prompt.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sp-merge merge 123 456 789
	 *     wp sp-merge merge 123 456 --force --yes
	 *
	 * @param array $args       Positional arguments: primary ID, then duplicate ID(s).
	 * @param array $assoc_args Associative arguments: skip-preview, force, yes.
	 */
	public function merge( $args, $assoc_args ): void {
		if ( ! current_user_can( 'manage_sportspress' ) && ! current_user_can( 'delete_sp_players' ) ) {
			\WP_CLI::error( 'Insufficient permissions.' );
		}

		$primary_raw   = $args[0] ?? null;
		$duplicate_raw = array_slice( $args, 1 );

		if ( null === $primary_raw || empty( $duplicate_raw ) ) {
			\WP_CLI::error( 'Usage: wp sp-merge merge <primary-id> <duplicate-id>...' );
		}

		// absint() here mirrors exactly what SP_Merge_Validation::validate_merge_selection()
		// would do to these values anyway (it is idempotent), so pre-casting to satisfy
		// run_one_merge()'s int/int[] signature changes nothing about how an invalid
		// (e.g. non-numeric) selection is reported.
		$outcome = $this->run_one_merge( absint( $primary_raw ), array_map( 'absint', $duplicate_raw ), $assoc_args );

		if ( ! $outcome['success'] ) {
			\WP_CLI::error( $outcome['message'] );
		}
	}

	/**
	 * Run one merge: validate, preview, survivor-warning gate, confirm, execute.
	 *
	 * Shared by `merge` (a single operator-driven merge) and `batch` (many merges
	 * read from a file). Never calls \WP_CLI::error() itself — doing so would halt
	 * the whole PHP process, which `batch` cannot allow under --continue-on-error
	 * — so every refusal (invalid selection, unoverridden survivor warning,
	 * processor failure) comes back as success:false with a message for the
	 * caller to act on however it needs to (merge() turns it into a fatal error;
	 * batch() logs it and decides whether to keep going).
	 *
	 * `--dry-run` in $assoc_args stops right after the same preview/warning gate
	 * a real run would refuse at, and never reaches confirm()/execute_merge() —
	 * merge() never sets this key, so its own behavior is completely unaffected.
	 *
	 * @param int   $primary_id    ID of the player that will survive the merge.
	 * @param int[] $duplicate_ids ID(s) of the player(s) to merge in and delete.
	 * @param array $assoc_args    Associative arguments: skip-preview, force, yes, dry-run.
	 * @return array{success: bool, backup_id: ?string, message: ?string}
	 */
	private function run_one_merge( int $primary_id, array $duplicate_ids, array $assoc_args ): array {
		$result = SP_Merge_Validation::validate_merge_selection( $primary_id, $duplicate_ids );
		if ( ! $result['valid'] ) {
			return array(
				'success'   => false,
				'backup_id' => null,
				'message'   => $result['error'],
			);
		}

		if ( ! isset( $assoc_args['skip-preview'] ) ) {
			$preview_data = ( new SP_Merge_Preview() )->generate_data( $result['primary_id'], $result['duplicate_ids'] );
			$this->render_preview_data( $preview_data );
		}

		$warnings         = SP_Merge_Validation::survivor_warnings( $result['primary_id'], $result['duplicate_ids'] );
		$warning_override = false;

		if ( ! empty( $warnings ) ) {
			// Printed regardless of --force: the confirmation question below
			// refers back to these ("see above"), so they must be visible
			// before the operator is asked to override them.
			foreach ( $warnings as $warning ) {
				\WP_CLI::warning( $warning );
			}

			if ( ! isset( $assoc_args['force'] ) ) {
				return array(
					'success'   => false,
					'backup_id' => null,
					'message'   => 'Merge refused: survivor warning(s) above. Re-run with --force to override.',
				);
			}

			$warning_override = true;
		}

		if ( isset( $assoc_args['dry-run'] ) ) {
			return array(
				'success'   => true,
				'backup_id' => null,
				'message'   => sprintf(
					'DRY RUN: would merge %1$d player(s) into #%2$d.',
					count( $result['duplicate_ids'] ),
					$result['primary_id']
				),
			);
		}

		$question = sprintf(
			'Merge %1$d player(s) into #%2$d? This permanently deletes the duplicate posts.',
			count( $result['duplicate_ids'] ),
			$result['primary_id']
		);

		if ( $warning_override ) {
			$question = 'Survivor warning overridden — see above. ' . $question;
		}

		\WP_CLI::confirm( $question, $assoc_args );

		$merge_result = ( new SP_Merge_Processor() )->execute_merge( $result['primary_id'], $result['duplicate_ids'] );

		if ( ! $merge_result['success'] ) {
			return array(
				'success'   => false,
				'backup_id' => null,
				'message'   => $merge_result['message'],
			);
		}

		\WP_CLI::success( sprintf( 'Merge completed. Backup ID: %s', $merge_result['backup_id'] ) );

		foreach ( (array) ( $merge_result['resolutions'] ?? array() ) as $resolution ) {
			$address = SP_Merge_Processor::format_resolution_path( $resolution );
			$kept    = SP_Merge_Processor::format_resolution_value( $resolution['kept'] );

			if ( 'conflict' === $resolution['action'] ) {
				\WP_CLI::log(
					sprintf(
						'  %1$s — keeping "%2$s", discarding "%3$s" (player %4$d)',
						$address,
						$kept,
						SP_Merge_Processor::format_resolution_value( $resolution['discarded'] ),
						(int) $resolution['duplicate_id']
					)
				);
			} else {
				\WP_CLI::log(
					sprintf(
						'  %1$s — the duplicate\'s value "%2$s" was used (player %3$d)',
						$address,
						$kept,
						(int) $resolution['duplicate_id']
					)
				);
			}
		}

		return array(
			'success'   => true,
			'backup_id' => $merge_result['backup_id'],
			'message'   => null,
		);
	}

	/**
	 * Run many merges from a CSV or JSON file, one `run_one_merge()` call per row.
	 *
	 * `--log` is mandatory rather than optional: the admin screen's backup list
	 * only shows the 10 most recent backups per page, so a batch of any real size
	 * needs its own externally recorded record of every backup ID it produced —
	 * without one, there is no way to find, and so no way to revert, most of a
	 * large batch's merges.
	 *
	 * Rows are processed strictly in file order with a plain sequential loop —
	 * the `sp_merge_lock` transient inside SP_Merge_Processor::execute_merge()
	 * already serializes merges, so this deliberately adds no locking of its own.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the CSV or JSON input file.
	 *
	 * [--format=<csv|json>]
	 * : Input format. Defaults to sniffing the file extension (.csv or .json);
	 * required when the extension is anything else.
	 *
	 * [--stop-on-error]
	 * : Halt on the first row that fails (after logging it). This is the default.
	 *
	 * [--continue-on-error]
	 * : Keep processing every row regardless of earlier failures.
	 *
	 * [--dry-run]
	 * : Run the preview and survivor-warning gate for every row, but never
	 * execute a merge. The log still gets one line per row.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt for every row.
	 *
	 * --log=<path>
	 * : Required. Path to append one JSON-Lines record to per processed row.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sp-merge batch players.csv --log=/tmp/batch.log
	 *     wp sp-merge batch players.json --continue-on-error --log=/tmp/batch.log
	 *     wp sp-merge batch players.csv --dry-run --log=/tmp/batch.log
	 *
	 * @param array $args       Positional arguments: the input file path.
	 * @param array $assoc_args Associative arguments: format, stop-on-error,
	 *                          continue-on-error, dry-run, yes, log.
	 */
	public function batch( $args, $assoc_args ): void {
		if ( ! current_user_can( 'manage_sportspress' ) && ! current_user_can( 'delete_sp_players' ) ) {
			\WP_CLI::error( 'Insufficient permissions.' );
		}

		$log_path = $assoc_args['log'] ?? null;
		if ( null === $log_path || '' === $log_path ) {
			\WP_CLI::error( '--log is required.' );
		}

		$file = $args[0] ?? null;
		if ( null === $file ) {
			\WP_CLI::error( 'Usage: wp sp-merge batch <file> --log=<path>' );
		}

		$format = $assoc_args['format'] ?? $this->sniff_batch_format( $file );
		if ( null === $format ) {
			\WP_CLI::error( 'Cannot determine input format: pass --format=<csv|json>, or use a .csv/.json file extension.' );
		}

		$contents = file_get_contents( $file );
		if ( false === $contents ) {
			\WP_CLI::error( sprintf( 'Could not read file: %s', $file ) );
		}

		$rows = 'csv' === $format ? $this->parse_batch_csv( $contents ) : $this->parse_batch_json( $contents );

		$log_handle = fopen( $log_path, 'a' );
		if ( false === $log_handle ) {
			\WP_CLI::error( sprintf( 'Could not open log file for writing: %s', $log_path ) );
		}

		$continue_on_error = isset( $assoc_args['continue-on-error'] );
		$total             = count( $rows );
		$processed         = 0;
		$succeeded         = 0;
		$failed            = 0;

		foreach ( $rows as $row ) {
			$outcome = $this->run_one_merge( $row['primary_id'], $row['duplicate_ids'], $assoc_args );

			// Written and flushed immediately, one row at a time: a crash mid-batch
			// must not lose the backup IDs of rows that already finished.
			fwrite(
				$log_handle,
				wp_json_encode(
					array(
						'primary_id'    => $row['primary_id'],
						'duplicate_ids' => $row['duplicate_ids'],
						'success'       => $outcome['success'],
						'backup_id'     => $outcome['backup_id'],
						'message'       => $outcome['message'],
					)
				) . "\n"
			);
			fflush( $log_handle );

			++$processed;

			if ( $outcome['success'] ) {
				++$succeeded;
				continue;
			}

			++$failed;
			\WP_CLI::warning(
				sprintf( 'Row %1$d (primary #%2$d): %3$s', $processed, $row['primary_id'], $outcome['message'] )
			);

			if ( ! $continue_on_error ) {
				break;
			}
		}

		fclose( $log_handle );

		// Logged before any fatal error() below: the summary must be visible
		// (and the batch's log file already closed) regardless of which branch
		// this ends in.
		\WP_CLI::log(
			sprintf(
				'Processed %1$d of %2$d row(s): %3$d succeeded, %4$d failed, %5$d remaining.',
				$processed,
				$total,
				$succeeded,
				$failed,
				$total - $processed
			)
		);

		if ( ! $continue_on_error && $failed > 0 ) {
			\WP_CLI::error(
				sprintf( 'Batch halted after row %1$d failed. %2$d row(s) remaining unprocessed.', $processed, $total - $processed )
			);
		}

		if ( $failed > 0 ) {
			\WP_CLI::warning( sprintf( '%d row(s) failed. See %s for details.', $failed, $log_path ) );
			return;
		}

		\WP_CLI::success( sprintf( 'Batch completed: %d row(s) succeeded.', $succeeded ) );
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
	 * (e.g. `101,205;309`). No header row. absint() normalizes every ID exactly
	 * as SP_Merge_Validation::validate_merge_selection() would anyway, so a
	 * malformed row (blank primary, non-numeric ID) surfaces as that row's own
	 * "Invalid player selection" failure rather than a parse-time fatal.
	 *
	 * @param string $contents Raw file contents.
	 * @return array{primary_id: int, duplicate_ids: int[]}[]
	 */
	private function parse_batch_csv( string $contents ): array {
		$rows = array();

		foreach ( preg_split( '/\r\n|\r|\n/', trim( $contents ) ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$fields = str_getcsv( $line );

			$rows[] = array(
				'primary_id'    => absint( $fields[0] ?? 0 ),
				'duplicate_ids' => array_values(
					array_filter( array_map( 'absint', explode( ';', (string) ( $fields[1] ?? '' ) ) ) )
				),
			);
		}

		return $rows;
	}

	/**
	 * Parse batch JSON input into the uniform row shape run_one_merge() expects.
	 *
	 * Shape: an array of `{"primary_id": 101, "duplicate_ids": [205, 309]}`
	 * objects. As with the CSV parser, IDs are absint()-normalized here rather
	 * than validated — an invalid row still reaches run_one_merge() and fails
	 * there, exactly like a bad CSV row.
	 *
	 * @param string $contents Raw file contents.
	 * @return array{primary_id: int, duplicate_ids: int[]}[]
	 */
	private function parse_batch_json( string $contents ): array {
		$decoded = json_decode( $contents, true );

		if ( ! is_array( $decoded ) ) {
			\WP_CLI::error( 'Invalid JSON input: expected an array of {"primary_id":.., "duplicate_ids":[..]} objects.' );
		}

		$rows = array();

		foreach ( $decoded as $entry ) {
			$rows[] = array(
				'primary_id'    => absint( $entry['primary_id'] ?? 0 ),
				'duplicate_ids' => array_values(
					array_filter( array_map( 'absint', (array) ( $entry['duplicate_ids'] ?? array() ) ) )
				),
			);
		}

		return $rows;
	}

	/**
	 * Revert a merge from the command line.
	 *
	 * A plain revert is attempted first, unforced, regardless of whether
	 * `--force` was passed — that first call either succeeds outright (nothing
	 * to override, nothing to confirm) or fails cleanly with no side effects
	 * (guards run before anything is written). Only when that call refuses with
	 * `values_changed`, and `--force` was given, is the operator shown what
	 * would be discarded and asked to confirm a second, forced call. A
	 * `conflict` refusal (a later merge overlaps this one) is never forceable,
	 * by design of SP_Merge_Backup::revert() itself — reverting it requires
	 * unwinding the later merge first.
	 *
	 * ## OPTIONS
	 *
	 * <backup-id>
	 * : Backup ID to revert.
	 *
	 * [--user=<id|login>]
	 * : Revert a backup owned by another user. Defaults to the current user;
	 * targeting anyone else requires the delete_sp_players capability.
	 *
	 * [--force]
	 * : Override a refusal caused by values changing after the merge ran. Has
	 * no effect on a conflict refusal, which is never forceable.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sp-merge revert merge_1700000000_abcd1234
	 *     wp sp-merge revert merge_1700000000_abcd1234 --force --yes
	 *
	 * @param array $args       Positional arguments: the backup ID.
	 * @param array $assoc_args Associative arguments: user, force, yes.
	 */
	public function revert( $args, $assoc_args ): void {
		if ( ! current_user_can( 'manage_sportspress' ) && ! current_user_can( 'delete_sp_players' ) ) {
			\WP_CLI::error( 'Insufficient permissions.' );
		}

		$backup_id = $args[0] ?? null;
		if ( null === $backup_id ) {
			\WP_CLI::error( 'Usage: wp sp-merge revert <backup-id>' );
		}

		$owner_id = $this->resolve_target_user( $assoc_args['user'] ?? null );
		$backup   = new SP_Merge_Backup();

		// Always the first call, whether or not --force was passed: it either
		// completes the revert (nothing needed overriding) or refuses without
		// having written anything.
		$attempt = $backup->revert( $backup_id, false, $owner_id );

		if ( $attempt['success'] ) {
			\WP_CLI::success( 'Merge reverted successfully' );
			return;
		}

		if ( ! isset( $assoc_args['force'] ) || 'values_changed' !== ( $attempt['code'] ?? '' ) ) {
			// Not forced, or a refusal --force could never have overridden
			// anyway (not_found, conflict, error) — nothing left to try.
			\WP_CLI::error( $attempt['message'] );
		}

		\WP_CLI::warning( $attempt['message'] );
		\WP_CLI::confirm( 'Override and revert anyway? Everything listed above was written after the merge and will be permanently discarded.', $assoc_args );

		$forced = $backup->revert( $backup_id, true, $owner_id );

		if ( ! $forced['success'] ) {
			\WP_CLI::error( $forced['message'] );
		}

		\WP_CLI::success( 'Merge reverted using the override. Values changed since the merge were discarded.' );
	}

	/**
	 * List recent merge backups.
	 *
	 * Everyone with edit_sp_players can list their own backups; seeing every
	 * user's backups is a step up in exposure (names and IDs of records other
	 * League Managers merged) and needs delete_sp_players, same as deleting one.
	 *
	 * ## OPTIONS
	 *
	 * [--user=<id|login>]
	 * : List backups owned by another user instead of the current user.
	 * Ignored when --all-users is passed. Targeting anyone else requires the
	 * delete_sp_players capability.
	 *
	 * [--all-users]
	 * : List backups for every user. Requires the delete_sp_players capability.
	 *
	 * [--status=<status>]
	 * : Only list backups with this status (e.g. active, pending, failed, reverted).
	 *
	 * [--limit=<n>]
	 * : Maximum number of backups to return.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp sp-merge backups list --all-users --status=active
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments: user, all-users, status, limit, format.
	 */
	public function backups_list( $args, $assoc_args ): void {
		$all_users    = isset( $assoc_args['all-users'] );
		$required_cap = $all_users ? 'delete_sp_players' : 'edit_sp_players';

		if ( ! current_user_can( $required_cap ) ) {
			\WP_CLI::error(
				$all_users
					? 'Only an Administrator (delete_sp_players) can list every user\'s backups.'
					: 'Insufficient permissions.'
			);
		}

		$user_id = $all_users ? null : $this->resolve_target_user( $assoc_args['user'] ?? null );
		$status  = $assoc_args['status'] ?? null;
		$format  = $assoc_args['format'] ?? 'table';
		$admin   = new SP_Merge_Admin();

		$backups = isset( $assoc_args['limit'] )
			? $admin->get_recent_backups( (int) $assoc_args['limit'], $user_id, $all_users )
			: $admin->get_recent_backups( user_id: $user_id, all_users: $all_users );

		if ( false === $backups ) {
			\WP_CLI::error( 'Failed to retrieve backup data.' );
		}

		if ( null !== $status ) {
			$backups = array_values(
				array_filter(
					$backups,
					static function ( $backup ) use ( $status ) {
						return ( $backup['status'] ?? '' ) === $status;
					}
				)
			);
		}

		\WP_CLI\Utils\format_items( $format, $backups, array( 'id', 'date', 'status', 'primary_name', 'duplicate_names' ) );
	}

	/**
	 * Delete one or more merge backups.
	 *
	 * A deleted backup is the only recovery path for its merge, so this always
	 * requires delete_sp_players — there is no lower "delete your own backup"
	 * tier the way merge/revert have a League Manager tier.
	 *
	 * ## OPTIONS
	 *
	 * <backup-id>...
	 * : Backup ID(s) to delete.
	 *
	 * [--user=<id|login>]
	 * : Delete backup(s) owned by another user instead of the current user.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sp-merge backups delete merge_1700000000_abcd1234 --yes
	 *
	 * @param array $args       Positional arguments: backup ID(s).
	 * @param array $assoc_args Associative arguments: user, yes.
	 */
	public function backups_delete( $args, $assoc_args ): void {
		if ( ! current_user_can( 'delete_sp_players' ) ) {
			\WP_CLI::error( 'Insufficient permissions.' );
		}

		if ( empty( $args ) ) {
			\WP_CLI::error( 'Usage: wp sp-merge backups delete <backup-id>...' );
		}

		// The helper's own capability check always passes here — delete_sp_players
		// was already required above — but it still resolves --user consistently
		// with every other subcommand, so it is reused rather than duplicated.
		$owner_id = $this->resolve_target_user( $assoc_args['user'] ?? null );

		\WP_CLI::warning( 'Deleting a backup permanently removes the only recovery path for that merge.' );
		\WP_CLI::confirm(
			sprintf( 'Delete %d backup(s)? This cannot be undone.', count( $args ) ),
			$assoc_args
		);

		$deleted = ( new SP_Merge_Backup() )->delete_backups( $args, $owner_id );

		\WP_CLI::success( sprintf( '%d backup(s) deleted.', $deleted ) );
	}

	/**
	 * Resolve which user a subcommand should act on behalf of.
	 *
	 * Defaults to the current user. An explicit target is only permitted for a
	 * caller holding delete_sp_players — the same tier the AJAX layer requires
	 * for touching another user's backups at all — so a League Manager cannot
	 * use `--user` to reach into an Administrator's (or another League
	 * Manager's) backups.
	 *
	 * @param string|null $user_arg Raw --user value: numeric ID or login, or null/empty for "self".
	 * @return int Resolved user ID.
	 */
	private function resolve_target_user( ?string $user_arg ): int {
		if ( null === $user_arg || '' === $user_arg ) {
			return get_current_user_id();
		}

		$user = is_numeric( $user_arg ) ? get_user_by( 'id', (int) $user_arg ) : get_user_by( 'login', $user_arg );
		if ( ! $user ) {
			\WP_CLI::error( sprintf( 'No user found matching "%s".', $user_arg ) );
		}

		$target_id = (int) $user->ID;

		if ( $target_id !== get_current_user_id() && ! current_user_can( 'delete_sp_players' ) ) {
			\WP_CLI::error( 'Only an Administrator (delete_sp_players) can act on another user\'s backups.' );
		}

		return $target_id;
	}
}
