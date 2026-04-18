<?php
/**
 * Backup Manager Class
 *
 * Handles backup creation, storage, and restoration.
 *
 * @package SportsPress_Player_Merge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SP_Merge_Backup
 */
class SP_Merge_Backup {

	/**
	 * Create a merge backup before executing.
	 *
	 * @param int   $primary_id    Primary player ID.
	 * @param int[] $duplicate_ids Duplicate player IDs.
	 * @return string Backup ID.
	 * @throws Exception On failure.
	 */
	public function create_merge_backup( int $primary_id, array $duplicate_ids ): string {
		$backup_id   = 'merge_' . time() . '_' . wp_generate_password( 8, false );
		$backup_data = $this->prepare_backup_data( $primary_id, $duplicate_ids );

		if ( empty( $backup_data ) ) {
			throw new Exception( __( 'Failed to prepare backup data', 'sportspress-player-merge' ) );
		}

		$this->save_backup( $backup_id, $backup_data );
		$this->cleanup_old_backups();

		return $backup_id;
	}

	/**
	 * Revert a merge from backup.
	 *
	 * @param string $backup_id Backup ID.
	 * @return array{success: bool, message?: string}
	 */
	public function revert( string $backup_id ): array {
		$backup_data = $this->load_backup_data( $backup_id );

		if ( ! $backup_data ) {
			return array(
				'success' => false,
				'message' => __( 'Backup data not found', 'sportspress-player-merge' ),
			);
		}

		global $wpdb;

		try {
			$wpdb->query( 'START TRANSACTION' );

			$this->execute_revert( $backup_data );

			$wpdb->query( 'COMMIT' );

			$this->cleanup_after_revert( $backup_id );

			return array( 'success' => true );

		} catch ( Exception $e ) {
			$wpdb->query( 'ROLLBACK' );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'SP Merge revert error: ' . $e->getMessage() );
			}

