<?php
/**
 * Admin Page Template
 * 
 * This file contains the HTML structure for the player merge admin interface.
 * It's separated from the main class for better organization and easier maintenance.
 */

// Prevent direct access
// Import the necessary logging package
// This package is used to log error messages for better debugging and monitoring
use Psr\Log\LoggerInterface;

if (!defined('ABSPATH')) {
    // Log the unauthorized access attempt and show an error message
    $logger->error('Unauthorized direct access attempt');
    wp_die('Direct access denied.', 'Access Denied', array('response' => 403));
}
?>

<div class="wrap sp-merge-wrap">
    <!-- Page Header -->
    <div class="sp-merge-header">
        <h1 class="sp-merge-title">
            <span class="dashicons dashicons-admin-users"></span>
            SportsPress Player Merge Tool
        </h1>
        <p class="sp-merge-description">
            Merge duplicate players while preserving all data. Changes can be reverted if needed.
        </p>
    </div>

    <!-- Main Content Container -->
    <div class="sp-merge-container">
        
        <!-- Merge Form Card -->
        <div class="sp-merge-card">
            <div class="sp-merge-card-header">
                <h2>Select Players to Merge</h2>
            </div>
            
            <div class="sp-merge-card-body">
                <form id="sp-merge-form">
                    <?php wp_nonce_field('sp_merge_nonce', 'sp_merge_nonce'); ?>
                    
                    <!-- Primary Player Selection -->
                    <div class="sp-form-group">
                        <label for="primary-player" class="sp-form-label">
                            <span class="dashicons dashicons-star-filled"></span>
                            Primary Player (will be kept)
                        </label>
                        <select name="primary_player" id="primary-player" class="sp-form-select" required>
                            <option value="">Choose the player to keep...</option>
                            <?php foreach ($players as $player): ?>
                                <option value="<?php echo esc_attr($player['id']); ?>">
                                    <?php echo esc_html($player['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="sp-form-help">This player will retain all data and remain in the system.</p>
                    </div>
                    
                    <!-- Duplicate Players Selection -->
                    <div class="sp-form-group">
                        <label for="duplicate-players" class="sp-form-label">
                            <span class="dashicons dashicons-trash"></span>
                            Duplicate Players (will be merged and deleted)
                        </label>
                        <select name="duplicate_players[]" id="duplicate-players" class="sp-form-multiselect" multiple size="8">
                            <?php foreach ($players as $player): ?>
                                <option value="<?php echo esc_attr($player['id']); ?>">
                                    <?php echo esc_html($player['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="sp-form-help">
                            <span class="dashicons dashicons-info"></span>
                            Hold <kbd>Ctrl</kbd> (or <kbd>Cmd</kbd> on Mac) to select multiple players
                        </p>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="sp-form-actions">
                        <button type="button" id="preview-merge" class="button button-secondary sp-btn-preview">
                            <span class="dashicons dashicons-visibility"></span>
                            Preview Merge
                        </button>
                        <button type="button" id="execute-merge" class="button button-primary sp-btn-execute" disabled>
                            <span class="dashicons dashicons-admin-tools"></span>
                            Execute Merge
                        </button>
                        <button type="button" id="revert-merge" class="button button-secondary sp-btn-revert" disabled style="display:none;">
                            <span class="dashicons dashicons-undo"></span>
                            Revert Last Merge
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Preview Results Card -->
        <div id="merge-preview-card" class="sp-merge-card" style="display:none;">
            <div class="sp-merge-card-header">
                <h2>
                    <span class="dashicons dashicons-visibility"></span>
                    Merge Preview
                </h2>
            </div>
            <div class="sp-merge-card-body">
                <div id="preview-content">
                    <!-- Preview content will be loaded here via AJAX -->
                </div>
            </div>
        </div>
        
        <!-- Recent Backups -->
        <?php $recent_backups = $this->get_recent_backups(); ?>
        <?php if (!empty($recent_backups)): ?>
        <div class="sp-merge-card">
            <div class="sp-merge-card-header">
                <h2>
                    <span class="dashicons dashicons-backup"></span>
                    Recent Merges (Available for Revert)
                </h2>
                <div class="sp-backup-actions">
                    <button type="button" id="select-all-backups" class="button button-secondary">Select All</button>
                    <button type="button" id="delete-selected-backups" class="button button-secondary" disabled>
                        <span class="dashicons dashicons-trash"></span> Delete Selected
                    </button>
                </div>
            </div>
            <div class="sp-merge-card-body">
                <?php foreach ($recent_backups as $backup): ?>
                <div class="sp-backup-item">
                    <input type="checkbox" class="backup-checkbox" value="<?php echo esc_attr($backup['id']); ?>" id="backup-<?php echo esc_attr($backup['id']); ?>">
                    <label for="backup-<?php echo esc_attr($backup['id']); ?>">
                        <strong><?php echo esc_html($backup['primary_name']); ?></strong> ← <?php echo esc_html(implode(', ', $backup['duplicate_names'])); ?>
                    </label>
                    <span class="sp-backup-date"><?php echo esc_html($backup['date']); ?></span>
                    <div class="sp-backup-buttons">
                        <button type="button" class="button button-secondary sp-revert-backup" data-backup-id="<?php echo esc_attr($backup['id']); ?>">
                            <span class="dashicons dashicons-undo"></span> Revert
                        </button>
                        <button type="button" class="button button-secondary sp-delete-backup" data-backup-id="<?php echo esc_attr($backup['id']); ?>">
                            <span class="dashicons dashicons-trash"></span> Delete
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Status Messages -->
        <div id="sp-merge-messages" class="sp-merge-messages">
            <!-- Status messages will appear here -->
        </div>
        
    </div>
    
    <!-- Loading Overlay -->
    <div id="sp-merge-loading" class="sp-merge-loading" style="display:none;">
        <div class="sp-loading-spinner">
            <div class="sp-spinner"></div>
            <p>Processing merge operation...</p>
        </div>
    </div>
    
</div>

<!-- Help Section -->
<div class="sp-merge-help">
    <h3>How to Use This Tool</h3>
    <ol>
        <li><strong>Select Primary Player:</strong> Choose the player you want to keep. This player will retain all existing data.</li>
        <li><strong>Select Duplicates:</strong> Choose one or more duplicate players to merge into the primary player.</li>
        <li><strong>Preview:</strong> Click "Preview Merge" to see what data will be combined.</li>
        <li><strong>Execute:</strong> Click "Execute Merge" to perform the merge operation.</li>
        <li><strong>Revert:</strong> If needed, you can revert the last merge operation to restore deleted players.</li>
    </ol>
    
    <div class="sp-merge-warning">
        <p><strong>Important:</strong> Always preview your merge before executing. While merges can be reverted, it's best to be certain of your selections.</p>
    </div>
</div>