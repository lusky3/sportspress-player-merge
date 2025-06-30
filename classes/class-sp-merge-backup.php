<?php
/**
 * Backup Manager Class
 * 
 * Handles backup creation, storage, and restoration
 */

if (!defined('ABSPATH')) {
    wp_die('Direct access denied.', 'Access Denied', array('response' => 403));
}

class SP_Merge_Backup {
    
    public function create_merge_backup($primary_id, $duplicate_ids) {
        try {
            $backup_id = $this->generate_backup_id();
            $backup_data = $this->prepare_backup_data($primary_id, $duplicate_ids);
            
            if (empty($backup_data)) {
                throw new Exception(__('Failed to prepare backup data', 'sportspress-player-merge'));
            }
            
            $this->save_backup($backup_id, $backup_data);
            $this->cleanup_old_backups();
            
            return $backup_id;
            
        } catch (Exception $e) {
            $user_id = get_current_user_id();
            error_log("SP Merge [User: " . intval($user_id) . "]: Backup creation failed - " . $e->getMessage());
            throw new Exception(__('Backup creation failed', 'sportspress-player-merge') . ': ' . $e->getMessage());
        }
    }
    
    private function generate_backup_id() {
        return 'merge_' . time() . '_' . wp_generate_password(8, false);
    }
    
    private function prepare_backup_data($primary_id, $duplicate_ids) {
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
        
        foreach ($duplicate_ids as $duplicate_id) {
            $backup_data['duplicate_backups'][$duplicate_id] = $this->backup_player_data($duplicate_id);
            $backup_data['duplicate_names'][$duplicate_id] = get_the_title($duplicate_id);
            $backup_data['reference_changes'][$duplicate_id] = $this->find_references($duplicate_id);
        }
        
        return $backup_data;
    }
    
