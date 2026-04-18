<?php
/**
 * Main Controller Class
 *
 * Coordinates all merge operations and initializes components.
 *
 * @package SportsPress_Player_Merge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SP_Merge_Controller
 */
class SP_Merge_Controller {

	/**
	 * Admin instance.
	 *
	 * @var SP_Merge_Admin
	 */
	private SP_Merge_Admin $admin;

	/**
	 * AJAX handler instance.
	 *
	 * @var SP_Merge_Ajax
	 */
	private SP_Merge_Ajax $ajax;

	/**
	 * Constructor — initializes components and hooks.
	 */
	public function __construct() {
		$this->admin = new SP_Merge_Admin();
		$this->ajax  = new SP_Merge_Ajax();

		$this->init_hooks();
	}

	/**
	 * Register WordPress hooks.
	 */
	private function init_hooks(): void {
		add_action( 'admin_menu', array( $this->admin, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this->admin, 'enqueue_scripts' ) );

		// AJAX handlers — authenticated only.
		add_action( 'wp_ajax_sp_preview_merge', array( $this->ajax, 'preview_merge' ) );
		add_action( 'wp_ajax_sp_execute_merge', array( $this->ajax, 'execute_merge' ) );
		add_action( 'wp_ajax_sp_revert_merge', array( $this->ajax, 'revert_merge' ) );
		add_action( 'wp_ajax_sp_delete_backup', array( $this->ajax, 'delete_backup' ) );
		add_action( 'wp_ajax_sp_get_recent_backups', array( $this->ajax, 'get_recent_backups' ) );
		add_action( 'wp_ajax_sp_search_players', array( $this->ajax, 'search_players' ) );
	}
}
