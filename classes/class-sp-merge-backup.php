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
	 * Backup table schema version. Bump whenever columns change.
	 *
	 * @var string
	 */
	private const DB_VERSION = '2';

	/**
	 * Option name holding the installed backup table schema version.
	 *
	 * @var string
	 */
	private const DB_VERSION_OPTION = 'sp_merge_backup_db_version';

	/**
	 * Statuses whose backup data may still be loaded and reverted.
	 *
	 * A merge that threw is stored as `failed` and stays revertible — it is the
	 * one case where the backup matters most. Only `reverted` is refused.
	 *
	 * @var string[]
	 */
	private const LOADABLE_STATUSES = array( 'pending', 'active', 'failed' );

	/**
	 * Meta keys that revert neither deletes nor restores.
	 *
	 * These are WordPress-internal bookkeeping rows that describe the current
	 * state of the site rather than the player, and removing them would break
	 * editor locks and post-merge permalink redirects. Everything else the
	 * backup captured — including `_thumbnail_id` and third-party keys such as
	 * `spt_email` — is restored verbatim.
	 *
	 * @var string[]
	 */
	private const META_RESTORE_DENY_LIST = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_desired_post_slug',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
	);

	/**
	 * Maximum number of items named in an operator-facing error message.
	 *
	 * @var int
	 */
	private const MAX_REPORTED_ITEMS = 20;

	/**
	 * Create a merge backup before executing.
	 *
	 * The row is written with status `pending`. The caller must promote it with
	 * mark_active() after COMMIT, or flag it with mark_failed() if the merge
	 * throws.
	 *
	 * @param int   $primary_id    Primary player ID.
	 * @param int[] $duplicate_ids Duplicate player IDs.
	 * @return string Backup ID.
	 * @throws Exception On failure.
	 */
	public function create_merge_backup( int $primary_id, array $duplicate_ids ): string {
		$this->maybe_upgrade_schema();

		$backup_id   = 'merge_' . time() . '_' . wp_generate_password( 8, false );
		$backup_data = $this->prepare_backup_data( $primary_id, $duplicate_ids );

		if ( empty( $backup_data ) ) {
			throw new Exception( __( 'Failed to prepare backup data', 'sportspress-player-merge' ) );
		}

		// Hash every captured value while it is still the pre-merge value.
		$backup_data['value_hashes'] = $this->compute_value_hashes( $backup_data );

		$this->save_backup( $backup_id, $backup_data, $this->collect_touched_posts( $backup_data ) );
		$this->cleanup_old_backups();

		return $backup_id;
	}

	/**
	 * Create a merge backup. Alias of create_merge_backup().
	 *
	 * @param int   $primary_id    Primary player ID.
	 * @param int[] $duplicate_ids Duplicate player IDs.
	 * @return string Backup ID.
	 * @throws Exception On failure.
	 */
	public function create_backup( int $primary_id, array $duplicate_ids ): string {
		return $this->create_merge_backup( $primary_id, $duplicate_ids );
	}

	/**
	 * Promote a pending backup to active after a successful COMMIT.
	 *
	 * Records the post-merge hash of every captured value. That is the
	 * expectation revert compares against: a value that still matches was only
	 * ever changed by the merge, and undoing it is safe.
	 *
	 * @param string $backup_id Backup ID.
	 * @return bool True if the backup was promoted.
	 */
	public function mark_active( string $backup_id ): bool {
		global $wpdb;

		$this->maybe_upgrade_schema();

		$row = $this->load_backup_row( $backup_id );
		if ( ! $row ) {
			return false;
		}

		$backup_data = json_decode( (string) $row->backup_data, true );
		$hashes      = is_array( $backup_data ) ? $this->compute_value_hashes( $backup_data ) : array();

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}sp_merge_backups
				SET status = 'active', post_hashes = %s
				WHERE backup_id = %s AND user_id = %d AND COALESCE(status, 'active') = 'pending'",
				(string) wp_json_encode( $hashes ),
				$backup_id,
				get_current_user_id()
			)
		);

		return is_int( $result ) && $result > 0;
	}

	/**
	 * Flag a backup as failed so it survives and stays revertible.
	 *
	 * Called when the merge throws. ROLLBACK is assumed but never trusted: the
	 * tables may not be InnoDB and $wpdb can reconnect mid-transaction.
	 *
	 * @param string $backup_id Backup ID.
	 * @return bool True if the backup was flagged.
	 */
	public function mark_failed( string $backup_id ): bool {
		global $wpdb;

		$this->maybe_upgrade_schema();

		$status = $this->get_backup_status( $backup_id );
		if ( null === $status || 'reverted' === $status ) {
			return false;
		}

		$result = $wpdb->update(
			$wpdb->prefix . 'sp_merge_backups',
			array( 'status' => 'failed' ),
			array(
				'backup_id' => $backup_id,
				'user_id'   => get_current_user_id(),
			),
			array( '%s' ),
			array( '%s', '%d' )
		);

		return false !== $result;
	}

	/**
	 * Revert a merge from backup.
	 *
	 * @param string   $backup_id     Backup ID.
	 * @param bool     $force         Proceed even when captured values changed after the merge.
	 * @param int|null $owner_user_id Backup owner to revert on behalf of, for a caller (such as
	 *                                WP-CLI, gated on delete_sp_players) acting on a backup it did
	 *                                not create itself. Null keeps the prior behavior of scoping
	 *                                to the current actor.
	 * @return array{success: bool, code?: string, message?: string} On refusal, code is one of
	 *               not_found, locked, conflict, values_changed (the only forcible one) or error.
	 */
	public function revert( string $backup_id, bool $force = false, ?int $owner_user_id = null ): array {
		global $wpdb;

		$this->maybe_upgrade_schema();

		// The same lock SP_Merge_Processor::execute_merge() takes, held across
		// both guards and the restore itself. Without it a long unattended
		// `wp sp-merge batch` and a revert typed into a second shell can interleave,
		// and a merge whose backup captured half-restored event meta is not a
		// recovery point at all. Never forcible: --force overrides a human's later
		// edits, not another process writing the same rows right now.
		if ( ! SP_Merge_Lock::acquire() ) {
			return array(
				'success' => false,
				'code'    => 'locked',
				'message' => __( 'A merge or revert is already in progress. Please wait and try again.', 'sportspress-player-merge' ),
			);
		}

		try {
			$row         = $this->load_backup_row( $backup_id, $owner_user_id );
			$backup_data = $row ? json_decode( (string) $row->backup_data, true ) : null;

			if ( ! $row || ! is_array( $backup_data ) || empty( $backup_data['primary_id'] ) ) {
				return array(
					'success' => false,
					'code'    => 'not_found',
					'message' => __( 'Backup data not found', 'sportspress-player-merge' ),
				);
			}

			// Guard 1: a later merge may have rewritten the same posts. Never
			// forcible — the later merges have to be unwound first.
			$conflicts = $this->find_conflicting_backups( $row );
			if ( ! empty( $conflicts ) ) {
				return array(
					'success' => false,
					'code'    => 'conflict',
					'message' => $this->format_conflict_message( $conflicts ),
				);
			}

			// Guard 2: somebody may have edited those posts since the merge ran.
			// This is the one refusal $force overrides, and the only one the UI
			// offers an override for.
			$changed = $this->find_values_changed_since_merge( $row, $backup_data );
			if ( ! empty( $changed ) && ! $force ) {
				return array(
					'success' => false,
					'code'    => 'values_changed',
					'message' => $this->format_changed_message( $changed ),
				);
			}

			try {
				$wpdb->query( 'START TRANSACTION' );

				$this->execute_revert( $backup_data );

				$wpdb->query( 'COMMIT' );

				$this->cleanup_after_revert( $backup_id, $owner_user_id );

				return array( 'success' => true );

			} catch ( Throwable $e ) {
				$wpdb->query( 'ROLLBACK' );

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'SP Merge revert error: ' . $e->getMessage() );
				}

				return array(
					'success' => false,
					'code'    => 'error',
					'message' => __( 'Revert failed. Please check the error log for details.', 'sportspress-player-merge' ),
				);
			}
		} finally {
			SP_Merge_Lock::release();
		}
	}

	/**
	 * Discard a backup after a failed merge.
	 *
	 * @deprecated Use mark_failed(). Retained so older callers cannot destroy the
	 *             one backup that matters; it now flags rather than deletes.
	 *
	 * @param string $backup_id Backup ID.
	 * @return bool
	 */
	public function delete_backup( string $backup_id ): bool {
		return $this->mark_failed( $backup_id );
	}

	/**
	 * Delete multiple backups.
	 *
	 * @param string[] $backup_ids    Backup IDs.
	 * @param int|null $owner_user_id Backup owner to delete on behalf of, for a caller (such as
	 *                                WP-CLI, gated on delete_sp_players) acting on backups it did
	 *                                not create itself. Null keeps the prior behavior of scoping
	 *                                to the current actor.
	 * @return int Number deleted.
	 */
	public function delete_backups( array $backup_ids, ?int $owner_user_id = null ): int {
		global $wpdb;

		$this->maybe_upgrade_schema();

		$table_name = $wpdb->prefix . 'sp_merge_backups';

		if ( empty( $backup_ids ) ) {
			return 0;
		}

		$user_id = $owner_user_id ?? get_current_user_id();

		$placeholders = implode( ',', array_fill( 0, count( $backup_ids ), '%s' ) );
		$query_args   = array_merge( $backup_ids, array( $user_id ) );
		$result       = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE backup_id IN ({$placeholders}) AND user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$query_args
			)
		);

		if ( false !== $result ) {
			$last_backup_id = get_user_meta( $user_id, 'sp_last_merge_backup', true );
			if ( in_array( $last_backup_id, $backup_ids, true ) ) {
				delete_user_meta( $user_id, 'sp_last_merge_backup' );
			}
			return $result;
		}

		return 0;
	}

	/**
	 * Add columns introduced after the activation-time CREATE TABLE.
	 *
	 * The table is only created by register_activation_hook, and the GitHub
	 * updater replaces files without re-activating, so the schema has to be
	 * brought forward from a normal page load.
	 */
	private function maybe_upgrade_schema(): void {
		global $wpdb;

		if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
			return;
		}

		$table_name = $wpdb->prefix . 'sp_merge_backups';

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( $table_name !== $table_exists ) {
			// Not installed yet; activation will create it at the current schema.
			return;
		}

		$columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$additions = array(
			'status'        => "ALTER TABLE {$table_name} ADD COLUMN status varchar(20) NOT NULL DEFAULT 'active'",
			'touched_posts' => "ALTER TABLE {$table_name} ADD COLUMN touched_posts longtext NULL DEFAULT NULL",
			'post_hashes'   => "ALTER TABLE {$table_name} ADD COLUMN post_hashes longtext NULL DEFAULT NULL",
		);

		foreach ( $additions as $column => $sql ) {
			if ( ! in_array( $column, $columns, true ) ) {
				$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
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
			'plugin_version'    => SP_MERGE_VERSION,
			'primary_id'        => $primary_id,
			'primary_name'      => get_the_title( $primary_id ),
			'duplicate_ids'     => $duplicate_ids,
			'duplicate_names'   => array(),
			'primary_backup'    => $this->backup_player_data( $primary_id ),
			'duplicate_backups' => array(),
			'affected_events'   => $this->backup_affected_event_meta( $duplicate_ids ),
			'affected_lists'    => $this->backup_affected_list_meta( $duplicate_ids ),
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
		$taxonomies    = get_object_taxonomies( 'sp_player' );
		$taxonomy_data = array();

		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $player_id, $taxonomy );
			if ( is_wp_error( $terms ) ) {
				continue;
			}

			// An explicit empty set is recorded so revert can clear terms the
			// merge added into a previously-empty taxonomy.
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
			$event_id   = (int) $event_id;
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
	 * Backup sp_list meta that will be modified during merge.
	 *
	 * @param int[] $duplicate_ids Duplicate player IDs.
	 * @return array Map of list_id => array of meta_key => original_value.
	 */
	private function backup_affected_list_meta( array $duplicate_ids ): array {
		global $wpdb;

		if ( empty( $duplicate_ids ) ) {
			return array();
		}

		$affected = array();

		foreach ( $duplicate_ids as $dup_id ) {
			$dup_str  = (string) $dup_id;
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

			foreach ( $list_ids as $list_id ) {
				$list_id = (int) $list_id;
				if ( isset( $affected[ $list_id ] ) ) {
					continue;
				}

				$list_data = array();

				// Backup sp_player simple meta rows.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta}
						WHERE post_id = %d AND meta_key = 'sp_player'",
						$list_id
					)
				);
				if ( ! empty( $rows ) ) {
					$list_data['_simple_sp_player'] = array();
					foreach ( $rows as $row ) {
						$list_data['_simple_sp_player'][] = array(
							'meta_id'    => (int) $row->meta_id,
							'meta_value' => $row->meta_value,
						);
					}
				}

				// Backup serialized sp_players.
				$sp_players = get_post_meta( $list_id, 'sp_players', true );
				if ( ! empty( $sp_players ) ) {
					$list_data['sp_players'] = $sp_players;
				}

				if ( ! empty( $list_data ) ) {
					$affected[ $list_id ] = $list_data;
				}
			}
		}

		return $affected;
	}

	/**
	 * Save backup to database.
	 *
	 * @param string $backup_id     Backup ID.
	 * @param array  $backup_data   Backup data.
	 * @param int[]  $touched_posts Post IDs this backup may rewrite on revert.
	 * @throws Exception On failure.
	 */
	private function save_backup( string $backup_id, array $backup_data, array $touched_posts ): void {
		global $wpdb;

		$json = wp_json_encode( $backup_data );
		if ( false === $json ) {
			throw new Exception( __( 'Failed to encode backup data', 'sportspress-player-merge' ) );
		}

		$result = $wpdb->insert(
			$wpdb->prefix . 'sp_merge_backups',
			array(
				'backup_id'     => $backup_id,
				'user_id'       => get_current_user_id(),
				'backup_data'   => $json,
				'created_at'    => current_time( 'mysql' ),
				'status'        => 'pending',
				'touched_posts' => (string) wp_json_encode( $touched_posts ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			throw new Exception( __( 'Failed to save backup to database', 'sportspress-player-merge' ) );
		}

		update_user_meta( get_current_user_id(), 'sp_last_merge_backup', $backup_id );
	}

	/**
	 * Load a backup row from the database, scoped to a single owner.
	 *
	 * Defaults to the current user. An explicit owner lets a caller holding
	 * delete_sp_players (mark_active()/mark_failed() never pass one — a backup
	 * is always promoted or flagged by whoever ran the merge) reach a backup
	 * that belongs to a different user, e.g. WP-CLI reverting on their behalf.
	 *
	 * @param string   $backup_id     Backup ID.
	 * @param int|null $owner_user_id Owner to scope the lookup to. Null uses the current user.
	 * @return object|null Row or null.
	 */
	private function load_backup_row( string $backup_id, ?int $owner_user_id = null ): ?object {
		global $wpdb;

		if ( ! preg_match( '/^merge_\d+_[a-zA-Z0-9]{8}$/', $backup_id ) ) {
			return null;
		}

		$placeholders = implode( ',', array_fill( 0, count( self::LOADABLE_STATUSES ), '%s' ) );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}sp_merge_backups
				WHERE backup_id = %s AND user_id = %d
				AND COALESCE(status, 'active') IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $backup_id, $owner_user_id ?? get_current_user_id() ), self::LOADABLE_STATUSES )
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Get the status of a backup owned by a single user.
	 *
	 * Defaults to the current user, mirroring load_backup_row() so the two stay
	 * consistent; mark_failed() (its only caller) never passes an explicit owner.
	 *
	 * @param string   $backup_id     Backup ID.
	 * @param int|null $owner_user_id Owner to scope the lookup to. Null uses the current user.
	 * @return string|null Status or null when no such backup exists.
	 */
	private function get_backup_status( string $backup_id, ?int $owner_user_id = null ): ?string {
		global $wpdb;

		if ( ! preg_match( '/^merge_\d+_[a-zA-Z0-9]{8}$/', $backup_id ) ) {
			return null;
		}

		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(status, 'active') FROM {$wpdb->prefix}sp_merge_backups
				WHERE backup_id = %s AND user_id = %d",
				$backup_id,
				$owner_user_id ?? get_current_user_id()
			)
		);

		return null === $status ? null : (string) $status;
	}

	/**
	 * Load backup data from database.
	 *
	 * @param string $backup_id Backup ID.
	 * @return array|null Backup data or null.
	 */
	private function load_backup_data( string $backup_id ): ?array {
		$row = $this->load_backup_row( $backup_id );

		if ( ! $row ) {
			return null;
		}

		$data = json_decode( (string) $row->backup_data, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Collect every post ID a revert of this backup would rewrite.
	 *
	 * @param array $backup_data Backup data.
	 * @return int[] Sorted, unique post IDs.
	 */
	private function collect_touched_posts( array $backup_data ): array {
		$ids = array( (int) ( $backup_data['primary_id'] ?? 0 ) );

		foreach ( (array) ( $backup_data['duplicate_ids'] ?? array() ) as $duplicate_id ) {
			$ids[] = (int) $duplicate_id;
		}

		foreach ( array( 'affected_events', 'affected_lists' ) as $section ) {
			foreach ( array_keys( (array) ( $backup_data[ $section ] ?? array() ) ) as $post_id ) {
				$ids[] = (int) $post_id;
			}
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );
		sort( $ids, SORT_NUMERIC );

		return $ids;
	}

	/**
	 * Resolve the touched-post set for a backup row.
	 *
	 * Rows written before the touched_posts column existed are back-filled from
	 * their own backup data, which describes exactly the same posts. Only a row
	 * whose data cannot be read at all is treated as unknown.
	 *
	 * @param int         $row_id       Row primary key.
	 * @param string|null $touched_json Stored touched_posts JSON, if any.
	 * @return int[]|null Post IDs, or null when they cannot be determined.
	 */
	private function resolve_touched_posts( int $row_id, ?string $touched_json ): ?array {
		global $wpdb;

		if ( ! empty( $touched_json ) ) {
			$ids = json_decode( $touched_json, true );
			if ( is_array( $ids ) ) {
				return array_map( 'intval', $ids );
			}
		}

		$json = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT backup_data FROM {$wpdb->prefix}sp_merge_backups WHERE id = %d",
				$row_id
			)
		);

		$data = null === $json ? null : json_decode( (string) $json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$ids = $this->collect_touched_posts( $data );

		$wpdb->update(
			$wpdb->prefix . 'sp_merge_backups',
			array( 'touched_posts' => (string) wp_json_encode( $ids ) ),
			array( 'id' => $row_id ),
			array( '%s' ),
			array( '%d' )
		);

		return $ids;
	}

	/**
	 * Find later backups that rewrote posts this backup also owns.
	 *
	 * Reverting out of order restores day-0 values over merges that ran later,
	 * silently rewinding them while their own backups still claim to be current.
	 * The scan is deliberately not scoped to the current user — a dependency is
	 * a property of the data, not of who created it.
	 *
	 * @param object $row Backup row being reverted.
	 * @return array[] Conflict descriptors; empty when the revert is safe.
	 */
	private function find_conflicting_backups( object $row ): array {
		global $wpdb;

		$own = $this->resolve_touched_posts( (int) $row->id, $row->touched_posts ?? null );

		if ( null === $own ) {
			return array(
				array(
					'backup_id'  => (string) $row->backup_id,
					'created_at' => (string) ( $row->created_at ?? '' ),
					'reason'     => 'unknown_self',
					'posts'      => array(),
				),
			);
		}

		$later = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, backup_id, created_at, touched_posts FROM {$wpdb->prefix}sp_merge_backups
				WHERE id > %d AND COALESCE(status, 'active') != 'reverted'
				ORDER BY id ASC",
				(int) $row->id
			)
		);

		$conflicts = array();

		foreach ( (array) $later as $candidate ) {
			$ids = $this->resolve_touched_posts( (int) $candidate->id, $candidate->touched_posts ?? null );

			if ( null === $ids ) {
				$conflicts[] = array(
					'backup_id'  => (string) $candidate->backup_id,
					'created_at' => (string) $candidate->created_at,
					'reason'     => 'unknown',
					'posts'      => array(),
				);
				continue;
			}

			$overlap = array_values( array_intersect( $own, $ids ) );
			if ( ! empty( $overlap ) ) {
				$conflicts[] = array(
					'backup_id'  => (string) $candidate->backup_id,
					'created_at' => (string) $candidate->created_at,
					'reason'     => 'overlap',
					'posts'      => $overlap,
				);
			}
		}

		return $conflicts;
	}

	/**
	 * Build the operator-facing message for an out-of-order revert.
	 *
	 * @param array[] $conflicts Conflict descriptors.
	 * @return string
	 */
	private function format_conflict_message( array $conflicts ): string {
		if ( 'unknown_self' === $conflicts[0]['reason'] ) {
			return __( 'Revert refused: this backup does not record which posts it modified, so newer merges cannot be checked for overlap. Restore from a database dump instead.', 'sportspress-player-merge' );
		}

		$labels = array();

		foreach ( array_slice( $conflicts, 0, self::MAX_REPORTED_ITEMS ) as $conflict ) {
			if ( 'unknown' === $conflict['reason'] ) {
				$labels[] = sprintf(
					/* translators: 1: backup ID, 2: creation date */
					__( '%1$s (%2$s, contents unreadable)', 'sportspress-player-merge' ),
					$conflict['backup_id'],
					$conflict['created_at']
				);
				continue;
			}

			$labels[] = sprintf(
				/* translators: 1: backup ID, 2: creation date, 3: comma-separated post IDs */
				__( '%1$s (%2$s, shared posts: %3$s)', 'sportspress-player-merge' ),
				$conflict['backup_id'],
				$conflict['created_at'],
				implode( ', ', array_slice( $conflict['posts'], 0, self::MAX_REPORTED_ITEMS ) )
			);
		}

		return sprintf(
			/* translators: 1: number of conflicting backups, 2: list of backups */
			__( 'Revert refused: %1$d later merge(s) modified the same posts and must be reverted first — %2$s', 'sportspress-player-merge' ),
			count( $conflicts ),
			implode( '; ', $labels )
		);
	}

	/**
	 * Find captured values that changed after the merge ran.
	 *
	 * A value is safe to restore when it still hashes to what the merge left
	 * behind (post_hashes, recorded at mark_active) or when it is already back
	 * at its pre-merge value (value_hashes, recorded at capture). Anything else
	 * was written by somebody after the merge and revert would destroy it.
	 *
	 * @param object $row         Backup row being reverted.
	 * @param array  $backup_data Decoded backup data.
	 * @return array[] Change descriptors; empty when the revert is safe.
	 */
	private function find_values_changed_since_merge( object $row, array $backup_data ): array {
		$expected = empty( $row->post_hashes ) ? null : json_decode( (string) $row->post_hashes, true );
		$expected = is_array( $expected ) ? $expected : null;

		$pre = isset( $backup_data['value_hashes'] ) && is_array( $backup_data['value_hashes'] )
			? $backup_data['value_hashes']
			: null;

		if ( null === $expected && null === $pre ) {
			return array(
				array(
					'post_id'  => (int) $backup_data['primary_id'],
					'meta_key' => '*',
					'reason'   => 'unverifiable',
				),
			);
		}

		$current = $this->compute_value_hashes( $backup_data );
		$changed = array();

		foreach ( array( 'events', 'lists' ) as $section ) {
			foreach ( (array) ( $current[ $section ] ?? array() ) as $post_id => $keys ) {
				foreach ( (array) $keys as $meta_key => $hash ) {
					$after  = $expected[ $section ][ $post_id ][ $meta_key ] ?? null;
					$before = $pre[ $section ][ $post_id ][ $meta_key ] ?? null;

					if ( ( null !== $after && $after === $hash ) || ( null !== $before && $before === $hash ) ) {
						continue;
					}

					$changed[] = array(
						'post_id'  => (int) $post_id,
						'meta_key' => (string) $meta_key,
						'reason'   => ( null === $after && null === $before ) ? 'unverifiable' : 'modified',
					);
				}
			}
		}

		return array_merge( $changed, $this->diff_primary_hashes( $current, $expected, $pre, (int) $backup_data['primary_id'] ) );
	}

	/**
	 * Compare the primary player's live meta against the recorded hashes.
	 *
	 * Revert deletes and rewrites the primary's meta wholesale, so keys added
	 * after the merge count as changes too.
	 *
	 * @param array      $current    Live hash map.
	 * @param array|null $expected   Post-merge hash map.
	 * @param array|null $pre        Pre-merge hash map.
	 * @param int        $primary_id Primary player ID.
	 * @return array[] Change descriptors.
	 */
	private function diff_primary_hashes( array $current, ?array $expected, ?array $pre, int $primary_id ): array {
		$live = (array) ( $current['primary'] ?? array() );

		$after  = isset( $expected['primary'] ) ? (array) $expected['primary'] : null;
		$before = isset( $pre['primary'] ) ? (array) $pre['primary'] : null;

		if ( null === $after && null === $before ) {
			return array(
				array(
					'post_id'  => $primary_id,
					'meta_key' => '*',
					'reason'   => 'unverifiable',
				),
			);
		}

		foreach ( array( $after, $before ) as $reference ) {
			if ( null !== $reference && empty( $this->diff_hash_map( $live, $reference ) ) ) {
				return array();
			}
		}

		$reference = null === $after ? $before : $after;
		$changed   = array();

		foreach ( $this->diff_hash_map( $live, (array) $reference ) as $meta_key ) {
			$changed[] = array(
				'post_id'  => $primary_id,
				'meta_key' => $meta_key,
				'reason'   => 'modified',
			);
		}

		return $changed;
	}

	/**
	 * Compare two meta_key => hash maps.
	 *
	 * @param array $current   Live map.
	 * @param array $reference Recorded map.
	 * @return string[] Keys that differ, were added, or were removed.
	 */
	private function diff_hash_map( array $current, array $reference ): array {
		$keys    = array_unique( array_merge( array_keys( $current ), array_keys( $reference ) ) );
		$changed = array();

		foreach ( $keys as $key ) {
			if ( ( $current[ $key ] ?? null ) !== ( $reference[ $key ] ?? null ) ) {
				$changed[] = (string) $key;
			}
		}

		return $changed;
	}

	/**
	 * Build the operator-facing message for values changed since the merge.
	 *
	 * @param array[] $changed Change descriptors.
	 * @return string
	 */
	private function format_changed_message( array $changed ): string {
		$labels = array();

		foreach ( array_slice( $changed, 0, self::MAX_REPORTED_ITEMS ) as $change ) {
			$labels[] = sprintf(
				/* translators: 1: post ID, 2: meta key */
				__( 'post %1$d (%2$s)', 'sportspress-player-merge' ),
				$change['post_id'],
				$change['meta_key']
			);
		}

		$unverifiable = false;
		foreach ( $changed as $change ) {
			if ( 'unverifiable' === $change['reason'] ) {
				$unverifiable = true;
				break;
			}
		}

		$message = sprintf(
			/* translators: 1: number of changed values, 2: list of posts */
			__( 'Revert refused: %1$d value(s) have changed since this merge ran and reverting would overwrite them — %2$s', 'sportspress-player-merge' ),
			count( $changed ),
			implode( ', ', $labels )
		);

		if ( $unverifiable ) {
			$message .= ' ' . __( 'Some values predate change tracking and cannot be verified.', 'sportspress-player-merge' );
		}

		return $message . ' ' . __( 'Review those posts, then re-run the revert with the override option if the current values really should be discarded.', 'sportspress-player-merge' );
	}

	/**
	 * Hash every value this backup captured, as it stands right now.
	 *
	 * Hashes are always taken from live data, never from the JSON-encoded copy,
	 * so both sides of a comparison are produced the same way.
	 *
	 * @param array $backup_data Backup data.
	 * @return array Map of section => post_id => meta_key => hash.
	 */
	private function compute_value_hashes( array $backup_data ): array {
		$hashes   = array(
			'events'  => array(),
			'lists'   => array(),
			'primary' => array(),
		);
		$sections = array(
			'affected_events' => 'events',
			'affected_lists'  => 'lists',
		);

		$post_ids = array();
		foreach ( $sections as $source => $section ) {
			foreach ( array_keys( (array) ( $backup_data[ $source ] ?? array() ) ) as $post_id ) {
				$post_ids[] = (int) $post_id;
			}
		}

		if ( ! empty( $post_ids ) ) {
			// The merge writes some rows with raw SQL, so drop any stale cache
			// before re-priming: a hash must always describe what is in the DB.
			foreach ( $post_ids as $post_id ) {
				wp_cache_delete( $post_id, 'post_meta' );
			}
			update_postmeta_cache( $post_ids );
		}

		$meta_rows = $this->load_meta_rows( $post_ids );

		foreach ( $sections as $source => $section ) {
			foreach ( (array) ( $backup_data[ $source ] ?? array() ) as $post_id => $entries ) {
				foreach ( (array) $entries as $meta_key => $captured ) {
					$hashes[ $section ][ (string) (int) $post_id ][ (string) $meta_key ] = $this->hash_live_meta(
						(int) $post_id,
						(string) $meta_key,
						$captured,
						$meta_rows
					);
				}
			}
		}

		if ( ! empty( $backup_data['primary_id'] ) ) {
			$hashes['primary'] = $this->hash_player_meta( (int) $backup_data['primary_id'] );
		}

		return $hashes;
	}

	/**
	 * Load raw postmeta rows for a set of posts, indexed by meta_id.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array<int, string> Map of meta_id => meta_value.
	 */
	private function load_meta_rows( array $post_ids ): array {
		global $wpdb;

		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );

		if ( empty( $post_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post_ids
			)
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->meta_id ] = (string) $row->meta_value;
		}

		return $map;
	}

	/**
	 * Hash the live value behind a captured backup entry.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $meta_key  Captured key, possibly a `_simple_` row group.
	 * @param mixed  $captured  Captured value.
	 * @param array  $meta_rows Map of meta_id => meta_value.
	 * @return string Hash.
	 */
	private function hash_live_meta( int $post_id, string $meta_key, $captured, array $meta_rows ): string {
		if ( 0 === strpos( $meta_key, '_simple_' ) ) {
			$values = array();

			foreach ( (array) $captured as $entry ) {
				$meta_id            = (int) ( $entry['meta_id'] ?? 0 );
				$values[ $meta_id ] = $meta_rows[ $meta_id ] ?? '__sp_merge_missing__';
			}

			ksort( $values, SORT_NUMERIC );

			return $this->hash_value( $values );
		}

		return $this->hash_value( get_post_meta( $post_id, $meta_key, true ) );
	}

	/**
	 * Hash every restorable meta key on a player.
	 *
	 * @param int $player_id Player ID.
	 * @return array<string, string> Map of meta_key => hash.
	 */
	private function hash_player_meta( int $player_id ): array {
		$hashes = array();

		wp_cache_delete( $player_id, 'post_meta' );
		$meta = get_post_meta( $player_id );

		foreach ( (array) $meta as $key => $values ) {
			if ( in_array( $key, self::META_RESTORE_DENY_LIST, true ) ) {
				continue;
			}
			$hashes[ (string) $key ] = $this->hash_value( (array) $values );
		}

		ksort( $hashes );

		return $hashes;
	}

	/**
	 * Hash a single value for change detection.
	 *
	 * SHA-256 rather than MD5. Nothing here is a security decision — the hash
	 * only answers "is this cell still what the merge left behind?" — but MD5 is
	 * flagged as a weak algorithm (SonarQube php:S4790), and a collision here
	 * would silently let revert overwrite data it should have refused to touch.
	 * The cost is 64 hex chars per cell instead of 32, inside a longtext column.
	 *
	 * @param mixed $value Value.
	 * @return string Hash.
	 */
	private function hash_value( $value ): string {
		return hash( 'sha256', (string) maybe_serialize( $value ) );
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

		// 2. Restore affected sp_list meta to original values.
		if ( ! empty( $backup_data['affected_lists'] ) ) {
			foreach ( $backup_data['affected_lists'] as $list_id => $list_entries ) {
				$list_id = (int) $list_id;
				foreach ( $list_entries as $meta_key => $original_value ) {
					if ( '_simple_sp_player' === $meta_key ) {
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
						update_post_meta( $list_id, $meta_key, $original_value );
					}
				}
				clean_post_cache( $list_id );
			}
		}

		// 3. Recreate deleted duplicate players.
		$primary_id    = (int) $backup_data['primary_id'];
		$recreated_ids = array();

		foreach ( $backup_data['duplicate_backups'] as $duplicate_id => $duplicate_backup ) {
			if ( isset( $duplicate_backup['post_data'] ) ) {
				$this->recreate_player( (int) $duplicate_id, $duplicate_backup );
				$recreated_ids[] = (int) $duplicate_id;
			}
		}

		// 4. Restore primary player to original state.
		$this->restore_player_data( $primary_id, $backup_data['primary_backup'] );

		// 5. Clear SportsPress caches for all affected players.
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
	 * @throws Exception When the player cannot be recreated under its original ID.
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

		/*
		 * WordPress only honours import_id while the ID is free. If it was taken
		 * the post is created under a new ID and every restored reference — event
		 * lineups, lists, taxonomies — would point at the old one. Abort loudly
		 * rather than report a success that orphans the data.
		 */
		if ( (int) $result !== $original_id ) {
			wp_delete_post( (int) $result, true );

			throw new Exception(
				sprintf(
					/* translators: 1: original player ID, 2: ID WordPress assigned */
					__( 'Failed to recreate player %1$d: WordPress assigned ID %2$d because the original ID is in use. Restore this backup from a database dump instead.', 'sportspress-player-merge' ),
					$original_id,
					(int) $result
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

		/*
		 * Clear existing meta. The merge copies far more than sp_* onto the
		 * primary — third-party keys and the duplicate's featured image among
		 * them — so restoring only sp_* left those behind permanently.
		 */
		$deny         = self::META_RESTORE_DENY_LIST;
		$placeholders = implode( ',', array_fill( 0, count( $deny ), '%s' ) );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key NOT IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $player_id ), $deny )
			)
		);

		wp_cache_delete( $player_id, 'post_meta' );

		// Restore all captured meta except WordPress-internal bookkeeping.
		if ( ! empty( $backup_data['meta_data'] ) ) {
			foreach ( $backup_data['meta_data'] as $key => $values ) {
				if ( in_array( $key, self::META_RESTORE_DENY_LIST, true ) ) {
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

		// Restore taxonomies, including ones the backup recorded as empty.
		if ( ! empty( $backup_data['taxonomies'] ) ) {
			foreach ( $backup_data['taxonomies'] as $taxonomy => $terms ) {
				$term_ids = wp_list_pluck( (array) $terms, 'term_id' );
				$term_ids = array_map( 'intval', $term_ids );
				wp_set_object_terms( $player_id, $term_ids, $taxonomy );
			}
		}

		clean_post_cache( $player_id );
	}

	/**
	 * Clean up after a successful revert.
	 *
	 * Must be scoped to the same owner revert() loaded the row under, or a
	 * cross-user revert would leave the source row stuck in its pre-revert
	 * status and clear the wrong user's "last merge backup" pointer.
	 *
	 * @param string   $backup_id     Backup ID.
	 * @param int|null $owner_user_id Backup owner, matching the one passed to revert(). Null
	 *                                uses the current user.
	 */
	private function cleanup_after_revert( string $backup_id, ?int $owner_user_id = null ): void {
		global $wpdb;

		$user_id = $owner_user_id ?? get_current_user_id();

		$wpdb->update(
			$wpdb->prefix . 'sp_merge_backups',
			array( 'status' => 'reverted' ),
			array(
				'backup_id' => $backup_id,
				'user_id'   => $user_id,
			),
			array( '%s' ),
			array( '%s', '%d' )
		);

		$last = get_user_meta( $user_id, 'sp_last_merge_backup', true );
		if ( $last === $backup_id ) {
			delete_user_meta( $user_id, 'sp_last_merge_backup' );
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
