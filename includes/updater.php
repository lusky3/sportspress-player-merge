<?php
/**
 * Simple GitHub updater for WordPress plugins
 */

if (!defined('ABSPATH')) {
    wp_die('Direct access denied.', 'Access Denied', array('response' => 403));
}

class SP_Merge_GitHub_Updater {
    private const GITHUB_BASE_URL = 'https://github.com';
    
    private $plugin_file;
    private $plugin_slug;
    private $version;
    private $github_repo;
    
    public function __construct($plugin_file, $github_repo) {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename($plugin_file);
        $this->version = SP_MERGE_VERSION;
        $this->github_repo = $github_repo;
        
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
    }
    
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        
        $remote_version = $this->get_remote_version();
        if ($remote_version === false) {
            return $transient; // Skip update check on failure
        }

        if ($this->needs_update($remote_version)) {
            $transient->response[$this->plugin_slug] = $this->build_update_response($remote_version);
        }

        return $transient;
    }

    private function needs_update($remote_version) {
        return version_compare($this->version, $remote_version, '<');
    }

    private function build_update_response($remote_version) {
        return (object) [
            'slug' => dirname($this->plugin_slug),
            'plugin' => $this->plugin_slug,
            'new_version' => $remote_version,
            'url' => $this->get_github_url(),
            'package' => $this->get_download_url(),
            'icons' => $this->get_plugin_icons()
        ];
    }

    private function get_github_url() {
        return self::GITHUB_BASE_URL . "/{$this->github_repo}";
    }

    private function get_download_url() {
        $cache_key = 'sp_merge_remote_version';
        $cached_version = get_transient($cache_key);
        if ($cached_version !== false) {
            return self::GITHUB_BASE_URL . "/{$this->github_repo}/archive/refs/tags/v{$cached_version}.zip";
        }
        return self::GITHUB_BASE_URL . "/{$this->github_repo}/archive/refs/heads/main.zip";
    }

    private function get_plugin_icons() {
        return [
            '1x' => SP_MERGE_PLUGIN_URL . 'assets/images/logo@1x.png',
            '2x' => SP_MERGE_PLUGIN_URL . 'assets/images/logo@2x.png'
        ];
    }
    
    private function get_remote_version() {
        $cache_key = 'sp_merge_remote_version';
        $cached_version = get_transient($cache_key);
        if ($cached_version !== false) {
            return $cached_version;
        }
        
        $request = wp_remote_get("https://api.github.com/repos/{$this->github_repo}/releases/latest");
        
        if (is_wp_error($request)) {
            error_log("SP Merge Updater: GitHub API request failed - " . $request->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($request);
        if ($response_code !== 200) {
            error_log("SP Merge Updater: GitHub API returned HTTP {$response_code}");
            return false;
        }
        
        $body = wp_remote_retrieve_body($request);
        if (empty($body)) {
            error_log("SP Merge Updater: Empty response from GitHub API");
            return false;
        }
        
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("SP Merge Updater: Invalid JSON response - " . json_last_error_msg());
            return false;
        }
        
        if (!isset($data['tag_name'])) {
            error_log("SP Merge Updater: Missing tag_name in GitHub API response");
            return false;
        }
        
        $version = ltrim($data['tag_name'], 'v');
        
        // Cache for 12 hours
        set_transient($cache_key, $version, 12 * HOUR_IN_SECONDS);
        
        return $version;
    }
}