<?php
/**
 * Admin Interface Class
 *
 * Handles admin menu, page rendering, and asset loading.
 *
 * @package SportsPress_Player_Merge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SP_Merge_Admin
 */
class SP_Merge_Admin {

	/**
	 * How many backups the admin page lists.
	 *
	 * A single run of this tool is 35 merges; a ten-row list made every backup
	 * before the last ten unreachable from the UI.
	 */
	private const MAX_LISTED_BACKUPS = 200;

	/**
	 * Backup statuses whose rows may still be reverted.
	 *
	 * Mirrors SP_Merge_Backup::LOADABLE_STATUSES — a 'reverted' row cannot be
	 * loaded, so offering its Revert button only produces "Backup data not found".
	 */
	private const REVERTABLE_STATUSES = array( 'pending', 'active', 'failed' );

	/**
	 * Register the admin submenu page.
	 */
	public function add_admin_menu(): void {
		add_submenu_page(
			'edit.php?post_type=sp_player',
			__( 'Player Merge Tool', 'sportspress-player-merge' ),
			__( 'Player Merge', 'sportspress-player-merge' ),
			'edit_sp_players',
			'sp-player-merge',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Resolve the filename suffix for a bundled asset.
	 *
	 * Minified assets are produced by the release workflow and are deliberately
	 * NOT committed, so a git-cloned install — or one updated from the GitHub
	 * source zipball rather than the built release asset — has no
	 * `admin.min.js`. Enqueuing it regardless returned a 404: Select2 never
	 * initialised, both player dropdowns stayed empty (they are populated only
	 * over AJAX) and every control on the page was inert, with no error shown.
	 *
	 * Prefer the minified file when it is actually present, and fall back to the
	 * unminified source otherwise, so the tool works from any install method.
	 *
	 * @param string $relative_path Path under assets/, without extension.
	 * @param string $extension     File extension, without the dot.
	 * @return string Either '.min' or an empty string.
	 */
	private static function asset_suffix( string $relative_path, string $extension ): string {
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			return '';
		}

		$minified = SP_MERGE_PLUGIN_PATH . "assets/{$relative_path}.min.{$extension}";

		return file_exists( $minified ) ? '.min' : '';
	}

	/**
	 * Enqueue admin scripts and styles on the merge page only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_scripts( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, 'sp-player-merge' ) ) {
			return;
		}

		$css_suffix = self::asset_suffix( 'css/admin', 'css' );
		$js_suffix  = self::asset_suffix( 'js/admin', 'js' );

		wp_enqueue_style(
			'sp-merge-admin-css',
			SP_MERGE_PLUGIN_URL . "assets/css/admin{$css_suffix}.css",
			array(),
			SP_MERGE_VERSION
		);

		// Select2 for AJAX-powered player search.
		wp_enqueue_style(
			'sp-merge-select2-css',
			SP_MERGE_PLUGIN_URL . 'assets/vendor/select2/select2.min.css',
			array(),
			'4.1.0-rc.0'
		);

		wp_enqueue_script(
			'sp-merge-select2-js',
			SP_MERGE_PLUGIN_URL . 'assets/vendor/select2/select2.min.js',
			array( 'jquery' ),
			'4.1.0-rc.0',
			true
		);

		wp_enqueue_script(
			'sp-merge-admin-js',
			SP_MERGE_PLUGIN_URL . "assets/js/admin{$js_suffix}.js",
			array( 'jquery', 'sp-merge-select2-js' ),
			SP_MERGE_VERSION,
			true
		);

		wp_localize_script(
			'sp-merge-admin-js',
			'spMergeAjax',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'sp_merge_nonce' ),
				'strings' => $this->get_localized_strings(),
			)
		);
	}

	/**
	 * Render the admin page.
	 */
	public function render_admin_page(): void {
		if ( ! current_user_can( 'edit_sp_players' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'sportspress-player-merge' ) );
		}

		$recent_backups = $this->get_recent_backups();

		$template_path = SP_MERGE_PLUGIN_PATH . 'includes/admin-page.php';
		if ( file_exists( $template_path ) ) {
			// Not include_once: this page can legitimately render more than
			// once in a request (e.g. via do_action() called twice), and
			// _once would silently emit nothing the second time.
			include $template_path;
		} else {
			wp_die( esc_html__( 'Error: Admin page template not found.', 'sportspress-player-merge' ) );
		}
	}

