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
	 * Transient key used for merge locking.
	 *
	 * @var string
	 */
	private const LOCK_KEY = 'sp_merge_lock';

	/**
	 * Execute a full merge operation with transaction safety and locking.
	 *
	 * @param int   $primary_id    The player ID to keep.
	 * @param int[] $duplicate_ids Player IDs to merge and delete.
	 * @return array{success: bool, backup_id?: string, message?: string}
	 */
	public function execute_merge( int $primary_id, array $duplicate_ids ): array {
		global $wpdb;

		// Acquire merge lock.
		if ( ! $this->acquire_lock() ) {
			return array(
				'success' => false,
				'message' => __( 'Another merge is in progress. Please wait and try again.', 'sportspress-player-merge' ),
			);
		}

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
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Acquire an atomic merge lock.
	 *
	 * @return bool True if lock acquired.
	 */
	private function acquire_lock(): bool {
		// wp_cache_add is atomic — returns false if key already exists.
		if ( wp_using_ext_object_cache() ) {
			$acquired = wp_cache_add( self::LOCK_KEY, get_current_user_id(), 'sp_merge', 300 );
			if ( ! $acquired ) {
				return false;
			}
		}
		// Also set transient as persistent fallback / for non-object-cache environments.
		if ( get_transient( self::LOCK_KEY ) ) {
			return false;
		}
		set_transient( self::LOCK_KEY, get_current_user_id(), 300 );
		return true;
	}

	/**
	 * Release the merge lock.
	 */
	private function release_lock(): void {
		delete_transient( self::LOCK_KEY );
		wp_cache_delete( self::LOCK_KEY, 'sp_merge' );
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
		$this->update_player_list_references( $primary_id, $duplicate_id );
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

		$skip_fields        = array( 'sp_columns', 'sp_number' );
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

		$merged = $this->deep_merge_arrays( $primary_value, $duplicate_value );
		update_post_meta( $primary_id, $key, $merged );
	}

	/**
	 * Deep merge two arrays. Primary values take precedence for scalar values.
	 * Numerically-indexed arrays are appended (array_merge) to preserve all entries.
	 * Associative arrays are recursed.
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
				// Numerically-indexed arrays (e.g., timeline minutes): append all values.
				if ( $this->is_numeric_indexed( $value ) && $this->is_numeric_indexed( $primary[ $key ] ) ) {
					$primary[ $key ] = array_values( array_unique( array_merge( $primary[ $key ], $value ) ) );
				} else {
					$primary[ $key ] = $this->deep_merge_arrays( $primary[ $key ], $value );
				}
			}
			// Scalar conflict: primary wins.
		}
		return $primary;
	}

	/**
	 * Check if an array is numerically indexed (sequential 0-based keys).
	 *
	 * @param array $arr Array to check.
	 * @return bool
	 */
	private function is_numeric_indexed( array $arr ): bool {
		if ( empty( $arr ) ) {
			return true;
		}
		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
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
	 *
	 * @param int $primary_id   Primary player ID.
	 * @param int $duplicate_id Duplicate player ID.
	 * @throws Exception On failure.
	 */
	private function update_event_references( int $primary_id, int $duplicate_id ): void {
		global $wpdb;

		// Simple meta: exact-match update.
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

		// Serialized meta: structure-aware replacement.
		$this->update_serialized_event_meta( $primary_id, $duplicate_id );
	}

	/**
	 * Update serialized event meta that contains player IDs as array keys.
	 * Uses additive merging for same-event collisions (sums numeric stats).
	 *
	 * @param int $primary_id   Primary player ID.
	 * @param int $duplicate_id Duplicate player ID.
	 */
	private function update_serialized_event_meta( int $primary_id, int $duplicate_id ): void {
		global $wpdb;

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

		// Pre-warm meta cache for all affected events.
		update_postmeta_cache( array_map( 'intval', $event_ids ) );

		foreach ( $event_ids as $event_id ) {
			$event_id = (int) $event_id;

			foreach ( self::SERIALIZED_PLAYER_META_KEYS as $meta_key ) {
				$raw = get_post_meta( $event_id, $meta_key, true );
				if ( empty( $raw ) || ! is_array( $raw ) ) {
					continue;
				}

				$updated = $this->replace_player_id_in_structure( $raw, $primary_id, $duplicate_id, $meta_key );
				if ( $updated !== $raw ) {
					update_post_meta( $event_id, $meta_key, $updated );
				}
			}
		}
	}

	/**
	 * Recursively replace a player ID in a nested structure.
	 * For sp_players: sums numeric performance values on collision.
	 * For sp_timeline: appends minute arrays on collision.
	 *
	 * @param array  $data         The data structure.
	 * @param int    $primary_id   Primary player ID.
	 * @param int    $duplicate_id Duplicate player ID.
	 * @param string $meta_key     The meta key context for merge strategy.
	 * @return array Modified structure.
	 */
	private function replace_player_id_in_structure( array $data, int $primary_id, int $duplicate_id, string $meta_key = '' ): array {
		$result = array();

		foreach ( $data as $key => $value ) {
			$new_key = ( (int) $key === $duplicate_id ) ? $primary_id : $key;

			if ( is_array( $value ) ) {
				$new_value = $this->replace_player_id_in_structure( $value, $primary_id, $duplicate_id, $meta_key );
			} else {
				$new_value = ( (int) $value === $duplicate_id ) ? (string) $primary_id : $value;
			}

			// Handle collision: both primary and duplicate exist under the same parent key.
			if ( isset( $result[ $new_key ] ) && is_array( $result[ $new_key ] ) && is_array( $new_value ) ) {
				$result[ $new_key ] = $this->merge_collision( $result[ $new_key ], $new_value, $meta_key );
			} else {
				$result[ $new_key ] = $new_value;
			}
		}

		return $result;
	}

	/**
	 * Merge two player entries that collide (same event, same team).
	 * For sp_players: sums numeric stat values, keeps primary's status/sub/position.
	 * For sp_timeline: appends minute arrays.
	 * For sp_order/sp_stars: keeps primary's values.
	 *
	 * @param array  $primary  Primary player's data.
	 * @param array  $incoming Duplicate player's data.
	 * @param string $meta_key Meta key context.
	 * @return array Merged data.
	 */
	private function merge_collision( array $primary, array $incoming, string $meta_key ): array {
		if ( 'sp_players' === $meta_key ) {
			return $this->merge_player_performance( $primary, $incoming );
		}

		if ( 'sp_timeline' === $meta_key ) {
			return $this->merge_timeline_data( $primary, $incoming );
		}

		// For sp_order, sp_stars: primary wins.
		return $primary;
	}

	/**
	 * Merge two player performance entries from the same event.
	 * Sums numeric stat values. Keeps primary's status, sub, position, number.
	 *
	 * @param array $primary  Primary performance data.
	 * @param array $incoming Duplicate performance data.
	 * @return array Merged performance.
	 */
	private function merge_player_performance( array $primary, array $incoming ): array {
		$non_numeric_keys = array( 'status', 'sub', 'number', 'position' );

		foreach ( $incoming as $stat_key => $stat_value ) {
			if ( in_array( $stat_key, $non_numeric_keys, true ) ) {
				// Keep primary's value for non-numeric fields.
				if ( ! isset( $primary[ $stat_key ] ) || '' === $primary[ $stat_key ] ) {
					$primary[ $stat_key ] = $stat_value;
				}
				continue;
			}

			if ( ! isset( $primary[ $stat_key ] ) || '' === $primary[ $stat_key ] ) {
				$primary[ $stat_key ] = $stat_value;
			} elseif ( is_numeric( $primary[ $stat_key ] ) && is_numeric( $stat_value ) ) {
				// Sum numeric stats (goals, assists, etc.).
				$primary[ $stat_key ] = (string) ( (float) $primary[ $stat_key ] + (float) $stat_value );
			}
		}

		return $primary;
	}

	/**
	 * Merge two timeline entries from the same event.
	 * Appends minute arrays for each performance key.
	 *
	 * @param array $primary  Primary timeline data.
	 * @param array $incoming Duplicate timeline data.
	 * @return array Merged timeline.
	 */
	private function merge_timeline_data( array $primary, array $incoming ): array {
		foreach ( $incoming as $perf_key => $minutes ) {
			if ( ! isset( $primary[ $perf_key ] ) ) {
				$primary[ $perf_key ] = $minutes;
			} elseif ( is_array( $primary[ $perf_key ] ) && is_array( $minutes ) ) {
				// Append all minutes and sort.
				$merged = array_merge( $primary[ $perf_key ], $minutes );
				sort( $merged, SORT_NUMERIC );
				$primary[ $perf_key ] = array_values( array_unique( $merged ) );
			}
		}

		return $primary;
	}

	/**
	 * Update sp_list posts that reference the duplicate player.
	 *
	 * @param int $primary_id   Primary player ID.
	 * @param int $duplicate_id Duplicate player ID.
	 */
	private function update_player_list_references( int $primary_id, int $duplicate_id ): void {
		global $wpdb;

		$dup_str = (string) $duplicate_id;

		// Find sp_list posts referencing the duplicate via sp_player simple meta
		// OR via serialized sp_players meta (LIKE search as fallback).
		$list_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = 'sp_list'
				AND (
					(pm.meta_key = 'sp_player' AND pm.meta_value = %s)
					OR (pm.meta_key = 'sp_players' AND pm.meta_value LIKE %s)
				)",
				$dup_str,
				'%' . $wpdb->esc_like( $dup_str ) . '%'
			)
		);

		if ( empty( $list_ids ) ) {
			return;
		}

		foreach ( $list_ids as $list_id ) {
			$list_id = (int) $list_id;

			// Update simple sp_player meta rows.
			$wpdb->update(
				$wpdb->postmeta,
				array( 'meta_value' => (string) $primary_id ),
				array(
					'post_id'    => $list_id,
					'meta_key'   => 'sp_player',
					'meta_value' => (string) $duplicate_id,
				),
				array( '%s' ),
				array( '%d', '%s', '%s' )
			);

			// Update serialized sp_players meta if present.
			$players_data = get_post_meta( $list_id, 'sp_players', true );
			if ( is_array( $players_data ) ) {
				$updated = $this->replace_player_id_in_structure( $players_data, $primary_id, $duplicate_id, 'sp_list' );
				if ( $updated !== $players_data ) {
					update_post_meta( $list_id, 'sp_players', $updated );
				}
			}

			clean_post_cache( $list_id );
		}
	}

	/**
	 * Clear SportsPress caches after merge so stats recalculate.
	 *
	 * @param int   $primary_id    Primary player ID.
	 * @param int[] $duplicate_ids Duplicate player IDs.
	 */
	private function clear_sportspress_caches( int $primary_id, array $duplicate_ids ): void {
		clean_post_cache( $primary_id );

		if ( function_exists( 'sp_delete_player_data' ) ) {
			sp_delete_player_data( $primary_id );
		}

		delete_transient( 'sp_player_data_' . $primary_id );

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
