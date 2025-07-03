<?php
/**
 * AJAX Handler Class
 * 
 * Handles all AJAX requests with security and validation
 */

if (!defined('ABSPATH')) {
    wp_die('Direct access denied.', 'Access Denied', array('response' => 403));
}

class SP_Merge_Ajax {
    
    public function deny_access() {
        wp_send_json_error();
    }
    
    public function preview_merge() {
        if (!$this->validate_request()) {
            return;
        }
        
        $input = $this->validate_merge_input();
        if (!$input) {
            return;
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $user_id = get_current_user_id();
            error_log(sprintf("SP Merge [User: %d]: Generating preview - Primary: %d, Duplicates: %s", intval($user_id), $input['primary_id'], implode(',', $input['duplicate_ids'])));
        }
        
        try {
            $preview = new SP_Merge_Preview();
            $preview_data = $preview->generate($input['primary_id'], $input['duplicate_ids']);
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $user_id = get_current_user_id();
                error_log(sprintf("SP Merge [User: %d]: Preview generated successfully", intval($user_id)));
            }
            
            wp_send_json_success(['preview' => $preview_data]);
        } catch (Exception $e) {
            $user_id = get_current_user_id();
            error_log(sprintf("SP Merge [User: %d]: Preview generation failed - %s", intval($user_id), $e->getMessage()));
            $this->send_error(__('Preview generation failed', 'sportspress-player-merge'), $e->getMessage());
        }
    }
    
    public function execute_merge() {
        if (!$this->validate_request()) {
            return;
        }
        
        $input = $this->validate_merge_input();
        if (!$input) {
            return;
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $user_id = get_current_user_id();
            error_log(sprintf("SP Merge [User: %d]: Executing merge - Primary: %d, Duplicates: %s", intval($user_id), $input['primary_id'], implode(',', $input['duplicate_ids'])));
        }
        
        try {
            $processor = new SP_Merge_Processor();
            $result = $processor->execute_merge($input['primary_id'], $input['duplicate_ids']);
            
            if ($result['success']) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $user_id = get_current_user_id();
                    error_log(sprintf("SP Merge [User: %d]: Merge completed successfully", intval($user_id)));
                }
                
                wp_send_json_success([
                    'message' => __('Merge completed successfully', 'sportspress-player-merge'),
                    'backup_id' => $result['backup_id']
                ]);
            } else {
                $this->send_error($result['message']);
            }
        } catch (Exception $e) {
            $user_id = get_current_user_id();
            error_log(sprintf("SP Merge [User: %d]: Merge execution failed - %s", intval($user_id), $e->getMessage()));
            $this->send_error(__('Merge operation failed', 'sportspress-player-merge'), $e->getMessage());
        }
    }
    
    public function revert_merge() {
        if (!$this->validate_request()) {
            return;
        }
        
        $backup_id = $this->get_backup_id();
        if (!$backup_id) {
            return;
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $user_id = get_current_user_id();
            error_log(sprintf("SP Merge [User: %d]: Reverting backup ID: %s", intval($user_id), $backup_id));
        }
        
        try {
            $backup = new SP_Merge_Backup();
            $result = $backup->revert($backup_id);
            
            if ($result['success']) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $user_id = get_current_user_id();
                    error_log(sprintf("SP Merge [User: %d]: Revert completed successfully", intval($user_id)));
                }
                
                wp_send_json_success(['message' => __('Merge reverted successfully', 'sportspress-player-merge')]);
            } else {
                $this->send_error($result['message']);
            }
        } catch (Exception $e) {
            $user_id = get_current_user_id();
            error_log(sprintf("SP Merge [User: %d]: Revert failed - %s", intval($user_id), $e->getMessage()));
            $this->send_error(__('Revert operation failed', 'sportspress-player-merge'), $e->getMessage());
        }
    }
    
    public function delete_backup() {
        if (!$this->validate_request()) {
            return;
        }
        
        if (!isset($_POST['backup_ids']) || !is_array($_POST['backup_ids'])) {
            $this->send_error('Invalid backup IDs format');
            return;
        }
        
        $backup_ids = array_map('sanitize_text_field', $_POST['backup_ids']);
        
        if (empty($backup_ids)) {
            $this->send_error(__('No backup IDs provided', 'sportspress-player-merge'));
            return;
        }
        
        try {
            $backup = new SP_Merge_Backup();
            $deleted_count = $backup->delete_backups($backup_ids);
            wp_send_json_success(['message' => sprintf(__('%d backup(s) deleted successfully', 'sportspress-player-merge'), $deleted_count)]);
        } catch (Exception $e) {
            $user_id = get_current_user_id();
            error_log(sprintf("SP Merge [User: %d]: Delete backup failed - %s", intval($user_id), $e->getMessage()));
            $this->send_error(__('Delete operation failed', 'sportspress-player-merge'), $e->getMessage());
        }
    }
    
    public function get_recent_backups() {
        if (!$this->validate_request()) {
            return;
        }
        
        try {
            $admin = new SP_Merge_Admin();
            $recent_backups = $admin->get_recent_backups();
            
            if ($recent_backups === false) {
                $this->send_error(__('Failed to retrieve backup data', 'sportspress-player-merge'));
                return;
            }
            
            ob_start();
            if (!empty($recent_backups)) {
                foreach ($recent_backups as $backup) {
                    echo '<div class="sp-backup-item">';
                    echo '<input type="checkbox" class="backup-checkbox" value="' . esc_attr($backup['id']) . '" id="backup-' . esc_attr($backup['id']) . '">';
                    echo '<label for="backup-' . esc_attr($backup['id']) . '">';
                    echo '<strong>' . esc_html($backup['primary_name']) . '</strong> ← ' . esc_html(implode(', ', $backup['duplicate_names']));
                    echo '</label>';
                    echo '<span class="sp-backup-date">' . esc_html($backup['date']) . '</span>';
                    echo '<div class="sp-backup-buttons">';
                    echo '<button type="button" class="button button-secondary sp-revert-backup" data-backup-id="' . esc_attr($backup['id']) . '">';
                    echo '<span class="dashicons dashicons-undo"></span> Revert';
                    echo '</button>';
                    echo '<button type="button" class="button button-secondary sp-delete-backup" data-backup-id="' . esc_attr($backup['id']) . '">';
                    echo '<span class="dashicons dashicons-trash"></span> Delete';
                    echo '</button>';
                    echo '</div>';
                    echo '</div>';
                }
            }
            $html = ob_get_clean();
            
            wp_send_json_success(['html' => $html]);
        } catch (Exception $e) {
            $user_id = get_current_user_id();
            error_log(sprintf("SP Merge [User: %d]: Get recent backups failed - %s", intval($user_id), $e->getMessage()));
            $this->send_error(__('Failed to load backups', 'sportspress-player-merge'), $e->getMessage());
        }
    }
    
    private function validate_merge_input() {
        $primary_id = intval($_POST['primary_player'] ?? 0);
        
        if (!isset($_POST['duplicate_players']) || !is_array($_POST['duplicate_players'])) {
            $this->send_error(__('Invalid input format', 'sportspress-player-merge'));
            return false;
        }
        
        $duplicate_ids = array_map('intval', $_POST['duplicate_players']);
        
        if (!$primary_id || empty($duplicate_ids)) {
            $this->send_error(__('Invalid player selection', 'sportspress-player-merge'));
            return false;
        }
        
        // Validate player IDs exist
        if (!get_post($primary_id) || get_post_type($primary_id) !== 'sp_player') {
            $this->send_error(__('Primary player not found', 'sportspress-player-merge'));
            return false;
        }
        
        foreach ($duplicate_ids as $duplicate_id) {
            if (!get_post($duplicate_id) || get_post_type($duplicate_id) !== 'sp_player') {
                $this->send_error(__('One or more duplicate players not found', 'sportspress-player-merge'));
                return false;
            }
        }
        
        return ['primary_id' => $primary_id, 'duplicate_ids' => $duplicate_ids];
    }
    
    private function get_backup_id() {
        $backup_id = sanitize_text_field($_POST['backup_id'] ?? '');
        
        if (empty($backup_id)) {
            $backup_id = get_user_meta(get_current_user_id(), 'sp_last_merge_backup', true);
        }
        
        if (empty($backup_id)) {
            $this->send_error(__('No backup data found to revert', 'sportspress-player-merge'));
            return false;
        }
        
        return $backup_id;
    }
    
    private function validate_request() {
        if (!current_user_can('edit_sp_players')) {
            $this->send_error(__('Insufficient permissions', 'sportspress-player-merge'));
            return false;
        }
        
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'sp_merge_nonce')) {
            $this->send_error(__('Security check failed', 'sportspress-player-merge'));
            return false;
        }
        
        return true;
    }
    
    private function send_error($message, $details = null) {
        $error_data = ['message' => $message];
        if ($details && defined('WP_DEBUG') && WP_DEBUG) {
            $error_data['details'] = $details;
        }
        wp_send_json_error($error_data);
    }
}