	/**
	 * Get recent backups, by default for the current user only.
	 *
	 * @param int         $limit     Maximum rows to return.
	 * @param int|null    $user_id   Owner to list backups for instead of the current user.
	 *                               Ignored when $all_users is true.
	 * @param bool        $all_users List backups for every owner (e.g. WP-CLI under
	 *                               delete_sp_players), ignoring $user_id.
	 * @param string|null $status    Only rows with this status. Applied in SQL, before
	 *                               $limit — filtering it out of the result set afterward
	 *                               would return the matching subset of the $limit newest
	 *                               rows overall, not the $limit newest matching rows.
	 * @return array[]|false Backups or false on error.
	 */
	public function get_recent_backups( int $limit = self::MAX_LISTED_BACKUPS, ?int $user_id = null, bool $all_users = false, ?string $status = null ): array|false {
		global $wpdb;

		$table_name = $wpdb->prefix . 'sp_merge_backups';

		// Check if table exists.
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);

		if ( $table_name !== $table_exists ) {
			return array();
		}

		// The status column arrives with the backup class's schema upgrade,
		// which a page load may not have run yet.
		$columns    = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$status_sql = in_array( 'status', $columns, true ) ? "COALESCE(status, 'active')" : "'active'";
		$limit      = max( 1, min( self::MAX_LISTED_BACKUPS, $limit ) );

		// $all_users drops the owner filter entirely rather than parameterizing
		// it away, so there is no user_id value — real or sentinel — that could
		// be mistaken for "every owner". $status is appended the same way, only
		// when actually requested, so the unfiltered call this method has always
		// answered is completely unchanged.
		$conditions = array();
		$query_args = array();

		if ( ! $all_users ) {
			$conditions[] = 'user_id = %d';
			$query_args[] = $user_id ?? get_current_user_id();
		}

		if ( null !== $status ) {
			$conditions[] = "{$status_sql} = %s";
			$query_args[] = $status;
		}