    private function save_backup($backup_id, $backup_data) {
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
            $user_id = get_current_user_id();
            $sanitized_error = sanitize_text_field($wpdb->last_error);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                wp_debug_log("SP Merge [User: " . intval($user_id) . "]: Failed to save backup {$backup_id} to database - Error: {$sanitized_error}");
            }
            throw new Exception(__('Failed to create backup', 'sportspress-player-merge'));
        }
        
        update_user_meta(get_current_user_id(), 'sp_last_merge_backup', $backup_id);
    }
    
    public function revert($backup_id) {
        $backup_data = $this->load_backup_data($backup_id);
        
        if (!$backup_data) {
            return ['success' => false, 'message' => __('Backup data not found', 'sportspress-player-merge')];
        }
        
        try {
            $this->execute_revert($backup_data);
            $this->cleanup_after_revert($backup_id);
            
            return ['success' => true];
            
        } catch (Exception $e) {
            $user_id = get_current_user_id();
            error_log("SP Merge [User: " . intval($user_id) . "]: Revert failed - " . $e->getMessage());
            return ['success' => false, 'message' => __('Revert failed', 'sportspress-player-merge') . ': ' . $e->getMessage()];
        }
    }
    
    private function load_backup_data($backup_id) {
        global $wpdb;
        
        // Validate backup_id format
        if (!preg_match('/^merge_\d+_[a-zA-Z0-9]{8}$/', $backup_id)) {
            return null;
        }
        
        $backup_row = $wpdb->get_row($wpdb->prepare(
            "SELECT backup_data FROM {$wpdb->prefix}sp_merge_backups WHERE backup_id = %s",
            sanitize_text_field($backup_id)
        ));
        
        return $backup_row ? json_decode($backup_row->backup_data, true) : null;
    }
        
    private function execute_revert($backup_data) {
        $user_id = get_current_user_id();
        
        // First recreate all deleted duplicate players
        foreach ($backup_data['duplicate_backups'] as $duplicate_id => $duplicate_backup) {
            if (isset($duplicate_backup['post_data'])) {
                $result = $this->recreate_player($duplicate_id, $duplicate_backup);
                if (!$result) {
                    error_log("SP Merge [User: " . intval($user_id) . "]: Failed to recreate player " . intval($duplicate_id));
                    throw new Exception("Failed to recreate player " . intval($duplicate_id));
                }
            }
        }
        
        // Then restore the primary player to original state
        error_log("SP Merge [User: " . intval($user_id) . "]: Attempting to restore primary player " . intval($backup_data['primary_id']));
        $result = $this->restore_player_data($backup_data['primary_id'], $backup_data['primary_backup']);
        if (!$result) {
            error_log("SP Merge [User: " . intval($user_id) . "]: Failed to restore primary player " . intval($backup_data['primary_id']));
            throw new Exception("Failed to restore primary player " . intval($backup_data['primary_id']));
        }
        error_log("SP Merge [User: " . intval($user_id) . "]: Primary player " . intval($backup_data['primary_id']) . " restored successfully");
        
        // Finally revert all references back to original players
        if (isset($backup_data['reference_changes'])) {
            foreach ($backup_data['reference_changes'] as $duplicate_id => $references) {
                error_log("SP Merge [User: " . intval($user_id) . "]: Reverting references for duplicate player " . intval($duplicate_id));
                $result = $this->revert_references($backup_data['primary_id'], $duplicate_id, $references);
                if (!$result) {
                    error_log("SP Merge [User: " . intval($user_id) . "]: Failed to revert references for player " . intval($duplicate_id));
                    throw new Exception("Failed to revert references for player " . intval($duplicate_id));
                }
                error_log("SP Merge [User: " . intval($user_id) . "]: Successfully reverted references for player " . intval($duplicate_id));
            }
        } else {
            error_log("SP Merge [User: " . intval($user_id) . "]: No reference changes found in backup data");
        }
    }
    
    private function cleanup_after_revert($backup_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sp_merge_backups';
        
        $result = $wpdb->delete($table_name, ['backup_id' => $backup_id], ['%s']);
        if ($result === false) {
            $user_id = get_current_user_id();
            error_log("SP Merge [User: " . intval($user_id) . "]: Failed to delete backup during cleanup - " . $wpdb->last_error);
        }
        delete_user_meta(get_current_user_id(), 'sp_last_merge_backup');
    }
    
    public function delete_backup($backup_id) {
        $this->cleanup_after_revert($backup_id);
        return true; // cleanup_after_revert doesn't return a value, assume success if no exception
    }
    
    public function delete_backups($backup_ids) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sp_merge_backups';
        $deleted_count = 0;
        $last_backup_id = get_user_meta(get_current_user_id(), 'sp_last_merge_backup', true);
        
        if (!empty($backup_ids)) {
            $placeholders = implode(',', array_fill(0, count($backup_ids), '%s'));
            $sql = "DELETE FROM {$table_name} WHERE backup_id IN ({$placeholders})";
            $result = $wpdb->query($wpdb->prepare($sql, $backup_ids));
            
            if ($result !== false) {
                $deleted_count = $result;
                if (in_array($last_backup_id, $backup_ids)) {
                    delete_user_meta(get_current_user_id(), 'sp_last_merge_backup');
                }
            } else {
                $user_id = get_current_user_id();
                error_log("SP Merge [User: " . intval($user_id) . "]: Failed to delete backups - " . $wpdb->last_error);
            }
        }
        
        return $deleted_count;
    }
    
    private function backup_player_data($player_id) {
        $player = get_post($player_id);
        
        global $wpdb;
        // Get available SportsPress taxonomies
        $available_taxonomies = [];
        $sp_taxonomies = ['sp_league', 'sp_season', 'sp_position'];
        foreach ($sp_taxonomies as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                $available_taxonomies[] = $taxonomy;
            }
        }
        
        if (empty($available_taxonomies)) {
            $term_relationships = [];
        } else {
            // Build secure IN clause with proper placeholders
            $sanitized_taxonomies = array_map('sanitize_key', $available_taxonomies);
            $taxonomy_count = count($sanitized_taxonomies);
            $taxonomy_placeholders = implode(',', array_fill(0, $taxonomy_count, '%s'));
            
            $sql = "SELECT tt.taxonomy, tr.term_taxonomy_id, t.term_id, t.name, t.slug
                    FROM {$wpdb->term_relationships} tr
                    JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                    JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                    WHERE tr.object_id = %d
                    AND tt.taxonomy IN ($taxonomy_placeholders)";
            
            $query_params = array_merge([$player_id], $sanitized_taxonomies);
            $term_relationships = $wpdb->get_results($wpdb->prepare($sql, $query_params));
        }
        
        if ($term_relationships === false) {
            $user_id = get_current_user_id();
            error_log("SP Merge [User: " . intval($user_id) . "]: Database error in backup_player_data - " . $wpdb->last_error);
            $term_relationships = [];
        }
        
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
        
        // Get ALL meta data (including non-SP fields for complete backup)
        $all_meta = get_post_meta($player_id);
        
        // Separate SP and non-SP meta for precise restoration
        $sp_meta = [];
        $other_meta = [];
        foreach ($all_meta as $key => $values) {
            if (strpos($key, 'sp_') === 0) {
                $sp_meta[$key] = $values;
            } else {
                $other_meta[$key] = $values;
            }
        }
        
        return [
            'post_data' => $player,
            'meta_data' => $all_meta, // Keep full backup for safety
            'sp_meta' => $sp_meta,    // Original SP fields only
            'other_meta' => $other_meta, // Non-SP fields
            'taxonomies' => $taxonomies
        ];
    }
    
    private function find_references($player_id) {
        global $wpdb;
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_key, meta_value 
             FROM {$wpdb->postmeta} 
             WHERE meta_key LIKE %s 
             AND meta_value LIKE %s",
            'sp_%',
            '%' . $wpdb->esc_like($player_id) . '%'
        ));
        
        if ($results === false) {
            $user_id = get_current_user_id();
            error_log("SP Merge [User: " . intval($user_id) . "]: Database error in find_references - " . $wpdb->last_error);
            return [];
        }
        
        return $results;
    }
    
    private function revert_references($primary_id, $duplicate_id, $references) {
        global $wpdb;
        $user_id = get_current_user_id();
        
        error_log("SP Merge [User: " . intval($user_id) . "]: Starting reference reversion for player " . intval($duplicate_id) . " (primary: " . intval($primary_id) . ")");
        error_log("SP Merge [User: " . intval($user_id) . "]: Found " . count($references) . " reference records to process");
        
        if (empty($references)) {
            error_log("SP Merge [User: " . intval($user_id) . "]: No references to revert for player " . intval($duplicate_id));
            return true;
        }
        
        foreach ($references as $ref) {
            // Handle both object and array formats
            $post_id = is_object($ref) ? $ref->post_id : $ref['post_id'];
            $meta_key = is_object($ref) ? $ref->meta_key : $ref['meta_key'];
            $original_value = is_object($ref) ? $ref->meta_value : $ref['meta_value'];
            
            error_log("SP Merge [User: " . intval($user_id) . "]: Processing reference - Post: " . intval($post_id) . ", Key: {$meta_key}");
            
            // Find current references that point to primary_id and change them back to duplicate_id
            $current_refs = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_id, meta_value FROM {$wpdb->postmeta} 
                 WHERE post_id = %d AND meta_key = %s AND meta_value LIKE %s",
                $post_id,
                $meta_key,
                '%' . $wpdb->esc_like($primary_id) . '%'
            ));
            
            if ($current_refs === false) {
                error_log("SP Merge [User: " . intval($user_id) . "]: Database error finding current references - " . $wpdb->last_error);
                return false;
            }
            
            error_log("SP Merge [User: " . intval($user_id) . "]: Found " . count($current_refs) . " current references to update");
            
            if (!empty($current_refs)) {
                if (!$this->batch_update_references($current_refs, $primary_id, $duplicate_id, $post_id)) {
                    return false;
                }
            }
        }
        
        error_log("SP Merge [User: " . intval($user_id) . "]: Successfully reverted all references for player " . intval($duplicate_id));
        return true;
    }
    
    private function restore_player_data($player_id, $backup_data) {
        $user_id = get_current_user_id();
        
        if (!isset($backup_data['meta_data']) || !isset($backup_data['taxonomies'])) {
            error_log("SP Merge [User: " . intval($user_id) . "]: Missing backup data for player " . intval($player_id));
            return false;
        }
        
        try {
            $this->restore_meta_data($player_id, $backup_data);
            $this->restore_taxonomy_data($player_id, $backup_data['taxonomies']);
            
            $assignments = get_post_meta($player_id, 'sp_assignments');
            if (!empty($assignments)) {
                $this->recalculate_statistics($player_id);
            }
            
            error_log("SP Merge [User: " . intval($user_id) . "]: Successfully restored player data for " . intval($player_id));
            return true;
        } catch (Exception $e) {
            error_log("SP Merge [User: " . intval($user_id) . "]: Failed to restore player data for " . intval($player_id) . " - " . $e->getMessage());
            return false;
        }
    }
    
    private function restore_meta_data($player_id, $backup_data) {
        $meta_sets = $this->prepare_meta_for_restoration($backup_data);
        
        if (empty($meta_sets['sp_meta']) && empty($meta_sets['fallback_meta'])) {
            return;
        }
        
        if (!$this->clear_existing_sp_meta($player_id)) {
            return;
        }
        
        $this->restore_sp_meta($player_id, $meta_sets['sp_meta']);
        $this->restore_other_meta($player_id, $meta_sets['other_meta']);
    }
    
    private function prepare_meta_for_restoration($backup_data) {
        $sp_meta = isset($backup_data['sp_meta']) ? $backup_data['sp_meta'] : [];
        $other_meta = isset($backup_data['other_meta']) ? $backup_data['other_meta'] : [];
        $meta_data = isset($backup_data['meta_data']) ? $backup_data['meta_data'] : [];
        
        $fallback_meta = !empty($sp_meta) ? $sp_meta : array_filter($meta_data, function($key) {
            return strpos($key, 'sp_') === 0;
        }, ARRAY_FILTER_USE_KEY);
        
        return [
            'sp_meta' => $fallback_meta,
            'other_meta' => $other_meta,
            'fallback_meta' => $meta_data
        ];
    }
    
    private function clear_existing_sp_meta($player_id) {
        global $wpdb;
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
            $player_id,
            'sp_%'
        ));
        
        if ($result === false) {
            $user_id = get_current_user_id();
            error_log("SP Merge [User: " . intval($user_id) . "]: Failed to clear SP meta for player " . intval($player_id) . " - " . $wpdb->last_error);
            return false;
        }
        return true;
    }
    
    private function restore_sp_meta($player_id, $sp_meta) {
        foreach ($sp_meta as $key => $values) {
            if (is_array($values)) {
                $this->restore_meta_values($player_id, $key, $values);
            }
        }
    }
    
    private function restore_other_meta($player_id, $other_meta) {
        if (empty($other_meta)) {
            return;
        }
        
        foreach ($other_meta as $key => $values) {
            if ($this->should_skip_meta_key($key)) {
                continue;
            }
            
            if (is_array($values)) {
                $this->restore_meta_values($player_id, $key, $values);
            }
        }
    }
    
    private function should_skip_meta_key($key) {
        return strpos($key, '_edit_') === 0 || strpos($key, '_wp_') === 0;
    }
    
    private function restore_meta_values($player_id, $key, $values) {
        foreach ($values as $value) {
            $restored_value = $this->prepare_meta_value($value);
            add_post_meta($player_id, $key, $restored_value);
        }
    }
    
    private function prepare_meta_value($value) {
        if (is_string($value) && $this->is_safe_serialized_data($value)) {
            return $this->safe_unserialize($value);
        }
        return $value;
    }
    
    private function restore_taxonomy_data($player_id, $taxonomies) {
        if (empty($taxonomies) || !is_array($taxonomies)) {
            return;
        }
        
        foreach ($taxonomies as $taxonomy => $terms) {
            $this->restore_single_taxonomy($player_id, $taxonomy, $terms);
        }
    }
    
    private function restore_single_taxonomy($player_id, $taxonomy, $terms) {
        if (empty($terms) || !is_array($terms)) {
            return;
        }
        
        $term_ids = $this->extract_term_ids($terms);
        if (empty($term_ids)) {
            return;
        }
        
        if (!$this->clear_taxonomy($player_id, $taxonomy)) {
            return;
        }
        
        $this->set_taxonomy_terms($player_id, $taxonomy, $term_ids);
    }
    
    private function extract_term_ids($terms) {
        $term_ids = [];
        foreach ($terms as $term) {
            if (is_array($term) && isset($term['term_id'])) {
                $term_ids[] = intval($term['term_id']);
            }
        }
        return $term_ids;
    }
    
    private function clear_taxonomy($player_id, $taxonomy) {
        $result = wp_set_object_terms($player_id, [], $taxonomy);
        if (is_wp_error($result)) {
            $this->log_taxonomy_error('clear', $taxonomy, $player_id);
            return false;
        }
        return true;
    }
    
    private function set_taxonomy_terms($player_id, $taxonomy, $term_ids) {
        $result = wp_set_object_terms($player_id, $term_ids, $taxonomy);
        if (is_wp_error($result)) {
            $this->log_taxonomy_error('set', $taxonomy, $player_id);
        }
    }
    
    private function log_taxonomy_error($action, $taxonomy, $player_id) {
        $user_id = get_current_user_id();
        error_log("SP Merge [User: " . intval($user_id) . "]: Failed to {$action} taxonomy {$taxonomy} for player " . intval($player_id));
    }
    
    private function batch_update_references($current_refs, $primary_id, $duplicate_id, $post_id) {
        global $wpdb;
        $user_id = get_current_user_id();
        
        $cases = [];
        $meta_ids = [];
        
        foreach ($current_refs as $current_ref) {
            $new_value = str_replace($primary_id, $duplicate_id, $current_ref->meta_value);
            $cases[] = $wpdb->prepare("WHEN %d THEN %s", $current_ref->meta_id, $new_value);
            $meta_ids[] = intval($current_ref->meta_id);
            error_log("SP Merge [User: " . intval($user_id) . "]: Updating meta_id " . intval($current_ref->meta_id) . " from '{$current_ref->meta_value}' to '{$new_value}'");
        }
        
        if (!empty($cases) && !empty($meta_ids)) {
            $cases_sql = implode(' ', $cases);
            $ids_sql = implode(',', $meta_ids);
            
            $sql = "UPDATE {$wpdb->postmeta} SET meta_value = CASE meta_id {$cases_sql} END WHERE meta_id IN ({$ids_sql})";
            error_log("SP Merge [User: " . intval($user_id) . "]: Executing SQL: {$sql}");
            $result = $wpdb->query($sql);
            
            if ($result === false) {
                error_log("SP Merge [User: " . intval($user_id) . "]: Failed to batch update references for post " . intval($post_id) . " - " . $wpdb->last_error);
                return false;
            }
            
            error_log("SP Merge [User: " . intval($user_id) . "]: Successfully updated " . intval($result) . " reference records");
        }
        
        return true;
    }
    
    private function is_safe_serialized_data($data) {
        if (!is_serialized($data)) {
            return false;
        }
        
        // Only allow basic serialized data types, no objects or classes
        if (strpos($data, 'O:') === 0 || strpos($data, 'C:') === 0) {
            return false;
        }
        
        return true;
    }
    
    private function safe_unserialize($data) {
        if (!$this->is_safe_serialized_data($data)) {
            return $data;
        }
        
        // Use unserialize with allowed_classes = false for maximum security
        $result = @unserialize($data, ['allowed_classes' => false]);
        
        // If unserialization fails, return original data
        if ($result === false && $data !== serialize(false)) {
            return $data;
        }
        
        return $result;
    }
    
    private function recreate_player($original_id, $backup_data) {
        global $wpdb;
        
        // Check if player already exists
        $existing_player = get_post($original_id);
        if ($existing_player && $existing_player->post_type === 'sp_player') {
            // Player exists, just restore the data
            $this->restore_player_data($original_id, $backup_data);
            return $original_id;
        }
        
        $post_data = (array) $backup_data['post_data'];
        
        $result = $wpdb->insert(
            $wpdb->posts,
            [
                'ID' => $original_id,
                'post_author' => intval($post_data['post_author']),
                'post_date' => sanitize_text_field($post_data['post_date']),
                'post_date_gmt' => sanitize_text_field($post_data['post_date_gmt']),
                'post_content' => wp_kses_post($post_data['post_content']),
                'post_title' => sanitize_text_field($post_data['post_title']),
                'post_excerpt' => sanitize_textarea_field($post_data['post_excerpt']),
                'post_status' => sanitize_text_field($post_data['post_status']),
                'comment_status' => sanitize_text_field($post_data['comment_status']),
                'ping_status' => sanitize_text_field($post_data['ping_status']),
                'post_type' => 'sp_player',
                'post_name' => sanitize_title($post_data['post_name'])
            ]
        );
        
        if ($result === false) {
            $user_id = get_current_user_id();
            error_log("SP Merge [User: " . intval($user_id) . "]: Failed to recreate player " . intval($original_id) . " - " . $wpdb->last_error);
            return false;
        }
        
        $this->restore_player_data($original_id, $backup_data);
        
        return $original_id;
    }
    
    private function recalculate_statistics($player_id) {
        // SportsPress calculates stats on-demand from assignments
        // Just clear any cached data to force recalculation
        wp_cache_delete($player_id, 'posts');
        wp_cache_delete($player_id, 'post_meta');
        
        if (class_exists('SP_Player')) {
            try {
                $player = new SP_Player($player_id);
                if (method_exists($player, 'statistics')) {
                    $player->statistics();
                }
            } catch (Exception $e) {
                $user_id = get_current_user_id();
                error_log("SP Merge [User: " . intval($user_id) . "]: Failed to recalculate statistics for player " . intval($player_id) . " - " . $e->getMessage());
            }
        }
    }
    
    private function cleanup_old_backups() {
        global $wpdb;
        
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}sp_merge_backups 
             WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            SP_MERGE_BACKUP_RETENTION_DAYS
        ));
        
        if ($result === false) {
            $user_id = get_current_user_id();
            error_log("SP Merge [User: " . intval($user_id) . "]: Failed to cleanup old backups - " . $wpdb->last_error);
        }
    }
}