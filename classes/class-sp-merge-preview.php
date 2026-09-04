<?php
/**
 * Preview Generator Class
 *
 * Generates merge preview data for user review.
 *
 * @package SportsPress_Player_Merge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SP_Merge_Preview
 */
class SP_Merge_Preview {

	/**
	 * Maximum number of resolved cells listed per category before summarising.
	 *
	 * A ten-season merge can resolve hundreds; the rest go to the log.
	 *
	 * @var int
	 */
	private const MAX_LISTED_RESOLUTIONS = 25;

	/**
	 * Generate the preview HTML.
	 *
	 * @param int   $primary_id    Primary player ID.
	 * @param int[] $duplicate_ids Duplicate player IDs.
	 * @return string HTML.
	 */
	public function generate( int $primary_id, array $duplicate_ids ): string {
		// Pre-cache meta and terms for all players to avoid N+1 queries.
		$all_ids = array_merge( array( $primary_id ), $duplicate_ids );
		update_postmeta_cache( $all_ids );
		update_object_term_cache( $all_ids, 'sp_player' );

		$primary    = $this->get_player_details( $primary_id );
		$duplicates = array_map( array( $this, 'get_player_details' ), $duplicate_ids );

		$html  = '<div class="merge-preview-container">';
		$html .= $this->render_player_names( $primary, $duplicates );
		$html .= $this->render_data_comparison( $primary_id, $duplicate_ids );
		$html .= '</div>';

		return $html;
	}

