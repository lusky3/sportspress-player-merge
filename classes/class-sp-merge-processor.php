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
	 * Meta keys owned by WordPress that must never be copied between players.
	 *
	 * `_thumbnail_id` is deliberately part of this list: the featured image is
	 * handled by merge_featured_image(), which only copies it when the primary
	 * has none. Copying it here as well would leave a second `_thumbnail_id`
	 * row on primaries that already have an image.
	 *
	 * @var string[]
	 */
	private const INTERNAL_META_KEYS = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_desired_post_slug',
		'_thumbnail_id',
		'_pingme',
		'_encloseme',
	);

	/**
	 * Prefixes of WordPress-internal meta keys that must never be copied.
	 *
	 * @var string[]
	 */
	private const INTERNAL_META_KEY_PREFIXES = array(
		'_wp_trash_meta_',
		'_oembed_',
	);

	/**
	 * Serialized-array meta keys merged cell-by-cell by merge_array_field().
	 *
	 * `sp_assignments` is deliberately absent: SportsPress stores it as multiple
	 * rows of plain "league_season_team" strings, so it belongs on the multi-row
	 * union path in merge_meta_data().
	 *
	 * Public because the preview replays the same merge to show what it will
	 * resolve, and a preview that disagreed with the merge would be worse than none.
	 *
	 * @var string[]
	 */
	public const ARRAY_MERGE_FIELDS = array( 'sp_statistics', 'sp_leagues', 'sp_metrics' );

	/**
	 * Array meta keys whose blank cell is stored as a non-positive integer.
	 *
	 * `sp_leagues` is `[league_id][season_id] => team_id` and SportsPress saves it
	 * through `sp_array_value( $_POST, 'sp_leagues', array(), 'int' )`, so every
	 * cell is an integer. Both widgets that write it post a non-positive value for
	 * "nothing selected": the team dropdown's "None" option carries
	 * sp_dropdown_pages()'s `option_none_value` of -1, and the season checkbox is
	 * shadowed by a hidden -1 that survives when the box is unticked. SportsPress
	 * reads it the same way — the player-assignments module skips every cell
	 * matching `0 >= $team_id` when it builds sp_assignments.
	 *
	 * The other array fields are saved with the 'text' sanitiser, so their cells
	 * are strings and only '' is blank: a '0' in `sp_statistics` or `sp_metrics`
	 * is a hand-entered zero and is real data.
	 *
	 * @var string[]
	 */
	private const NON_POSITIVE_BLANK_FIELDS = array( 'sp_leagues' );

	/**
	 * Post IDs whose meta was written during the current merge attempt.
	 *
	 * Tracked so the failure path can purge them from the object cache: ROLLBACK
	 * restores the database rows but not the values update_post_meta() already
	 * wrote into a persistent cache.
	 *
	 * @var int[]
	 */
	private array $touched_post_ids = array();

	/**
	 * Cell-level decisions taken while deep-merging serialized array fields.
	 *
	 * Every entry records something the operator cannot see anywhere else: either a
	 * value the duplicate contributed because the primary's cell was blank, or a
	 * value the duplicate lost because the primary's cell already held something
	 * different. Hand-entered career statistics have no other source, so a silent
	 * decision here is unrecoverable.
	 *
	 * @var array<int, array{meta_key: string, duplicate_id: int, path: array, action: string, kept: mixed, discarded: mixed}>
	 */
	private array $merge_resolutions = array();

	/**
	 * Execute a full merge operation with transaction safety and locking.
	 *
	 * The backup is written as `pending` and is only promoted to `active` once the
	 * merge has committed. A failed merge marks it `failed` — which load_backup_data()
	 * still accepts — so the operator keeps a usable recovery point.
	 *
	 * The selection is re-validated here, under the lock, even though every caller
	 * validated it before calling: the caller's validation ran outside the lock,
	 * so a concurrent operation holding the lock (another batch row, an admin
	 * merge in a browser) can delete a duplicate in between. Without this the
	 * delete loop's `if ( ! $post ) { continue; }` would silently no-op and the
	 * merge would report success having done nothing.
	 *
	 * @param int   $primary_id    The player ID to keep.
	 * @param int[] $duplicate_ids Player IDs to merge and delete.
	 * @return array{success: bool, backup_id?: string, message?: string, resolutions?: array}
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

		// Re-check the selection now that nothing else can be merging: a handful
		// of get_post() calls, most still warm in the object cache from the
		// caller's own validation moments ago. Released explicitly rather than in
		// the finally below, which only covers the try block that follows.
		$revalidated = SP_Merge_Validation::validate_merge_selection( $primary_id, $duplicate_ids );
		if ( ! $revalidated['valid'] ) {
			$this->release_lock();

			return array(
				'success' => false,
				'message' => $revalidated['error'],
			);
		}

		$backup                  = new SP_Merge_Backup();
		$backup_id               = null;
		$this->touched_post_ids  = array();
		$this->merge_resolutions = array();

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

			/*
			 * Only a committed merge promotes the backup out of `pending`. A
			 * false return here (a race, or the schema upgrade that adds
			 * post_hashes having silently failed) means post_hashes is never
			 * recorded — every future revert of THIS backup will then refuse
			 * with values_changed, since there is nothing to compare against.
			 * The merge itself already committed, so this must not fail loudly;
			 * it must not fail silently either.
			 */
			if ( method_exists( $backup, 'mark_active' ) && ! $backup->mark_active( $backup_id ) ) {
				error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					sprintf(
						'SP Merge: mark_active() failed for backup %s. Revert will require --force until this is investigated.',
						$backup_id
					)
				);
			}

			$this->clear_sportspress_caches( $primary_id, $duplicate_ids );

			$this->log_merge_resolutions( $primary_id );

			return array(
				'success'     => true,
				'backup_id'   => $backup_id,
				'resolutions' => $this->merge_resolutions,
			);

		} catch ( Throwable $e ) {
			// Throwable, not Exception: an Error raised inside the transaction — a
			// TypeError or ValueError from malformed legacy data, or a fatal from
			// third-party code hooked into the meta, term or delete calls — is not
			// an Exception in PHP 8, and letting it escape would skip the ROLLBACK.
			$wpdb->query( 'ROLLBACK' );

			// ROLLBACK restores the rows but not the object cache: update_post_meta()
			// has already written the merged values into Redis/Memcached, where they
			// would keep being served and could be re-persisted by the next save.
			$this->purge_merge_caches( $primary_id, $duplicate_ids );

			// Never delete the backup here — a failed merge is precisely when it is
			// needed. mark_failed() keeps the row loadable by load_backup_data();
			// if the backup class predates that method the row is left untouched,
			// which is still recoverable.
			if ( $backup_id && method_exists( $backup, 'mark_failed' ) ) {
				$backup->mark_failed( $backup_id );
			}

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log(
					sprintf(
						'SP Merge error: %s (backup %s retained)',
						$e->getMessage(),
						$backup_id ? $backup_id : 'none'
					)
				);
			}

			if ( $backup_id ) {
				return array(
					'success'   => false,
					'backup_id' => $backup_id,
					'message'   => sprintf(
						/* translators: %s: retained backup ID */
						__( 'Merge failed and was rolled back. Backup %s has been retained — use it to verify or restore the affected records before retrying.', 'sportspress-player-merge' ),
						$backup_id
					),
				);
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
	 * Acquire the atomic merge lock.
	 *
	 * The implementation moved to SP_Merge_Lock so SP_Merge_Backup::revert() can
	 * take the same lock — an unattended `wp sp-merge batch` and a revert typed
	 * into a second shell are otherwise free to rewrite the same rows at once.
	 * These two thin wrappers stay so the call sites below read unchanged.
	 *
	 * @return bool True if lock acquired.
	 */
	private function acquire_lock(): bool {
		return SP_Merge_Lock::acquire();
	}

	/**
	 * Release the merge lock.
	 */
	private function release_lock(): void {
		SP_Merge_Lock::release();
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
		$this->merge_featured_image( $primary_id, $duplicate_id );
		$this->update_event_references( $primary_id, $duplicate_id );
		$this->update_player_list_references( $primary_id, $duplicate_id );
	}

	/**
	 * Copy featured image from duplicate to primary if primary has none.
	 *
	 * @param int $primary_id   Primary player ID.
	 * @param int $duplicate_id Duplicate player ID.
	 */
	private function merge_featured_image( int $primary_id, int $duplicate_id ): void {
		if ( ! has_post_thumbnail( $primary_id ) && has_post_thumbnail( $duplicate_id ) ) {
			set_post_thumbnail( $primary_id, get_post_thumbnail_id( $duplicate_id ) );
		}
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
	 * Every key on the duplicate is considered except WordPress internals — third
	 * party fields such as `spt_email`, `spt_skill` and `spt_captain` carry real
	 * data and were previously discarded by an `sp_`-only allow-list.
	 *
	 * @param int $primary_id   Primary player ID.
	 * @param int $duplicate_id Duplicate player ID.
	 * @throws Exception On failure.
	 */
	private function merge_meta_data( int $primary_id, int $duplicate_id ): void {
		$duplicate_meta = get_post_meta( $duplicate_id );

		$skip_fields = array( 'sp_columns', 'sp_number' );

		// Serialized-array fields only; see the constant for why sp_assignments is not one.
		$array_merge_fields = self::ARRAY_MERGE_FIELDS;

		foreach ( $duplicate_meta as $key => $values ) {
			if ( $this->is_internal_meta_key( $key ) ) {
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

			/*
			 * Non-SportsPress fields are single-valued by convention, so they only
			 * fill a gap on the primary. Unioning them would leave the primary with
			 * two competing values (two `spt_email` rows, for example).
			 */
			if ( 0 !== strpos( $key, 'sp_' ) && ! empty( $existing ) ) {
				continue;
			}

			foreach ( $values as $value ) {
				if ( ! in_array( $value, $existing, true ) ) {
					/*
					 * get_post_meta() returns values already unslashed, and
					 * add_post_meta()/update_post_meta() unslash again on the
					 * way in — a bare round-trip strips one level of
					 * backslashes from anything that legitimately has them
					 * (a Windows path, escaped JSON, regex in a custom
					 * field). wp_slash() restores the level WordPress is
					 * about to remove.
					 */
					add_post_meta( $primary_id, $key, wp_slash( $value ) );
				}
			}
		}

		$this->deduplicate_multi_value_meta( $primary_id );
	}

	/**
	 * Check whether a meta key belongs to WordPress itself and must not be copied.
	 *
	 * @param string $key Meta key.
	 * @return bool True when the key is a WordPress internal.
	 */
	private function is_internal_meta_key( string $key ): bool {
		if ( in_array( $key, self::INTERNAL_META_KEYS, true ) ) {
			return true;
		}

		foreach ( self::INTERNAL_META_KEY_PREFIXES as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}

		return false;
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
			update_post_meta( $primary_id, $key, wp_slash( $duplicate_value ) );
			return;
		}

		$merged = $this->deep_merge_arrays(
			$primary_value,
			$duplicate_value,
			array(
				'meta_key'     => $key,
				'duplicate_id' => $duplicate_id,
			)
		);
		update_post_meta( $primary_id, $key, wp_slash( $merged ) );
	}

	/**
	 * Deep merge two arrays. Primary values take precedence for scalar values,
	 * but only where the primary actually holds one.
	 * Numerically-indexed arrays are appended (array_merge) to preserve all entries.
	 * Associative arrays are recursed.
	 *
	 * The merge context travels with the recursion because emptiness is
	 * field-specific: a 0 is a blank in `sp_leagues` and real data in
	 * `sp_statistics`, and the recursion is the only place that knows which cell
	 * it is looking at.
	 *
	 * @param array $primary   Primary array.
	 * @param array $duplicate Duplicate array.
	 * @param array $context   Merge context: `meta_key` and `duplicate_id`.
	 * @param array $path      Keys walked so far, used to identify a resolved cell.
	 * @return array Merged array.
	 */
	private function deep_merge_arrays( array $primary, array $duplicate, array $context, array $path = array() ): array {
		foreach ( $duplicate as $key => $value ) {
			$key_path = array_merge( $path, array( $key ) );

			if ( ! isset( $primary[ $key ] ) ) {
				$primary[ $key ] = $value;
				continue;
			}

			if ( is_array( $value ) && is_array( $primary[ $key ] ) ) {
				// Numerically-indexed arrays (e.g., timeline minutes): append all values.
				if ( $this->is_numeric_indexed( $value ) && $this->is_numeric_indexed( $primary[ $key ] ) ) {
					$primary[ $key ] = array_values( array_unique( array_merge( $primary[ $key ], $value ) ) );
				} else {
					$primary[ $key ] = $this->deep_merge_arrays( $primary[ $key ], $value, $context, $key_path );
				}
				continue;
			}

			/*
			 * Scalar cell. SportsPress posts a value for every rendered cell, blanks
			 * included, so the primary nearly always has an entry and isset() alone
			 * let a blank primary cell silently beat a real value on the duplicate.
			 * The primary therefore only wins when its cell actually holds something.
			 */
			if ( $this->is_blank_cell( $context['meta_key'], $primary[ $key ] ) ) {
				if ( $this->is_blank_cell( $context['meta_key'], $value ) ) {
					continue;
				}

				$this->record_resolution( $context, $key_path, 'filled', $value, $primary[ $key ] );
				$primary[ $key ] = $value;
				continue;
			}

			if ( $this->is_blank_cell( $context['meta_key'], $value ) || $this->values_match( $primary[ $key ], $value ) ) {
				continue;
			}

			// Both cells hold a value and they differ: a real conflict, primary wins.
			$this->record_resolution( $context, $key_path, 'conflict', $primary[ $key ], $value );
		}

		return $primary;
	}

	/**
	 * Whether a scalar cell counts as blank for the field it belongs to.
	 *
	 * A single rule cannot serve every field: `0` means "no team selected" in
	 * `sp_leagues` and "the operator typed a zero" in `sp_statistics`. See
	 * self::NON_POSITIVE_BLANK_FIELDS for where each convention comes from.
	 *
	 * @param string $meta_key Meta key being merged.
	 * @param mixed  $value    Cell value.
	 * @return bool True when the cell holds nothing.
	 */
	private function is_blank_cell( string $meta_key, $value ): bool {
		if ( null === $value || '' === $value ) {
			return true;
		}

		if ( is_array( $value ) ) {
			return array() === $value;
		}

		if ( in_array( $meta_key, self::NON_POSITIVE_BLANK_FIELDS, true ) ) {
			return is_numeric( $value ) && (int) $value <= 0;
		}

		return false;
	}

	/**
	 * Whether two cells hold the same value.
	 *
	 * Compared as strings when both are scalar: `sp_leagues` is stored as integers
	 * and `sp_statistics` as strings, and a legacy row that mixes 1 with '1' is the
	 * same team, not a conflict worth reporting.
	 *
	 * @param mixed $primary   Primary cell.
	 * @param mixed $duplicate Duplicate cell.
	 * @return bool True when the two are equivalent.
	 */
	private function values_match( $primary, $duplicate ): bool {
		if ( is_scalar( $primary ) && is_scalar( $duplicate ) ) {
			return (string) $primary === (string) $duplicate;
		}

		return $primary === $duplicate;
	}

	/**
	 * Record a cell-level decision for the caller and the log.
	 *
	 * @param array  $context   Merge context: `meta_key` and `duplicate_id`.
	 * @param array  $path      Keys identifying the cell.
	 * @param string $action    Either `filled` (duplicate's value used) or `conflict` (duplicate's value discarded).
	 * @param mixed  $kept      Value that survives the merge.
	 * @param mixed  $discarded Value that does not.
	 */
	private function record_resolution( array $context, array $path, string $action, $kept, $discarded ): void {
		$this->merge_resolutions[] = array(
			'meta_key'     => (string) $context['meta_key'],
			'duplicate_id' => (int) ( $context['duplicate_id'] ?? 0 ),
			'path'         => $path,
			'action'       => $action,
			'kept'         => $kept,
			'discarded'    => $discarded,
		);
	}

	/**
	 * Cell-level decisions taken by the last merge run on this instance.
	 *
	 * @return array<int, array{meta_key: string, duplicate_id: int, path: array, action: string, kept: mixed, discarded: mixed}>
	 */
	public function get_merge_resolutions(): array {
		return $this->merge_resolutions;
	}

	/**
	 * Dry-run the deep merge of one array field and report what it would resolve.
	 *
	 * Runs the real merge code against copies so the preview can never disagree
	 * with the merge, and writes nothing: deep_merge_arrays() only reads. The
	 * merged array comes back so a caller previewing several duplicates can feed
	 * each result into the next call, exactly as execute_merge() does.
	 *
	 * @param string $meta_key        Meta key being merged.
	 * @param array  $primary_value   Primary player's stored array.
	 * @param array  $duplicate_value Duplicate player's stored array.
	 * @param int    $duplicate_id    Duplicate player ID, for labelling.
	 * @return array{merged: array, resolutions: array}
	 */
	public function preview_array_field_merge( string $meta_key, array $primary_value, array $duplicate_value, int $duplicate_id = 0 ): array {
		$saved                   = $this->merge_resolutions;
		$this->merge_resolutions = array();

		$merged = $this->deep_merge_arrays(
			$primary_value,
			$duplicate_value,
			array(
				'meta_key'     => $meta_key,
				'duplicate_id' => $duplicate_id,
			)
		);

		$resolutions             = $this->merge_resolutions;
		$this->merge_resolutions = $saved;

		return array(
			'merged'      => $merged,
			'resolutions' => $resolutions,
		);
	}

	/**
	 * Render a resolved cell as `sp_statistics[12][34][goals]`.
	 *
	 * @param array $resolution One entry from get_merge_resolutions().
	 * @return string Cell address.
	 */
	public static function format_resolution_path( array $resolution ): string {
		$path = '';

		foreach ( (array) $resolution['path'] as $segment ) {
			$path .= '[' . ( is_scalar( $segment ) ? (string) $segment : '?' ) . ']';
		}

		return $resolution['meta_key'] . $path;
	}

	/**
	 * Render a resolved cell's value for a log line or the preview.
	 *
	 * @param mixed $value Cell value.
	 * @return string Printable value.
	 */
	public static function format_resolution_value( $value ): string {
		if ( null === $value ) {
			return '';
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return '(' . gettype( $value ) . ')';
	}

	/**
	 * Log every cell-level decision so an operator can audit a completed merge.
	 *
	 * @param int $primary_id Primary player ID.
	 */
	private function log_merge_resolutions( int $primary_id ): void {
		if ( empty( $this->merge_resolutions ) ) {
			return;
		}

		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		foreach ( $this->merge_resolutions as $resolution ) {
			error_log(
				sprintf(
					'SP Merge %s: player %d, duplicate %d, %s kept "%s", discarded "%s"',
					'conflict' === $resolution['action'] ? 'conflict resolved' : 'gap filled',
					$primary_id,
					$resolution['duplicate_id'],
					self::format_resolution_path( $resolution ),
					self::format_resolution_value( $resolution['kept'] ),
					self::format_resolution_value( $resolution['discarded'] )
				)
			);
		}
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
	 * Check if an array is numerically indexed AND holds only scalar values —
	 * the flat [index => player_id] shape sp_list's sp_players meta uses, as
	 * opposed to event meta's [team => [player => [stat => value]]] nesting.
	 *
	 * @param array $arr Array to check.
	 * @return bool
	 */
	private function is_flat_scalar_list( array $arr ): bool {
		if ( ! $this->is_numeric_indexed( $arr ) ) {
			return false;
		}

		foreach ( $arr as $value ) {
			if ( is_array( $value ) ) {
				return false;
			}
		}

		return true;
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
						add_post_meta( $player_id, $field, wp_slash( $value ) );
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

		// Pre-collect event IDs BEFORE simple meta update changes the player ID.
		$event_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = 'sp_player' AND meta_value = %s",
				(string) $duplicate_id
			)
		);

		// Simple meta: exact-match update.
		foreach ( self::SIMPLE_PLAYER_META_KEYS as $meta_key ) {
			$updated_rows = $wpdb->update(
				$wpdb->postmeta,
				array( 'meta_value' => (string) $primary_id ),
				array(
					'meta_key'   => $meta_key,
					'meta_value' => (string) $duplicate_id,
				),
				array( '%s' ),
				array( '%s', '%s' )
			);

			if ( false === $updated_rows ) {
				throw new Exception(
					sprintf(
						/* translators: %s: meta key */
						__( 'Failed to update %s references from the merged player.', 'sportspress-player-merge' ),
						$meta_key
					)
				);
			}

			/*
			 * A post that already carried both players under this key — the
			 * same-event collision the preview warns about — now has two
			 * identical rows after the rewrite above. Collapse them, keeping
			 * the lowest meta_id: the row the backup captured first, so
			 * revert's meta_id-scoped restore still finds it.
			 */
			$this->deduplicate_simple_meta_row( $meta_key, $primary_id );
		}

		// Serialized meta: structure-aware replacement using pre-collected event IDs.
		$this->update_serialized_event_meta( $primary_id, $duplicate_id, $event_ids );
	}

	/**
	 * Collapse duplicate (post_id, meta_key, meta_value) rows left behind when
	 * a simple-meta rewrite collides with a row the primary already had.
	 *
	 * @param string   $meta_key   Meta key just rewritten.
	 * @param int      $primary_id Surviving value.
	 * @param int|null $post_id    Restrict to one post, or null for every post
	 *                             the update above could have touched.
	 */
	private function deduplicate_simple_meta_row( string $meta_key, int $primary_id, ?int $post_id = null ): void {
		global $wpdb;

		$sql = "DELETE p1 FROM {$wpdb->postmeta} p1
			INNER JOIN {$wpdb->postmeta} p2
				ON p1.post_id = p2.post_id
				AND p1.meta_key = p2.meta_key
				AND p1.meta_value = p2.meta_value
				AND p1.meta_id > p2.meta_id
			WHERE " . ( null !== $post_id ? 'p1.post_id = %d AND ' : '' ) . 'p1.meta_key = %s AND p1.meta_value = %s';

		$args = null !== $post_id ? array( $post_id, $meta_key, (string) $primary_id ) : array( $meta_key, (string) $primary_id );

		$wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Update serialized event meta that contains player IDs as array keys.
	 * Uses additive merging for same-event collisions (sums numeric stats).
	 *
	 * @param int   $primary_id   Primary player ID.
	 * @param int   $duplicate_id Duplicate player ID.
	 * @param int[] $event_ids    Event IDs collected before the simple meta update.
	 */
	private function update_serialized_event_meta( int $primary_id, int $duplicate_id, array $event_ids = array() ): void {
		if ( empty( $event_ids ) ) {
			return;
		}

		// Pre-warm meta cache for all affected events.
		update_postmeta_cache( array_map( 'intval', $event_ids ) );

		foreach ( $event_ids as $event_id ) {
			$event_id                 = (int) $event_id;
			$this->touched_post_ids[] = $event_id;

			foreach ( self::SERIALIZED_PLAYER_META_KEYS as $meta_key ) {
				$raw = get_post_meta( $event_id, $meta_key, true );
				if ( empty( $raw ) || ! is_array( $raw ) ) {
					continue;
				}

				$updated = $this->replace_player_id_in_structure( $raw, $primary_id, $duplicate_id, $meta_key );
				if ( $updated !== $raw ) {
					update_post_meta( $event_id, $meta_key, wp_slash( $updated ) );
				}
			}
		}
	}

	/**
	 * Recursively replace a player ID in a nested structure.
	 * For sp_players: sums numeric performance values on collision.
	 * For sp_timeline: appends minute arrays on collision.
	 *
	 * @param array  $data           The data structure.
	 * @param int    $primary_id     Primary player ID.
	 * @param int    $duplicate_id   Duplicate player ID.
	 * @param string $meta_key       The meta key context for merge strategy.
	 * @param bool   $replace_values Also rewrite leaf scalar values that equal
	 *                               $duplicate_id, not just array keys. Only
	 *                               correct where player IDs can appear as
	 *                               values — sp_list's flat sp_players array.
	 *                               Event stat structures (sp_players,
	 *                               sp_timeline) key player IDs; a stat value
	 *                               that happens to equal a player ID must not
	 *                               be rewritten, so this stays false there.
	 * @return array Modified structure.
	 */
	private function replace_player_id_in_structure( array $data, int $primary_id, int $duplicate_id, string $meta_key = '', bool $replace_values = false ): array {
		$result = array();

		foreach ( $data as $key => $value ) {
			$new_key = ( (int) $key === $duplicate_id ) ? $primary_id : $key;

			if ( is_array( $value ) ) {
				$new_value = $this->replace_player_id_in_structure( $value, $primary_id, $duplicate_id, $meta_key, $replace_values );
			} elseif ( $replace_values ) {
				$new_value = ( (int) $value === $duplicate_id ) ? (string) $primary_id : $value;
			} else {
				$new_value = $value;
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
			$list_id                  = (int) $list_id;
			$this->touched_post_ids[] = $list_id;

			// Update simple sp_player meta rows.
			$updated_rows = $wpdb->update(
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

			if ( false === $updated_rows ) {
				throw new Exception(
					sprintf(
						/* translators: %d: list post ID */
						__( 'Failed to update sp_player references on list %d.', 'sportspress-player-merge' ),
						$list_id
					)
				);
			}

			// A list that already carried both players under sp_player now has
			// two identical rows — collapse to the one the backup captured first.
			$this->deduplicate_simple_meta_row( 'sp_player', $primary_id, $list_id );

			// Update serialized sp_players meta if present.
			$players_data = get_post_meta( $list_id, 'sp_players', true );
			if ( is_array( $players_data ) ) {
				$updated = $this->replace_player_id_in_structure( $players_data, $primary_id, $duplicate_id, 'sp_list', true );

				/*
				 * sp_list's sp_players is a flat [index => player_id] list, not
				 * a keyed structure — a player ID appearing twice is a
				 * duplicate VALUE, not a key collision merge_collision() can
				 * see. If the list already held both players, renaming the
				 * duplicate's entry above just gave the primary's ID two
				 * indices.
				 */
				if ( $this->is_flat_scalar_list( $updated ) ) {
					$updated = array_values( array_unique( $updated, SORT_STRING ) );
				}

				if ( $updated !== $players_data ) {
					update_post_meta( $list_id, 'sp_players', wp_slash( $updated ) );
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

	/**
	 * Purge object-cache entries written during a merge that was rolled back.
	 *
	 * Covers the primary, every duplicate, and every event or list post whose meta
	 * was rewritten before the failure. Without this a persistent object cache keeps
	 * serving the merged values that no longer exist in the database.
	 *
	 * @param int   $primary_id    Primary player ID.
	 * @param int[] $duplicate_ids Duplicate player IDs.
	 */
	private function purge_merge_caches( int $primary_id, array $duplicate_ids ): void {
		$post_ids = array_merge(
			array( $primary_id ),
			array_map( 'intval', $duplicate_ids ),
			$this->touched_post_ids
		);

		foreach ( array_unique( $post_ids ) as $post_id ) {
			$post_id = (int) $post_id;

			// clean_post_cache() bails when the post row cannot be read, so the meta
			// cache is dropped explicitly first.
			wp_cache_delete( $post_id, 'post_meta' );
			clean_post_cache( $post_id );

			delete_transient( 'sp_player_data_' . $post_id );
			delete_transient( 'sp_event_data_' . $post_id );
		}

		$this->touched_post_ids = array();
	}
}
