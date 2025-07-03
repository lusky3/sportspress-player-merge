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
    error_log(sprintf("SP Merge [User: %d]: Direct access denied.", intval($user_id)));
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
            $this->create_sp_merge_backups_table();
        } catch (InvalidArgumentException $e) {
            $user_id = get_current_user_id();
            error_log(sprintf("SP Merge [User: %d]: Invalid table configuration - %s", intval($user_id), $e->getMessage()));
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(__('Database table configuration error. Please contact support.', 'sportspress-player-merge'));
        } catch (RuntimeException $e) {
            $user_id = get_current_user_id();
            error_log(sprintf("SP Merge [User: %d]: Database operation failed - %s", intval($user_id), $e->getMessage()));
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(__('Database connection or permission error. Please check database settings.', 'sportspress-player-merge'));
        } catch (Exception $e) {
            $user_id = get_current_user_id();
            error_log(sprintf("SP Merge [User: %d]: Backup table creation failed - %s", intval($user_id), $e->getMessage()));
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(__('Failed to create backup table. Please check server logs for details.', 'sportspress-player-merge'));
        }
    }
    
    /**
     * Create backup table for storing merge data
     * @throws Exception If table creation fails
     */
    private function create_sp_merge_backups_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'sp_merge_backups';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            backup_id varchar(255) NOT NULL,
            user_id bigint(20) NOT NULL,
            backup_data longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY backup_id (backup_id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) {$charset_collate}";
        
        // Use WordPress core file safely with path validation
        $upgrade_path = 'wp-admin/includes/upgrade.php';
        if (validate_file($upgrade_path) !== 0) {
            $user_id = get_current_user_id();
            error_log(sprintf("SP Merge [User: %d]: Path validation failed - %s", intval($user_id), $upgrade_path));
            throw new Exception(sprintf(__('Invalid file path detected: %s', 'sportspress-player-merge'), $upgrade_path));
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
            
            $result = dbDelta($sql);
            
            // Check if table was created successfully
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) !== $table_name) {
                throw new RuntimeException(__('Failed to create backup table in database', 'sportspress-player-merge'));
            }
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $user_id = get_current_user_id();
                if (!empty($result)) {
                    error_log(sprintf("SP Merge [User: %d]: Backup table creation completed - %s", intval($user_id), implode(', ', $result)));
                } else {
                    error_log(sprintf("SP Merge [User: %d]: Backup table creation - no changes made (table may already exist)", intval($user_id)));
                }
            }
        } else {
            throw new Exception(__('WordPress core file missing or invalid: wp-admin/includes/upgrade.php', 'sportspress-player-merge'));
        }
    }
    
    /**
     * Initialize the plugin - loads required files and classes
     */
    public function init() {
        $user_id = get_current_user_id();
        
        $this->setup_updater($user_id);
        $this->setup_translations();
        $this->setup_classes($user_id);
        $this->setup_controller();
    }

    private function setup_updater($user_id) {
        try {
            $this->init_updater();
            error_log(sprintf("SP Merge [User: %d]: Updater initialized", intval($user_id)));
        } catch (Exception $e) {
            error_log(sprintf("SP Merge [User: %d]: Updater failed - %s", intval($user_id), $e->getMessage()));
        }
    }

    private function setup_translations() {
        $this->load_translations();
    }

    private function setup_classes($user_id) {
        try {
            $this->load_class_files();
            error_log(sprintf("SP Merge [User: %d]: Class files loaded", intval($user_id)));
        } catch (InvalidArgumentException $e) {
            error_log(sprintf("SP Merge [User: %d]: Invalid class file configuration - %s\nStack trace: %s", intval($user_id), $e->getMessage(), $e->getTraceAsString()));
            throw $e; // Re-throw critical configuration errors
        } catch (RuntimeException $e) {
            error_log(sprintf("SP Merge [User: %d]: Class file system error - %s\nStack trace: %s", intval($user_id), $e->getMessage(), $e->getTraceAsString()));
            throw $e; // Re-throw critical file system errors
        } catch (Exception $e) {
            error_log(sprintf("SP Merge [User: %d]: Class loading failed - %s\nStack trace: %s", intval($user_id), $e->getMessage(), $e->getTraceAsString()));
            return; // Cannot continue without classes
        }
    }

    private function setup_controller() {
        $this->init_controller();
    }

     
    private function init_updater() {
        $updater_file = 'updater.php';
        
        if (validate_file($updater_file) !== 0) {
            throw new Exception(sprintf(__('Invalid updater file name: %s', 'sportspress-player-merge'), $updater_file));
        }
        
        $file_path = SP_MERGE_PLUGIN_PATH . 'includes/' . $updater_file;
        $real_file_path = realpath($file_path);
        $real_includes_path = realpath(SP_MERGE_PLUGIN_PATH . 'includes/');
        
        if (!$real_file_path || !$real_includes_path || 
            strpos($real_file_path, $real_includes_path) !== 0) {
            throw new Exception(__('Updater file path validation failed', 'sportspress-player-merge'));
        }
        
        if (!file_exists($real_file_path) || !is_readable($real_file_path)) {
            throw new Exception(__('Updater file not accessible', 'sportspress-player-merge'));
        }
        
        require_once $real_file_path;
        new SP_Merge_GitHub_Updater(__FILE__, 'lusky3/sportspress-player-merge');
    }
    
    private function load_translations() {
        load_plugin_textdomain('sportspress-player-merge', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    private function load_class_files() {
        $class_files = [
            'class-sp-merge-controller.php',
            'class-sp-merge-admin.php',
            'class-sp-merge-ajax.php',
            'class-sp-merge-processor.php',
            'class-sp-merge-backup.php',
            'class-sp-merge-preview.php'
        ];
        
        $real_classes_path = realpath(SP_MERGE_PLUGIN_PATH . 'classes/');
        
        foreach ($class_files as $file) {
            $this->load_class_file($file, $real_classes_path);
        }
    }
    
    private function load_class_file($file, $real_classes_path) {
        if (validate_file($file) !== 0) {
            throw new Exception(sprintf(__('Invalid class file name: %s', 'sportspress-player-merge'), $file));
        }
        
        $file_path = SP_MERGE_PLUGIN_PATH . 'classes/' . $file;
        $real_file_path = realpath($file_path);
        
        if (!$real_file_path || !$real_classes_path || strpos($real_file_path, $real_classes_path) !== 0) {
            throw new Exception(sprintf(__('Class file path validation failed: %s', 'sportspress-player-merge'), $file));
        }
        
        if (!file_exists($real_file_path)) {
            throw new Exception(sprintf(__('Class file not found: %s', 'sportspress-player-merge'), $file));
        }
        
        if (!is_readable($real_file_path)) {
            throw new Exception(sprintf(__('Class file not readable: %s', 'sportspress-player-merge'), $file));
        }
        
        require_once $real_file_path;
        
        $user_id = get_current_user_id();
        error_log(sprintf("SP Merge [User: %d]: Successfully loaded class file - %s", intval($user_id), sanitize_file_name($file)));
    }
    
    private function init_controller() {
        if (class_exists('SP_Merge_Controller')) {
            new SP_Merge_Controller();
        }
    }
    
}

// Start the plugin
new SportsPress_Player_Merge_Init();