	/**
	 * Generate the same comparison the HTML preview renders, as plain data.
	 *
	 * Built from the same private getters generate() uses, so a terminal preview
	 * and a browser preview can never disagree about what a merge will do. No
	 * escaping happens here — this is a data structure for a CLI or JSON consumer,
	 * not markup, and it deliberately does not cap the array-field resolutions the
	 * way the HTML table does: MAX_LISTED_RESOLUTIONS exists to keep a browser
	 * table readable, and has nothing to say about a terminal or a JSON payload.
	 *
	 * @param int   $primary_id    Primary player ID.
	 * @param int[] $duplicate_ids Duplicate player IDs.
	 * @return array{
	 *     primary: array{id: int, name: string},
	 *     duplicates: array<array{id: int, name: string}>,
	 *     current_team: array{primary: string|null, duplicates: string[], result: string[]},
	 *     past_teams: array{primary: string[], duplicates: string[], result: string[]},
	 *     taxonomies: array<string, array{label: string, primary: string[], duplicates: string[], result: string[]}>,
	 *     events: array{primary: int, duplicates: int, result: int},
	 *     collision_count: int,
	 *     array_field_filled: array,
	 *     array_field_conflicts: array
	 * }
	 */
	public function generate_data( int $primary_id, array $duplicate_ids ): array {
		// Pre-cache meta and terms for all players to avoid N+1 queries, exactly
		// as generate() does — a CLI preview (and every row of a batch run) walks
		// the same per-player getters below, so it pays the same N+1 cost if this
		// priming is skipped.
		$all_ids = array_merge( array( $primary_id ), $duplicate_ids );
		update_postmeta_cache( $all_ids );
		update_object_term_cache( $all_ids, 'sp_player' );

		$primary    = $this->get_player_details( $primary_id );
		$duplicates = array_map( array( $this, 'get_player_details' ), $duplicate_ids );

		$primary_team    = $this->get_current_team( $primary_id );
		$duplicate_teams = array();
		foreach ( $duplicate_ids as $dup_id ) {
			$team = $this->get_current_team( (int) $dup_id );
			if ( $team ) {
				$duplicate_teams[] = $team;
			}
		}
		$unique_dup_teams = array_values( array_unique( $duplicate_teams ) );
		$all_teams        = $primary_team ? array_merge( array( $primary_team ), $unique_dup_teams ) : $unique_dup_teams;
		$result_teams     = array_values( array_unique( $all_teams ) );

		$primary_past       = $this->get_past_teams( $primary_id );
		$all_duplicate_past = array();
		foreach ( $duplicate_ids as $dup_id ) {
			$all_duplicate_past = array_merge( $all_duplicate_past, $this->get_past_teams( (int) $dup_id ) );
		}
		$unique_dup_past = array_values( array_unique( $all_duplicate_past ) );
		$merged_past     = array_values( array_unique( array_merge( $primary_past, $unique_dup_past ) ) );

		$taxonomies = array();
		foreach ( get_object_taxonomies( 'sp_player', 'objects' ) as $taxonomy ) {
			$primary_terms       = $this->get_taxonomy_terms( $primary_id, $taxonomy->name );
			$all_duplicate_terms = array();
			foreach ( $duplicate_ids as $dup_id ) {
				$all_duplicate_terms = array_merge( $all_duplicate_terms, $this->get_taxonomy_terms( (int) $dup_id, $taxonomy->name ) );
			}
			$unique_dup_terms = array_values( array_unique( $all_duplicate_terms ) );
			$merged_terms     = array_values( array_unique( array_merge( $primary_terms, $unique_dup_terms ) ) );

			$taxonomies[ $taxonomy->name ] = array(
				'label'      => $taxonomy->labels->name,
				'primary'    => $primary_terms,
				'duplicates' => $unique_dup_terms,
				'result'     => $merged_terms,
			);
		}

		$event_counts     = SP_Merge_Validation::get_event_counts( $all_ids );
		$primary_events   = $event_counts[ $primary_id ] ?? 0;
		$duplicate_events = 0;
		foreach ( $duplicate_ids as $dup_id ) {
			$duplicate_events += $event_counts[ (int) $dup_id ] ?? 0;
		}

		$resolutions = $this->compute_array_field_resolutions( $primary_id, $duplicate_ids );

		return array(
			'primary'               => $primary,
			'duplicates'            => $duplicates,
			'current_team'          => array(
				'primary'    => $primary_team,
				'duplicates' => $unique_dup_teams,
				'result'     => $result_teams,
			),
			'past_teams'            => array(
				'primary'    => $primary_past,
				'duplicates' => $unique_dup_past,
				'result'     => $merged_past,
			),
			'taxonomies'            => $taxonomies,
			'events'                => array(
				'primary'    => $primary_events,
				'duplicates' => $duplicate_events,
				'result'     => $primary_events + $duplicate_events,
			),
			'collision_count'       => $this->count_collision_events( $primary_id, $duplicate_ids ),
			'array_field_filled'    => $resolutions['filled'],
			'array_field_conflicts' => $resolutions['conflicts'],
		);
	}

