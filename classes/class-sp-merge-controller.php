<?php
/**
 * Main Controller Class
 * 
 * Coordinates all merge operations and initializes components
 */

if (!defined('ABSPATH')) {
    wp_die('Direct access denied.', 'Access Denied', array('response' => 403));
}

class SP_Merge_Controller {
    
    private $admin;
    private $ajax;
    private $processor;
    private $backup;
    
    public function __construct() {
        $this->init_components();
        $this->init_hooks();
    }
    
    private function init_components() {
        try {
            $this->admin = new SP_Merge_Admin();
            $this->ajax = new SP_Merge_Ajax();
            $this->processor = new SP_Merge_Processor();
            $this->backup = new SP_Merge_Backup();
        } catch (Exception $e) {
            $user_id = get_current_user_id();
            error_log("SP Merge [User: " . intval($user_id) . "]: Component initialization failed - " . $e->getMessage());
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error"><p>' . 
                     esc_html(__('SportsPress Player Merge: Plugin initialization failed. Please check error logs.', 'sportspress-player-merge')) . 
                     '</p></div>';
            });
            return;
        }
    }
    
    private function init_hooks() {
        add_action('admin_menu', [$this->admin, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this->admin, 'enqueue_scripts']);
        
        // AJAX handlers
        add_action('wp_ajax_sp_preview_merge', [$this->ajax, 'preview_merge']);
        add_action('wp_ajax_sp_execute_merge', [$this->ajax, 'execute_merge']);
        add_action('wp_ajax_sp_revert_merge', [$this->ajax, 'revert_merge']);
        add_action('wp_ajax_sp_delete_backup', [$this->ajax, 'delete_backup']);
        add_action('wp_ajax_sp_get_recent_backups', [$this->ajax, 'get_recent_backups']);
        
        // No nopriv handlers - require login for all actions
    }
    
    public function get_processor() {
        return $this->processor;
    }
    
    public function get_backup() {
        return $this->backup;
    }
}