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

/*
 * `backups list`/`backups delete` are registered from SP_Merge_CLI_Backups
 * instead (see classes/class-sp-merge-cli-backups.php): registering them as
 * methods here as well as under an explicit `sp-merge backups <verb>` command
 * path would give WP-CLI's method-name convention (each public method becomes
 * its own hyphenated leaf subcommand) a second, redundant spelling for the
 * same destructive operation — `wp sp-merge backups-list` alongside the
 * intended `wp sp-merge backups list`.
 *
 * `batch` lives on SP_Merge_CLI_Batch (classes/class-sp-merge-cli-batch.php)
 * for a different reason: it is a file reader, two parsers, a log writer and a
 * row loop that nothing else uses, and it dwarfed every other subcommand here.
 * It still runs each of its rows through this class's run_one_merge(), which is
 * why that one is `public static` — public so the batch class can reach it,
 * static because WP-CLI maps only public *instance* methods to subcommands, and
 * `wp sp-merge run-one-merge` is not a command anyone should be able to type.
 */
/**
 * Scan for, preview, execute, and revert SportsPress player merges from the
 * command line — headless equivalents of the Player Merge admin screen, for
 * scripting and bulk operations.
 */
class SP_Merge_CLI {

	/**
	 * Fields printed for each scanned group member, in column order.
	 *
	 * @var string[]
	 */
	private const SCAN_FIELDS = array( 'group_certainty', 'scenario', 'player_id', 'name', 'member_certainty', 'events' );

	/**
	 * Every scenario name SP_Merge_Name_Matcher::compare() can return. Must be
	 * kept in sync with that method's own 'scenario' literals — used only to
	 * reject a typo'd --scenario value with a clear error instead of silently
	 * returning zero rows.
	 *
	 * @var string[]
	 */
	private const VALID_SCENARIOS = array(
		'exact',
		'normalization',
		'reversal',
		'french_compound',
		'nickname',
		'nickname+typo',
		'nickname+compound',
		'typo',
		'initial',
		'middle_name',
		'compound_last',
	);

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
	 * Compared against the same adjusted score the admin screen shows — the raw
	 * name-matcher score after the shared email/team boosts and the
	 * different-positions penalty, not the raw score itself.
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
		if ( ! current_user_can( 'edit_sp_players' ) ) {
			\WP_CLI::error( __( 'Insufficient permissions.', 'sportspress-player-merge' ) );
		}

		$min_certainty = SP_Merge_CLI_Support::int_option( $assoc_args, 'min-certainty', 0, 0, 100, __( '--min-certainty must be an integer between 0 and 100.', 'sportspress-player-merge' ) );
		$limit         = SP_Merge_CLI_Support::int_option( $assoc_args, 'limit', 50, 1, PHP_INT_MAX, __( '--limit must be a positive integer.', 'sportspress-player-merge' ) );

		if ( isset( $assoc_args['scenario'] ) && ! in_array( $assoc_args['scenario'], self::VALID_SCENARIOS, true ) ) {
			\WP_CLI::error(
				sprintf(
					/* translators: 1: the invalid --scenario value, 2: comma-separated list of valid scenario names */
					__( 'Unknown --scenario "%1$s". Valid scenarios: %2$s.', 'sportspress-player-merge' ),
					$assoc_args['scenario'],
					implode( ', ', self::VALID_SCENARIOS )
				)
			);
		}

		$scan   = SP_Merge_Validation::collect_scan_players();
		$groups = SP_Merge_Name_Matcher::find_groups( $scan['players'] );

		$this->prime_scan_caches( $groups );

