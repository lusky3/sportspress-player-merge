<?php
/**
 * Admin Interface Class
 * 
 * Handles admin menu, page rendering, and asset loading
 */

if (!defined('ABSPATH')) {
    wp_die('Direct access denied.', 'Access Denied', array('response' => 403));
}

class SP_Merge_Admin {
    
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=sp_player',
            __('Player Merge Tool', 'sportspress-player-merge'),
            __('Player Merge', 'sportspress-player-merge'),
            'edit_sp_players',
            'sp-player-merge',
            [$this, 'render_admin_page']
        );
    }
    
    public function enqueue_scripts($hook) {
        if (!$hook || strpos($hook, 'sp-player-merge') === false) {
            return;
        }
        
        // Validate plugin URL constant
        if (!defined('SP_MERGE_PLUGIN_URL') || !defined('SP_MERGE_VERSION')) {
            $user_id = get_current_user_id();
            error_log("SP Merge [User: " . intval($user_id) . "]: Plugin constants not defined");
            return;
        }
        
        wp_enqueue_script('jquery');
        
        // Check CSS file exists before enqueueing
        $css_path = SP_MERGE_PLUGIN_PATH . 'assets/css/admin.css';
        if (file_exists($css_path)) {
            wp_enqueue_style(
                'sp-merge-admin-css',
                SP_MERGE_PLUGIN_URL . 'assets/css/admin.css',
                [],
                SP_MERGE_VERSION
            );
        } else {
            $user_id = get_current_user_id();
            error_log("SP Merge [User: " . intval($user_id) . "]: CSS file not found: " . $css_path);
        }
        
        // Check JS file exists before enqueueing
        $js_path = SP_MERGE_PLUGIN_PATH . 'assets/js/admin.js';
        if (file_exists($js_path)) {
            wp_enqueue_script(
                'sp-merge-admin-js',
                SP_MERGE_PLUGIN_URL . 'assets/js/admin.js',
                ['jquery'],
                SP_MERGE_VERSION,
                true
            );
            
            wp_localize_script('sp-merge-admin-js', 'spMergeAjax', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('sp_merge_nonce'),
                'strings' => $this->get_localized_strings()
            ]);
        } else {
            $user_id = get_current_user_id();
            error_log("SP Merge [User: " . intval($user_id) . "]: JS file not found: " . $js_path);
        }
    }

    
    public function render_admin_page() {
        if (!current_user_can('edit_sp_players')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'sportspress-player-merge'));
        }
        
        $players = $this->get_all_players();
        if ($players === false) {
            wp_die(__('Error: Unable to load player data. Please try again.', 'sportspress-player-merge'));
        }
        
        try {
            $template_path = $this->get_secure_template_path();
            if ($template_path && $this->is_safe_to_include($template_path)) {
                include $template_path;
            } else {
                wp_die(__('Error: Admin page template not found or invalid.', 'sportspress-player-merge'));
            }
        } catch (Exception $e) {
            $user_id = get_current_user_id();
            error_log("SP Merge [User: " . intval($user_id) . "]: Template inclusion failed - " . $e->getMessage());
            wp_die(__('Error: Unable to load admin page template.', 'sportspress-player-merge'));
        }
    }

    
    private function get_secure_template_path() {
        $template_file = 'admin-page.php';
        $template_path = SP_MERGE_PLUGIN_PATH . 'includes/' . $template_file;
        
        // Ensure path is within plugin directory
        $real_plugin_path = realpath(SP_MERGE_PLUGIN_PATH);
        $real_template_path = realpath($template_path);
        
        if ($real_template_path && 
            strpos($real_template_path, $real_plugin_path) === 0 && 
            file_exists($real_template_path) && 
            validate_file($real_template_path) === 0) {
            return $real_template_path;
        }
        
        return false;
    }
    
    private function is_safe_to_include($file_path) {
        // Final safety check before inclusion
        $real_plugin_path = realpath(SP_MERGE_PLUGIN_PATH);
        $real_file_path = realpath($file_path);
        
        return ($real_file_path && 
                strpos($real_file_path, $real_plugin_path) === 0 && 
                file_exists($real_file_path) && 
                is_readable($real_file_path) && 
                pathinfo($real_file_path, PATHINFO_EXTENSION) === 'php');
    }
    
    private function get_all_players() {
        try {
            $args = [
                'post_type' => 'sp_player',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'orderby' => 'title',
                'order' => 'ASC'
            ];
            
            $player_posts = get_posts($args);
            if ($player_posts === false) {
                $user_id = get_current_user_id();
                error_log("SP Merge [User: " . intval($user_id) . "]: Failed to retrieve players from database");
                return false;
            }
            
            if (empty($player_posts)) {
                return [];
            }
            
            return array_map(function($player) {
                return [
                    'id' => $player->ID,
                    'name' => $player->post_title . ' (ID: ' . $player->ID . ')'
                ];
            }, $player_posts);
            
        } catch (Exception $e) {
            $user_id = get_current_user_id();
            error_log("SP Merge [User: " . intval($user_id) . "]: Exception in get_all_players(): " . $e->getMessage());
            return false;
        }
    }
    
    public function get_recent_backups() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'sp_merge_backups';
        
        // Check if backup table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            $user_id = get_current_user_id();
            error_log("SP Merge [User: " . intval($user_id) . "]: Backup table does not exist: " . $table_name);
            return [];
        }
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT backup_id, user_id, created_at, 
                    JSON_EXTRACT(backup_data, '$.primary_name') as primary_name,
                    JSON_EXTRACT(backup_data, '$.duplicate_names') as duplicate_names
             FROM {$table_name} 
             WHERE user_id = %d 
             ORDER BY created_at DESC 
             LIMIT 10",
            get_current_user_id()
        ));
        
        if ($results === null) {
            $user_id = get_current_user_id();
            error_log("SP Merge [User: " . intval($user_id) . "]: Database error in get_recent_backups(): " . $wpdb->last_error);
            return false;
        }
        
        if (empty($results)) {
            return [];
        }
        
        $backups = [];
        foreach ($results as $backup_record) {
            $backups[] = [
                'id' => $backup_record->backup_id,
                'date' => mysql2date('M j, Y g:i A', $backup_record->created_at),
                'primary_name' => json_decode($backup_record->primary_name, true) ?? __('Unknown', 'sportspress-player-merge'),
                'duplicate_names' => json_decode($backup_record->duplicate_names, true) ?? []
            ];
        }
        
        return $backups;
    }
    
    private function get_localized_strings() {
        return [
            'confirmMerge' => __('Are you sure you want to merge these players?', 'sportspress-player-merge'),
            'confirmRevert' => __('Are you sure you want to revert the last merge?', 'sportspress-player-merge'),
            'selectPlayers' => __('Please select a primary player and at least one duplicate.', 'sportspress-player-merge'),
            'mergeSuccess' => __('Players merged successfully!', 'sportspress-player-merge'),
            'revertSuccess' => __('Merge reverted successfully!', 'sportspress-player-merge'),
            'noMergeData' => __('No recent merge data found to revert.', 'sportspress-player-merge')
        ];
    }
}