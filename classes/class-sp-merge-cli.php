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

		$this->render_preview_report( $data );
	}

	/**
	 * Render the structured preview data as a human-readable report.
	 *
	 * @param array $data Return value of SP_Merge_Preview::generate_data().
	 */
	private function render_preview_report( array $data ): void {
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
}