		// Adjusted before anything is filtered or dropped: --min-certainty has to
		// mean the same thing the admin screen's badge means, or a pair the
		// browser demoted to "low confidence" would still pass --min-certainty=90
		// on a command that permanently deletes posts.
		$groups = array_map( array( $this, 'adjust_group_certainty' ), $groups );
		$groups = $this->rank_scan_groups( $groups, $assoc_args['scenario'] ?? null, $min_certainty, $limit );

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $this->flatten_scan_groups( $groups ), self::SCAN_FIELDS );

		$this->report_scan_coverage( $scan );
	}

	/**
	 * Prime the post and term caches for every scanned group member.
	 *
	 * The per-group certainty adjustment that follows calls get_post_meta()/
	 * get_post()/wp_get_post_terms() per group member: without this, each of those
	 * is an uncached, one-row-at-a-time query — exactly the N+1
	 * SP_Merge_Ajax::find_duplicates() already primes against for the same loop.
	 *
	 * @param array $groups Groups from SP_Merge_Name_Matcher::find_groups().
	 */
	private function prime_scan_caches( array $groups ): void {
		$scan_player_ids = array();
		foreach ( $groups as $group ) {
			foreach ( $group['players'] as $member ) {
				$scan_player_ids[] = (int) $member->ID;
			}
		}

		if ( ! empty( $scan_player_ids ) ) {
			update_meta_cache( 'post', $scan_player_ids );
			update_object_term_cache( $scan_player_ids, 'sp_player' );
		}
	}

	/**
	 * Rank the adjusted groups, drop the ones the filters exclude, and cap them.
	 *
	 * Re-sorted for the same reason SP_Merge_Ajax::find_duplicates() re-sorts: the
	 * certainty adjustments can reorder the matcher's own ranking, and --limit
	 * must keep the strongest groups by the score actually being reported.
	 *
	 * @param array       $groups        Groups whose certainties are already adjusted.
	 * @param string|null $scenario      --scenario value, or null for every scenario.
	 * @param int         $min_certainty --min-certainty floor.
	 * @param int         $limit         --limit cap, applied last.
	 * @return array
	 */
	private function rank_scan_groups( array $groups, ?string $scenario, int $min_certainty, int $limit ): array {
		usort( $groups, static fn( $a, $b ) => $b['certainty'] - $a['certainty'] );

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

		return array_slice( $groups, 0, $limit );
	}

	/**
	 * Report how much of the roster the scan actually covered.
	 *
	 * A warning rather than a log line when the roster was truncated: the groups
	 * printed above are then only the duplicates among the players that were
	 * looked at, which is not what "no more duplicates" looks like.
	 *
	 * @param array $scan Return value of SP_Merge_Validation::collect_scan_players().
	 */
	private function report_scan_coverage( array $scan ): void {
		if ( $scan['truncated'] ) {
			\WP_CLI::warning(
				sprintf(
					/* translators: 1: players scanned, 2: total published players, 3: players skipped */
					__( 'Scanned %1$d of %2$d players; %3$d were skipped.', 'sportspress-player-merge' ),
					$scan['scanned'],
					$scan['total'],
					$scan['total'] - $scan['scanned']
				)
			);
			return;
		}

		\WP_CLI::log(
			sprintf(
				/* translators: 1: players scanned, 2: total published players */
				__( 'Scanned %1$d of %2$d players.', 'sportspress-player-merge' ),
				$scan['scanned'],
				$scan['total']
			)
		);
	}

	/**
	 * Apply the shared certainty adjustments to one matched group.
	 *
	 * The name matcher scores names only. SP_Merge_Ajax::find_duplicates() has
	 * always applied three further signals to that score before showing it — a
	 * shared email boosts, a shared current team boosts, differing positions
	 * penalise down to a floor of 50 — and `scan` reported the raw score instead,
	 * which reads as *more* confident than the browser on exactly the pairs the
	 * browser is warning about. Both now go through
	 * SP_Merge_Validation::apply_certainty_adjustments().
	 *
	 * @param array $group One group from SP_Merge_Name_Matcher::find_groups().
	 * @return array The same group with its group and per-member certainties adjusted.
	 */
	private function adjust_group_certainty( array $group ): array {
		$members = array();

		foreach ( $group['players'] as $member ) {
			$player_id = (int) $member->ID;
			$signals   = SP_Merge_Validation::certainty_signals( $player_id );

			$members[] = array(
				'email'     => $signals['email'],
				'position'  => $signals['position'],
				'team_id'   => $signals['team_id'],
				'certainty' => $group['member_certainty'][ $player_id ] ?? ( $member->certainty ?? null ),
			);
		}

		$adjusted           = SP_Merge_Validation::apply_certainty_adjustments( $group, $members );
		$group['certainty'] = $adjusted['certainty'];

		foreach ( array_values( $group['players'] ) as $index => $member ) {
			$player_id = (int) $member->ID;
			$score     = $adjusted['members'][ $index ]['certainty'] ?? null;

			// The matcher hands out cloned post objects per group, so writing the
			// adjusted score back cannot leak onto a cached WP_Post.
			$member->certainty                       = $score;
			$group['member_certainty'][ $player_id ] = $score;
			$group['certainties'][ $player_id ]      = $score;
		}

		return $group;
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

	// Deliberately does not run SP_Merge_Validation::validate_merge_selection()
	// first: this command is read-only and informational, so an operator can
	// point it at any two IDs to see what SP_Merge_Preview would show, including
	// IDs that would fail validation, without that failing loudly.
	/**
	 * Preview a merge without executing it. Always exits 0 — nothing here is a
	 * WP-CLI error condition, even for an invalid or nonexistent selection.
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
	 * payload; table (the default) renders the human-readable report, since
	 * there is no flat row for a table to render.
	 * ---
	 * default: table
	 * options:
	 *   - table
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
		if ( ! current_user_can( 'edit_sp_players' ) ) {
			\WP_CLI::error( __( 'Insufficient permissions.', 'sportspress-player-merge' ) );
		}

		$primary_id    = absint( $args[0] ?? 0 );
		$duplicate_ids = array_map( 'absint', array_slice( $args, 1 ) );
		$format        = $assoc_args['format'] ?? null;
		$porcelain     = isset( $assoc_args['porcelain'] );

		try {
			$data = ( new SP_Merge_Preview() )->generate_data( $primary_id, $duplicate_ids );
		} catch ( Throwable $e ) {
			// Throwable, not Exception: generate_data() walks decade-old serialized
			// postmeta through deep_merge_arrays(), and malformed legacy data raises
			// a TypeError, which is not an Exception in PHP 8. Same guard every AJAX
			// handler that calls SP_Merge_Preview already has. Degrades to a warning
			// rather than an error() because this command's contract is to always
			// exit 0 — a script branching on --porcelain reads the line, not the code.
			\WP_CLI::warning( $e->getMessage() );

			// A script relying on --porcelain must only ever see OK or WARN, never
			// silence: without this, a Throwable here printed nothing to stdout at
			// all, an undocumented third state.
			if ( $porcelain ) {
				\WP_CLI::log( 'WARN' );
			}
			return;
		}

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

		self::render_preview_data( $data );
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
	private static function render_preview_data( array $data ): void {
		$duplicate_names = implode(
			', ',
			array_map(
				static function ( $duplicate ) {
					return sprintf( '%s (#%d)', $duplicate['name'], $duplicate['id'] );
				},
				$data['duplicates']
			)
		);

		\WP_CLI::log(
			sprintf(
				/* translators: 1: primary player name, 2: primary player ID */
				__( 'Primary:    %1$s (#%2$d)', 'sportspress-player-merge' ),
				$data['primary']['name'],
				$data['primary']['id']
			)
		);
		\WP_CLI::log( sprintf( /* translators: %s: comma-separated duplicate player names and IDs */ __( 'Duplicates: %s', 'sportspress-player-merge' ), $duplicate_names ) );
		\WP_CLI::log( '' );

		self::render_comparison_section( __( 'Current team', 'sportspress-player-merge' ), $data['current_team'] );
		self::render_comparison_section( __( 'Past team(s)', 'sportspress-player-merge' ), $data['past_teams'] );

		foreach ( $data['taxonomies'] as $taxonomy ) {
			self::render_comparison_section( $taxonomy['label'], $taxonomy );
		}

		\WP_CLI::log(
			sprintf(
				/* translators: 1: primary player's event count, 2: duplicates' combined event count, 3: resulting event count */
				__( 'Events: primary %1$d, duplicates %2$d, result %3$d', 'sportspress-player-merge' ),
				$data['events']['primary'],
				$data['events']['duplicates'],
				$data['events']['result']
			)
		);

		if ( $data['collision_count'] > 0 ) {
			\WP_CLI::log( '' );
			\WP_CLI::warning(
				sprintf(
					/* translators: %d: number of events containing both the primary and a duplicate */
					__( '%d event(s) contain both the primary and duplicate player(s). Performance stats in those events will be combined.', 'sportspress-player-merge' ),
					$data['collision_count']
				)
			);
		}

		self::render_resolution_section(
			$data['array_field_filled'],
			/* translators: %d: number of values filled from a duplicate */
			__( "%d value(s) will be taken from a duplicate because the primary's cell is blank:", 'sportspress-player-merge' ),
			true
		);

		self::render_resolution_section(
			$data['array_field_conflicts'],
			/* translators: %d: number of conflicting values */
			__( "%d value(s) differ between the players. The primary's value is kept and the duplicate's is discarded:", 'sportspress-player-merge' ),
			false
		);
	}

	/**
	 * Render one primary/duplicates/result comparison row.
	 *
	 * @param string $label Section label.
	 * @param array  $row   array{primary: mixed, duplicates: array, result: array}.
	 */
	private static function render_comparison_section( string $label, array $row ): void {
		$primary = $row['primary'];
		$primary = is_array( $primary ) ? implode( ', ', $primary ) : ( $primary ?? '' );

		$none = __( 'None', 'sportspress-player-merge' );

		\WP_CLI::log( sprintf( '%s:', $label ) );
		\WP_CLI::log( sprintf( /* translators: %s: value, or None */ __( '  Primary:    %s', 'sportspress-player-merge' ), '' === $primary ? $none : $primary ) );
		\WP_CLI::log( sprintf( /* translators: %s: comma-separated values, or None */ __( '  Duplicates: %s', 'sportspress-player-merge' ), empty( $row['duplicates'] ) ? $none : implode( ', ', $row['duplicates'] ) ) );
		\WP_CLI::log( sprintf( /* translators: %s: comma-separated values, or None */ __( '  Result:     %s', 'sportspress-player-merge' ), empty( $row['result'] ) ? $none : implode( ', ', $row['result'] ) ) );
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
	private static function render_resolution_section( array $resolutions, string $heading, bool $is_filled ): void {
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
						/* translators: 1: field address, 2: value used, 3: duplicate player ID */
						__( '  %1$s — the duplicate\'s value "%2$s" will be used (player %3$d)', 'sportspress-player-merge' ),
						$address,
						$kept,
						(int) $resolution['duplicate_id']
					)
				);
			} else {
				\WP_CLI::log(
					sprintf(
						/* translators: 1: field address, 2: value kept, 3: value discarded, 4: duplicate player ID */
						__( '  %1$s — keeping "%2$s", discarding "%3$s" (player %4$d)', 'sportspress-player-merge' ),
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
	 * [--porcelain]
	 * : On success, print only the backup ID and nothing else — no preview
	 * report, no "Merge completed" message, no per-cell resolution list.
	 * Implies --skip-preview (the preview report would otherwise still be
	 * printed to stdout ahead of the backup ID, defeating the point of a
	 * machine-readable success line). Matches `wp post create --porcelain`'s
	 * convention. Has no effect on failure, which always goes through the
	 * normal error path regardless.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sp-merge merge 123 456 789
	 *     wp sp-merge merge 123 456 --force --yes
	 *     wp sp-merge merge 123 456 --yes --porcelain
	 *
	 * @param array $args       Positional arguments: primary ID, then duplicate ID(s).
	 * @param array $assoc_args Associative arguments: skip-preview, force, yes, porcelain.
	 */
	public function merge( $args, $assoc_args ): void {
		if ( ! current_user_can( 'manage_sportspress' ) && ! current_user_can( 'delete_sp_players' ) ) {
			\WP_CLI::error( __( 'Insufficient permissions.', 'sportspress-player-merge' ) );
		}

		$primary_raw   = $args[0] ?? null;
		$duplicate_raw = array_slice( $args, 1 );

		if ( null === $primary_raw || empty( $duplicate_raw ) ) {
			\WP_CLI::error( __( 'Usage: wp sp-merge merge <primary-id> <duplicate-id>...', 'sportspress-player-merge' ) );
		}

		$porcelain = isset( $assoc_args['porcelain'] );

		// absint() here mirrors exactly what SP_Merge_Validation::validate_merge_selection()
		// would do to these values anyway (it is idempotent), so pre-casting to satisfy
		// run_one_merge()'s int/int[] signature changes nothing about how an invalid
		// (e.g. non-numeric) selection is reported.
		$outcome = self::run_one_merge( absint( $primary_raw ), array_map( 'absint', $duplicate_raw ), $assoc_args );

		if ( ! $outcome['success'] ) {
			\WP_CLI::error( $outcome['message'] );
		}

		if ( $porcelain ) {
			\WP_CLI::log( (string) $outcome['backup_id'] );
		}
	}

	/**
	 * Run one merge: validate, preview, survivor-warning gate, confirm, execute.
	 *
	 * Shared by `merge` (a single operator-driven merge) and SP_Merge_CLI_Batch
	 * (many merges read from a file), which is why it is public — and static, so
	 * that being public cannot turn it into a `wp sp-merge run-one-merge`
	 * subcommand. Never calls \WP_CLI::error() itself — doing so would halt the
	 * whole PHP process, which `batch` cannot allow under --continue-on-error
	 * — so every refusal (invalid selection, unoverridden survivor warning,
	 * processor failure) comes back as success:false with a message for the
	 * caller to act on however it needs to (merge() turns it into a fatal error;
	 * batch logs it and decides whether to keep going).
	 *
	 * `--dry-run` in $assoc_args stops right after the same preview/warning gate
	 * a real run would refuse at, and never reaches confirm()/execute_merge() —
	 * merge() never sets this key, so its own behavior is completely unaffected.
	 *
	 * `--porcelain` implies `--skip-preview` for the same reason merge() implies
	 * it in its own docblock: printing the human preview report ahead of a
	 * machine-readable backup ID defeats the point of porcelain output. batch()
	 * never sets `porcelain`, so this has no effect on it.
	 *
	 * A Throwable raised while previewing (malformed legacy serialized meta walked
	 * through deep_merge_arrays() raises a TypeError, which is not an Exception in
	 * PHP 8) is caught and returned as a row failure for the same reason: a bad row
	 * must be skippable, not fatal to the process. `backup_id` is whatever
	 * execute_merge() returned even on failure — a failed merge deliberately
	 * retains its backup, and that ID is the operator's recovery path.
	 *
	 * @param int   $primary_id    ID of the player that will survive the merge.
	 * @param int[] $duplicate_ids ID(s) of the player(s) to merge in and delete.
	 * @param array $assoc_args    Associative arguments: skip-preview, force, yes, dry-run.
	 * @return array{success: bool, backup_id: ?string, message: ?string}
	 */
	public static function run_one_merge( int $primary_id, array $duplicate_ids, array $assoc_args ): array {
		$result = SP_Merge_Validation::validate_merge_selection( $primary_id, $duplicate_ids );
		if ( ! $result['valid'] ) {
			return self::merge_outcome( false, message: $result['error'] );
		}

		try {
			self::render_preview_unless_skipped( $result, $assoc_args );

			$warnings = SP_Merge_Validation::survivor_warnings( $result['primary_id'], $result['duplicate_ids'] );
		} catch ( Throwable $e ) {
			// Throwable, not Exception: generate_data() walks legacy serialized
			// postmeta through deep_merge_arrays()/preview_array_field_merge(),
			// where malformed decade-old data raises a TypeError — not an Exception
			// in PHP 8. Unguarded, one bad row would kill the whole PHP process
			// mid-batch, which is exactly what --continue-on-error exists to
			// prevent; as a row failure it is logged and skipped like any other.
			return self::merge_outcome( false, message: $e->getMessage() );
		}

		$warning_override = self::gate_survivor_warnings( $warnings, $assoc_args );

		if ( null === $warning_override ) {
			return self::merge_outcome( false, message: __( 'Merge refused: survivor warning(s) above. Re-run with --force to override.', 'sportspress-player-merge' ) );
		}

		if ( isset( $assoc_args['dry-run'] ) ) {
			return self::merge_outcome(
				true,
				message: sprintf(
					/* translators: 1: number of duplicate players, 2: primary player ID */
					__( 'DRY RUN: would merge %1$d player(s) into #%2$d.', 'sportspress-player-merge' ),
					count( $result['duplicate_ids'] ),
					$result['primary_id']
				)
			);
		}

		\WP_CLI::confirm( self::merge_question( $result, $warning_override ), $assoc_args );

		$merge_result = ( new SP_Merge_Processor() )->execute_merge( $result['primary_id'], $result['duplicate_ids'] );

		if ( ! $merge_result['success'] ) {
			// execute_merge() deliberately returns the retained backup ID on
			// failure — a failed-but-retained backup is the operator's recovery
			// path, and for a batch row the log is the only place that ID is
			// ever written down.
			return self::merge_outcome( false, $merge_result['backup_id'] ?? null, $merge_result['message'] );
		}

		self::report_merge_result( $merge_result, $assoc_args );

		return self::merge_outcome( true, $merge_result['backup_id'] );
	}

	/**
	 * Build one of run_one_merge()'s three-key results.
	 *
	 * Exists so the six places that return one cannot drift into disagreeing
	 * about the shape callers unpack — `batch` writes every key of it straight
	 * into its log.
	 *
	 * @param bool        $success   Whether the merge (or dry run) got where it was going.
	 * @param string|null $backup_id Backup ID, where one exists — including on a failure that kept its backup.
	 * @param string|null $message   Operator-facing explanation; null on a plain success.
	 * @return array{success: bool, backup_id: ?string, message: ?string}
	 */
	private static function merge_outcome( bool $success, ?string $backup_id = null, ?string $message = null ): array {
		return array(
			'success'   => $success,
			'backup_id' => $backup_id,
			'message'   => $message,
		);
	}

	/**
	 * Print the pre-merge preview report, unless this run asked for neither.
	 *
	 * `--porcelain` implies `--skip-preview` for the same reason merge() implies
	 * it in its own docblock: printing the human preview report ahead of a
	 * machine-readable backup ID defeats the point of porcelain output.
	 *
	 * @param array $result     A validated selection from SP_Merge_Validation::validate_merge_selection().
	 * @param array $assoc_args Associative arguments: skip-preview, porcelain.
	 */
	private static function render_preview_unless_skipped( array $result, array $assoc_args ): void {
		if ( isset( $assoc_args['skip-preview'] ) || isset( $assoc_args['porcelain'] ) ) {
			return;
		}

		self::render_preview_data( ( new SP_Merge_Preview() )->generate_data( $result['primary_id'], $result['duplicate_ids'] ) );
	}

	/**
	 * Show any survivor warnings and decide whether the merge may continue.
	 *
	 * Warnings are printed regardless of --force: the confirmation question
	 * refers back to them ("see above"), so they have to be visible before the
	 * operator is asked to override them.
	 *
	 * @param string[] $warnings   SP_Merge_Validation::survivor_warnings() output.
	 * @param array    $assoc_args Associative arguments: force.
	 * @return bool|null True when the operator overrode a warning, false when there
	 *                   was none to override, null when the merge must be refused.
	 */
	private static function gate_survivor_warnings( array $warnings, array $assoc_args ): ?bool {
		if ( empty( $warnings ) ) {
			return false;
		}

		foreach ( $warnings as $warning ) {
			\WP_CLI::warning( $warning );
		}

		return isset( $assoc_args['force'] ) ? true : null;
	}

	/**
	 * The confirmation question for a merge that is about to run.
	 *
	 * @param array $result           A validated selection from SP_Merge_Validation::validate_merge_selection().
	 * @param bool  $warning_override Whether --force overrode a survivor warning printed above.
	 * @return string
	 */
	private static function merge_question( array $result, bool $warning_override ): string {
		$question = sprintf(
			/* translators: 1: number of duplicate players, 2: primary player ID */
			__( 'Merge %1$d player(s) into #%2$d? This permanently deletes the duplicate posts.', 'sportspress-player-merge' ),
			count( $result['duplicate_ids'] ),
			$result['primary_id']
		);

		if ( $warning_override ) {
			return __( 'Survivor warning overridden — see above. ', 'sportspress-player-merge' ) . $question;
		}

		return $question;
	}

	/**
	 * Announce a completed merge and list every value the merge had to resolve.
	 *
	 * Suppressed entirely under --porcelain: merge() is the only caller that ever
	 * sets that key (batch never does, so a batch row's per-row success message
	 * and resolution list are unaffected), and porcelain's contract is to print
	 * only the backup ID on success — no prose, no resolution list.
	 *
	 * @param array $merge_result SP_Merge_Processor::execute_merge()'s successful result.
	 * @param array $assoc_args   Associative arguments: porcelain.
	 */
	private static function report_merge_result( array $merge_result, array $assoc_args ): void {
		if ( isset( $assoc_args['porcelain'] ) ) {
			return;
		}

		\WP_CLI::success( sprintf( /* translators: %s: backup ID */ __( 'Merge completed. Backup ID: %s', 'sportspress-player-merge' ), $merge_result['backup_id'] ) );

		foreach ( (array) ( $merge_result['resolutions'] ?? array() ) as $resolution ) {
			$address = SP_Merge_Processor::format_resolution_path( $resolution );
			$kept    = SP_Merge_Processor::format_resolution_value( $resolution['kept'] );

			if ( 'conflict' === $resolution['action'] ) {
				\WP_CLI::log(
					sprintf(
						/* translators: 1: field address, 2: value kept, 3: value discarded, 4: duplicate player ID */
						__( '  %1$s — keeping "%2$s", discarding "%3$s" (player %4$d)', 'sportspress-player-merge' ),
						$address,
						$kept,
						SP_Merge_Processor::format_resolution_value( $resolution['discarded'] ),
						(int) $resolution['duplicate_id']
					)
				);
				continue;
			}

			\WP_CLI::log(
				sprintf(
					/* translators: 1: field address, 2: value used, 3: duplicate player ID */
					__( '  %1$s — the duplicate\'s value "%2$s" was used (player %3$d)', 'sportspress-player-merge' ),
					$address,
					$kept,
					(int) $resolution['duplicate_id']
				)
			);
		}
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
	 * unwinding the later merge first. Neither is a `locked` refusal, which means
	 * a merge (very likely a `batch` run) or another revert holds the shared merge
	 * lock right now: wait for it to finish and try again.
	 *
	 * ## OPTIONS
	 *
	 * <backup-id>
	 * : Backup ID to revert.
	 *
	 * [--owner=<id|login>]
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
	 * @param array $assoc_args Associative arguments: owner, force, yes.
	 */
	public function revert( $args, $assoc_args ): void {
		if ( ! current_user_can( 'manage_sportspress' ) && ! current_user_can( 'delete_sp_players' ) ) {
			\WP_CLI::error( __( 'Insufficient permissions.', 'sportspress-player-merge' ) );
		}

		$backup_id = $args[0] ?? null;
		if ( null === $backup_id ) {
			\WP_CLI::error( __( 'Usage: wp sp-merge revert <backup-id>', 'sportspress-player-merge' ) );
		}

		$owner_id = SP_Merge_CLI_Support::resolve_target_user( $assoc_args['owner'] ?? null );
		$backup   = new SP_Merge_Backup();

		// Always the first call, whether or not --force was passed: it either
		// completes the revert (nothing needed overriding) or refuses without
		// having written anything.
		$attempt = $backup->revert( $backup_id, false, $owner_id );

		// The success message is SP_Merge_Validation::revert_success_message() —
		// shared with SP_Merge_Ajax::revert_merge() so this wording is defined
		// exactly once, not hand-copied and kept in sync between the two layers.
		if ( $attempt['success'] ) {
			\WP_CLI::success( SP_Merge_Validation::revert_success_message( false ) );
			return;
		}

		if ( ! isset( $assoc_args['force'] ) || 'values_changed' !== ( $attempt['code'] ?? '' ) ) {
			// Not forced, or a refusal --force could never have overridden
			// anyway (not_found, conflict, error) — nothing left to try.
			\WP_CLI::error( $attempt['message'] );
		}

		\WP_CLI::warning( $attempt['message'] );
		\WP_CLI::confirm( __( 'Override and revert anyway? Everything listed above was written after the merge and will be permanently discarded.', 'sportspress-player-merge' ), $assoc_args );

		$forced = $backup->revert( $backup_id, true, $owner_id );

		if ( ! $forced['success'] ) {
			\WP_CLI::error( $forced['message'] );
		}

		\WP_CLI::success( SP_Merge_Validation::revert_success_message( true ) );
	}
}