	/**
	 * Render the player names section.
	 *
	 * @param array   $primary    Primary player details.
	 * @param array[] $duplicates Duplicate player details.
	 * @return string HTML.
	 */
	private function render_player_names( array $primary, array $duplicates ): string {
		$html  = '<div class="preview-section">';
		$html .= '<h4>' . esc_html__( 'Players Being Merged', 'sportspress-player-merge' ) . '</h4>';
		$html .= '<p><strong>' . esc_html__( 'Primary Player (will be kept):', 'sportspress-player-merge' ) . '</strong> ' . esc_html( $primary['name'] ) . '</p>';
		$html .= '<p><strong>' . esc_html__( 'Duplicate Players (will be deleted):', 'sportspress-player-merge' ) . '</strong></p>';
		$html .= '<ul>';
		foreach ( $duplicates as $duplicate ) {
			$html .= '<li>' . esc_html( $duplicate['name'] ) . '</li>';
		}
		$html .= '</ul>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Render the data comparison table.
	 *
	 * @param int   $primary_id    Primary player ID.
	 * @param int[] $duplicate_ids Duplicate player IDs.
	 * @return string HTML.
	 */
	private function render_data_comparison( int $primary_id, array $duplicate_ids ): string {
		$html  = '<div class="preview-section">';
		$html .= '<h4>' . esc_html__( 'Data Merge Preview', 'sportspress-player-merge' ) . '</h4>';
		$html .= '<table class="merge-preview-table">';
		$html .= '<thead>';
		$html .= '<tr>';
		$html .= '<th>' . esc_html__( 'Data Type', 'sportspress-player-merge' ) . '</th>';
		$html .= '<th>' . esc_html__( 'Current (Primary)', 'sportspress-player-merge' ) . '</th>';
		$html .= '<th>' . esc_html__( 'Incoming (Duplicates)', 'sportspress-player-merge' ) . '</th>';
		$html .= '<th>' . esc_html__( 'Result After Merge', 'sportspress-player-merge' ) . '</th>';
		$html .= '</tr>';
		$html .= '</thead>';
		$html .= '<tbody>';

		$html .= $this->render_current_teams_row( $primary_id, $duplicate_ids );
		$html .= $this->render_past_teams_row( $primary_id, $duplicate_ids );

		// Dynamic taxonomy rows.
		$taxonomies = get_object_taxonomies( 'sp_player', 'objects' );
		foreach ( $taxonomies as $taxonomy ) {
			$html .= $this->render_taxonomy_row( $taxonomy->name, $taxonomy->labels->name, $primary_id, $duplicate_ids );
		}

		// Event count row.
		$html .= $this->render_event_count_row( $primary_id, $duplicate_ids );

		// Same-event collision warning.
		$html .= $this->render_collision_warning( $primary_id, $duplicate_ids );

		// Cell-level decisions the "Result After Merge" column cannot express.
		$html .= $this->render_array_field_warning( $primary_id, $duplicate_ids );

		$html .= '</tbody>';
		$html .= '</table>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Count events where both the primary and a duplicate player appear.
	 *
	 * Shared by the HTML collision warning and generate_data(), so a terminal
	 * preview and a browser preview can never disagree about how many events are
	 * contested.
	 *
	 * Reads through SP_Merge_Validation::get_event_ids(), which restricts to
	 * `post_type = 'sp_event'`: the raw `sp_player` meta rows this used to scan
	 * also cover `sp_list` posts (see SP_Merge_Processor::update_player_list_references()),
	 * so two players who merely appear on the same squad list were being reported
	 * as a same-event collision — a spurious WARN out of `preview --porcelain`.
	 *
	 * @param int   $primary_id    Primary player ID.
	 * @param int[] $duplicate_ids Duplicate player IDs.
	 * @return int Number of events naming both the primary and a duplicate.
	 */
	private function count_collision_events( int $primary_id, array $duplicate_ids ): int {
		$event_ids = SP_Merge_Validation::get_event_ids( array_merge( array( $primary_id ), $duplicate_ids ) );

		$primary_events = $event_ids[ $primary_id ] ?? array();
		if ( empty( $primary_events ) ) {
			return 0;
		}

		$collision_count = 0;
		foreach ( $duplicate_ids as $dup_id ) {
			$dup_events       = $event_ids[ (int) $dup_id ] ?? array();
			$collision_count += count( array_intersect( $primary_events, $dup_events ) );
		}

		return $collision_count;
	}

	/**
	 * Detect and warn about events where both primary and duplicate appear.
	 *
	 * @param int   $primary_id    Primary player ID.
	 * @param int[] $duplicate_ids Duplicate player IDs.
	 * @return string HTML warning row, or empty string.
	 */
	private function render_collision_warning( int $primary_id, array $duplicate_ids ): string {
		$collision_count = $this->count_collision_events( $primary_id, $duplicate_ids );

		if ( 0 === $collision_count ) {
			return '';
		}

		return '</tbody></table>'
			. '<div class="sp-merge-warning" style="margin-top:12px;">'
			. '<p><strong>' . esc_html__( 'Warning:', 'sportspress-player-merge' ) . '</strong> '
			. sprintf(
				/* translators: %d: number of shared events */
				esc_html__( '%d event(s) contain both the primary and duplicate player(s). Performance stats in those events will be combined (numeric values summed). Timeline entries will be merged. Please verify the result after merging.', 'sportspress-player-merge' ),
				$collision_count
			)
			. '</p></div>'
			. '<table class="merge-preview-table" style="display:none;"><tbody>';
	}

	/**
	 * Replay the serialized-array merge SP_Merge_Processor will perform, without
	 * writing anything, and collect every cell-level decision it makes.
	 *
	 * Shared by the HTML warning block and generate_data() so neither can ever
	 * report a resolution the real merge would not also make.
	 *
	 * @param int   $primary_id    Primary player ID.
	 * @param int[] $duplicate_ids Duplicate player IDs.
	 * @return array{filled: array, conflicts: array}
	 */
	private function compute_array_field_resolutions( int $primary_id, array $duplicate_ids ): array {
		if ( ! class_exists( 'SP_Merge_Processor' ) ) {
			return array(
				'filled'    => array(),
				'conflicts' => array(),
			);
		}

		$processor = new SP_Merge_Processor();
		$filled    = array();
		$conflicts = array();

		foreach ( SP_Merge_Processor::ARRAY_MERGE_FIELDS as $meta_key ) {
			$state = get_post_meta( $primary_id, $meta_key, true );
			$state = is_array( $state ) ? $state : array();

			foreach ( $duplicate_ids as $dup_id ) {
				$duplicate_value = get_post_meta( (int) $dup_id, $meta_key, true );

				if ( ! is_array( $duplicate_value ) || empty( $duplicate_value ) ) {
					continue;
				}

				/*
				 * With nothing on the primary the merge copies the duplicate's array
				 * wholesale — no cell is resolved, but the copy becomes the state the
				 * next duplicate merges into, exactly as execute_merge() sequences it.
				 */
				if ( empty( $state ) ) {
					$state = $duplicate_value;
					continue;
				}

				$result = $processor->preview_array_field_merge( $meta_key, $state, $duplicate_value, (int) $dup_id );
				$state  = $result['merged'];

				foreach ( $result['resolutions'] as $resolution ) {
					if ( 'conflict' === $resolution['action'] ) {
						$conflicts[] = $resolution;
					} else {
						$filled[] = $resolution;
					}
				}
			}
		}

		return array(
			'filled'    => $filled,
			'conflicts' => $conflicts,
		);
	}

	/**
	 * Warn about the cells the merge has to resolve in the serialized array fields.
	 *
	 * The "Result After Merge" column reads as a clean union, which those fields
	 * are not: they are merged cell by cell, so a season's statistic can only come
	 * from one of the two players. This replays the real merge (SP_Merge_Processor
	 * does the walking) and reports both directions — a value taken from the
	 * duplicate because the primary's cell is blank, and a value discarded because
	 * the primary's cell already held something different.
	 *
	 * @param int   $primary_id    Primary player ID.
	 * @param int[] $duplicate_ids Duplicate player IDs.
	 * @return string HTML warning block, or empty string.
	 */
	private function render_array_field_warning( int $primary_id, array $duplicate_ids ): string {
		$resolutions = $this->compute_array_field_resolutions( $primary_id, $duplicate_ids );
		$filled      = $resolutions['filled'];
		$conflicts   = $resolutions['conflicts'];

		if ( empty( $filled ) && empty( $conflicts ) ) {
			return '';
		}

		$html = '</tbody></table><div class="sp-merge-warning" style="margin-top:12px;">';

		if ( ! empty( $filled ) ) {
			$html .= '<p><strong>' . esc_html__( 'Note:', 'sportspress-player-merge' ) . '</strong> '
				. sprintf(
					/* translators: %d: number of cells taken from a duplicate */
					esc_html__( "%d value(s) will be taken from a duplicate because the primary's cell is blank.", 'sportspress-player-merge' ),
					count( $filled )
				)
				. '</p>'
				. $this->render_resolution_list( $filled, true );
		}

		if ( ! empty( $conflicts ) ) {
			$html .= '<p><strong>' . esc_html__( 'Warning:', 'sportspress-player-merge' ) . '</strong> '
				. sprintf(
					/* translators: %d: number of conflicting cells */
					esc_html__( "%d value(s) differ between the players. The primary's value is kept and the duplicate's is discarded — check these before merging, they cannot be recovered from the merged player.", 'sportspress-player-merge' ),
					count( $conflicts )
				)
				. '</p>'
				. $this->render_resolution_list( $conflicts, false );
		}

		$html .= '</div><table class="merge-preview-table" style="display:none;"><tbody>';

		return $html;
	}

	/**
	 * List the resolved cells, capped so a ten-season merge stays readable.
	 *
	 * @param array $resolutions Resolutions of a single kind.
	 * @param bool  $is_filled   True for cells taken from the duplicate, false for discarded values.
	 * @return string HTML list.
	 */
	private function render_resolution_list( array $resolutions, bool $is_filled ): string {
		$listed    = array_slice( $resolutions, 0, self::MAX_LISTED_RESOLUTIONS );
		$remaining = count( $resolutions ) - count( $listed );

		$html = '<ul style="margin-left:1.5em; list-style:disc;">';

		foreach ( $listed as $resolution ) {
			$address = SP_Merge_Processor::format_resolution_path( $resolution );
			$kept    = SP_Merge_Processor::format_resolution_value( $resolution['kept'] );

			if ( $is_filled ) {
				$line = sprintf(
					/* translators: 1: meta field address, 2: value taken from the duplicate, 3: duplicate player ID */
					esc_html__( '%1$s — the duplicate\'s value "%2$s" will be used (player %3$d)', 'sportspress-player-merge' ),
					esc_html( $address ),
					esc_html( $kept ),
					(int) $resolution['duplicate_id']
				);
			} else {
				$line = sprintf(
					/* translators: 1: meta field address, 2: value that is kept, 3: value that is discarded, 4: duplicate player ID */
					esc_html__( '%1$s — keeping "%2$s", discarding "%3$s" (player %4$d)', 'sportspress-player-merge' ),
					esc_html( $address ),
					esc_html( $kept ),
					esc_html( SP_Merge_Processor::format_resolution_value( $resolution['discarded'] ) ),
					(int) $resolution['duplicate_id']
				);
			}

			$html .= '<li>' . $line . '</li>';
		}

		if ( $remaining > 0 ) {
			$html .= '<li>' . sprintf(
				/* translators: %d: number of further resolved cells */
				esc_html__( '…and %d more. The full list is written to the error log when the merge runs with WP_DEBUG enabled.', 'sportspress-player-merge' ),
				$remaining
			) . '</li>';
		}

		return $html . '</ul>';
	}

	/**
	 * Render the current teams row.
	 *
	 * @param int   $primary_id    Primary player ID.
	 * @param int[] $duplicate_ids Duplicate player IDs.
	 * @return string HTML.
	 */
	private function render_current_teams_row( int $primary_id, array $duplicate_ids ): string {
		$primary_team    = $this->get_current_team( $primary_id );
		$duplicate_teams = array();

		foreach ( $duplicate_ids as $dup_id ) {
			$team = $this->get_current_team( (int) $dup_id );
			if ( $team ) {
				$duplicate_teams[] = $team;
			}
		}

		$unique_dup_teams = array_unique( $duplicate_teams );
		$all_teams        = $primary_team ? array_merge( array( $primary_team ), $unique_dup_teams ) : $unique_dup_teams;
		$result_teams     = array_unique( $all_teams );

		$none = esc_html__( 'None', 'sportspress-player-merge' );

		return '<tr>'
			. '<td><strong>' . esc_html__( 'Current Team', 'sportspress-player-merge' ) . '</strong></td>'
			. '<td>' . ( $primary_team ? esc_html( $primary_team ) : $none ) . '</td>'
			. '<td>' . ( empty( $unique_dup_teams ) ? $none : esc_html( implode( ', ', $unique_dup_teams ) ) ) . '</td>'
			. '<td>' . ( empty( $result_teams ) ? $none : esc_html( implode( ', ', $result_teams ) ) ) . '</td>'
			. '</tr>';
	}

	/**
	 * Render the past teams row.
	 *
	 * @param int   $primary_id    Primary player ID.
	 * @param int[] $duplicate_ids Duplicate player IDs.
	 * @return string HTML.
	 */
	private function render_past_teams_row( int $primary_id, array $duplicate_ids ): string {
		$primary_past       = $this->get_past_teams( $primary_id );
		$all_duplicate_past = array();

		foreach ( $duplicate_ids as $dup_id ) {
			$all_duplicate_past = array_merge( $all_duplicate_past, $this->get_past_teams( (int) $dup_id ) );
		}

		$unique_dup_past = array_unique( $all_duplicate_past );
		$merged_past     = array_unique( array_merge( $primary_past, $unique_dup_past ) );

		return '<tr>'
			. '<td><strong>' . esc_html__( 'Past Team(s)', 'sportspress-player-merge' ) . '</strong></td>'
			. '<td>' . $this->format_expandable_list( $primary_past, 'primary-past-teams' ) . '</td>'
			. '<td>' . $this->format_expandable_list( $unique_dup_past, 'duplicate-past-teams' ) . '</td>'
			. '<td>' . $this->format_expandable_list( $merged_past, 'merged-past-teams' ) . '</td>'
			. '</tr>';
	}

	/**
	 * Render a taxonomy comparison row.
	 *
	 * @param string $taxonomy     Taxonomy slug.
	 * @param string $label        Display label.
	 * @param int    $primary_id   Primary player ID.
	 * @param int[]  $duplicate_ids Duplicate player IDs.
	 * @return string HTML.
	 */
	private function render_taxonomy_row( string $taxonomy, string $label, int $primary_id, array $duplicate_ids ): string {
		$primary_terms       = $this->get_taxonomy_terms( $primary_id, $taxonomy );
		$all_duplicate_terms = array();

		foreach ( $duplicate_ids as $dup_id ) {
			$all_duplicate_terms = array_merge( $all_duplicate_terms, $this->get_taxonomy_terms( (int) $dup_id, $taxonomy ) );
		}

		$unique_dup_terms = array_unique( $all_duplicate_terms );
		$merged_terms     = array_unique( array_merge( $primary_terms, $unique_dup_terms ) );

		return '<tr>'
			. '<td><strong>' . esc_html( $label ) . '</strong></td>'
			. '<td>' . $this->format_expandable_list( $primary_terms, 'primary-' . esc_attr( $taxonomy ) ) . '</td>'
			. '<td>' . $this->format_expandable_list( $unique_dup_terms, 'duplicate-' . esc_attr( $taxonomy ) ) . '</td>'
			. '<td>' . $this->format_expandable_list( $merged_terms, 'merged-' . esc_attr( $taxonomy ) ) . '</td>'
			. '</tr>';
	}

	/**
	 * Render the event count row.
	 *
	 * Counted through SP_Merge_Validation::get_event_counts(): one batched query
	 * for every player in the merge rather than one query per duplicate, and — as
	 * with the collision count above — restricted to real `sp_event` posts, so
	 * this row, `wp sp-merge preview` and `wp sp-merge scan`'s events column all
	 * report the same number for the same player.
	 *
	 * @param int   $primary_id    Primary player ID.
	 * @param int[] $duplicate_ids Duplicate player IDs.
	 * @return string HTML.
	 */
	private function render_event_count_row( int $primary_id, array $duplicate_ids ): string {
		$event_counts  = SP_Merge_Validation::get_event_counts( array_merge( array( $primary_id ), $duplicate_ids ) );
		$primary_count = $event_counts[ $primary_id ] ?? 0;

		$dup_count = 0;
		foreach ( $duplicate_ids as $dup_id ) {
			$dup_count += $event_counts[ (int) $dup_id ] ?? 0;
		}

		$none = esc_html__( 'None', 'sportspress-player-merge' );

		return '<tr>'
			. '<td><strong>' . esc_html__( 'Events', 'sportspress-player-merge' ) . '</strong></td>'
			. '<td>' . ( $primary_count ? esc_html( (string) $primary_count ) : $none ) . '</td>'
			. '<td>' . ( $dup_count ? esc_html( (string) $dup_count ) : $none ) . '</td>'
			. '<td>' . esc_html( (string) ( $primary_count + $dup_count ) ) . '</td>'
			. '</tr>';
	}

	/**
	 * Get player details.
	 *
	 * @param int $player_id Player ID.
	 * @return array{id: int, name: string}
	 */
	private function get_player_details( int $player_id ): array {
		$player = get_post( $player_id );

		if ( ! $player || 'sp_player' !== $player->post_type ) {
			return array(
				'id'   => $player_id,
				'name' => __( 'Unknown Player', 'sportspress-player-merge' ),
			);
		}

		return array(
			'id'   => $player->ID,
			'name' => $player->post_title,
		);
	}

	/**
	 * Get current team name for a player.
	 *
	 * @param int $player_id Player ID.
	 * @return string|null Team name or null.
	 */
	private function get_current_team( int $player_id ): ?string {
		$team_ids = get_post_meta( $player_id, 'sp_current_team' );
		$team_ids = array_reverse( $team_ids );

		foreach ( $team_ids as $team_id ) {
			if ( $team_id && '0' !== $team_id && is_numeric( $team_id ) ) {
				$team = get_post( (int) $team_id );
				if ( $team && 'sp_team' === $team->post_type ) {
					return $team->post_title;
				}
			}
		}

		return null;
	}

	/**
	 * Get past team names for a player.
	 *
	 * @param int $player_id Player ID.
	 * @return string[] Team names.
	 */
	private function get_past_teams( int $player_id ): array {
		$names    = array();
		$team_ids = get_post_meta( $player_id, 'sp_past_team' );

		foreach ( $team_ids as $team_id ) {
			if ( $team_id && '0' !== $team_id && is_numeric( $team_id ) ) {
				$team = get_post( (int) $team_id );
				if ( $team && 'sp_team' === $team->post_type ) {
					$names[] = $team->post_title;
				}
			}
		}

		return array_unique( $names );
	}

	/**
	 * Get taxonomy term names for a player.
	 *
	 * @param int    $player_id Player ID.
	 * @param string $taxonomy  Taxonomy slug.
	 * @return string[] Term names.
	 */
	private function get_taxonomy_terms( int $player_id, string $taxonomy ): array {
		$terms = wp_get_object_terms( $player_id, $taxonomy, array( 'fields' => 'names' ) );
		return ( is_array( $terms ) && ! is_wp_error( $terms ) ) ? $terms : array();
	}

	/**
	 * Format a list with expand/collapse for long lists.
	 *
	 * @param string[] $items List items.
	 * @param string   $id    Unique ID for the expandable section.
	 * @return string HTML.
	 */
	private function format_expandable_list( array $items, string $id ): string {
		if ( empty( $items ) ) {
			return esc_html__( 'None', 'sportspress-player-merge' );
		}

		$escaped = array_map( 'esc_html', $items );

		if ( count( $escaped ) <= 3 ) {
			return implode( ', ', $escaped );
		}

		$visible = array_slice( $escaped, 0, 2 );
		$hidden  = array_slice( $escaped, 2 );

		$html  = implode( ', ', $visible );
		$html .= ' <a href="#" class="sp-expand-toggle" data-target="' . esc_attr( $id ) . '">+'
			. sprintf(
				/* translators: %d: number of hidden items */
				esc_html__( '%d more', 'sportspress-player-merge' ),
				count( $hidden )
			)
			. '</a>';
		$html .= '<div id="' . esc_attr( $id ) . '" style="display:none; margin-top:5px; font-size:0.9em;">';
		$html .= implode( ', ', $hidden );
		$html .= '</div>';

		return $html;
	}
}
