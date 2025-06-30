<?php
/**
 * Preview Generator Class
 * 
 * Generates merge preview data for user review
 */

if (!defined('ABSPATH')) {
    wp_die('Direct access denied.', 'Access Denied', array('response' => 403));
}

class SP_Merge_Preview {
    
    public function generate($primary_id, $duplicate_ids) {
        $primary = $this->get_player_details($primary_id);
        $duplicates = array_map([$this, 'get_player_details'], $duplicate_ids);
        
        $html = '<div class="merge-preview-container">';
        $html .= $this->render_player_names($primary, $duplicates);
        $html .= $this->render_data_comparison($primary_id, $duplicate_ids);
        $html .= $this->render_expandable_script();
        $html .= '</div>';
        
        return $html;
    }
    
    private function render_player_names($primary, $duplicates) {
        $html = '<div class="preview-section">';
        $html .= '<h4>' . __('Players Being Merged', 'sportspress-player-merge') . '</h4>';
        $html .= '<p><strong>' . __('Primary Player (will be kept):', 'sportspress-player-merge') . '</strong> ' . esc_html($primary['name']) . '</p>';
        $html .= '<p><strong>' . __('Duplicate Players (will be deleted):', 'sportspress-player-merge') . '</strong></p>';
        $html .= '<ul>';
        foreach ($duplicates as $duplicate) {
            $html .= '<li>' . esc_html($duplicate['name']) . '</li>';
        }
        $html .= '</ul>';
        $html .= '</div>';
        
        return $html;
    }
    
    private function render_data_comparison($primary_id, $duplicate_ids) {
        $html = '<div class="preview-section">';
        $html .= '<h4>' . __('Data Merge Preview', 'sportspress-player-merge') . '</h4>';
        $html .= '<table class="merge-preview-table">';
        $html .= '<thead>';
        $html .= '<tr><th>' . __('Data Type', 'sportspress-player-merge') . '</th><th>' . __('Current (Primary)', 'sportspress-player-merge') . '</th><th>' . __('Incoming (Duplicates)', 'sportspress-player-merge') . '</th><th>' . __('Result After Merge', 'sportspress-player-merge') . '</th></tr>';
        $html .= '</thead>';
        $html .= '<tbody>';
        
        $html .= $this->render_current_teams_row($primary_id, $duplicate_ids);
        $html .= $this->render_past_teams_row($primary_id, $duplicate_ids);
        $html .= $this->render_taxonomy_row('sp_league', 'League', $primary_id, $duplicate_ids);
        $html .= $this->render_taxonomy_row('sp_season', 'Season', $primary_id, $duplicate_ids);
        $html .= $this->render_taxonomy_row('sp_position', 'Position', $primary_id, $duplicate_ids);
        
        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '</div>';
        
        return $html;
    }
    
    private function render_current_teams_row($primary_id, $duplicate_ids) {
        $primary_team = $this->get_current_team($primary_id);
        $duplicate_teams = [];
        
        foreach ($duplicate_ids as $dup_id) {
            try {
                $team = $this->get_current_team($dup_id);
                if ($team) $duplicate_teams[] = $team;
            } catch (Exception $e) {
                error_log("SP Merge: Failed to get current team for player " . intval($dup_id) . " - " . $e->getMessage());
            }
        }
        
        $unique_dup_teams = array_unique($duplicate_teams);
        $all_teams = [];
        if ($primary_team) $all_teams[] = $primary_team;
        $all_teams = array_merge($all_teams, $unique_dup_teams);
        $result_teams = array_unique($all_teams);
        
        return '<tr>' .
               '<td><strong>' . __('Current Team', 'sportspress-player-merge') . '</strong></td>' .
               '<td>' . ($primary_team ?: __('None', 'sportspress-player-merge')) . '</td>' .
               '<td>' . (empty($unique_dup_teams) ? __('None', 'sportspress-player-merge') : implode(', ', $unique_dup_teams)) . '</td>' .
               '<td>' . (empty($result_teams) ? __('None', 'sportspress-player-merge') : implode(', ', $result_teams)) . '</td>' .
               '</tr>';
    }
    
