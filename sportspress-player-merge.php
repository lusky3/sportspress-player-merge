<?php
/**
 * Plugin Name: SportsPress Player Merge
 * Description: Advanced tool to merge duplicate SportsPress players with data preservation and revert functionality
 * Version: 0.1
 * Author: Your Name
 * Text Domain: sportspress-player-merge
 */

// Prevent direct access to this file
// Import the necessary logging package
// This package is used for error logging and handling
use Psr\Log\LoggerInterface;

if (!defined('ABSPATH')) {
    try {
        throw new \RuntimeException('Direct access denied.');
    } catch (\RuntimeException $e) {
        // Log the error and show a user-friendly message
        $logger->error($e->getMessage());
        wp_die('Unauthorized access.', 403);
    }
}

// Define plugin constants for easy path management
define('SP_MERGE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SP_MERGE_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('SP_MERGE_VERSION', '0.1');
define('SP_MERGE_BACKUP_RETENTION_DAYS', 30);

/**
 * Main plugin initialization class
 * Handles plugin setup, file includes, and WordPress hooks
 */
class SportsPress_Player_Merge_Init {
    
    /**
     * Constructor - Sets up the plugin when WordPress loads
     */
    public function __construct() {
        // Hook into WordPress initialization
        add_action('init', [$this, 'init']);
        
        // Hook into admin initialization for backend functionality
        add_action('admin_init', [$this, 'admin_init']);
        
        // Hook into plugin activation
        register_activation_hook(__FILE__, [$this, 'activate']);
    }
    
    /**
     * Plugin activation hook
     */
    public function activate() {
        // Check if SportsPress is active
        if (!class_exists('SportsPress')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('SportsPress Player Merge requires SportsPress plugin to be installed and activated.');
        }
        
        // Check minimum WordPress version
        if (version_compare(get_bloginfo('version'), '5.0', '<')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('SportsPress Player Merge requires WordPress 5.0 or higher.');
        }
        
        // Create backup table
        $this->create_backup_table();
    }
    
    /**
     * Create backup table for storing merge data
     */
    private function create_backup_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'sp_merge_backups';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            backup_id varchar(255) NOT NULL,
            user_id bigint(20) NOT NULL,
            backup_data longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY backup_id (backup_id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Sanitize the path and validate the file existence
        $upgrade_file = wp_normalize_path(ABSPATH . 'wp-admin/includes/upgrade.php');
        if (file_exists($upgrade_file)) {
            require_once($upgrade_file);
            dbDelta($sql);
        } else {
            // Handle the error appropriately, e.g., log it or throw an exception
            error_log('Required file not found: ' . $upgrade_file);
        }
    }
    
    /**
     * Initialize the plugin - loads required files and classes
     */
    public function init() {
        // Load the main plugin class
        $file_path = SP_MERGE_PLUGIN_PATH . 'classes/class-player-merge.php';
        if (file_exists($file_path) && validate_file($file_path) === 0) {
            require_once $file_path;
            
            // Initialize the main functionality
            new SportsPress_Player_Merge();
        }
    }
    
    /**
     * Initialize admin-specific functionality
     */
    public function admin_init() {
        // Admin initialization happens here if needed
    }
}

// Start the plugin
new SportsPress_Player_Merge_Init();