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

			wp_send_json_success(
				array(
					'preview'  => $preview_data,
					// Binds the execution that follows to exactly this selection.
					'token'    => self::selection_token( $input['primary_id'], $input['duplicate_ids'] ),
					'warnings' => SP_Merge_Validation::survivor_warnings( $input['primary_id'], $input['duplicate_ids'] ),
				)
			);
		} catch ( Throwable $e ) {
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

		if ( ! $this->verify_preview_token( $input['primary_id'], $input['duplicate_ids'] ) ) {
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
		} catch ( Throwable $e ) {
			$this->send_error( __( 'Merge operation failed', 'sportspress-player-merge' ) );
		}
	}

	/**
	 * Handle revert merge request.
	 *
	 * A revert is attempted unforced first. When the backup class refuses
	 * because values changed after the merge, the refusal is returned with
	 * force_offered so the UI can present the override as a second, separately
	 * confirmed step. The override is never the default and never implicit.
	 */
	public function revert_merge(): void {
		if ( ! $this->validate_write_request() ) {
			return;
		}

		$backup_id = $this->get_backup_id();
		if ( ! $backup_id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in validate_write_request
		$force = isset( $_POST['force'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['force'] ) );

		try {
			$backup = new SP_Merge_Backup();
			$result = $backup->revert( $backup_id, $force );

			if ( $result['success'] ) {
				wp_send_json_success(
					array(
						'message' => SP_Merge_Validation::revert_success_message( $force ),
					)
				);
			} else {
				$this->send_error(
					$result['message'] ?? __( 'Revert failed', 'sportspress-player-merge' ),
					array(
						'backup_id'     => $backup_id,
						'force_offered' => ! $force && 'values_changed' === ( $result['code'] ?? '' ),
					)
				);
			}
		} catch ( Throwable $e ) {
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
		} catch ( Throwable $e ) {
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
			// Rendered by the admin class so this markup and the admin page's
			// initial render cannot drift apart on status or escaping.
			foreach ( $recent_backups as $backup ) {
				$admin->render_backup_item( $backup );
			}
			$html = ob_get_clean();

			wp_send_json_success( array( 'html' => $html ) );
		} catch ( Throwable $e ) {
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

		$result = SP_Merge_Validation::validate_merge_selection( $primary_id, $raw_duplicates );
		if ( ! $result['valid'] ) {
			$this->send_error( $result['error'] );
			return false;
		}

		return array(
			'primary_id'    => $result['primary_id'],
			'duplicate_ids' => $result['duplicate_ids'],
		);
	}

	/**
	 * Derive the token that binds an execution to the preview that approved it.
	 *
	 * The token covers the primary and the *set* of duplicates, so re-ordering
	 * the POST still verifies while swapping either side does not. Stateless by
	 * design: nothing to expire, store or clean up.
	 *
	 * @param int   $primary_id    Primary player ID.
	 * @param int[] $duplicate_ids Duplicate player IDs.
	 * @return string
	 */
	public static function selection_token( int $primary_id, array $duplicate_ids ): string {
		$ids = array_values( array_unique( array_map( 'intval', $duplicate_ids ) ) );
		sort( $ids, SORT_NUMERIC );

		return wp_hash( 'sp_merge_selection|' . $primary_id . '|' . implode( ',', $ids ) );
	}

	/**
	 * Require a preview token matching the selection being executed.
	 *
	 * Without this a stale preview card and a changed dropdown can execute a
	 * merge the operator never previewed.
	 *
	 * @param int   $primary_id    Primary player ID.
	 * @param int[] $duplicate_ids Duplicate player IDs.
	 * @return bool True when the token matches.
	 */
	private function verify_preview_token( int $primary_id, array $duplicate_ids ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in validate_write_request
		$token = isset( $_POST['preview_token'] ) ? sanitize_text_field( wp_unslash( $_POST['preview_token'] ) ) : '';

		if ( '' === $token || ! hash_equals( self::selection_token( $primary_id, $duplicate_ids ), $token ) ) {
			$this->send_error( __( 'This merge does not match the preview that was reviewed. Preview the current selection again before executing.', 'sportspress-player-merge' ) );
			return false;
		}

		return true;
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
	 * Read a field from a name-matcher group member.
	 *
	 * Members are post objects today. The matcher is being reworked to attach a
	 * per-member certainty, so read defensively rather than assume a shape: a
	 * missing field degrades, it never fatals.
	 *
	 * @param mixed  $member  Group member.
	 * @param string $key     Field name.
	 * @param mixed  $fallback Value when the field is absent.
	 * @return mixed
	 */
	private function member_value( $member, string $key, $fallback = null ) {
		if ( is_object( $member ) && isset( $member->$key ) ) {
			return $member->$key;
		}

		if ( is_array( $member ) && isset( $member[ $key ] ) ) {
			return $member[ $key ];
		}

		return $fallback;
	}

	/**
	 * Read a member's own certainty from the matcher payload.
	 *
	 * @param mixed $member    Group member.
	 * @param array $group     The group the member belongs to.
	 * @param int   $player_id Member player ID.
	 * @return int|null Certainty, or null when the matcher did not supply one.
	 */
	private function member_certainty( $member, array $group, int $player_id ): ?int {
		$certainty = $this->member_value( $member, 'certainty' );

		foreach ( array( 'member_certainty', 'certainties' ) as $map_key ) {
			if ( null === $certainty && isset( $group[ $map_key ][ $player_id ] ) ) {
				$certainty = $group[ $map_key ][ $player_id ];
			}
		}

		if ( ! is_numeric( $certainty ) ) {
			return null;
		}

		return max( 0, min( 100, (int) $certainty ) );
	}

	/**
	 * Find possible duplicate players by matching names.
	 */
	public function find_duplicates(): void {
		if ( ! $this->validate_request() ) {
			return;
		}

		$scan    = SP_Merge_Validation::collect_scan_players();
		$players = $scan['players'];

		$player_ids = wp_list_pluck( $players, 'ID' );
		if ( ! empty( $player_ids ) ) {
			update_meta_cache( 'post', $player_ids );
			update_object_term_cache( $player_ids, 'sp_player' );
		}

		// Use fuzzy name matcher to find duplicate groups.
		$matched_groups = SP_Merge_Name_Matcher::find_groups( $players );

		// Batch event count query for all matched player IDs.
		$matched_ids = array();
		foreach ( $matched_groups as $mg ) {
			foreach ( $mg['players'] as $p ) {
				$matched_ids[] = (int) $this->member_value( $p, 'ID', 0 );
			}
		}
		$event_counts = SP_Merge_Validation::get_event_counts( $matched_ids );

		$groups = array();
		foreach ( $matched_groups as $mg ) {
			$details = array();
			$members = array();
			foreach ( $mg['players'] as $p ) {
				$player_id = (int) $this->member_value( $p, 'ID', 0 );
				if ( ! $player_id ) {
					continue;
				}

				// Team, position and email are read through the shared helper so
				// `wp sp-merge scan` cannot end up scoring the same group from
				// differently-read signals.
				$signals = SP_Merge_Validation::certainty_signals( $player_id );

				// Null when the matcher supplied none; the UI then treats the
				// member as low confidence and leaves it unchecked.
				$certainty = $this->member_certainty( $p, $mg, $player_id );

				$details[] = array(
					'id'        => $player_id,
					'name'      => (string) $this->member_value( $p, 'post_title', '' ),
					'team'      => $signals['team'],
					'position'  => $signals['position'],
					'events'    => $event_counts[ $player_id ] ?? 0,
					'email'     => $signals['email'],
					'certainty' => $certainty,
					'edit_link' => get_edit_post_link( $player_id, 'raw' ),
				);

				$members[] = array(
					'email'     => $signals['email'],
					'position'  => $signals['position'],
					'team_id'   => $signals['team_id'],
					'certainty' => $certainty,
				);
			}

			if ( count( $details ) < 2 ) {
				continue;
			}

			// The email boost, team boost and position penalty all live on
			// SP_Merge_Validation now, so the browser's badge and the CLI's
			// --min-certainty filter are the same number by construction.
			$adjusted  = SP_Merge_Validation::apply_certainty_adjustments( $mg, $members );
			$certainty = $adjusted['certainty'];

			foreach ( $adjusted['members'] as $index => $member ) {
				$details[ $index ]['certainty'] = $member['certainty'];
			}

			$groups[] = array(
				'name'      => $details[0]['name'],
				'certainty' => $certainty,
				'scenario'  => $mg['scenario'] ?? '',
				'players'   => $details,
			);
		}

		usort( $groups, fn( $a, $b ) => $b['certainty'] - $a['certainty'] );

		$groups = array_slice( $groups, 0, 50 );

		wp_send_json_success(
			array(
				'groups'    => $groups,
				'scanned'   => $scan['scanned'],
				'total'     => $scan['total'],
				'truncated' => $scan['truncated'],
			)
		);
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

		// Event counts make the survivor choice visible: without them a 2016
		// record with a decade of history and an empty 2026 one look identical.
		$event_counts = SP_Merge_Validation::get_event_counts( wp_list_pluck( $players, 'ID' ) );

		foreach ( $players as $player ) {
			$current_team = SP_Merge_Validation::current_team( $player->ID );
			$team         = $current_team['name'] ?? '';

			$results[] = array(
				'id'   => $player->ID,
				'text' => sprintf(
					/* translators: 1: player name, 2: player ID, 3: team suffix, may be empty, 4: number of events */
					__( '%1$s (ID: %2$d)%3$s — %4$d events', 'sportspress-player-merge' ),
					$player->post_title,
					$player->ID,
					$team ? ' - ' . $team : '',
					$event_counts[ $player->ID ] ?? 0
				),
			);
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * Send a JSON error response.
	 *
	 * @param string $message Error message.
	 * @param array  $extra   Additional payload merged into the response data.
	 */
	private function send_error( string $message, array $extra = array() ): void {
		wp_send_json_error( array_merge( array( 'message' => $message ), $extra ) );
	}
}
