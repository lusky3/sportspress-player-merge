<?php
/**
 * Plugin Name: SportsPress Player Merge
 * Plugin URI: https://github.com/lusky3/sportspress-player-merge
 * Description: Advanced tool to merge duplicate SportsPress players with data preservation and revert functionality
 * Version: 0.2.0
 * Author: Cody (lusky3)
 * Author URI: https://github.com/lusky3
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sportspress-player-merge
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Network: false
 */

// Prevent direct access to this file
// WordPress handles logging internally - no external logger needed

if (!defined('ABSPATH')) {
    $user_id = get_current_user_id();
    error_log("SP Merge [User: " . intval($user_id) . "]: Direct access denied.");
    wp_die(__('Unauthorized access.', 'sportspress-player-merge'), 403);
}

// Define plugin constants for easy path management
define('SP_MERGE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SP_MERGE_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('SP_MERGE_VERSION', '0.2.0');
define('SP_MERGE_BACKUP_RETENTION_DAYS', 30);

/**
 * Get plugin version
 * @return string Plugin version
 */
function sp_merge_get_version() {
    return SP_MERGE_VERSION;
}

/**
 * Main plugin initialization class
 * Handles plugin setup, file includes, and WordPress hooks
 */
class SportsPress_Player_Merge_Init {
    
    const BACKUP_RETENTION_DAYS = 30;
    
    /**
     * Constructor - Sets up the plugin when WordPress loads
     */
    public function __construct() {
        // Hook into WordPress initialization
        add_action('init', [$this, 'init']);
        

        
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
            wp_die(__('SportsPress Player Merge requires SportsPress plugin to be installed and activated.', 'sportspress-player-merge'));
        }
        
        // Check minimum WordPress version
        if (version_compare(get_bloginfo('version'), '5.0', '<')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(__('SportsPress Player Merge requires WordPress 5.0 or higher.', 'sportspress-player-merge'));
        }
        
        // Create backup table
        try {
            $this->create_backup_table();
        } catch (Exception $e) {
            $user_id = get_current_user_id();
            error_log("SP Merge [User: " . intval($user_id) . "]: Backup table creation failed: " . $e->getMessage());
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(__('Failed to create backup table. Please check server logs for details.', 'sportspress-player-merge'));
        }
    }
    
    /**
     * Create backup table for storing merge data
     * @throws Exception If table creation fails
     */
    private function create_backup_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'sp_merge_backups';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = $wpdb->prepare(
            "CREATE TABLE %1s (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                backup_id varchar(255) NOT NULL,
                user_id bigint(20) NOT NULL,
                backup_data longtext NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id),
                KEY backup_id (backup_id),
                KEY user_id (user_id),
                KEY created_at (created_at)
            ) %1s",
            $table_name,
            $charset_collate
        );
        
        // Use WordPress core file safely with path validation
        $upgrade_path = 'wp-admin/includes/upgrade.php';
        if (validate_file($upgrade_path) !== 0) {
            throw new Exception(__('Invalid file path detected', 'sportspress-player-merge'));
        }
        
        $upgrade_file = ABSPATH . $upgrade_path;
        $real_upgrade_file = realpath($upgrade_file);
        $real_abspath = realpath(ABSPATH);
        
        if ($real_upgrade_file && $real_abspath && 
            strpos($real_upgrade_file, $real_abspath) === 0 && 
            file_exists($real_upgrade_file) && 
            pathinfo($real_upgrade_file, PATHINFO_EXTENSION) === 'php' && 
            is_readable($real_upgrade_file)) {
            require_once($real_upgrade_file);
            dbDelta($sql);
        } else {
            throw new Exception(__('WordPress core file missing or invalid: wp-admin/includes/upgrade.php', 'sportspress-player-merge'));
        }
    }
    
    /**
     * Initialize the plugin - loads required files and classes
     */
    public function init() {
        // Load text domain for translations
        load_plugin_textdomain('sportspress-player-merge', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Load all modular class files
        $class_files = [
            'class-sp-merge-controller.php',
            'class-sp-merge-admin.php',
            'class-sp-merge-ajax.php',
            'class-sp-merge-processor.php',
            'class-sp-merge-backup.php',
            'class-sp-merge-preview.php'
        ];
        
        foreach ($class_files as $file) {
            if (validate_file($file) !== 0) {
                continue;
            }
            
            $file_path = SP_MERGE_PLUGIN_PATH . 'classes/' . $file;
            $real_file_path = realpath($file_path);
            $real_classes_path = realpath(SP_MERGE_PLUGIN_PATH . 'classes/');
            
            if ($real_file_path && $real_classes_path && 
                strpos($real_file_path, $real_classes_path) === 0 && 
                file_exists($real_file_path)) {
                require_once $real_file_path;
            } else {
                $user_id = get_current_user_id();
                error_log("SP Merge [User: " . intval($user_id) . "]: Failed to load class file: " . sanitize_file_name($file));
            }
        }
        
        // Initialize the main controller
        if (class_exists('SP_Merge_Controller')) {
            new SP_Merge_Controller();
        }
    }
    

}

// Start the plugin
new SportsPress_Player_Merge_Init();