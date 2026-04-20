<?php
/**
 * AJAX Handler Class
 *
 * Handles all AJAX requests with security and validation.
 *
 * @package SportsPress_Player_Merge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SP_Merge_Ajax
 */
class SP_Merge_Ajax {

	/**
	 * Handle preview merge request.
	 */
	public function preview_merge(): void {
		if ( ! $this->validate_request() ) {
			return;
		}

		$input = $this->validate_merge_input();
		if ( ! $input ) {
			return;
		}

		try {
			$preview      = new SP_Merge_Preview();
			$preview_data = $preview->generate( $input['primary_id'], $input['duplicate_ids'] );
			wp_send_json_success( array( 'preview' => $preview_data ) );
		} catch ( Exception $e ) {
			$this->send_error( __( 'Preview generation failed', 'sportspress-player-merge' ) );
		}
	}

	/**
	 * Handle execute merge request.
	 */
	public function execute_merge(): void {
		if ( ! $this->validate_write_request() ) {
			return;
		}

		$input = $this->validate_merge_input();
		if ( ! $input ) {
			return;
		}

		try {
			$processor = new SP_Merge_Processor();
			$result    = $processor->execute_merge( $input['primary_id'], $input['duplicate_ids'] );

			if ( $result['success'] ) {
				wp_send_json_success(
					array(
						'message'   => __( 'Merge completed successfully', 'sportspress-player-merge' ),
						'backup_id' => $result['backup_id'],
					)
				);
			} else {
				$this->send_error( $result['message'] ?? __( 'Merge failed', 'sportspress-player-merge' ) );
			}
		} catch ( Exception $e ) {
			$this->send_error( __( 'Merge operation failed', 'sportspress-player-merge' ) );
		}
	}

	/**
	 * Handle revert merge request.
	 */
	public function revert_merge(): void {
		if ( ! $this->validate_write_request() ) {
			return;
		}

		$backup_id = $this->get_backup_id();
		if ( ! $backup_id ) {
			return;
		}

		try {
			$backup = new SP_Merge_Backup();
			$result = $backup->revert( $backup_id );

			if ( $result['success'] ) {
				wp_send_json_success( array( 'message' => __( 'Merge reverted successfully', 'sportspress-player-merge' ) ) );
			} else {
				$this->send_error( $result['message'] ?? __( 'Revert failed', 'sportspress-player-merge' ) );
			}
		} catch ( Exception $e ) {
			$this->send_error( __( 'Revert operation failed', 'sportspress-player-merge' ) );
		}
	}

