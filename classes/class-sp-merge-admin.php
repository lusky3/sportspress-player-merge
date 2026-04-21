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
	 * Enqueue admin scripts and styles on the merge page only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_scripts( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, 'sp-player-merge' ) ) {
			return;
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_enqueue_style(
			'sp-merge-admin-css',
			SP_MERGE_PLUGIN_URL . "assets/css/admin{$suffix}.css",
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
			SP_MERGE_PLUGIN_URL . "assets/js/admin{$suffix}.js",
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
			include_once $template_path;
		} else {
			wp_die( esc_html__( 'Error: Admin page template not found.', 'sportspress-player-merge' ) );
		}
	}

	/**
	 * Get all published players, capped for performance.
	 *
	 * @return array[] Array of player data arrays.
	 */
	public function get_all_players(): array {
		$player_posts = get_posts(
			array(
				'post_type'      => 'sp_player',
				'posts_per_page' => 500,
				'no_found_rows'  => true,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( empty( $player_posts ) ) {
			return array();
		}

		return array_map(
			static function ( $player ) {
				return array(
					'id'   => $player->ID,
					'name' => $player->post_title . ' (ID: ' . $player->ID . ')',
				);
			},
			$player_posts
		);
	}

	/**
	 * Get recent backups for the current user.
	 *
	 * @return array[]|false Backups or false on error.
	 */
	public function get_recent_backups(): array|false {
		global $wpdb;

		$table_name = $wpdb->prefix . 'sp_merge_backups';

		// Check if table exists.
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);

		if ( $table_name !== $table_exists ) {
			return array();
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT backup_id, user_id, created_at,
						JSON_EXTRACT(backup_data, '$.primary_name') as primary_name,
						JSON_EXTRACT(backup_data, '$.duplicate_names') as duplicate_names
				 FROM {$table_name}
				 WHERE user_id = %d
				 ORDER BY created_at DESC
				 LIMIT 10",
				get_current_user_id()
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
				'date'            => mysql2date( 'M j, Y g:i A', $row->created_at ),
				'primary_name'    => json_decode( $row->primary_name, true ) ?? __( 'Unknown', 'sportspress-player-merge' ),
				'duplicate_names' => json_decode( $row->duplicate_names, true ) ?? array(),
			);
		}

		return $backups;
	}

	/**
	 * Get localized strings for JavaScript.
	 *
	 * @return array<string, string>
	 */
	private function get_localized_strings(): array {
		return array(
			'confirmMerge'  => __( 'Are you sure you want to merge these players?', 'sportspress-player-merge' ),
			'confirmRevert' => __( 'Are you sure you want to revert the last merge?', 'sportspress-player-merge' ),
			'selectPlayers' => __( 'Please select a primary player and at least one duplicate.', 'sportspress-player-merge' ),
			'mergeSuccess'  => __( 'Players merged successfully!', 'sportspress-player-merge' ),
			'revertSuccess' => __( 'Merge reverted successfully!', 'sportspress-player-merge' ),
			'noMergeData'   => __( 'No recent merge data found to revert.', 'sportspress-player-merge' ),
		);
	}
}
