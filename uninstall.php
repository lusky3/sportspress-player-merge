<?php
/**
 * Uninstall Script
 * 
 * Cleans up all plugin data when the plugin is deleted
 */

// Prevent direct access
if (!defined('WP_UNINSTALL_PLUGIN')) {
    wp_die('Unauthorized access to uninstall plugin', 'Access Denied', array('response' => 403));
}

// Remove custom backup table
global $wpdb;
$table = $wpdb->prefix . 'sp_merge_backups';
$sql = $wpdb->prepare("DROP TABLE IF EXISTS `%1s`", $table);
$result = $wpdb->query($sql);
if ($result === false) {
    error_log("SP Merge Uninstall: Failed to drop backup table '{$table}'. MySQL error: " . $wpdb->last_error);
} else {
    error_log("SP Merge Uninstall: Backup table drop successful");
}

// Remove user meta data
$meta_result = delete_metadata('user', 0, 'sp_last_merge_backup', '', true);
error_log("SP Merge Uninstall: User meta cleanup " . ($meta_result ? 'successful' : 'completed'));

// Clear any transients
$trans1 = $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE option_name LIKE %s", '_transient_sp_merge_%'));
$trans2 = $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE option_name LIKE %s", '_transient_timeout_sp_merge_%'));
error_log("SP Merge Uninstall: Transients cleanup - removed {$trans1} main and {$trans2} timeout entries");

// Clear any cached data
wp_cache_flush();
error_log("SP Merge Uninstall: Plugin cleanup completed successfully");