	/**
	 * Handle delete backup request.
	 */
	public function delete_backup(): void {
		if ( ! $this->validate_admin_request() ) {
			return;
		}

		$raw_ids = isset( $_POST['backup_ids'] ) ? wp_unslash( $_POST['backup_ids'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in validate_request
		if ( ! is_array( $raw_ids ) ) {
			$this->send_error( __( 'Invalid backup IDs format', 'sportspress-player-merge' ) );
			return;
		}

		$backup_ids = array_map( 'sanitize_text_field', $raw_ids );
		if ( empty( $backup_ids ) ) {
			$this->send_error( __( 'No backup IDs provided', 'sportspress-player-merge' ) );
			return;
		}

		try {
			$backup        = new SP_Merge_Backup();
			$deleted_count = $backup->delete_backups( $backup_ids );
			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: %d: number of backups deleted */
						__( '%d backup(s) deleted successfully', 'sportspress-player-merge' ),
						$deleted_count
					),
				)
			);
		} catch ( Exception $e ) {
			$this->send_error( __( 'Delete operation failed', 'sportspress-player-merge' ) );
		}
	}

	/**
	 * Handle get recent backups request.
	 */
	public function get_recent_backups(): void {
		if ( ! $this->validate_request() ) {
			return;
		}

		try {
			$admin          = new SP_Merge_Admin();
			$recent_backups = $admin->get_recent_backups();

			if ( false === $recent_backups ) {
				$this->send_error( __( 'Failed to retrieve backup data', 'sportspress-player-merge' ) );
				return;
			}

			ob_start();
			if ( ! empty( $recent_backups ) ) {
				foreach ( $recent_backups as $backup ) {
					echo '<div class="sp-backup-item">';
					echo '<input type="checkbox" class="backup-checkbox" value="' . esc_attr( $backup['id'] ) . '" id="backup-' . esc_attr( $backup['id'] ) . '">';
					echo '<label for="backup-' . esc_attr( $backup['id'] ) . '">';
					echo '<strong>' . esc_html( $backup['primary_name'] ) . '</strong> &larr; ' . esc_html( implode( ', ', $backup['duplicate_names'] ) );
					echo '</label>';
					echo '<span class="sp-backup-date">' . esc_html( $backup['date'] ) . '</span>';
					echo '<div class="sp-backup-buttons">';
					echo '<button type="button" class="button button-secondary sp-revert-backup" data-backup-id="' . esc_attr( $backup['id'] ) . '">';
					echo '<span class="dashicons dashicons-undo"></span> ' . esc_html__( 'Revert', 'sportspress-player-merge' );
					echo '</button>';
					echo '<button type="button" class="button button-secondary sp-delete-backup" data-backup-id="' . esc_attr( $backup['id'] ) . '">';
					echo '<span class="dashicons dashicons-trash"></span> ' . esc_html__( 'Delete', 'sportspress-player-merge' );
					echo '</button>';
					echo '</div>';
					echo '</div>';
				}
			}
			$html = ob_get_clean();

			wp_send_json_success( array( 'html' => $html ) );
		} catch ( Exception $e ) {
			$this->send_error( __( 'Failed to load backups', 'sportspress-player-merge' ) );
		}
	}

	/**
	 * Validate and extract merge input from POST data.
	 *
	 * @return array{primary_id: int, duplicate_ids: int[]}|false
	 */
	private function validate_merge_input(): array|false {
		$primary_id = isset( $_POST['primary_player'] ) ? absint( wp_unslash( $_POST['primary_player'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$raw_duplicates = isset( $_POST['duplicate_players'] ) ? wp_unslash( $_POST['duplicate_players'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! is_array( $raw_duplicates ) ) {
			$this->send_error( __( 'Invalid input format', 'sportspress-player-merge' ) );
			return false;
		}

		$duplicate_ids = array_unique( array_map( 'absint', $raw_duplicates ) );
		$duplicate_ids = array_values( array_filter( $duplicate_ids ) );

		if ( ! $primary_id || empty( $duplicate_ids ) ) {
			$this->send_error( __( 'Invalid player selection', 'sportspress-player-merge' ) );
			return false;
		}

		// Limit number of duplicates per merge.
		if ( count( $duplicate_ids ) > 10 ) {
			$this->send_error( __( 'Maximum 10 duplicate players per merge operation.', 'sportspress-player-merge' ) );
			return false;
		}

		// Prevent merging a player into itself.
		if ( in_array( $primary_id, $duplicate_ids, true ) ) {
			$this->send_error( __( 'Primary player cannot also be a duplicate', 'sportspress-player-merge' ) );
			return false;
		}

		$primary_post = get_post( $primary_id );
		if ( ! $primary_post || 'sp_player' !== $primary_post->post_type || 'publish' !== $primary_post->post_status ) {
			$this->send_error( __( 'Primary player not found or not published', 'sportspress-player-merge' ) );
			return false;
		}

		foreach ( $duplicate_ids as $dup_id ) {
			$dup_post = get_post( $dup_id );
			if ( ! $dup_post || 'sp_player' !== $dup_post->post_type || 'publish' !== $dup_post->post_status ) {
				$this->send_error( __( 'One or more duplicate players not found or not published', 'sportspress-player-merge' ) );
				return false;
			}
		}

		return array(
			'primary_id'    => $primary_id,
			'duplicate_ids' => $duplicate_ids,
		);
	}

	/**
	 * Get backup ID from POST data or user meta.
	 *
	 * @return string|false
	 */
	private function get_backup_id(): string|false {
		$backup_id = isset( $_POST['backup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( empty( $backup_id ) ) {
			$backup_id = get_user_meta( get_current_user_id(), 'sp_last_merge_backup', true );
		}

		if ( empty( $backup_id ) ) {
			$this->send_error( __( 'No backup data found to revert', 'sportspress-player-merge' ) );
			return false;
		}

		return $backup_id;
	}

	/**
	 * Validate nonce and read-level capabilities.
	 *
	 * @return bool
	 */
	private function validate_request(): bool {
		return $this->check_request( 'edit_sp_players' );
	}

	/**
	 * Validate nonce and merge-level capabilities (execute/revert).
	 * League Managers (manage_sportspress) and Administrators (delete_sp_players) can merge.
	 *
	 * @return bool
	 */
	private function validate_write_request(): bool {
		if ( current_user_can( 'manage_sportspress' ) || current_user_can( 'delete_sp_players' ) ) {
			$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'sp_merge_nonce' ) ) {
				$this->send_error( __( 'Security check failed', 'sportspress-player-merge' ) );
				return false;
			}
			return true;
		}
		$this->send_error( __( 'Insufficient permissions', 'sportspress-player-merge' ) );
		return false;
	}

	/**
	 * Validate nonce and admin-level capabilities (delete backup).
	 *
	 * @return bool
	 */
	private function validate_admin_request(): bool {
		return $this->check_request( 'delete_sp_players' );
	}

	/**
	 * Check nonce and a specific capability.
	 *
	 * @param string $capability Required capability.
	 * @return bool
	 */
	private function check_request( string $capability ): bool {
		if ( ! current_user_can( $capability ) ) {
			$this->send_error( __( 'Insufficient permissions', 'sportspress-player-merge' ) );
			return false;
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'sp_merge_nonce' ) ) {
			$this->send_error( __( 'Security check failed', 'sportspress-player-merge' ) );
			return false;
		}

		return true;
	}

	/**
	 * Find possible duplicate players by matching names.
	 */
	public function find_duplicates(): void {
		if ( ! $this->validate_request() ) {
			return;
		}

		$players = get_posts(
			array(
				'post_type'      => 'sp_player',
				'posts_per_page' => 2000,
				'no_found_rows'  => true,
				'post_status'    => 'publish',
				'fields'         => '',
			)
		);

		$player_ids = wp_list_pluck( $players, 'ID' );
		if ( ! empty( $player_ids ) ) {
			update_meta_cache( 'post', $player_ids );
			update_object_term_cache( $player_ids, 'sp_player' );
		}

		// Use fuzzy name matcher to find duplicate groups.
		$matched_groups = SP_Merge_Name_Matcher::find_groups( $players );

		// Batch event count query for all matched player IDs.
		$duplicate_ids = array();
		foreach ( $matched_groups as $mg ) {
			foreach ( $mg['players'] as $p ) {
				$duplicate_ids[] = $p->ID;
			}
		}
		$event_counts = array();
		if ( ! empty( $duplicate_ids ) ) {
			global $wpdb;
			$placeholders = implode( ',', array_fill( 0, count( $duplicate_ids ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT pm.meta_value AS player_id, COUNT(*) AS cnt FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.post_type = 'sp_event' AND pm.meta_key = 'sp_player' AND pm.meta_value IN ($placeholders) GROUP BY pm.meta_value",
				...$duplicate_ids
			) );
			foreach ( $rows as $row ) {
				$event_counts[ (int) $row->player_id ] = (int) $row->cnt;
			}
		}

		$groups = array();
		foreach ( $matched_groups as $mg ) {
			$details = array();
			$teams   = array();
			foreach ( $mg['players'] as $p ) {
				$team    = '';
				$team_id = 0;
				$t_ids   = get_post_meta( $p->ID, 'sp_current_team' );
				foreach ( array_reverse( $t_ids ) as $tid ) {
					if ( $tid && '0' !== $tid ) {
						$t = get_post( (int) $tid );
						if ( $t && 'sp_team' === $t->post_type ) {
							$team    = $t->post_title;
							$team_id = $t->ID;
							break;
						}
					}
				}
				$teams[]   = $team_id;
				$events    = $event_counts[ $p->ID ] ?? 0;
				$positions  = wp_get_post_terms( $p->ID, 'sp_position', array( 'fields' => 'names' ) );
				$position   = is_array( $positions ) && ! empty( $positions ) ? implode( ', ', $positions ) : '';
				$details[] = array(
					'id'        => $p->ID,
					'name'      => $p->post_title,
					'team'      => $team,
					'position'  => $position,
					'events'    => $events,
					'email'     => get_post_meta( $p->ID, 'spt_email', true ) ?: '',
					'edit_link' => get_edit_post_link( $p->ID, 'raw' ),
				);
			}

			$certainty = $mg['certainty'];

			// Boost certainty when players share the same email address.
			$emails = array_filter( array_column( $details, 'email' ), 'strlen' );
			if ( count( $emails ) >= 2 && count( array_unique( $emails ) ) === 1 ) {
				$certainty = min( 100, $certainty + 20 );
			}

			// Boost certainty when all players share the same team.
			$team_ids = array_filter( $teams );
			if ( ! empty( $team_ids ) && count( array_unique( $team_ids ) ) === 1 && count( $team_ids ) === count( $mg['players'] ) ) {
				$certainty = min( 100, $certainty + 5 );
			}

			// Reduce certainty when players have different positions.
			$all_positions = array_column( $details, 'position' );
			$all_positions = array_filter( $all_positions, 'strlen' );
			if ( count( $all_positions ) >= 2 && count( array_unique( $all_positions ) ) > 1 ) {
				$certainty = max( 50, $certainty - 20 );
			}

			$groups[] = array(
				'name'      => $details[0]['name'],
				'certainty' => $certainty,
				'scenario'  => $mg['scenario'],
				'players'   => $details,
			);
		}

		usort( $groups, fn( $a, $b ) => $b['certainty'] - $a['certainty'] );

		$groups = array_slice( $groups, 0, 50 );

		wp_send_json_success( array( 'groups' => $groups ) );
	}

	/**
	 * Handle AJAX player search for Select2.
	 *
	 * Select2 sends search requests via GET, so we read from $_GET.
	 */
	public function search_players(): void {
		if ( ! current_user_can( 'edit_sp_players' ) ) {
			$this->send_error( __( 'Insufficient permissions', 'sportspress-player-merge' ) );
			return;
		}

		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! wp_verify_nonce( $nonce, 'sp_merge_nonce' ) ) {
			$this->send_error( __( 'Security check failed', 'sportspress-player-merge' ) );
			return;
		}

		$search = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$args = array(
			'post_type'      => 'sp_player',
			'posts_per_page' => 20,
			'no_found_rows'  => true,
			'post_status'    => 'publish',
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		$players = get_posts( $args );
		$results = array();

		foreach ( $players as $player ) {
			$team = '';
			$team_ids = get_post_meta( $player->ID, 'sp_current_team' );
			foreach ( array_reverse( $team_ids ) as $tid ) {
				if ( $tid && '0' !== $tid ) {
					$t = get_post( (int) $tid );
					if ( $t && 'sp_team' === $t->post_type ) {
						$team = $t->post_title;
						break;
					}
				}
			}

			$results[] = array(
				'id'   => $player->ID,
				'text' => $player->post_title . ' (ID: ' . $player->ID . ')' . ( $team ? ' - ' . $team : '' ),
			);
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * Send a JSON error response.
	 *
	 * @param string $message Error message.
	 */
	private function send_error( string $message ): void {
		wp_send_json_error( array( 'message' => $message ) );
	}
}
