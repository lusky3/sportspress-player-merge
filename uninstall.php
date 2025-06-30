<?php
/**
 * Uninstall Script
 * 
 * Cleans up all plugin data when the plugin is deleted
 */

// Prevent direct access
// Import the WordPress logging function for error handling
// This allows us to log errors without abruptly terminating execution
if (!function_exists('wp_die')) {
    require_once(ABSPATH . 'wp-includes/functions.php');
}

if (!defined('WP_UNINSTALL_PLUGIN')) {
    wp_die('Unauthorized access to uninstall plugin', 'Access Denied', array('response' => 403));
}

// Remove custom backup table
global $wpdb;
$table_name = $wpdb->prefix . 'sp_merge_backups';
// Validate table name to prevent injection
if (preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
    $result = $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS `%1s`", $table_name));
    error_log("SP Merge Uninstall: Backup table drop " . ($result !== false ? 'successful' : 'failed'));
}

// Remove user meta data
$meta_result = delete_metadata('user', 0, 'sp_last_merge_backup', '', true);
error_log("SP Merge Uninstall: User meta cleanup " . ($meta_result ? 'successful' : 'completed'));

// Clear any transients
$trans1 = $wpdb->query($wpdb->prepare("DELETE FROM `%1s` WHERE option_name LIKE %s", $wpdb->options, '_transient_sp_merge_%'));
$trans2 = $wpdb->query($wpdb->prepare("DELETE FROM `%1s` WHERE option_name LIKE %s", $wpdb->options, '_transient_timeout_sp_merge_%'));
error_log("SP Merge Uninstall: Transients cleanup - removed {$trans1} main and {$trans2} timeout entries");

// Clear any cached data
wp_cache_flush();
error_log("SP Merge Uninstall: Plugin cleanup completed successfully");