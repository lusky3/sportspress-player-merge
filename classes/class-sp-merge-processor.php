<?php
/**
 * Merge Processor Class
 *
 * Handles the core merge logic and data operations.
 *
 * @package SportsPress_Player_Merge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SP_Merge_Processor
 */
class SP_Merge_Processor {

	/**
	 * Simple meta keys on events that store individual player IDs as separate rows.
	 *
	 * @var string[]
	 */
	private const SIMPLE_PLAYER_META_KEYS = array(
		'sp_player',
		'sp_offense',
		'sp_defense',
	);

	/**
	 * Serialized meta keys on events where player IDs appear as array keys.
	 *
	 * @var string[]
	 */
	private const SERIALIZED_PLAYER_META_KEYS = array(
		'sp_players',
		'sp_timeline',
		'sp_order',
		'sp_stars',
	);

	/**
	 * Execute a full merge operation with transaction safety.
	 *
	 * @param int   $primary_id    The player ID to keep.
	 * @param int[] $duplicate_ids Player IDs to merge and delete.
	 * @return array{success: bool, backup_id?: string, message?: string}
	 */
	public function execute_merge( int $primary_id, array $duplicate_ids ): array {
		global $wpdb;

		$backup    = new SP_Merge_Backup();
		$backup_id = null;

		try {
			$backup_id = $backup->create_merge_backup( $primary_id, $duplicate_ids );
			if ( ! $backup_id ) {
				throw new Exception( __( 'Failed to create backup before merge', 'sportspress-player-merge' ) );
			}

			$wpdb->query( 'START TRANSACTION' );

			foreach ( $duplicate_ids as $duplicate_id ) {
				$this->merge_single_player( $primary_id, (int) $duplicate_id );
			}

			foreach ( $duplicate_ids as $duplicate_id ) {
				$post = get_post( $duplicate_id );
				if ( ! $post ) {
					continue;
				}
				$deleted = wp_delete_post( $duplicate_id, true );
				if ( ! $deleted ) {
					throw new Exception(
						sprintf(
							/* translators: %d: player ID */
							__( 'Failed to delete duplicate player %d', 'sportspress-player-merge' ),
							$duplicate_id
						)
					);
				}
			}

			$wpdb->query( 'COMMIT' );

			$this->clear_sportspress_caches( $primary_id, $duplicate_ids );

			return array(
				'success'   => true,
				'backup_id' => $backup_id,
			);

		} catch ( Exception $e ) {
			$wpdb->query( 'ROLLBACK' );

			if ( $backup_id ) {
				$backup->delete_backup( $backup_id );
			}

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'SP Merge error: ' . $e->getMessage() );
			}