			return array(
				'success' => false,
				'message' => __( 'Revert failed. Please check the error log for details.', 'sportspress-player-merge' ),
			);
		}
	}

	/**
	 * Delete a single backup.
	 *
	 * @param string $backup_id Backup ID.
	 * @return bool
	 */
	public function delete_backup( string $backup_id ): bool {
		$this->cleanup_after_revert( $backup_id );
		return true;
	}

	/**
	 * Delete multiple backups.
	 *
	 * @param string[] $backup_ids Backup IDs.
	 * @return int Number deleted.
	 */
	public function delete_backups( array $backup_ids ): int {
		global $wpdb;

		$table_name = $wpdb->prefix . 'sp_merge_backups';

		if ( empty( $backup_ids ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $backup_ids ), '%s' ) );
		$query_args   = array_merge( $backup_ids, array( get_current_user_id() ) );
		$result       = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE backup_id IN ({$placeholders}) AND user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$query_args
			)
		);

		if ( false !== $result ) {
			$last_backup_id = get_user_meta( get_current_user_id(), 'sp_last_merge_backup', true );
			if ( in_array( $last_backup_id, $backup_ids, true ) ) {
				delete_user_meta( get_current_user_id(), 'sp_last_merge_backup' );
			}
			return $result;
		}

		return 0;
	}

	/**
	 * Prepare comprehensive backup data including affected event meta.
	 *
	 * @param int   $primary_id    Primary player ID.
	 * @param int[] $duplicate_ids Duplicate player IDs.
	 * @return array Backup data.
	 */
	private function prepare_backup_data( int $primary_id, array $duplicate_ids ): array {
		// Pre-cache meta for all players.
		$all_ids = array_merge( array( $primary_id ), $duplicate_ids );
		update_postmeta_cache( $all_ids );

		$backup_data = array(
			'timestamp'         => current_time( 'mysql' ),
			'primary_id'        => $primary_id,
			'primary_name'      => get_the_title( $primary_id ),
			'duplicate_ids'     => $duplicate_ids,
			'duplicate_names'   => array(),
			'primary_backup'    => $this->backup_player_data( $primary_id ),
			'duplicate_backups' => array(),
			'affected_events'   => $this->backup_affected_event_meta( $duplicate_ids ),
		);

		foreach ( $duplicate_ids as $duplicate_id ) {
			$backup_data['duplicate_backups'][ $duplicate_id ] = $this->backup_player_data( (int) $duplicate_id );
			$backup_data['duplicate_names'][ $duplicate_id ]   = get_the_title( $duplicate_id );
		}

		return $backup_data;
	}

	/**
	 * Backup all player data: post, meta, taxonomies.
	 *
	 * @param int $player_id Player ID.
	 * @return array Player backup data.
	 */
	private function backup_player_data( int $player_id ): array {
		$player = get_post( $player_id );

		// Get all taxonomies dynamically.
		$taxonomies     = get_object_taxonomies( 'sp_player' );
		$taxonomy_data  = array();

		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $player_id, $taxonomy );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				$taxonomy_data[ $taxonomy ] = array_map(
					static function ( $term ) {
						return array(
							'term_id'          => $term->term_id,
							'name'             => $term->name,
							'slug'             => $term->slug,
							'term_taxonomy_id' => $term->term_taxonomy_id,
						);
					},
					$terms
				);
			}
		}

		$all_meta = get_post_meta( $player_id );

		return array(
			'post_data'  => $player,
			'meta_data'  => $all_meta,
			'taxonomies' => $taxonomy_data,
		);
	}

	/**
	 * Backup serialized event meta that will be modified during merge.
	 *
	 * @param int[] $duplicate_ids Duplicate player IDs.
	 * @return array Map of event_id => array of meta_key => original_value.
	 */
	private function backup_affected_event_meta( array $duplicate_ids ): array {
		global $wpdb;

		if ( empty( $duplicate_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $duplicate_ids ), '%s' ) );
		$str_ids      = array_map( 'strval', $duplicate_ids );

		// Find events referencing any duplicate player.
		$event_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = 'sp_player' AND meta_value IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$str_ids
			)
		);

		if ( empty( $event_ids ) ) {
			return array();
		}

		$serialized_keys = array( 'sp_players', 'sp_timeline', 'sp_order', 'sp_stars' );
		$simple_keys     = array( 'sp_player', 'sp_offense', 'sp_defense' );
		$affected        = array();

		foreach ( $event_ids as $event_id ) {
			$event_id = (int) $event_id;
			$event_data = array();

			// Backup serialized meta.
			foreach ( $serialized_keys as $meta_key ) {
				$value = get_post_meta( $event_id, $meta_key, true );
				if ( ! empty( $value ) ) {
					$event_data[ $meta_key ] = $value;
				}
			}

			// Backup simple meta rows that reference duplicate players.
			foreach ( $simple_keys as $meta_key ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT meta_id, meta_value FROM {$wpdb->postmeta}
						WHERE post_id = %d AND meta_key = %s AND meta_value IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						array_merge( array( $event_id, $meta_key ), $str_ids )
					)
				);
				if ( ! empty( $rows ) ) {
					$event_data[ '_simple_' . $meta_key ] = array();
					foreach ( $rows as $row ) {
						$event_data[ '_simple_' . $meta_key ][] = array(
							'meta_id'    => (int) $row->meta_id,
							'meta_value' => $row->meta_value,
						);
					}
				}
			}

			if ( ! empty( $event_data ) ) {
				$affected[ $event_id ] = $event_data;
			}
		}

		return $affected;
	}

	/**
	 * Save backup to database.
	 *
	 * @param string $backup_id   Backup ID.
	 * @param array  $backup_data Backup data.
	 * @throws Exception On failure.
	 */
	private function save_backup( string $backup_id, array $backup_data ): void {
		global $wpdb;

		$json = wp_json_encode( $backup_data );
		if ( false === $json ) {
			throw new Exception( __( 'Failed to encode backup data', 'sportspress-player-merge' ) );
		}

		$result = $wpdb->insert(
			$wpdb->prefix . 'sp_merge_backups',
			array(
				'backup_id'   => $backup_id,
				'user_id'     => get_current_user_id(),
				'backup_data' => $json,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			throw new Exception( __( 'Failed to save backup to database', 'sportspress-player-merge' ) );
		}

		update_user_meta( get_current_user_id(), 'sp_last_merge_backup', $backup_id );
	}

	/**
	 * Load backup data from database.
	 *
	 * @param string $backup_id Backup ID.
	 * @return array|null Backup data or null.
	 */
	private function load_backup_data( string $backup_id ): ?array {
		global $wpdb;

		if ( ! preg_match( '/^merge_\d+_[a-zA-Z0-9]{8}$/', $backup_id ) ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT backup_data FROM {$wpdb->prefix}sp_merge_backups WHERE backup_id = %s AND user_id = %d",
				$backup_id,
				get_current_user_id()
			)
		);

		return $row ? json_decode( $row->backup_data, true ) : null;
	}

	/**
	 * Execute the revert operation.
	 *
	 * @param array $backup_data Backup data.
	 * @throws Exception On failure.
	 */
	private function execute_revert( array $backup_data ): void {
		global $wpdb;

		// 1. Restore affected event meta to original values.
		if ( ! empty( $backup_data['affected_events'] ) ) {
			foreach ( $backup_data['affected_events'] as $event_id => $meta_entries ) {
				$event_id = (int) $event_id;
				foreach ( $meta_entries as $meta_key => $original_value ) {
					if ( 0 === strpos( $meta_key, '_simple_' ) ) {
						// Restore simple meta rows by meta_id.
						foreach ( $original_value as $row ) {
							$wpdb->update(
								$wpdb->postmeta,
								array( 'meta_value' => $row['meta_value'] ),
								array( 'meta_id' => (int) $row['meta_id'] ),
								array( '%s' ),
								array( '%d' )
							);
						}
					} else {
						update_post_meta( $event_id, $meta_key, $original_value );
					}
				}
				clean_post_cache( $event_id );
				delete_transient( 'sp_event_data_' . $event_id );
			}
		}

		// 2. Recreate deleted duplicate players.
		$primary_id    = (int) $backup_data['primary_id'];
		$recreated_ids = array();

		foreach ( $backup_data['duplicate_backups'] as $duplicate_id => $duplicate_backup ) {
			if ( isset( $duplicate_backup['post_data'] ) ) {
				$this->recreate_player( (int) $duplicate_id, $duplicate_backup );
				$recreated_ids[] = (int) $duplicate_id;
			}
		}

		// 3. Restore primary player to original state.
		$this->restore_player_data( $primary_id, $backup_data['primary_backup'] );

		// 4. Clear SportsPress caches for all affected players.
		$all_player_ids = array_merge( array( $primary_id ), $recreated_ids );
		foreach ( $all_player_ids as $pid ) {
			clean_post_cache( $pid );
			delete_transient( 'sp_player_data_' . $pid );
			if ( function_exists( 'sp_delete_player_data' ) ) {
				sp_delete_player_data( $pid );
			}
		}
	}

	/**
	 * Recreate a deleted player from backup data.
	 *
	 * @param int   $original_id Original player ID.
	 * @param array $backup_data Player backup data.
	 * @return int|false Player ID or false.
	 */
	private function recreate_player( int $original_id, array $backup_data ): int|false {
		$existing = get_post( $original_id );
		if ( $existing && 'sp_player' === $existing->post_type ) {
			$this->restore_player_data( $original_id, $backup_data );
			return $original_id;
		}

		$post_data = (array) $backup_data['post_data'];

		$result = wp_insert_post(
			array(
				'import_id'      => $original_id,
				'post_author'    => (int) ( $post_data['post_author'] ?? 1 ),
				'post_date'      => $post_data['post_date'] ?? '',
				'post_date_gmt'  => $post_data['post_date_gmt'] ?? '',
				'post_content'   => $post_data['post_content'] ?? '',
				'post_title'     => $post_data['post_title'] ?? '',
				'post_excerpt'   => $post_data['post_excerpt'] ?? '',
				'post_status'    => $post_data['post_status'] ?? 'publish',
				'post_type'      => 'sp_player',
				'post_name'      => $post_data['post_name'] ?? '',
				'menu_order'     => (int) ( $post_data['menu_order'] ?? 0 ),
				'comment_status' => $post_data['comment_status'] ?? 'closed',
				'ping_status'    => $post_data['ping_status'] ?? 'closed',
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			throw new Exception(
				sprintf(
					/* translators: %d: player ID */
					__( 'Failed to recreate player %d', 'sportspress-player-merge' ),
					$original_id
				)
			);
		}

		$this->restore_player_data( $original_id, $backup_data );

		return $original_id;
	}

	/**
	 * Restore a player's meta and taxonomy data from backup.
	 *
	 * @param int   $player_id   Player ID.
	 * @param array $backup_data Player backup data.
	 */
	private function restore_player_data( int $player_id, array $backup_data ): void {
		global $wpdb;

		// Clear existing SP meta.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
				$player_id,
				'sp\_%'
			)
		);

		// Restore all meta.
		if ( ! empty( $backup_data['meta_data'] ) ) {
			foreach ( $backup_data['meta_data'] as $key => $values ) {
				if ( 0 !== strpos( $key, 'sp_' ) ) {
					continue;
				}
				if ( is_array( $values ) ) {
					foreach ( $values as $value ) {
						$restored = maybe_unserialize( $value );
						if ( is_object( $restored ) ) {
							continue; // Skip unexpected objects for safety.
						}
						add_post_meta( $player_id, $key, $restored );
					}
				}
			}
		}

		// Restore taxonomies.
		if ( ! empty( $backup_data['taxonomies'] ) ) {
			foreach ( $backup_data['taxonomies'] as $taxonomy => $terms ) {
				$term_ids = wp_list_pluck( $terms, 'term_id' );
				$term_ids = array_map( 'intval', $term_ids );
				wp_set_object_terms( $player_id, $term_ids, $taxonomy );
			}
		}

		clean_post_cache( $player_id );
	}

	/**
	 * Clean up after a successful revert.
	 *
	 * @param string $backup_id Backup ID.
	 */
	private function cleanup_after_revert( string $backup_id ): void {
		global $wpdb;

		$wpdb->delete(
			$wpdb->prefix . 'sp_merge_backups',
			array(
				'backup_id' => $backup_id,
				'user_id'   => get_current_user_id(),
			),
			array( '%s', '%d' )
		);

		$last = get_user_meta( get_current_user_id(), 'sp_last_merge_backup', true );
		if ( $last === $backup_id ) {
			delete_user_meta( get_current_user_id(), 'sp_last_merge_backup' );
		}
	}

	/**
	 * Remove backups older than retention period.
	 */
	private function cleanup_old_backups(): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}sp_merge_backups WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				SP_MERGE_BACKUP_RETENTION_DAYS
			)
		);
	}
}