    private function render_past_teams_row($primary_id, $duplicate_ids) {
        $primary_past = $this->get_past_teams($primary_id);
        $all_duplicate_past = [];
        
        foreach ($duplicate_ids as $dup_id) {
            $all_duplicate_past = array_merge($all_duplicate_past, $this->get_past_teams($dup_id));
        }
        
        $unique_duplicate_past = array_unique($all_duplicate_past);
        $merged_past = array_unique(array_merge($primary_past, $unique_duplicate_past));
        
        return '<tr>' .
               '<td><strong>' . __('Past Team(s)', 'sportspress-player-merge') . '</strong></td>' .
               '<td>' . $this->format_expandable_list($primary_past, 'primary-past-teams') . '</td>' .
               '<td>' . $this->format_expandable_list($unique_duplicate_past, 'duplicate-past-teams') . '</td>' .
               '<td>' . $this->format_expandable_list($merged_past, 'merged-past-teams') . '</td>' .
               '</tr>';
    }
    
    private function render_taxonomy_row($taxonomy, $label, $primary_id, $duplicate_ids) {
        $primary_terms = $this->get_taxonomy_terms($primary_id, $taxonomy);
        $all_duplicate_terms = [];
        
        foreach ($duplicate_ids as $dup_id) {
            $all_duplicate_terms = array_merge($all_duplicate_terms, $this->get_taxonomy_terms($dup_id, $taxonomy));
        }
        
        $unique_duplicate_terms = array_unique($all_duplicate_terms);
        $merged_terms = array_unique(array_merge($primary_terms, $unique_duplicate_terms));
        
        return '<tr>' .
               '<td><strong>' . esc_html($label) . '</strong></td>' .
               '<td>' . $this->format_expandable_list($primary_terms, 'primary-' . $taxonomy) . '</td>' .
               '<td>' . $this->format_expandable_list($unique_duplicate_terms, 'duplicate-' . $taxonomy) . '</td>' .
               '<td>' . $this->format_expandable_list($merged_terms, 'merged-' . $taxonomy) . '</td>' .
               '</tr>';
    }
    
    private function get_player_details($player_id) {
        $player = get_post($player_id);
        
        if (is_wp_error($player) || !$player || !isset($player->post_type) || $player->post_type !== 'sp_player') {
            return ['name' => __('Unknown Player', 'sportspress-player-merge'), 'id' => $player_id];
        }
        
        return [
            'id' => $player->ID,
            'name' => $player->post_title,
            'post' => $player
        ];
    }
    
    private function get_current_team($player_id) {
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
    
    private function get_past_teams($player_id) {
        $past_team_names = [];
        $past_team_ids = get_post_meta($player_id, 'sp_past_team');
        
        foreach ($past_team_ids as $team_id) {
            if ($team_id && $team_id != '0' && is_numeric($team_id)) {
                $team_post = get_post($team_id);
                if ($team_post && $team_post->post_type === 'sp_team') {
                    $past_team_names[] = $team_post->post_title;
                }
            }
        }
        
        return array_unique($past_team_names);
    }
    
    private function get_taxonomy_terms($player_id, $taxonomy) {
        $terms = wp_get_object_terms($player_id, $taxonomy, ['fields' => 'names']);
        return is_array($terms) && !is_wp_error($terms) ? $terms : [];
    }
    
    private function format_expandable_list($items, $id) {
        if (empty($items)) {
            return __('None', 'sportspress-player-merge');
        }
        
        if (count($items) <= 3) {
            return implode(', ', $items);
        }
        
        $visible = array_slice($items, 0, 2);
        $hidden = array_slice($items, 2);
        
        $html = implode(', ', $visible);
        $html .= ' <a href="#" class="sp-expand-toggle" data-target="' . $id . '">+' . sprintf(__('%d more', 'sportspress-player-merge'), count($hidden)) . '</a>';
        $html .= '<div id="' . $id . '" style="display:none; margin-top:5px; font-size:0.9em;">';
        $html .= implode(', ', $hidden);
        $html .= '</div>';
        
        return $html;
    }
    
    private function render_expandable_script() {
        return '';
    }
}