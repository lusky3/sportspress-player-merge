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
        $user_id = get_current_user_id();
        
        try {
            $this->admin = new SP_Merge_Admin();
        } catch (Error $e) {
            error_log(sprintf("SP Merge [User: %d]: Admin component fatal error - %s", intval($user_id), $e->getMessage()));
            wp_die(__('Critical error initializing admin component.', 'sportspress-player-merge'));
        } catch (Exception $e) {
            error_log(sprintf("SP Merge [User: %d]: Admin component failed - %s", intval($user_id), $e->getMessage()));
            return;
        }
        
        try {
            $this->ajax = new SP_Merge_Ajax();
            $this->processor = new SP_Merge_Processor();
            $this->backup = new SP_Merge_Backup();
        } catch (Error $e) {
            error_log(sprintf("SP Merge [User: %d]: Core component fatal error - %s", intval($user_id), $e->getMessage()));
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>' . 
                     esc_html(__('SportsPress Player Merge: Critical initialization error.', 'sportspress-player-merge')) . 
                     '</p></div>';
            });
        } catch (Exception $e) {
            error_log(sprintf("SP Merge [User: %d]: Component initialization failed - %s", intval($user_id), $e->getMessage()));
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>' . 
                     esc_html(__('SportsPress Player Merge: Plugin initialization failed.', 'sportspress-player-merge')) . 
                     '</p></div>';
            });
        }
    }
    
    private function init_hooks() {
        // Only register hooks if components are properly initialized
        if ($this->admin && method_exists($this->admin, 'add_admin_menu')) {
            add_action('admin_menu', [$this->admin, 'add_admin_menu']);
        }
        if ($this->admin && method_exists($this->admin, 'enqueue_scripts')) {
            add_action('admin_enqueue_scripts', [$this->admin, 'enqueue_scripts']);
        }
        
        // AJAX handlers - only if component exists
        if ($this->ajax) {
            add_action('wp_ajax_sp_preview_merge', [$this->ajax, 'preview_merge']);
            add_action('wp_ajax_sp_execute_merge', [$this->ajax, 'execute_merge']);
            add_action('wp_ajax_sp_revert_merge', [$this->ajax, 'revert_merge']);
            add_action('wp_ajax_sp_delete_backup', [$this->ajax, 'delete_backup']);
            add_action('wp_ajax_sp_get_recent_backups', [$this->ajax, 'get_recent_backups']);
        }
        
        // No nopriv handlers - require login for all actions
    }
    
    public function get_processor() {
        return $this->processor;
    }
    
    public function get_backup() {
        return $this->backup;
    }
}