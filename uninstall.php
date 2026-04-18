<?php
/**
 * Uninstall Script
 *
 * Cleans up all plugin data when the plugin is deleted.
 *
 * @package SportsPress_Player_Merge
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop the backup table.
$table_name = $wpdb->prefix . 'sp_merge_backups';
$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

// Remove user meta.
delete_metadata( 'user', 0, 'sp_last_merge_backup', '', true );

// Remove transients.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_sp_merge_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_sp_merge_' ) . '%'
	)
);
