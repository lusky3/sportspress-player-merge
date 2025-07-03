<?php
/**
 * Merge Processor Class
 * 
 * Handles the core merge logic and data operations
 */

if (!defined('ABSPATH')) {
    wp_die('Direct access denied.', 'Access Denied', array('response' => 403));
}

class SP_Merge_Processor {
    
    public function execute_merge($primary_id, $duplicate_ids) {
        $backup = null;
        $backup_id = null;
        
        try {
            $backup = new SP_Merge_Backup();
            $backup_id = $backup->create_merge_backup($primary_id, $duplicate_ids);
            
            if (!$backup_id) {
                throw new Exception(__('Failed to create backup before merge', 'sportspress-player-merge'));
            }
            
            foreach ($duplicate_ids as $duplicate_id) {
                if (!$this->merge_single_player($primary_id, $duplicate_id)) {
                    throw new Exception(__('Player merge operation failed', 'sportspress-player-merge'));
                }
            }
            
            foreach ($duplicate_ids as $duplicate_id) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $user_id = get_current_user_id();
                    error_log(sprintf("SP Merge [User: %d]: Attempting to delete player ID %d", intval($user_id), intval($duplicate_id)));
                }
                
                // Check if player exists before deletion
                $player_before = get_post($duplicate_id);
                if (!$player_before) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        $user_id = get_current_user_id();
                        error_log(sprintf("SP Merge [User: %d]: Player %d does not exist, skipping deletion", intval($user_id), intval($duplicate_id)));
                    }
                    continue;
                }
                
                $deleted = wp_delete_post($duplicate_id, true);
                
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $user_id = get_current_user_id();
                    error_log(sprintf("SP Merge [User: %d]: wp_delete_post returned: %s", intval($user_id), var_export($deleted, true)));
                }
                
                // Verify deletion
                $player_after = get_post($duplicate_id);
                if ($player_after && $player_after->post_status !== 'trash') {
                    $user_id = get_current_user_id();
                    error_log(sprintf("SP Merge [User: %d]: Player %d still exists after deletion attempt", intval($user_id), intval($duplicate_id)));
                    throw new Exception(__('Failed to delete duplicate player', 'sportspress-player-merge') . ': ' . $duplicate_id);
                }
                
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $user_id = get_current_user_id();
                    error_log(sprintf("SP Merge [User: %d]: Successfully deleted player ID %d", intval($user_id), intval($duplicate_id)));
                }
            }
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $user_id = get_current_user_id();
                error_log(sprintf("SP Merge [User: %d]: Completed merge of player %d", intval($user_id), intval($primary_id)));
            }
            
            return [
                'success' => true,
                'backup_id' => $backup_id
            ];
            
        } catch (Exception $e) {
            // Clean up backup if merge failed
            if ($backup && $backup_id) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $user_id = get_current_user_id();
                    error_log(sprintf("SP Merge [User: %d]: Merge failed, cleaning up backup %s", intval($user_id), $backup_id));
                }
                $backup->delete_backup($backup_id);
            }
            
            return [
                'success' => false,
                'message' => __('Merge failed', 'sportspress-player-merge') . ': ' . $e->getMessage()
            ];
        }
    }
    
    private function merge_single_player($primary_id, $duplicate_id) {
        try {
            $this->merge_taxonomies($primary_id, $duplicate_id);
            $this->merge_meta_data($primary_id, $duplicate_id);
            $this->update_references($primary_id, $duplicate_id);
            return true;
        } catch (Exception $e) {
            $user_id = get_current_user_id();
            error_log(sprintf("SP Merge [User: %d]: Single player merge failed - %s", intval($user_id), $e->getMessage()));
            return false;
        }
    }
    
    private function merge_taxonomies($primary_id, $duplicate_id) {
        $taxonomies = ['sp_league', 'sp_season', 'sp_position'];
        
        foreach ($taxonomies as $taxonomy) {
            // Check if taxonomy exists before attempting to use it
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }
            
            $primary_terms = wp_get_object_terms($primary_id, $taxonomy, ['fields' => 'ids']);
            $duplicate_terms = wp_get_object_terms($duplicate_id, $taxonomy, ['fields' => 'ids']);
            
            if (is_wp_error($primary_terms) || is_wp_error($duplicate_terms)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $user_id = get_current_user_id();
                    error_log(sprintf("SP Merge [User: %d]: Error getting terms for taxonomy %s", intval($user_id), sanitize_key($taxonomy)));
                }
                continue;
            }
            
            $primary_terms = is_array($primary_terms) ? $primary_terms : [];
            $duplicate_terms = is_array($duplicate_terms) ? $duplicate_terms : [];
            
            $merged_terms = array_unique(array_merge($primary_terms, $duplicate_terms));
            
            if (count($merged_terms) > count($primary_terms)) {
                $result = wp_set_object_terms($primary_id, $merged_terms, $taxonomy);
                if (is_wp_error($result)) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        $user_id = get_current_user_id();
                        error_log(sprintf("SP Merge [User: %d]: Failed to set terms for taxonomy %s", intval($user_id), sanitize_key($taxonomy)));
                    }
                }
            }
        }
    }
    
    private function merge_meta_data($primary_id, $duplicate_id) {
        $duplicate_meta = get_post_meta($duplicate_id);
        
        // Fields that should have single values (replace, don't duplicate)
        $single_value_fields = ['sp_leagues', 'sp_statistics', 'sp_metrics'];
        
        // Fields to skip during merge (preserve primary player's settings)
        $skip_fields = ['sp_assignments', 'sp_columns', 'sp_leagues'];
        
        // Note: These fields control frontend stat display and must be preserved:
        // - sp_columns: which stat columns to show (goals, assists, etc.)
        // - sp_assignments: division-grouped stats display (creates league-season-team records)
        // - sp_leagues: career totals display (-1 = hidden, other values = show)
        // Preserving these maintains the merge rule: primary player's display settings are kept.
        
        foreach ($duplicate_meta as $key => $values) {
            if (strpos($key, 'sp_') === 0 && !in_array($key, $skip_fields)) {
                if (in_array($key, $single_value_fields)) {
                    // For single-value fields, merge the data intelligently
                    $this->merge_single_value_field($primary_id, $duplicate_id, $key);
                } else {
                    // For multi-value fields, add unique values only
                    $existing_values = get_post_meta($primary_id, $key);
                    foreach ($values as $value) {
                        if (!in_array($value, $existing_values, true)) {
                            if (!add_post_meta($primary_id, $key, $value)) {
                                throw new Exception(__('Failed to add meta field', 'sportspress-player-merge') . ': ' . $key);
                            }
                        }
                    }
                }
            }
        }
        
        // Clean up any duplicates that may have been created
        $this->cleanup_duplicate_meta($primary_id);
    }
    
    private function merge_single_value_field($primary_id, $duplicate_id, $key) {
        $user_id = get_current_user_id();
        $primary_value = get_post_meta($primary_id, $key, true);
        $duplicate_value = get_post_meta($duplicate_id, $key, true);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf("SP Merge [User: %d]: Merging field '%s' - Primary empty: %s, Duplicate empty: %s", intval($user_id), $key, (empty($primary_value) ? 'yes' : 'no'), (empty($duplicate_value) ? 'yes' : 'no')));
        }
        
        if (empty($primary_value) && !empty($duplicate_value)) {
            // Primary is empty, use duplicate value
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf("SP Merge [User: %d]: Using duplicate value for '%s'", intval($user_id), $key));
            }
            if (!update_post_meta($primary_id, $key, $duplicate_value)) {
                error_log(sprintf("SP Merge [User: %d]: Failed to update meta field '%s' with duplicate value", intval($user_id), $key));
                throw new Exception(__('Failed to update meta field', 'sportspress-player-merge') . ': ' . $key);
            }
        } elseif (!empty($primary_value) && !empty($duplicate_value)) {
            // Both have values, merge them intelligently
            if ($key === 'sp_leagues' || $key === 'sp_statistics') {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf("SP Merge [User: %d]: Merging array data for '%s'", intval($user_id), $key));
                }
                try {
                    $merged = $this->merge_array_data($primary_value, $duplicate_value);
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log(sprintf("SP Merge [User: %d]: Merged data size for '%s': %d bytes", intval($user_id), $key, strlen(serialize($merged))));
                    }
                    
                    // Validate merged data before attempting update
                    if (!is_array($merged)) {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log(sprintf("SP Merge [User: %d]: Merged data for '%s' is not an array, using primary value instead", intval($user_id), $key));
                        }
                        $merged = $primary_value;
                    }
                    
                    // Log a sample of the merged data structure for debugging
                    if ($key === 'sp_statistics') {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log(sprintf("SP Merge [User: %d]: Sample merged data structure: %s", intval($user_id), substr(print_r($merged, true), 0, 500)));
                        }
                    }
                    
                    // Try to update and capture any WordPress errors
                    $update_result = update_post_meta($primary_id, $key, $merged);
                    if ($update_result === false) {
                        global $wpdb;
                        error_log(sprintf("SP Merge [User: %d]: WordPress database error: %s", intval($user_id), $wpdb->last_error));
                        error_log(sprintf("SP Merge [User: %d]: MySQL error: %s", intval($user_id), mysqli_error($wpdb->dbh)));
                        
                        // Try a simpler approach - just keep the primary value
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log(sprintf("SP Merge [User: %d]: Attempting fallback - keeping primary value for '%s'", intval($user_id), $key));
                        }
                        $fallback_result = update_post_meta($primary_id, $key, $primary_value);
                        if ($fallback_result === false) {
                            error_log(sprintf("SP Merge [User: %d]: Fallback also failed for '%s' - FAILING MERGE to preserve data integrity", intval($user_id), $key));
                            throw new Exception(__('Failed to update merged meta field', 'sportspress-player-merge') . ': ' . $key);
                        }
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log(sprintf("SP Merge [User: %d]: Fallback successful - kept primary value for '%s'", intval($user_id), $key));
                        }
                    } else {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log(sprintf("SP Merge [User: %d]: Successfully merged field '%s'", intval($user_id), $key));
                        }
                    }
                } catch (Exception $e) {
                    error_log(sprintf("SP Merge [User: %d]: Exception merging '%s': %s", intval($user_id), $key, $e->getMessage()));
                    throw $e;
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf("SP Merge [User: %d]: No merge needed for '%s' - keeping primary value", intval($user_id), $key));
            }
        }
    }
    
    private function merge_array_data($primary_data, $duplicate_data) {
        if (!is_array($primary_data)) {
            $user_id = get_current_user_id();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf("SP Merge [User: %d]: Primary data is not array, converting to empty array", intval($user_id)));
            }
            $primary_data = [];
        }
        if (!is_array($duplicate_data)) {
            $user_id = get_current_user_id();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf("SP Merge [User: %d]: Duplicate data is not array, converting to empty array", intval($user_id)));
            }
            $duplicate_data = [];
        }
        
        // For SportsPress statistics, merge carefully to avoid data corruption
        // Structure: [league_id][event_id][stat_key] = value
        foreach ($duplicate_data as $league_id => $league_data) {
            if (!isset($primary_data[$league_id])) {
                // League doesn't exist in primary, add it completely
                $primary_data[$league_id] = $league_data;
            } elseif (is_array($league_data) && is_array($primary_data[$league_id])) {
                // League exists in both, merge event data
                foreach ($league_data as $event_id => $event_stats) {
                    if (!isset($primary_data[$league_id][$event_id])) {
                        $primary_data[$league_id][$event_id] = $event_stats;
                    }
                }
            }
        }
        
        return $primary_data;
    }
    
    private function cleanup_duplicate_meta($player_id) {
        $multi_value_fields = ['sp_team', 'sp_current_team', 'sp_past_team'];
        
        foreach ($multi_value_fields as $field) {
            $values = get_post_meta($player_id, $field);
            if (count($values) > 1) {
                $unique_values = array_unique($values, SORT_STRING);
                if (count($unique_values) < count($values)) {
                    // Remove all entries and re-add unique ones
                    delete_post_meta($player_id, $field);
                    foreach ($unique_values as $value) {
                        if ($value !== '' && $value !== '0') {
                            if (!add_post_meta($player_id, $field, $value)) {
                                throw new Exception(__('Failed to restore unique meta field', 'sportspress-player-merge') . ': ' . $field);
                            }
                        }
                    }
                }
            }
        }
    }
    
    private function update_references($primary_id, $duplicate_id) {
        global $wpdb;
        
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} 
            SET meta_value = REPLACE(meta_value, %s, %s) 
            WHERE meta_key LIKE %s 
            AND meta_value LIKE %s",
            $duplicate_id,
            $primary_id,
            'sp_%',
            '%' . $wpdb->esc_like($duplicate_id) . '%'
        ));
        if ($result === false) {
            $user_id = get_current_user_id();
            error_log(sprintf("SP Merge [User: %d]: Failed to update player references - %s", intval($user_id), $wpdb->last_error));
            throw new Exception(__('Database update failed', 'sportspress-player-merge'));
        }
    }
}