		$where_sql    = empty( $conditions ) ? '' : 'WHERE ' . implode( ' AND ', $conditions );
		$query_args[] = $limit;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT backup_id, user_id, created_at, {$status_sql} AS status,
						JSON_EXTRACT(backup_data, '$.primary_name') as primary_name,
						JSON_EXTRACT(backup_data, '$.duplicate_names') as duplicate_names
				 FROM {$table_name}
				 {$where_sql}
				 ORDER BY created_at DESC
				 LIMIT %d",
				$query_args
			)
		);

		if ( null === $results ) {
			return false;
		}

		if ( empty( $results ) ) {
			return array();
		}

		$backups = array();
		foreach ( $results as $row ) {
			$backups[] = array(
				'id'              => $row->backup_id,
				'user_id'         => (int) $row->user_id,
				'date'            => mysql2date( 'M j, Y g:i A', $row->created_at ),
				// A machine-sortable alternative to 'date' above, for callers
				// rendering a script-consumed format (CSV/JSON): 'M j, Y g:i A' is
				// locale- and timezone-translated and neither sorts nor parses
				// reliably. Not rendered anywhere by default — the admin screen and
				// WP-CLI's --format=table both use 'date'.
				'date_machine'    => mysql2date( 'Y-m-d H:i:s', $row->created_at, false ),
				'status'          => (string) ( $row->status ?? 'active' ),
				'primary_name'    => json_decode( $row->primary_name, true ) ?? __( 'Unknown', 'sportspress-player-merge' ),
				'duplicate_names' => json_decode( $row->duplicate_names, true ) ?? array(),
			);
		}

		return $backups;
	}

	/**
	 * Describe a backup status for the operator.
	 *
	 * @param string $status Stored status.
	 * @return array{label: string, hint: string} Badge text and what to do about it.
	 */
	public function get_status_meta( string $status ): array {
		switch ( $status ) {
			case 'pending':
				return array(
					'label' => __( 'Interrupted', 'sportspress-player-merge' ),
					'hint'  => __( 'The merge never confirmed it finished. Check this player before merging anything else.', 'sportspress-player-merge' ),
				);
			case 'failed':
				return array(
					'label' => __( 'Merge failed', 'sportspress-player-merge' ),
					'hint'  => __( 'The merge threw partway through. Verify the player, and revert if anything was left half-applied.', 'sportspress-player-merge' ),
				);
			case 'reverted':
				return array(
					'label' => __( 'Reverted', 'sportspress-player-merge' ),
					'hint'  => __( 'Already reverted. Nothing left to restore from this backup.', 'sportspress-player-merge' ),
				);
			default:
				return array(
					'label' => __( 'Merged', 'sportspress-player-merge' ),
					'hint'  => '',
				);
		}
	}

	/**
	 * Render one backup row.
	 *
	 * Shared by the admin page template and the AJAX refresh so the two surfaces
	 * cannot drift apart on status, escaping or which controls are offered.
	 *
	 * @param array $backup One row from get_recent_backups().
	 */
	public function render_backup_item( array $backup ): void {
		$backup_id  = (string) ( $backup['id'] ?? '' );
		$status     = (string) ( $backup['status'] ?? 'active' );
		$meta       = $this->get_status_meta( $status );
		$revertable = in_array( $status, self::REVERTABLE_STATUSES, true );
		$duplicates = is_array( $backup['duplicate_names'] ?? null ) ? $backup['duplicate_names'] : array();
		?>
		<div class="sp-backup-item sp-backup-status-<?php echo esc_attr( $status ); ?>">
			<input type="checkbox" class="backup-checkbox" value="<?php echo esc_attr( $backup_id ); ?>" id="backup-<?php echo esc_attr( $backup_id ); ?>">
			<label for="backup-<?php echo esc_attr( $backup_id ); ?>">
				<strong><?php echo esc_html( (string) ( $backup['primary_name'] ?? '' ) ); ?></strong> &larr; <?php echo esc_html( implode( ', ', $duplicates ) ); ?>
				<span class="sp-backup-status sp-backup-status-badge-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $meta['label'] ); ?></span>
				<?php if ( '' !== $meta['hint'] ) : ?>
					<span class="sp-backup-hint"><?php echo esc_html( $meta['hint'] ); ?></span>
				<?php endif; ?>
			</label>
			<span class="sp-backup-date"><?php echo esc_html( (string) ( $backup['date'] ?? '' ) ); ?></span>
			<div class="sp-backup-buttons">
				<?php if ( $revertable ) : ?>
					<button type="button" class="button button-secondary sp-revert-backup" data-backup-id="<?php echo esc_attr( $backup_id ); ?>">
						<span class="dashicons dashicons-undo"></span> <?php esc_html_e( 'Revert', 'sportspress-player-merge' ); ?>
					</button>
				<?php endif; ?>
				<button type="button" class="button button-secondary sp-delete-backup" data-backup-id="<?php echo esc_attr( $backup_id ); ?>">
					<span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Delete', 'sportspress-player-merge' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Get localized strings for JavaScript.
	 *
	 * @return array<string, string>
	 */
	private function get_localized_strings(): array {
		return array(
			'confirmRevert' => __( 'Are you sure you want to revert the last merge?', 'sportspress-player-merge' ),
			'selectPlayers' => __( 'Please select a primary player and at least one duplicate.', 'sportspress-player-merge' ),
			'mergeSuccess'  => __( 'Players merged successfully!', 'sportspress-player-merge' ),
			'revertSuccess' => __( 'Merge reverted successfully!', 'sportspress-player-merge' ),
			'noMergeData'   => __( 'No recent merge data found to revert.', 'sportspress-player-merge' ),
			'previewStale'  => __( 'Selection changed, so the preview no longer applies. Preview again before executing.', 'sportspress-player-merge' ),
			'overrideLabel' => __( 'Override and revert anyway', 'sportspress-player-merge' ),
			'overrideIntro' => __( 'Override the safety check and revert this merge? Everything listed below was written AFTER the merge and will be permanently discarded:', 'sportspress-player-merge' ),
			'selectMembers' => __( 'Select at least two players from this group to merge.', 'sportspress-player-merge' ),
		);
	}
}
