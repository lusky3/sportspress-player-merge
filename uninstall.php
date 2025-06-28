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
$wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", $table_name));

// Remove user meta data
$wpdb->delete($wpdb->usermeta, array('meta_key' => 'sp_last_merge_backup'), array('%s'));

// Clear any cached data
wp_cache_flush();