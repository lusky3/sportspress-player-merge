<?php
/**
 * Main Player Merge Class
 * 
 * Handles all player merging functionality including:
 * - Admin interface creation
 * - AJAX request processing
 * - Data merging operations
 * - Backup and restore functionality
 */

if (!defined('ABSPATH')) {
    return;
}

class SportsPress_Player_Merge {
    
    /**
     * Constructor - Sets up WordPress hooks and actions
     */
    public function __construct() {
        // Add admin menu item under SportsPress Players
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // Register AJAX handlers for logged-in users with proper capability checks
        add_action('wp_ajax_sp_preview_merge', [$this, 'ajax_preview_merge']);
        add_action('wp_ajax_sp_execute_merge', [$this, 'ajax_execute_merge']);
        add_action('wp_ajax_sp_revert_merge', [$this, 'ajax_revert_merge']);
        add_action('wp_ajax_sp_delete_backup', [$this, 'ajax_delete_backup']);
        
        // Explicitly deny access for non-logged-in users
        add_action('wp_ajax_nopriv_sp_preview_merge', [$this, 'ajax_deny_access']);
        add_action('wp_ajax_nopriv_sp_execute_merge', [$this, 'ajax_deny_access']);
        add_action('wp_ajax_nopriv_sp_revert_merge', [$this, 'ajax_deny_access']);
        add_action('wp_ajax_nopriv_sp_delete_backup', [$this, 'ajax_deny_access']);
        
        // Enqueue scripts and styles for admin pages
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }
    