			return array(
				'success' => false,
				'message' => __( 'Merge failed. Please check the error log for details.', 'sportspress-player-merge' ),
			);
		}
	}

	/**
	 * Merge a single duplicate player into the primary.
	 *
	 * @param int $primary_id   Primary player ID.
	 * @param int $duplicate_id Duplicate player ID.
	 * @throws Exception On failure.
	 */
	private function merge_single_player( int $primary_id, int $duplicate_id ): void {
		$this->merge_taxonomies( $primary_id, $duplicate_id );
		$this->merge_meta_data( $primary_id, $duplicate_id );
		$this->update_event_references( $primary_id, $duplicate_id );
	}

	/**
	 * Merge all taxonomies registered for sp_player.
	 *
	 * @param int $primary_id   Primary player ID.
	 * @param int $duplicate_id Duplicate player ID.
	 */
	private function merge_taxonomies( int $primary_id, int $duplicate_id ): void {
		$taxonomies = get_object_taxonomies( 'sp_player' );

		foreach ( $taxonomies as $taxonomy ) {
			$primary_terms   = wp_get_object_terms( $primary_id, $taxonomy, array( 'fields' => 'ids' ) );
			$duplicate_terms = wp_get_object_terms( $duplicate_id, $taxonomy, array( 'fields' => 'ids' ) );

			if ( is_wp_error( $primary_terms ) || is_wp_error( $duplicate_terms ) ) {
				continue;
			}

			$merged = array_unique( array_merge( $primary_terms, $duplicate_terms ) );

			if ( count( $merged ) > count( $primary_terms ) ) {
				wp_set_object_terms( $primary_id, $merged, $taxonomy );
			}
		}
	}

	/**
	 * Merge player meta data intelligently.
	 *
	 * @param int $primary_id   Primary player ID.
	 * @param int $duplicate_id Duplicate player ID.
	 * @throws Exception On failure.
	 */
	private function merge_meta_data( int $primary_id, int $duplicate_id ): void {
		$duplicate_meta = get_post_meta( $duplicate_id );

		// Fields to skip — preserve primary player's display settings.
		$skip_fields = array( 'sp_columns', 'sp_number' );

		// Serialized array fields that need intelligent merging.
		$array_merge_fields = array( 'sp_statistics', 'sp_leagues', 'sp_assignments', 'sp_metrics' );

		foreach ( $duplicate_meta as $key => $values ) {
			if ( 0 !== strpos( $key, 'sp_' ) ) {
				continue;
			}

			if ( in_array( $key, $skip_fields, true ) ) {
				continue;
			}

			if ( in_array( $key, $array_merge_fields, true ) ) {
				$this->merge_array_field( $primary_id, $duplicate_id, $key );
				continue;
			}

			// Multi-value fields: add unique values only.
			$existing = get_post_meta( $primary_id, $key );
			foreach ( $values as $value ) {
				if ( ! in_array( $value, $existing, true ) ) {
					add_post_meta( $primary_id, $key, $value );
				}
			}
		}

		$this->deduplicate_multi_value_meta( $primary_id );
	}

	/**
	 * Merge a serialized array meta field from duplicate into primary.
	 *
	 * @param int    $primary_id   Primary player ID.
	 * @param int    $duplicate_id Duplicate player ID.
	 * @param string $key          Meta key.
	 */
	private function merge_array_field( int $primary_id, int $duplicate_id, string $key ): void {
		$primary_value   = get_post_meta( $primary_id, $key, true );
		$duplicate_value = get_post_meta( $duplicate_id, $key, true );

		if ( empty( $duplicate_value ) || ! is_array( $duplicate_value ) ) {
			return;
		}

		if ( empty( $primary_value ) || ! is_array( $primary_value ) ) {
			update_post_meta( $primary_id, $key, $duplicate_value );
			return;
		}

		// Deep merge: add keys from duplicate that don't exist in primary.
		$merged = $this->deep_merge_arrays( $primary_value, $duplicate_value );
		update_post_meta( $primary_id, $key, $merged );
	}

	/**
	 * Deep merge two associative arrays. Primary values take precedence for scalar values.
	 * For nested arrays, recurse. For keys only in duplicate, add them.
	 *
	 * @param array $primary   Primary array.
	 * @param array $duplicate Duplicate array.
	 * @return array Merged array.
	 */
	private function deep_merge_arrays( array $primary, array $duplicate ): array {
		foreach ( $duplicate as $key => $value ) {
			if ( ! isset( $primary[ $key ] ) ) {
				$primary[ $key ] = $value;
			} elseif ( is_array( $value ) && is_array( $primary[ $key ] ) ) {
				$primary[ $key ] = $this->deep_merge_arrays( $primary[ $key ], $value );
			}
			// If both have scalar values for the same key, primary wins.
		}
		return $primary;
	}

	/**
	 * Remove duplicate values from multi-value meta fields.
	 *
	 * @param int $player_id Player ID.
	 */
	private function deduplicate_multi_value_meta( int $player_id ): void {
		$fields = array( 'sp_team', 'sp_current_team', 'sp_past_team', 'sp_nationality' );

		foreach ( $fields as $field ) {
			$values = get_post_meta( $player_id, $field );
			if ( count( $values ) <= 1 ) {
				continue;
			}

			$unique = array_unique( $values, SORT_STRING );
			if ( count( $unique ) < count( $values ) ) {
				delete_post_meta( $player_id, $field );
				foreach ( $unique as $value ) {
					if ( '' !== $value && '0' !== $value ) {
						add_post_meta( $player_id, $field, $value );
					}
				}
			}
		}
	}

	/**
	 * Update all event references from duplicate to primary player.
	 * Uses structure-aware handling for serialized data.
	 *
	 * @param int $primary_id   Primary player ID.
	 * @param int $duplicate_id Duplicate player ID.
	 * @throws Exception On failure.
	 */
	private function update_event_references( int $primary_id, int $duplicate_id ): void {
		global $wpdb;

		// 1. Simple meta: individual rows with exact player ID values.
		foreach ( self::SIMPLE_PLAYER_META_KEYS as $meta_key ) {
			$wpdb->update(
				$wpdb->postmeta,
				array( 'meta_value' => (string) $primary_id ),
				array(
					'meta_key'   => $meta_key,
					'meta_value' => (string) $duplicate_id,
				),
				array( '%s' ),
				array( '%s', '%s' )
			);
		}

		// 2. Serialized meta: unserialize, replace keys/values, re-serialize.
		$this->update_serialized_event_meta( $primary_id, $duplicate_id );
	}

	/**
	 * Update serialized event meta that contains player IDs as array keys.
	 *
	 * @param int $primary_id   Primary player ID.
	 * @param int $duplicate_id Duplicate player ID.
	 */
	private function update_serialized_event_meta( int $primary_id, int $duplicate_id ): void {
		global $wpdb;

		// Find all events that reference this duplicate player.
		$event_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = 'sp_player' AND meta_value = %s",
				(string) $duplicate_id
			)
		);

		if ( empty( $event_ids ) ) {
			return;
		}

		foreach ( $event_ids as $event_id ) {
			foreach ( self::SERIALIZED_PLAYER_META_KEYS as $meta_key ) {
				$raw = get_post_meta( (int) $event_id, $meta_key, true );
				if ( empty( $raw ) || ! is_array( $raw ) ) {
					continue;
				}

				$updated = $this->replace_player_id_in_structure( $raw, $primary_id, $duplicate_id );
				if ( $updated !== $raw ) {
					update_post_meta( (int) $event_id, $meta_key, $updated );
				}
			}
		}
	}

	/**
	 * Recursively replace a player ID used as an array key in a nested structure.
	 *
	 * SportsPress structures like sp_players and sp_timeline use:
	 *   array( team_id => array( player_id => data ) )
	 *
	 * This walks the structure and re-keys entries from duplicate_id to primary_id.
	 *
	 * @param array $data         The data structure.
	 * @param int   $primary_id   Primary player ID.
	 * @param int   $duplicate_id Duplicate player ID.
	 * @return array Modified structure.
	 */
	private function replace_player_id_in_structure( array $data, int $primary_id, int $duplicate_id ): array {
		$result = array();

		foreach ( $data as $key => $value ) {
			$new_key = ( (int) $key === $duplicate_id ) ? $primary_id : $key;

			if ( is_array( $value ) ) {
				$new_value = $this->replace_player_id_in_structure( $value, $primary_id, $duplicate_id );
			} else {
				$new_value = ( (int) $value === $duplicate_id ) ? (string) $primary_id : $value;
			}

			// If primary already exists at this key, merge rather than overwrite.
			if ( isset( $result[ $new_key ] ) && is_array( $result[ $new_key ] ) && is_array( $new_value ) ) {
				$result[ $new_key ] = $this->deep_merge_arrays( $result[ $new_key ], $new_value );
			} else {
				$result[ $new_key ] = $new_value;
			}
		}

		return $result;
	}

	/**
	 * Clear SportsPress caches after merge so stats recalculate.
	 *
	 * @param int   $primary_id    Primary player ID.
	 * @param int[] $duplicate_ids Duplicate player IDs.
	 */
	private function clear_sportspress_caches( int $primary_id, array $duplicate_ids ): void {
		// Clear post and meta caches for primary player.
		clean_post_cache( $primary_id );

		// Fire SportsPress recalculation hooks.
		if ( function_exists( 'sp_delete_player_data' ) ) {
			sp_delete_player_data( $primary_id );
		}

		// Clear transients that SportsPress may have cached.
		delete_transient( 'sp_player_data_' . $primary_id );

		// Find affected events and clear their caches too.
		global $wpdb;
		$event_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = 'sp_player' AND meta_value = %s",
				(string) $primary_id
			)
		);

		foreach ( $event_ids as $event_id ) {
			clean_post_cache( (int) $event_id );
			delete_transient( 'sp_event_data_' . $event_id );
		}

		/**
		 * Fires after a player merge completes and caches are cleared.
		 *
		 * @param int   $primary_id    The kept player ID.
		 * @param int[] $duplicate_ids The merged (deleted) player IDs.
		 */
		do_action( 'sp_merge_after_merge', $primary_id, $duplicate_ids );
	}
}
