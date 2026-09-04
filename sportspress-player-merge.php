<?php
/**
 * Plugin Name: SportsPress Player Merge
 * Plugin URI: https://github.com/lusky3/sportspress-player-merge
 * Description: Advanced tool to merge duplicate SportsPress players with data preservation and revert functionality
 * Version: 1.2.0
 * Author: Cody (lusky3)
 * Author URI: https://github.com/lusky3
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sportspress-player-merge
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires Plugins: sportspress
 * Tested up to: 6.9
 * Requires PHP: 8.2
 * Network: false
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SP_MERGE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SP_MERGE_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SP_MERGE_PLUGIN_FILE', __FILE__ );
define( 'SP_MERGE_VERSION', '1.2.0' );
define( 'SP_MERGE_BACKUP_RETENTION_DAYS', 30 );

/**
 * Main plugin initialization class.
 */
class SportsPress_Player_Merge_Init {

	/**
	 * Constructor — sets up activation hook and admin init.
	 */
	public function __construct() {
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Plugin activation hook.
	 */
	public function activate(): void {
		if ( ! class_exists( 'SportsPress' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				esc_html__( 'SportsPress Player Merge requires SportsPress plugin to be installed and activated.', 'sportspress-player-merge' ),
				'',
				array( 'back_link' => true )
			);
		}

		$this->create_backup_table();
	}

	/**
	 * Create backup table for storing merge data.
	 *
	 * Keep the column list in step with SP_Merge_Backup::maybe_upgrade_schema(),
	 * which brings existing installs forward; columns listed here let a fresh
	 * install skip that ALTER path entirely.
	 */
	private function create_backup_table(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'sp_merge_backups';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			backup_id varchar(255) NOT NULL,
			user_id bigint(20) NOT NULL,
			backup_data longtext NOT NULL,
			created_at datetime NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			touched_posts longtext NULL DEFAULT NULL,
			post_hashes longtext NULL DEFAULT NULL,
			PRIMARY KEY (id),
			KEY backup_id (backup_id),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) {$charset_collate}";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Initialize the plugin — admin only.
	 */
	public function init(): void {
		if ( ! is_admin() && ! wp_doing_ajax() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		/*
		 * GitHub updater runs regardless of SportsPress status, unless the
		 * deployment has frozen it. This plugin permanently deletes posts, so an
		 * operator working through a batch of merges may not want the code
		 * changing underneath them: define SP_MERGE_DISABLE_UPDATER as true in
		 * wp-config.php and no update check, no update offer and no self-install
		 * can happen.
		 */
		$updater_path = SP_MERGE_PLUGIN_PATH . 'classes/class-sp-merge-github-updater.php';
		if ( file_exists( $updater_path ) && ( ! defined( 'SP_MERGE_DISABLE_UPDATER' ) || ! SP_MERGE_DISABLE_UPDATER ) ) {
			require_once $updater_path;
			$GLOBALS['sp_merge_updater'] = new SP_Merge_GitHub_Updater( SP_MERGE_PLUGIN_FILE, SP_MERGE_VERSION );
		}

		if ( ! class_exists( 'SportsPress' ) ) {
			return;
		}

		load_plugin_textdomain(
			'sportspress-player-merge',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);

		$class_files = array(
			'class-sp-merge-name-matcher.php',
			'class-sp-merge-controller.php',
			'class-sp-merge-admin.php',
			'class-sp-merge-lock.php',
			'class-sp-merge-validation.php',
			'class-sp-merge-ajax.php',
			'class-sp-merge-processor.php',
			'class-sp-merge-backup.php',
			'class-sp-merge-preview.php',
		);

		foreach ( $class_files as $file ) {
			$file_path = SP_MERGE_PLUGIN_PATH . 'classes/' . $file;
			if ( file_exists( $file_path ) ) {
				require_once $file_path;
			}
		}

		if ( class_exists( 'SP_Merge_Controller' ) ) {
			$GLOBALS['sp_merge_controller'] = new SP_Merge_Controller();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once SP_MERGE_PLUGIN_PATH . 'classes/class-sp-merge-cli.php';
			require_once SP_MERGE_PLUGIN_PATH . 'classes/class-sp-merge-cli-backups.php';

			/*
			 * Two class registrations, each at its own namespace: WP-CLI maps
			 * every public method of a registered class to its own hyphenated
			 * leaf subcommand under that class's namespace. SP_Merge_CLI is
			 * registered at the bare `sp-merge` namespace (scan, preview, merge,
			 * revert, batch); SP_Merge_CLI_Backups is registered one level
			 * deeper, at `sp-merge backups`, so its `list()`/`delete()` methods
			 * become `wp sp-merge backups list` / `wp sp-merge backups delete`.
			 * Registering backup methods on SP_Merge_CLI as well (as this file
			 * once did, alongside two explicit `sp-merge backups <verb>`
			 * registrations) would have given WP-CLI's own method-name mapping a
			 * second, redundant spelling for the same destructive operation —
			 * `wp sp-merge backups-list` next to the intended `sp-merge backups
			 * list` — which is why the methods live on a separate class instead.
			 */
			WP_CLI::add_command( 'sp-merge', 'SP_Merge_CLI' );
			WP_CLI::add_command( 'sp-merge backups', 'SP_Merge_CLI_Backups' );
		}
	}
}

$GLOBALS['sp_merge'] = new SportsPress_Player_Merge_Init();
