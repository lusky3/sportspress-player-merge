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
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf("SP Merge [User: %d]: Plugin constants not defined", intval($user_id)));
            }
            return;
        }
        
        wp_enqueue_script('jquery');
        
        // Securely check CSS file exists
        if ($this->is_safe_plugin_file('assets/css/admin.css')) {
            wp_enqueue_style(
                'sp-merge-admin-css',
                SP_MERGE_PLUGIN_URL . 'assets/css/admin.css',
                [],
                SP_MERGE_VERSION
            );
        } else {
            $user_id = get_current_user_id();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf("SP Merge [User: %d]: CSS file not found or invalid", intval($user_id)));
            }
        }
        
        // Securely check JS file exists
        if ($this->is_safe_plugin_file('assets/js/admin.js')) {
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
                'strings' => $this->get_localized_strings(),
                'debug' => defined('WP_DEBUG') && WP_DEBUG,
                'userCanEdit' => current_user_can('edit_sp_players')
            ]);
        } else {
            $user_id = get_current_user_id();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf("SP Merge [User: %d]: JS file not found or invalid", intval($user_id)));
            }
        }
    }

    
    public function render_admin_page() {
        if (!current_user_can('edit_sp_players')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'sportspress-player-merge'));
        }
        
        $players = $this->get_all_players();
        if ($players === null) {
            wp_die(__('Error: Unable to load player data. Please try again.', 'sportspress-player-merge'));
        }
        
        $template_path = $this->get_secure_template_path();
        if ($template_path) {
            include $template_path;
        } else {
            wp_die(__('Error: Admin page template not found or invalid.', 'sportspress-player-merge'));
        }
    }

    
    private function is_safe_plugin_file($relative_path) {
        if (!defined('SP_MERGE_PLUGIN_PATH') || empty($relative_path)) {
            return false;
        }
        
        $full_path = SP_MERGE_PLUGIN_PATH . $relative_path;
        $real_plugin_path = realpath(SP_MERGE_PLUGIN_PATH);
        $real_file_path = realpath($full_path);
        
        return ($real_file_path && 
                $real_plugin_path &&
                strpos($real_file_path, $real_plugin_path) === 0 && 
                file_exists($real_file_path) && 
                validate_file($real_file_path) === 0);
    }
    
    private function get_secure_template_path() {
        if ($this->is_safe_plugin_file('includes/admin-page.php')) {
            return SP_MERGE_PLUGIN_PATH . 'includes/admin-page.php';
        }
        return false;
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
                error_log(sprintf("SP Merge [User: %d]: Failed to retrieve players from database", intval($user_id)));
                return null;
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
            error_log(sprintf("SP Merge [User: %d]: Exception in get_all_players(): %s", intval($user_id), $e->getMessage()));
            return null;
        }
    }
    
    public function get_recent_backups() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'sp_merge_backups';
        
        // Check if backup table exists
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) !== $table_name) {
            $user_id = get_current_user_id();
            error_log(sprintf("SP Merge [User: %d]: Backup table does not exist: %s", intval($user_id), $table_name));
            return [];
        }
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT backup_id, user_id, created_at, 
                    JSON_EXTRACT(backup_data, '$.primary_name') as primary_name,
                    JSON_EXTRACT(backup_data, '$.duplicate_names') as duplicate_names
             FROM `%1s` 
             WHERE user_id = %d 
             ORDER BY created_at DESC 
             LIMIT 10",
            $table_name,
            get_current_user_id()
        ));
        
        if ($results === null) {
            $user_id = get_current_user_id();
            error_log(sprintf("SP Merge [User: %d]: Database error in get_recent_backups(): %s", intval($user_id), $wpdb->last_error));
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