    /**
     * Deny access for non-logged-in users
     */
    public function ajax_deny_access() {
        wp_send_json_error();
    }


    
    /**
     * Add submenu page under SportsPress Players menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=sp_player',  // Parent menu slug
            'Player Merge Tool',              // Page title
            'Player Merge',                   // Menu title
            'edit_sp_players',               // Required capability
            'sp-player-merge',               // Menu slug
            [$this, 'render_admin_page']     // Callback function
        );
    }
    
    /**
     * Load CSS and JavaScript files for the admin page
     * Only loads on our specific admin page to avoid conflicts
     */
    public function enqueue_admin_assets($hook) {
        // Only load assets on our plugin page
        if (!$hook || strpos($hook, 'sp-player-merge') === false) {
            return;
        }
        
        // Enqueue jQuery (WordPress built-in)
        wp_enqueue_script('jquery');
        
        // Enqueue our custom CSS
        wp_enqueue_style(
            'sp-merge-admin-css',
            SP_MERGE_PLUGIN_URL . 'assets/css/admin.css',
            [],
            SP_MERGE_VERSION
        );
        
        // Enqueue our custom JavaScript
        wp_enqueue_script(
            'sp-merge-admin-js',
            SP_MERGE_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            SP_MERGE_VERSION,
            true
        );
        
        // Pass PHP data to JavaScript
        wp_localize_script('sp-merge-admin-js', 'spMergeAjax', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sp_merge_nonce'),
            'strings' => [
                'confirmMerge' => 'Are you sure you want to merge these players? This action will be logged for potential reversal.',
                'confirmRevert' => 'Are you sure you want to revert the last merge? This will restore the deleted players.',
                'selectPlayers' => 'Please select a primary player and at least one duplicate player.',
                'mergeSuccess' => 'Players merged successfully! You can revert this action if needed.',
                'revertSuccess' => 'Merge reverted successfully! Players have been restored.',
                'noMergeData' => 'No recent merge data found to revert.'
            ]
        ]);
    }
    
    /**
     * Render the main admin page HTML
     * Includes the form interface and containers for dynamic content
     */
    public function render_admin_page() {
        // Check if user has permission to edit players
        if (!current_user_can('edit_sp_players')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        // Get all players for the dropdowns
        $players = $this->get_all_players();
        
        // Include the admin page template
        $template_path = SP_MERGE_PLUGIN_PATH . 'includes/admin-page.php';
        if (file_exists($template_path) && validate_file($template_path) === 0) {
            include $template_path;
        } else {
            wp_die(__('Error: Admin page template not found or invalid.'));
        }
    }
    
    /**
     * Get all SportsPress players from the database
     * Returns formatted array with ID and display name
     * 
     * @return array Array of player data with 'id' and 'name' keys
     */
    private function get_all_players() {
        // Query arguments for getting all published players
        $args = [
            'post_type' => 'sp_player',
            'posts_per_page' => -1,           // Get all players (no limit)
            'post_status' => 'publish',       // Only published players
            'orderby' => 'title',            // Sort alphabetically
            'order' => 'ASC'                 // A to Z order
        ];
        
        // Get players from WordPress
        $player_posts = get_posts($args);
        if (empty($player_posts)) {
            return [];
        }
        
        // Format player data for display
        return array_map(function($player) {
            return [
                'id' => $player->ID,
                'name' => $player->post_title . ' (ID: ' . $player->ID . ')'
            ];
        }, $player_posts);
    }
    
    /**
     * AJAX Handler: Generate merge preview
     * Shows what data will be merged before execution
     */
    public function ajax_preview_merge() {
        // Check permissions
        if (!current_user_can('edit_sp_players')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }
        
        // Verify security nonce
        check_ajax_referer('sp_merge_nonce', 'nonce');
        
        // Get and validate input data
        $primary_id = isset($_POST['primary_player']) ? intval($_POST['primary_player']) : 0;
        $duplicate_ids = isset($_POST['duplicate_players']) && is_array($_POST['duplicate_players']) ? array_map('intval', $_POST['duplicate_players']) : [];
        
        // Validate required data
        if (!$primary_id || empty($duplicate_ids)) {
            wp_send_json_error(['message' => 'Invalid player selection']);
        }
        
        // Generate preview data
        try {
            $preview_data = $this->generate_merge_preview($primary_id, $duplicate_ids);
            wp_send_json_success(['preview' => $preview_data]);
        } catch (InvalidArgumentException $e) {
            error_log('SP Merge: Preview generation failed due to invalid argument - ' . $e->getMessage());
            wp_send_json_error(['message' => 'Invalid data provided: ' . esc_html($e->getMessage())]);
        } catch (RuntimeException $e) {
            error_log('SP Merge: Preview generation failed due to runtime error - ' . $e->getMessage());
            wp_send_json_error(['message' => 'An error occurred during preview generation. Please try again.']);
        } catch (Exception $e) {
            error_log('SP Merge: Unexpected error during preview generation - ' . $e->getMessage());
            wp_send_json_error(['message' => 'An unexpected error occurred. Please contact support.']);
        }
    }
    
    /**
     * AJAX Handler: Execute the player merge
     * Performs the actual merge operation and creates backup data
     */
    public function ajax_execute_merge() {
        // Check permissions
        if (!current_user_can('edit_sp_players')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }
        
        // Verify security nonce
        check_ajax_referer('sp_merge_nonce', 'nonce');
        
        // Get and validate input data
        $primary_id = isset($_POST['primary_player']) ? intval($_POST['primary_player']) : 0;
        $duplicate_ids = isset($_POST['duplicate_players']) ? array_map('intval', $_POST['duplicate_players']) : [];
        
        // Validate required data
        if (!$primary_id || empty($duplicate_ids)) {
            wp_send_json_error(['message' => 'Invalid player selection']);
        }
        
        // Execute the merge operation
        try {
            $result = $this->execute_player_merge($primary_id, $duplicate_ids);
            
            // Return appropriate response
            if ($result['success']) {
                wp_send_json_success([
                    'message' => 'Merge completed successfully',
                    'backup_id' => $result['backup_id']
                ]);
            } else {
                wp_send_json_error(['message' => $result['message']]);
            }
        } catch (Exception $e) {
            error_log('SP Merge: Execute merge failed - ' . $e->getMessage());
            wp_send_json_error(['message' => 'An error occurred during the merge operation: ' . esc_html($e->getMessage())]);
        }
    }
    
    /**
     * AJAX Handler: Revert the last merge operation
     * Restores deleted players and removes merged data
     */
    public function ajax_revert_merge() {
        // Check permissions
        if (!current_user_can('edit_sp_players')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }
        
        // Verify security nonce
        check_ajax_referer('sp_merge_nonce', 'nonce');
        
        // Get backup ID from request
        $backup_id = isset($_POST['backup_id']) ? sanitize_text_field($_POST['backup_id']) : '';
        
        // If no backup ID provided, try to get the last one for current user
        if (empty($backup_id)) {
            $backup_id = get_user_meta(get_current_user_id(), 'sp_last_merge_backup', true);
        }
        
        // Validate backup ID
        if (empty($backup_id)) {
            wp_send_json_error(array('message' => 'No backup data found to revert'));
        }
        
        // Execute revert operation
        $result = $this->revert_merge($backup_id);
        if ($result['success']) {
            wp_send_json_success(['message' => 'Merge reverted successfully']);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }
    
    /**
     * AJAX Handler: Delete backup(s)
     * Removes backup data without reverting
     */
    public function ajax_delete_backup() {
        // Check permissions
        if (!current_user_can('edit_sp_players')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }
        
        // Verify security nonce
        check_ajax_referer('sp_merge_nonce', 'nonce');
        
        // Get backup IDs from request
        $backup_ids = isset($_POST['backup_ids']) ? array_map('sanitize_text_field', $_POST['backup_ids']) : [];
        
        if (empty($backup_ids)) {
            wp_send_json_error(['message' => 'No backup IDs provided']);
        }
        
        $deleted_count = 0;
        $last_backup_id = get_user_meta(get_current_user_id(), 'sp_last_merge_backup', true);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'sp_merge_backups';
        
        foreach ($backup_ids as $backup_id) {
            $result = $wpdb->delete($table_name, ['backup_id' => $backup_id], ['%s']);
            if ($result !== false) {
                $deleted_count++;
                // Clear user meta if this was the last backup
                if ($backup_id === $last_backup_id) {
                    delete_user_meta(get_current_user_id(), 'sp_last_merge_backup');
                }
            }
        }
        
        wp_send_json_success(['message' => $deleted_count . ' backup(s) deleted successfully']);
    }
    
    /**
     * Generate detailed merge preview showing current vs merged data
     * 
     * @param int $primary_id ID of the primary player to keep
     * @param array $duplicate_ids Array of duplicate player IDs to merge
     * @return string HTML table showing merge preview
     */
    private function generate_merge_preview($primary_id, $duplicate_ids) {
        // Get player data
        $primary = $this->get_player_details($primary_id);
        $duplicates = array_map([$this, 'get_player_details'], $duplicate_ids);
        
        // Start building preview HTML
        $html = '<div class="merge-preview-container">';
        
        // Player names section
        $html .= '<div class="preview-section">';
        $html .= '<h4>Players Being Merged</h4>';
        $html .= '<p><strong>Primary Player (will be kept):</strong> ' . esc_html($primary['name']) . '</p>';
        $html .= '<p><strong>Duplicate Players (will be deleted):</strong></p>';
        $html .= '<ul>';
        foreach ($duplicates as $duplicate) {
            $html .= '<li>' . esc_html($duplicate['name']) . '</li>';
        }
        $html .= '</ul>';
        $html .= '</div>';
        
        // Data comparison table
        $html .= '<div class="preview-section">';
        $html .= '<h4>Data Merge Preview</h4>';
        $html .= '<table class="merge-preview-table">';
        $html .= '<thead>';
        $html .= '<tr><th>Data Type</th><th>Current (Primary)</th><th>Incoming (Duplicates)</th><th>Result After Merge</th></tr>';
        $html .= '</thead>';
        $html .= '<tbody>';
        
        // Current Teams
        $primary_current_team = $this->get_player_current_team($primary_id);
        $duplicate_current_teams = [];
        foreach ($duplicate_ids as $dup_id) {
            $team = $this->get_player_current_team($dup_id);
            if ($team) $duplicate_current_teams[] = $team;
        }
        $unique_dup_current = array_unique($duplicate_current_teams);
        
        // Result logic: combine all current teams (primary + duplicates)
        $all_current_teams = [];
        if ($primary_current_team) $all_current_teams[] = $primary_current_team;
        $all_current_teams = array_merge($all_current_teams, $unique_dup_current);
        $result_current_teams = array_unique($all_current_teams);
        
        $html .= '<tr>';
        $html .= '<td><strong>Current Team</strong></td>';
        $html .= '<td>' . ($primary_current_team ?: 'None') . '</td>';
        $html .= '<td>' . (empty($unique_dup_current) ? 'None' : implode(', ', $unique_dup_current)) . '</td>';
        $html .= '<td>' . (empty($result_current_teams) ? 'None' : implode(', ', $result_current_teams)) . '</td>';
        $html .= '</tr>';
        
        // Past Teams (expandable)
        $primary_past_teams = $this->get_player_past_teams($primary_id);
        $all_duplicate_past_teams = [];
        foreach ($duplicate_ids as $dup_id) {
            $all_duplicate_past_teams = array_merge($all_duplicate_past_teams, $this->get_player_past_teams($dup_id));
        }
        $unique_duplicate_past_teams = array_unique($all_duplicate_past_teams);
        $merged_past_teams = array_unique(array_merge($primary_past_teams, $unique_duplicate_past_teams));
        
        $html .= '<tr>';
        $html .= '<td><strong>Past Team(s)</strong></td>';
        $html .= '<td>' . $this->format_expandable_list($primary_past_teams, 'primary-past-teams') . '</td>';
        $html .= '<td>' . $this->format_expandable_list($unique_duplicate_past_teams, 'duplicate-past-teams') . '</td>';
        $html .= '<td>' . $this->format_expandable_list($merged_past_teams, 'merged-past-teams') . '</td>';
        $html .= '</tr>';
        
        // Leagues/Divisions
        $primary_leagues = $this->get_player_leagues($primary_id);
        $all_duplicate_leagues = [];
        foreach ($duplicate_ids as $dup_id) {
            $all_duplicate_leagues = array_merge($all_duplicate_leagues, $this->get_player_leagues($dup_id));
        }
        $unique_duplicate_leagues = array_unique($all_duplicate_leagues);
        $merged_leagues = array_unique(array_merge($primary_leagues, $unique_duplicate_leagues));
        
        $html .= '<tr>';
        $html .= '<td><strong>' . $this->get_taxonomy_label('sp_league') . '</strong></td>';
        $html .= '<td>' . $this->format_expandable_list($primary_leagues, 'primary-leagues') . '</td>';
        $html .= '<td>' . $this->format_expandable_list($unique_duplicate_leagues, 'duplicate-leagues') . '</td>';
        $html .= '<td>' . $this->format_expandable_list($merged_leagues, 'merged-leagues') . '</td>';
        $html .= '</tr>';
        
        // Seasons
        $primary_seasons = $this->get_player_seasons($primary_id);
        $all_duplicate_seasons = [];
        foreach ($duplicate_ids as $dup_id) {
            $all_duplicate_seasons = array_merge($all_duplicate_seasons, $this->get_player_seasons($dup_id));
        }
        $unique_duplicate_seasons = array_unique($all_duplicate_seasons);
        $merged_seasons = array_unique(array_merge($primary_seasons, $unique_duplicate_seasons));
        
        $html .= '<tr>';
        $html .= '<td><strong>' . $this->get_taxonomy_label('sp_season') . '</strong></td>';
        $html .= '<td>' . $this->format_expandable_list($primary_seasons, 'primary-seasons') . '</td>';
        $html .= '<td>' . $this->format_expandable_list($unique_duplicate_seasons, 'duplicate-seasons') . '</td>';
        $html .= '<td>' . $this->format_expandable_list($merged_seasons, 'merged-seasons') . '</td>';
        $html .= '</tr>';
        
        // Positions
        $primary_positions = $this->get_player_positions($primary_id);
        $all_duplicate_positions = [];
        foreach ($duplicate_ids as $dup_id) {
            $all_duplicate_positions = array_merge($all_duplicate_positions, $this->get_player_positions($dup_id));
        }
        $unique_duplicate_positions = array_unique($all_duplicate_positions);
        $merged_positions = array_unique(array_merge($primary_positions, $unique_duplicate_positions));
        
        $html .= '<tr>';
        $html .= '<td><strong>' . $this->get_taxonomy_label('sp_position') . '</strong></td>';
        $html .= '<td>' . (empty($primary_positions) ? 'None' : implode(', ', $primary_positions)) . '</td>';
        $html .= '<td>' . (empty($unique_duplicate_positions) ? 'None' : implode(', ', $unique_duplicate_positions)) . '</td>';
        $html .= '<td>' . (empty($merged_positions) ? 'None' : implode(', ', $merged_positions)) . '</td>';
        $html .= '</tr>';
        
        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '</div>';
        
        // Add JavaScript for expandable lists
        $html .= '<script>
        jQuery(document).ready(function($) {
            $(".sp-expand-toggle").click(function(e) {
                e.preventDefault();
                var target = $(this).data("target");
                $("#" + target).toggle();
                $(this).text($("#" + target).is(":visible") ? "Show Less" : "Show More");
            });
        });
        </script>';
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Get detailed player information
     * 
     * @param int $player_id Player ID
     * @return array Player details
     */
    private function get_player_details($player_id) {
        $player = get_post($player_id);
        
        if (!$player || $player->post_type !== 'sp_player') {
            return ['name' => 'Unknown Player', 'id' => $player_id];
        }
        
        return [
            'id' => $player->ID,
            'name' => $player->post_title,
            'post' => $player
        ];
    }
    
    /**
     * Get teams associated with a player
     * 
     * @param int $player_id Player ID
     * @return array Team names
     */
    private function get_player_teams($player_id) {
        // First try taxonomy
        $team_terms = wp_get_object_terms($player_id, 'sp_team', ['fields' => 'names']);
        if (!empty($team_terms) && !is_wp_error($team_terms)) {
            return $team_terms;
        }
        
        // Fallback to meta fields if no taxonomy teams
        $team_names = [];
        $meta_keys = ['sp_current_team', 'sp_past_team', 'sp_team'];
        
        foreach ($meta_keys as $key) {
            $team_ids = get_post_meta($player_id, $key);
            foreach ($team_ids as $team_id) {
                if ($team_id && $team_id != '0' && is_numeric($team_id)) {
                    $team_post = get_post($team_id);
                    if ($team_post && $team_post->post_type === 'sp_team') {
                        $team_names[] = $team_post->post_title;
                    }
                }
            }
        }
        
        return array_unique($team_names);
    }
    
    /**
     * Get positions associated with a player
     * 
     * @param int $player_id Player ID
     * @return array Position names
     */
    private function get_player_positions($player_id) {
        $position_terms = wp_get_object_terms($player_id, 'sp_position', ['fields' => 'names']);
        return is_array($position_terms) && !is_wp_error($position_terms) ? $position_terms : [];
    }
    
    /**
     * Get current team for a player
     * 
     * @param int $player_id Player ID
     * @return string Current team name or null
     */
    private function get_player_current_team($player_id) {
        // Get current team from meta
        $current_team_ids = get_post_meta($player_id, 'sp_current_team');
        if (!empty($current_team_ids)) {
            $current_team_ids = array_reverse($current_team_ids);
            foreach ($current_team_ids as $team_id) {
                if ($team_id && $team_id != '0' && is_numeric($team_id)) {
                    $team_post = get_post($team_id);
                    if ($team_post && $team_post->post_type === 'sp_team') {
                        return $team_post->post_title;
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Get past teams for a player
     * 
     * @param int $player_id Player ID
     * @return array Past team names
     */
    private function get_player_past_teams($player_id) {
        $team_names = [];
        
        // Get past teams from sp_past_team meta
        $past_team_ids = get_post_meta($player_id, 'sp_past_team');
        
        foreach ($past_team_ids as $team_id) {
            if ($team_id && $team_id != '0' && is_numeric($team_id)) {
                $team_post = get_post($team_id);
                if ($team_post && $team_post->post_type === 'sp_team') {
                    $team_names[] = $team_post->post_title;
                }
            }
        }
        
        return array_unique($team_names);
    }
    
    /**
     * Get leagues associated with a player
     * 
     * @param int $player_id Player ID
     * @return array League names
     */
    private function get_player_leagues($player_id) {
        $league_terms = wp_get_object_terms($player_id, 'sp_league', ['fields' => 'names']);
        return is_array($league_terms) && !is_wp_error($league_terms) ? $league_terms : [];
    }
    
    /**
     * Get seasons associated with a player
     * 
     * @param int $player_id Player ID
     * @return array Season names
     */
    private function get_player_seasons($player_id) {
        $season_terms = wp_get_object_terms($player_id, 'sp_season', ['fields' => 'names']);
        return is_array($season_terms) && !is_wp_error($season_terms) ? $season_terms : [];
    }
    
    /**
     * Format a list as expandable if it has many items
     * 
     * @param array $items List of items
     * @param string $id Unique ID for the expandable section
     * @return string Formatted HTML
     */
    private function format_expandable_list($items, $id) {
        if (empty($items)) {
            return 'None';
        }
        
        if (count($items) <= 3) {
            return implode(', ', $items);
        }
        
        $visible = array_slice($items, 0, 2);
        $hidden = array_slice($items, 2);
        
        $html = implode(', ', $visible);
        $html .= ' <a href="#" class="sp-expand-toggle" data-target="' . $id . '">+' . count($hidden) . ' more</a>';
        $html .= '<div id="' . $id . '" style="display:none; margin-top:5px; font-size:0.9em;">';
        $html .= implode(', ', $hidden);
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Execute the actual player merge operation
     * Creates backup data before merging for potential revert
     * 
     * @param int $primary_id Primary player ID
     * @param array $duplicate_ids Duplicate player IDs
     * @return array Result with success status and backup ID
     */
    private function execute_player_merge($primary_id, $duplicate_ids) {
        try {
            // Create backup before making changes
            $backup_id = $this->create_merge_backup($primary_id, $duplicate_ids);
            
            // Merge each duplicate into primary
            foreach ($duplicate_ids as $duplicate_id) {
                $this->merge_single_player($primary_id, $duplicate_id);
            }
            
            // Delete duplicate players
            foreach ($duplicate_ids as $duplicate_id) {
                $deleted = wp_delete_post($duplicate_id, true);
                if (!$deleted) {
                    error_log('SP Merge: Failed to delete player ID ' . intval($duplicate_id));
                } else {
                    error_log('SP Merge: Successfully deleted player ID ' . intval($duplicate_id));
                }
            }
            
            error_log('SP Merge: Completed merge of player ' . intval($primary_id) . ' with duplicates: ' . implode(', ', array_map('intval', $duplicate_ids)));
            
            return [
                'success' => true,
                'backup_id' => $backup_id
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Merge failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
            ];
        }
    }
    
    /**
     * Merge a single duplicate player into the primary player
     * Handles teams, statistics, and other metadata
     * 
     * @param int $primary_id Primary player ID
     * @param int $duplicate_id Duplicate player ID
     */
    private function merge_single_player($primary_id, $duplicate_id) {
        // Get current teams for both players
        $primary_teams = wp_get_object_terms($primary_id, 'sp_team', ['fields' => 'ids']);
        $duplicate_teams = wp_get_object_terms($duplicate_id, 'sp_team', ['fields' => 'ids']);
        
        // Ensure we have arrays
        $primary_teams = is_array($primary_teams) ? $primary_teams : [];
        $duplicate_teams = is_array($duplicate_teams) ? $duplicate_teams : [];
        
        // Merge and deduplicate teams
        $merged_teams = array_unique(array_merge($primary_teams, $duplicate_teams));
        
        // Only update if there are new teams to add
        if (count($merged_teams) > count($primary_teams)) {
            wp_set_object_terms($primary_id, $merged_teams, 'sp_team');
        }
        
        // Merge other taxonomies with proper deduplication
        $taxonomies = ['sp_league', 'sp_season', 'sp_position'];
        foreach ($taxonomies as $taxonomy) {
            $primary_terms = wp_get_object_terms($primary_id, $taxonomy, ['fields' => 'ids']);
            $duplicate_terms = wp_get_object_terms($duplicate_id, $taxonomy, ['fields' => 'ids']);
            
            $primary_terms = is_array($primary_terms) ? $primary_terms : [];
            $duplicate_terms = is_array($duplicate_terms) ? $duplicate_terms : [];
            
            $merged_terms = array_unique(array_merge($primary_terms, $duplicate_terms));
            
            // Only update if there are new terms to add
            if (count($merged_terms) > count($primary_terms)) {
                wp_set_object_terms($primary_id, $merged_terms, $taxonomy);
            }
        }
        
        // Merge custom meta data (avoid duplicates)
        $duplicate_meta = get_post_meta($duplicate_id);
        $primary_meta = get_post_meta($primary_id);
        
        foreach ($duplicate_meta as $key => $values) {
            // Only merge SportsPress specific meta
            if (strpos($key, 'sp_') === 0) {
                foreach ($values as $value) {
                    // Check if this exact value already exists for the primary player
                    $existing_values = isset($primary_meta[$key]) ? $primary_meta[$key] : [];
                    if (!in_array($value, $existing_values)) {
                        add_post_meta($primary_id, $key, $value);
                    }
                }
            }
        }
        
        // Update references in events and other posts
        $this->update_player_references($primary_id, $duplicate_id);
    }
    
    /**
     * Update all references to the duplicate player with the primary player
     * 
     * @param int $primary_id Primary player ID
     * @param int $duplicate_id Duplicate player ID to replace
     */
private function update_player_references($primary_id, $duplicate_id) {
    global $wpdb;
    
    // Update meta values that reference the duplicate player
    $sql = $wpdb->prepare(
        "UPDATE {$wpdb->postmeta} 
        SET meta_value = REPLACE(meta_value, %s, %s) 
        WHERE meta_key LIKE %s 
        AND meta_value LIKE %s",
        $duplicate_id,
        $primary_id,
        'sp_%',
        '%' . $wpdb->esc_like($duplicate_id) . '%'
    );
    $result = $wpdb->query($wpdb->prepare($sql));
    if ($result === false) {
        error_log('SP Merge: Failed to update player references');
    }
}
    
    /**
     * Create backup data for potential merge revert
     * Stores complete player data and references
     * 
     * @param int $primary_id Primary player ID
     * @param array $duplicate_ids Duplicate player IDs
     * @return string Backup ID for later revert
     */
    private function create_merge_backup($primary_id, $duplicate_ids) {
        // Generate unique backup ID
        $backup_id = 'merge_' . time() . '_' . wp_generate_password(8, false);
        
        // Collect backup data with player names for display
        $backup_data = [
            'timestamp' => current_time('mysql'),
            'primary_id' => $primary_id,
            'primary_name' => get_the_title($primary_id),
            'duplicate_ids' => $duplicate_ids,
            'duplicate_names' => [],
            'primary_backup' => $this->backup_player_data($primary_id),
            'duplicate_backups' => [],
            'reference_changes' => []
        ];
        
        // Backup each duplicate player and store names
        foreach ($duplicate_ids as $duplicate_id) {
            $backup_data['duplicate_backups'][$duplicate_id] = $this->backup_player_data($duplicate_id);
            $backup_data['duplicate_names'][$duplicate_id] = get_the_title($duplicate_id);
            
            // Track reference changes for this duplicate
            $backup_data['reference_changes'][$duplicate_id] = $this->find_player_references($duplicate_id);
        }
        
        // Store backup data in custom table
        global $wpdb;
        $table_name = $wpdb->prefix . 'sp_merge_backups';
        
        $result = $wpdb->insert(
            $table_name,
            [
                'backup_id' => $backup_id,
                'user_id' => get_current_user_id(),
                'backup_data' => wp_json_encode($backup_data),
                'created_at' => current_time('mysql')
            ],
            ['%s', '%d', '%s', '%s']
        );
        
        if ($result === false) {
            error_log('SP Merge: Failed to create backup - ' . $wpdb->last_error);
            throw new Exception('Failed to create backup');
        }
        
        // Store backup ID in user meta for easy access
        update_user_meta(get_current_user_id(), 'sp_last_merge_backup', $backup_id);
        
        // Schedule cleanup of old backups
        $this->cleanup_old_backups();
        
        return $backup_id;
    }
    
    /**
     * Create complete backup of a single player's data
     * 
     * @param int $player_id Player ID to backup
     * @return array Complete player data backup
     */
    private function backup_player_data($player_id) {
        $player = get_post($player_id);
        
        // Get taxonomy term IDs directly from database to ensure accuracy
        global $wpdb;
        $term_relationships = $wpdb->get_results($wpdb->prepare(
            "SELECT tt.taxonomy, tr.term_taxonomy_id, t.term_id, t.name, t.slug
             FROM wp_term_relationships tr
             JOIN wp_term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             JOIN wp_terms t ON tt.term_id = t.term_id
             WHERE tr.object_id = %d
             AND tt.taxonomy IN ('sp_team', 'sp_league', 'sp_season', 'sp_position')",
            $player_id
        ));
        
        $taxonomies = [];
        foreach ($term_relationships as $rel) {
            if (!isset($taxonomies[$rel->taxonomy])) {
                $taxonomies[$rel->taxonomy] = [];
            }
            $taxonomies[$rel->taxonomy][] = [
                'term_id' => $rel->term_id,
                'name' => $rel->name,
                'slug' => $rel->slug,
                'term_taxonomy_id' => $rel->term_taxonomy_id
            ];
        }
        
        return [
            'post_data' => $player,
            'meta_data' => get_post_meta($player_id),
            'taxonomies' => $taxonomies
        ];
    }
    
    /**
     * Revert a merge operation using backup data
     * 
     * @param string $backup_id Backup ID to revert
     * @return array Result with success status
     */
    private function revert_merge($backup_id) {
        // Get backup data from custom table
        global $wpdb;
        $table_name = $wpdb->prefix . 'sp_merge_backups';
        
        $backup_row = $wpdb->get_row($wpdb->prepare(
            "SELECT backup_data FROM {$wpdb->prefix}sp_merge_backups WHERE backup_id = %s",
            $backup_id
        ));
        
        $backup_data = $backup_row ? json_decode($backup_row->backup_data, true) : null;
        
        if (!$backup_data) {
            error_log('SP Merge: Backup data not found for ID ' . preg_replace('/[^\w\-]/', '', $backup_id)); // Sanitize input before logging
            return ['success' => false, 'message' => 'Backup data not found'];
        }
        
        try {
            // Validate backup data structure
            if (!isset($backup_data['primary_backup']) || !isset($backup_data['duplicate_backups'])) {
                return ['success' => false, 'message' => 'Invalid backup data structure'];
            }
            
            // Revert player references first
            if (isset($backup_data['reference_changes'])) {
                foreach ($backup_data['reference_changes'] as $duplicate_id => $references) {
                    $this->revert_player_references($backup_data['primary_id'], $duplicate_id, $references);
                }
            }
            
            // Restore primary player to original state
            $this->restore_player_data($backup_data['primary_id'], $backup_data['primary_backup']);
            
            // Recreate deleted duplicate players
            foreach ($backup_data['duplicate_backups'] as $duplicate_id => $duplicate_backup) {
                if (isset($duplicate_backup['post_data'])) {
                    $this->recreate_player($duplicate_id, $duplicate_backup);
                }
            }
            
            // Clean up backup data only after successful restore
            $wpdb->delete($table_name, ['backup_id' => $backup_id], ['%s']);
            delete_user_meta(get_current_user_id(), 'sp_last_merge_backup');
            
            return ['success' => true];
            
        } catch (Exception $e) {
            error_log('SP Merge: Revert failed - ' . $e->getMessage());
            return ['success' => false, 'message' => 'Revert failed: ' . esc_html($e->getMessage())];
        }
    }
    
    /**
     * Restore a player's data from backup
     * 
     * @param int $player_id Player ID
     * @param array $backup_data Backup data to restore
     */
    private function restore_player_data($player_id, $backup_data) {
        // Validate backup data before proceeding
        if (!isset($backup_data['meta_data']) || !isset($backup_data['taxonomies'])) {
            error_log('SP Merge: Invalid backup data for player ' . $player_id);
            return;
        }
        
        // Only clear and restore meta data if we have valid backup data
        if (!empty($backup_data['meta_data']) && is_array($backup_data['meta_data'])) {
            // Get current meta to preserve non-SportsPress data
            $current_meta = get_post_meta($player_id);
            
            // Only clear SportsPress meta that exists in backup
            foreach ($backup_data['meta_data'] as $key => $backup_values) {
                if (strpos($key, 'sp_') === 0) {
                    delete_post_meta($player_id, $key);
                }
            }
            
            // Restore meta data with proper unserialization
            foreach ($backup_data['meta_data'] as $key => $values) {
                if (is_array($values)) {
                    foreach ($values as $value) {
                        // Unserialize the value if it was serialized
                        $restored_value = maybe_unserialize($value);
                        add_post_meta($player_id, $key, $restored_value);
                    }
                }
            }
        }
        
        // Restore taxonomies only if we have backup data
        if (!empty($backup_data['taxonomies']) && is_array($backup_data['taxonomies'])) {
            foreach ($backup_data['taxonomies'] as $taxonomy => $terms) {
                if (!empty($terms) && is_array($terms)) {
                    $term_ids = [];
                    foreach ($terms as $term) {
                        if (is_array($term) && isset($term['term_id'])) {
                            $term_ids[] = intval($term['term_id']);
                        }
                    }
                    if (!empty($term_ids)) {
                        // Clear existing terms first
                        wp_set_object_terms($player_id, [], $taxonomy);
                        // Set the backed up terms
                        wp_set_object_terms($player_id, $term_ids, $taxonomy);
                    }
                }
            }
        }
        
        // Trigger SportsPress statistics recalculation if assignments exist
        $assignments = get_post_meta($player_id, 'sp_assignments');
        if (!empty($assignments)) {
            // Force recalculation of player statistics
            $this->recalculate_player_statistics($player_id);
        }
    }
    
    /**
     * Recreate a deleted player from backup data with original ID
     * 
     * @param int $original_id Original player ID
     * @param array $backup_data Backup data to recreate from
     * @return int Restored player ID
     */
    private function recreate_player($original_id, $backup_data) {
        global $wpdb;
        
        // Recreate the post with original ID
        $post_data = (array) $backup_data['post_data'];
        $post_data['ID'] = $original_id; // Keep original ID
        $post_data['post_status'] = 'publish';
        
        // Insert directly into database to preserve ID
        $result = $wpdb->insert(
            $wpdb->posts,
            [
                'ID' => $original_id,
                'post_author' => $post_data['post_author'],
                'post_date' => $post_data['post_date'],
                'post_date_gmt' => $post_data['post_date_gmt'],
                'post_content' => $post_data['post_content'],
                'post_title' => $post_data['post_title'],
                'post_excerpt' => $post_data['post_excerpt'],
                'post_status' => 'publish',
                'comment_status' => $post_data['comment_status'],
                'ping_status' => $post_data['ping_status'],
                'post_password' => $post_data['post_password'],
                'post_name' => $post_data['post_name'],
                'to_ping' => $post_data['to_ping'],
                'pinged' => $post_data['pinged'],
                'post_modified' => current_time('mysql'),
                'post_modified_gmt' => current_time('mysql', 1),
                'post_content_filtered' => $post_data['post_content_filtered'],
                'post_parent' => $post_data['post_parent'],
                'guid' => $post_data['guid'],
                'menu_order' => $post_data['menu_order'],
                'post_type' => 'sp_player',
                'post_mime_type' => $post_data['post_mime_type'],
                'comment_count' => $post_data['comment_count']
            ],
            [
                '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d'
            ]
        );
        
        if ($result !== false) {
            // Restore meta data
            if (isset($backup_data['meta_data']) && is_array($backup_data['meta_data'])) {
                foreach ($backup_data['meta_data'] as $key => $values) {
                    if (is_array($values)) {
                        foreach ($values as $value) {
                            // Unserialize the value if it was serialized
                            $restored_value = maybe_unserialize($value);
                            add_post_meta($original_id, $key, $restored_value);
                        }
                    }
                }
            }
            
            // Restore taxonomies
            if (isset($backup_data['taxonomies'])) {
                foreach ($backup_data['taxonomies'] as $taxonomy => $terms) {
                    if (!empty($terms) && is_array($terms)) {
                        $term_ids = [];
                        foreach ($terms as $term) {
                            if (is_array($term) && isset($term['term_id'])) {
                                $term_ids[] = intval($term['term_id']);
                            }
                        }
                        if (!empty($term_ids)) {
                            wp_set_object_terms($original_id, $term_ids, $taxonomy);
                        }
                    }
                }
            }
            
            // Clear WordPress cache for this post
            clean_post_cache($original_id);
            
            // Trigger SportsPress statistics recalculation if assignments exist
            $assignments = get_post_meta($original_id, 'sp_assignments');
            if (!empty($assignments)) {
                // Force recalculation of player statistics
                $this->recalculate_player_statistics($original_id);
            }
        }
        
        return $original_id;
    }
    
    /**
     * Get recent backups for display
     * 
     * @return array Recent backup data
     */
    private function get_recent_backups() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'sp_merge_backups';
        
        $backups = $wpdb->get_results($wpdb->prepare(
            "SELECT backup_id, backup_data FROM `{$table_name}` 
             WHERE user_id = %d 
             ORDER BY created_at DESC LIMIT 5",
            get_current_user_id()
        ));
        
        $recent = [];
        foreach ($backups as $backup) {
            $data = json_decode($backup->backup_data, true);
            if ($data && isset($data['timestamp'])) {
                $backup_id = $backup->backup_id;
                
                $primary_name = isset($data['primary_name']) ? 
                    esc_html($data['primary_name']) . ' (ID: ' . intval($data['primary_id']) . ')' : 
                    (get_the_title(intval($data['primary_id'])) ?: 'Unknown Player');
                
                $duplicate_names = [];
                if (isset($data['duplicate_names'])) {
                    foreach ($data['duplicate_names'] as $dup_id => $dup_name) {
                        $duplicate_names[] = esc_html($dup_name) . ' (ID: ' . intval($dup_id) . ')';
                    }
                } else {
                    foreach ($data['duplicate_ids'] as $dup_id) {
                        $duplicate_names[] = get_the_title(intval($dup_id)) ?: 'Unknown Player';
                    }
                }
                
                $recent[] = [
                    'id' => $backup_id,
                    'primary_name' => $primary_name,
                    'duplicate_names' => $duplicate_names,
                    'date' => date('M j, Y g:i A', strtotime($data['timestamp']))
                ];
            }
        }
        
        return $recent;
    }
    
    /**
     * Get the proper label for a SportsPress taxonomy
     * Respects custom labels set by SportsPress extensions
     * 
     * @param string $taxonomy Taxonomy name
     * @return string Taxonomy label
     */
    private function get_taxonomy_label($taxonomy) {
        // Get the taxonomy object
        $tax_obj = get_taxonomy($taxonomy);
        
        if ($tax_obj && isset($tax_obj->labels->name)) {
            return $tax_obj->labels->name;
        }
        
        // Fallback to default labels if taxonomy not found
        $defaults = [
            'sp_league' => 'Leagues',
            'sp_season' => 'Seasons', 
            'sp_position' => 'Positions',
            'sp_team' => 'Teams'
        ];
        
        return $defaults[$taxonomy] ?? ucfirst(str_replace('sp_', '', $taxonomy));
    }
    
    /**
     * Find all references to a player in the database
     * 
     * @param int $player_id Player ID to find references for
     * @return array Array of posts that reference this player
     */
    private function find_player_references($player_id) {
        global $wpdb;
        
        $references = [];
        
        // Find direct meta references
        $meta_refs = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} 
             WHERE meta_value LIKE %s AND meta_key LIKE 'sp_%'",
            '%' . $wpdb->esc_like($player_id) . '%'
        ));
        
        foreach ($meta_refs as $ref) {
            $references[] = [
                'post_id' => $ref->post_id,
                'meta_key' => $ref->meta_key,
                'meta_value' => $ref->meta_value
            ];
        }
        
        return $references;
    }
    
    /**
     * Revert player references from primary back to duplicate
     * 
     * @param int $primary_id Primary player ID
     * @param int $duplicate_id Duplicate player ID
     * @param array $original_references Original references before merge
     */
    private function revert_player_references($primary_id, $duplicate_id, $original_references) {
        global $wpdb;
        
        // Revert references from primary back to duplicate
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} 
             SET meta_value = REPLACE(meta_value, %s, %s) 
             WHERE meta_key LIKE 'sp_%' 
             AND meta_value LIKE %s",
            $primary_id,
            $duplicate_id,
            '%' . $wpdb->esc_like($primary_id) . '%'
        ));
        
        error_log('SP Merge: Reverted references from player ' . $primary_id . ' back to ' . $duplicate_id);
    }
    
    /**
     * Recalculate player statistics from their assignments
     * 
     * @param int $player_id Player ID
     */
    private function recalculate_player_statistics($player_id) {
        // Try SportsPress action hook first
        do_action('sportspress_calculate_player_stats', $player_id);
        
        // If SportsPress class exists, use it directly
        if (class_exists('SP_Player')) {
            $player = new SP_Player($player_id);
            if (method_exists($player, 'update_statistics')) {
                $player->update_statistics();
            }
        }
        
        // Alternative: Clear statistics cache to force recalculation on next view
        delete_post_meta($player_id, 'sp_statistics_cache');
        
        // Log the recalculation attempt
        error_log('SP Merge: Triggered statistics recalculation for player ' . $player_id);
    }
    
    /**
     * Clean up backups older than 30 days
     * Removes old backup data to prevent database bloat
     */
    private function cleanup_old_backups() {
        global $wpdb;
        
        // Get configurable backup retention period (default 30 days)
        $retention_days = defined('SP_MERGE_BACKUP_RETENTION_DAYS') ? SP_MERGE_BACKUP_RETENTION_DAYS : 30;
        $cutoff_date = date('Y-m-d H:i:s', time() - ($retention_days * 24 * 60 * 60));
        
        // Delete old backups from custom table
        $table_name = $wpdb->prefix . 'sp_merge_backups';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `" . $table_name . "` WHERE created_at < %s",
            $cutoff_date
        ));
    }
}