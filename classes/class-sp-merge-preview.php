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

		$html .= '</tbody>';
		$html .= '</table>';
		$html .= '</div>';

		return $html;
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
	 * @param int   $primary_id    Primary player ID.
	 * @param int[] $duplicate_ids Duplicate player IDs.
	 * @return string HTML.
	 */
	private function render_event_count_row( int $primary_id, array $duplicate_ids ): string {
		global $wpdb;

		$primary_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'sp_player' AND meta_value = %s",
				(string) $primary_id
			)
		);

		$dup_count = 0;
		if ( ! empty( $duplicate_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $duplicate_ids ), '%s' ) );
			$dup_count    = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'sp_player' AND meta_value IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					array_map( 'strval', $duplicate_ids )
				)
			);
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
