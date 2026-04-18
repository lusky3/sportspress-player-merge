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
		if ( ! $this->validate_request() ) {
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
		if ( ! $this->validate_request() ) {
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
		if ( ! $this->validate_request() ) {
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
	private function validate_merge_input() {
		$primary_id = isset( $_POST['primary_player'] ) ? absint( wp_unslash( $_POST['primary_player'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$raw_duplicates = isset( $_POST['duplicate_players'] ) ? wp_unslash( $_POST['duplicate_players'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! is_array( $raw_duplicates ) ) {
			$this->send_error( __( 'Invalid input format', 'sportspress-player-merge' ) );
			return false;
		}

		$duplicate_ids = array_map( 'absint', $raw_duplicates );
		$duplicate_ids = array_filter( $duplicate_ids );

		if ( ! $primary_id || empty( $duplicate_ids ) ) {
			$this->send_error( __( 'Invalid player selection', 'sportspress-player-merge' ) );
			return false;
		}

		$primary_post = get_post( $primary_id );
		if ( ! $primary_post || 'sp_player' !== $primary_post->post_type ) {
			$this->send_error( __( 'Primary player not found', 'sportspress-player-merge' ) );
			return false;
		}

		foreach ( $duplicate_ids as $dup_id ) {
			$dup_post = get_post( $dup_id );
			if ( ! $dup_post || 'sp_player' !== $dup_post->post_type ) {
				$this->send_error( __( 'One or more duplicate players not found', 'sportspress-player-merge' ) );
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
	private function get_backup_id() {
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
	 * Validate nonce and capabilities.
	 *
	 * @return bool
	 */
	private function validate_request(): bool {
		if ( ! current_user_can( 'edit_sp_players' ) ) {
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
	 * Send a JSON error response.
	 *
	 * @param string $message Error message.
	 */
	private function send_error( string $message ): void {
		wp_send_json_error( array( 'message' => $message ) );
	}
}
