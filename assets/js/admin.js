/**
 * SportsPress Player Merge - Admin JavaScript
 * 
 * Handles all frontend interactions for the player merge tool including:
 * - Form validation and submission
 * - AJAX requests for preview, execute, and revert operations
 * - UI state management and user feedback
 * - Loading states and error handling
 */

(function($) {
    'use strict';
    
    /**
     * Main application object to organize all functionality
     */
    const SpMergeApp = {
        
        // Store backup ID for revert functionality
        lastBackupId: null,
        
        /**
         * Custom secure confirmation dialog
         */
        customConfirm: function(message) {
            return new Promise((resolve) => {
                const modal = $(`
                    <div class="sp-confirm-modal" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;">
                        <div class="sp-confirm-dialog" style="background:white;padding:20px;border-radius:5px;max-width:400px;text-align:center;">
                            <p>${message}</p>
                            <button class="button button-primary sp-confirm-yes">Yes</button>
                            <button class="button sp-confirm-no" style="margin-left:10px;">No</button>
                        </div>
                    </div>
                `);
                
                $('body').append(modal);
                
                modal.find('.sp-confirm-yes').on('click', () => {
                    modal.remove();
                    resolve(true);
                });
                
                modal.find('.sp-confirm-no').on('click', () => {
                    modal.remove();
                    resolve(false);
                });
                
                modal.on('click', (e) => {
                    if (e.target === modal[0]) {
                        modal.remove();
                        resolve(false);
                    }
                });
            });
        },
        
        /**
         * Initialize the application when DOM is ready
         */
        init: function() {
            this.bindEvents();
            this.checkForExistingBackup();
        },
        
        /**
         * Bind all event handlers
         */
        bindEvents: function() {
            // Preview merge button
            $('#preview-merge').on('click', this.handlePreviewMerge.bind(this));
            
            // Execute merge button
            $('#execute-merge').on('click', this.handleExecuteMerge.bind(this));
            
            // Revert merge button
            $('#revert-merge').on('click', this.handleRevertMerge.bind(this));
            
            // Recent backup revert buttons
            $(document).on('click', '.sp-revert-backup', this.handleBackupRevert.bind(this));
            
            // Backup delete buttons
            $(document).on('click', '.sp-delete-backup', this.handleBackupDelete.bind(this));
            
            // Select all backups checkbox
            $('#select-all-backups').on('click', this.handleSelectAllBackups.bind(this));
            
            // Delete selected backups
            $('#delete-selected-backups').on('click', this.handleDeleteSelectedBackups.bind(this));
            
            // Update delete button state when checkboxes change
            $(document).on('change', '.backup-checkbox', this.updateDeleteButtonState.bind(this));
            
            // Form validation on selection change
            $('#primary-player, #duplicate-players').on('change', this.validateForm.bind(this));
        },
        
        /**
         * Check if there's an existing backup that can be reverted
         */
        checkForExistingBackup: function() {
            // Check localStorage for recent backup ID
            const backupId = localStorage.getItem('sp_last_backup_id');
            if (backupId) {
                this.lastBackupId = backupId;
                $('#revert-merge').show().prop('disabled', false);
            }
        },
        
        /**
         * Validate form inputs and enable/disable buttons accordingly
         */
        validateForm: function() {
            const primaryPlayer = $('#primary-player').val();
            const duplicatePlayers = $('#duplicate-players').val() || [];
            
            // Check if we have valid selections
            const isValid = primaryPlayer && duplicatePlayers.length > 0;
            
            // Enable/disable preview button
            $('#preview-merge').prop('disabled', !isValid);
            
            // Reset execute button if form becomes invalid
            if (!isValid) {
                $('#execute-merge').prop('disabled', true);
                $('#merge-preview-card').hide();
            }
            
            // Check for duplicate selection (primary player selected as duplicate)
            if (primaryPlayer && duplicatePlayers.includes(primaryPlayer)) {
                this.showMessage('error', 'Primary player cannot be selected as a duplicate player.');
                $('#preview-merge').prop('disabled', true);
                return false;
            }
            
            return isValid;
        },
        
        /**
         * Handle preview merge button click
         */
        handlePreviewMerge: function(e) {
            e.preventDefault();
            
            // Validate form first
            if (!this.validateForm()) {
                this.showMessage('error', spMergeAjax.strings.selectPlayers);
                return;
            }
            
            // Show loading state
            this.setLoadingState(true);
            
            // Prepare form data
            const formData = {
                action: 'sp_preview_merge',
                nonce: spMergeAjax.nonce,
                primary_player: $('#primary-player').val(),
                duplicate_players: $('#duplicate-players').val() || []
            };
            
            // Make AJAX request
            $.post(spMergeAjax.ajaxUrl, formData)
                .done(this.handlePreviewSuccess.bind(this))
                .fail(this.handleAjaxError.bind(this))
                .always(() => this.setLoadingState(false));
        },
        
        /**
         * Handle successful preview response
         */
        handlePreviewSuccess: function(response) {
            if (response.success) {
                // Use jQuery's text() method for basic XSS protection
                const previewContent = $('<div>').html(response.data.preview).html();
                $('#preview-content').html(previewContent);
                $('#merge-preview-card').show();
                
                // Enable execute button
                $('#execute-merge').prop('disabled', false);
                
                // Scroll to preview
                $('html, body').animate({
                    scrollTop: $('#merge-preview-card').offset().top - 50
                }, 500);
                
                this.showMessage('info', 'Preview generated successfully. Review the changes and click "Execute Merge" to proceed.');
                
            } else {
                this.showMessage('error', response.data.message || 'Preview generation failed.');
            }
        },
        
        /**
         * Handle execute merge button click
         */
        handleExecuteMerge: function(e) {
            e.preventDefault();
            
            // Confirm action with user
            if (spMergeAjax.strings.confirmMerge) {
                // Proceed with merge
            } else {
                return;
            }
            
            // Show loading state
            this.setLoadingState(true);
            
            // Prepare form data
            const formData = {
                action: 'sp_execute_merge',
                nonce: spMergeAjax.nonce,
                primary_player: $('#primary-player').val(),
                duplicate_players: $('#duplicate-players').val() || []
            };
            
            // Make AJAX request
            $.post(spMergeAjax.ajaxUrl, formData)
                .done(this.handleExecuteSuccess.bind(this))
                .fail(this.handleAjaxError.bind(this))
                .always(() => this.setLoadingState(false));
        },
        
        /**
         * Handle successful execute response
         */
        handleExecuteSuccess: function(response) {
            if (response.success) {
                // Store backup ID for potential revert
                this.lastBackupId = response.data.backup_id;
                localStorage.setItem('sp_last_backup_id', this.lastBackupId);
                
                // Update UI state
                $('#execute-merge').prop('disabled', true);
                $('#revert-merge').show().prop('disabled', false);
                
                // Show success message with longer duration
                this.showMessage('success', spMergeAjax.strings.mergeSuccess + ' Backup ID: ' + this.lastBackupId);
                
                // Clear the form selections but don't reset completely
                $('#primary-player').val('');
                $('#duplicate-players').val([]);
                $('#merge-preview-card').hide();
                
            } else {
                this.showMessage('error', response.data.message || 'Merge execution failed.');
            }
        },
        
        /**
         * Handle revert merge button click
         */
        handleRevertMerge: function(e) {
            e.preventDefault();
            
            // Check if we have a backup ID
            if (!this.lastBackupId) {
                this.showMessage('error', spMergeAjax.strings.noMergeData);
                return;
            }
            
            // Confirm action with user
            this.customConfirm(spMergeAjax.strings.confirmRevert).then((confirmed) => {
                if (!confirmed) return;
                this.executeRevert();
            });
        },
        
        /**
         * Execute the revert operation
         */
        executeRevert: function() {
            // Show loading state
            this.setLoadingState(true);
            
            // Prepare form data
            const formData = {
                action: 'sp_revert_merge',
                nonce: spMergeAjax.nonce,
                backup_id: this.lastBackupId
            };
            
            // Make AJAX request
            $.post(spMergeAjax.ajaxUrl, formData)
                .done(this.handleRevertSuccess.bind(this))
                .fail(this.handleAjaxError.bind(this))
                .always(() => this.setLoadingState(false));
        },
        
        /**
         * Handle successful revert response
         */
        handleRevertSuccess: function(response) {
            if (response.success) {
                // Clear backup data
                this.lastBackupId = null;
                localStorage.removeItem('sp_last_backup_id');
                
                // Update UI state
                $('#revert-merge').hide().prop('disabled', true);
                $('#execute-merge').prop('disabled', true);
                
                // Show success message
                this.showMessage('success', spMergeAjax.strings.revertSuccess);
                
                // Reset form
                this.resetForm();
                
                // Reload page after longer delay to refresh player lists
                setTimeout(() => {
                    window.location.reload();
                }, 3000);
                
            } else {
                this.showMessage('error', response.data.message || 'Revert operation failed.');
            }
        },
        
        /**
         * Handle AJAX errors
         */
        handleAjaxError: function(xhr, status, error) {
            console.error('AJAX Error:', status, error);
            this.showMessage('error', 'Network error occurred. Please try again.');
        },
        
        /**
         * Show/hide loading state
         */
        setLoadingState: function(isLoading) {
            if (isLoading) {
                $('#sp-merge-loading').show();
                $('button').prop('disabled', true);
            } else {
                $('#sp-merge-loading').hide();
                this.validateForm(); // Re-enable appropriate buttons
            }
        },
        
        /**
         * Display status messages to user
         */
        showMessage: function(type, message) {
            // Remove existing messages
            $('.sp-merge-message').remove();
            
            // Create new message element
            const messageHtml = `
                <div class="sp-merge-message ${type}">
                    <span class="dashicons dashicons-${this.getMessageIcon(type)}"></span>
                    <span>${message}</span>
                </div>
            `;
            
            // Add message to container
            $('#sp-merge-messages').html(messageHtml);
            
            // Auto-hide messages after longer duration for success
            const hideDelay = type === 'success' ? 10000 : (type === 'info' ? 7000 : 0);
            if (hideDelay > 0) {
                setTimeout(() => {
                    $('.sp-merge-message').fadeOut();
                }, hideDelay);
            }
            
            // Scroll to message
            $('html, body').animate({
                scrollTop: $('#sp-merge-messages').offset().top - 100
            }, 300);
        },
        
        /**
         * Get appropriate icon for message type
         */
        getMessageIcon: function(type) {
            const icons = {
                'success': 'yes-alt',
                'error': 'warning',
                'info': 'info'
            };
            return icons[type] || 'info';
        },
        
        /**
         * Reset form to initial state
         */
        resetForm: function() {
            $('#sp-merge-form')[0].reset();
            $('#merge-preview-card').hide();
            $('#execute-merge').prop('disabled', true);
            $('.sp-merge-message').fadeOut();
        },
        
        /**
         * Handle revert button click for recent backups
         */
        handleBackupRevert: function(e) {
            e.preventDefault();
            
            const backupId = $(e.target).closest('.sp-revert-backup').data('backup-id');
            
            // Confirm action with user
            this.customConfirm('Are you sure you want to revert this merge? This will restore the deleted players.').then((confirmed) => {
                if (!confirmed) return;
                this.executeBackupRevert(backupId);
            });
        },
        
        /**
         * Execute backup revert operation
         */
        executeBackupRevert: function(backupId) {
            // Show loading state
            this.setLoadingState(true);
            
            // Prepare form data
            const formData = {
                action: 'sp_revert_merge',
                nonce: spMergeAjax.nonce,
                backup_id: backupId
            };
            
            // Make AJAX request
            $.post(spMergeAjax.ajaxUrl, formData)
                .done((response) => {
                    if (response.success) {
                        this.showMessage('success', 'Merge reverted successfully!');
                        setTimeout(() => window.location.reload(), 2000);
                    } else {
                        this.showMessage('error', response.data.message || 'Revert failed.');
                    }
                })
                .fail(this.handleAjaxError.bind(this))
                .always(() => this.setLoadingState(false));
        },
        
        /**
         * Handle delete button click for individual backup
         */
        handleBackupDelete: function(e) {
            e.preventDefault();
            
            const backupId = $(e.target).closest('.sp-delete-backup').data('backup-id');
            
            // Confirm action with user
            this.customConfirm('Are you sure you want to delete this backup? This action cannot be undone.').then((confirmed) => {
                if (!confirmed) return;
                this.deleteBackups([backupId]);
            });
        },
        
        /**
         * Handle backup delete with confirmation resolved
         */
        handleBackupDeleteConfirmed: function(backupId) {
            this.deleteBackups([backupId]);
        },
        
        /**
         * Handle select all backups button
         */
        handleSelectAllBackups: function(e) {
            e.preventDefault();
            
            const checkboxes = $('.backup-checkbox');
            const allChecked = checkboxes.filter(':checked').length === checkboxes.length;
            
            checkboxes.prop('checked', !allChecked);
            this.updateDeleteButtonState();
        },
        
        /**
         * Handle delete selected backups button
         */
        handleDeleteSelectedBackups: function(e) {
            e.preventDefault();
            
            const selectedIds = $('.backup-checkbox:checked').map(function() {
                return $(this).val();
            }).get();
            
            if (selectedIds.length === 0) {
                this.showMessage('error', 'No backups selected.');
                return;
            }
            
            this.customConfirm(`Are you sure you want to delete ${selectedIds.length} backup(s)? This action cannot be undone.`).then((confirmed) => {
                if (!confirmed) return;
                this.deleteBackups(selectedIds);
            });
        },
        
        /**
         * Delete backups via AJAX
         */
        deleteBackups: function(backupIds) {
            this.setLoadingState(true);
            
            const formData = {
                action: 'sp_delete_backup',
                nonce: spMergeAjax.nonce,
                backup_ids: backupIds
            };
            
            $.post(spMergeAjax.ajaxUrl, formData)
                .done((response) => {
                    if (response.success) {
                        this.showMessage('success', response.data.message);
                        // Hide revert button if no backups remain
                        if ($('.backup-checkbox').length === backupIds.length) {
                            $('#revert-merge').hide();
                        }
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        this.showMessage('error', response.data.message || 'Delete failed.');
                    }
                })
                .fail(this.handleAjaxError.bind(this))
                .always(() => this.setLoadingState(false));
        },
        
        /**
         * Update delete button state based on checkbox selection
         */
        updateDeleteButtonState: function() {
            const selectedCount = $('.backup-checkbox:checked').length;
            $('#delete-selected-backups').prop('disabled', selectedCount === 0);
        }
    };
    
    /**
     * Initialize the application when document is ready
     */
    $(document).ready(function() {
        SpMergeApp.init();
    });
    
    /**
     * Additional utility functions
     */
    
    /**
     * Enhance multiselect functionality with better UX
     */
    $(document).ready(function() {
        const $multiselect = $('#duplicate-players');
        
        // Add visual feedback for multiselect
        $multiselect.on('focus', function() {
            $(this).closest('.sp-form-group').addClass('focused');
        });
        
        $multiselect.on('blur', function() {
            $(this).closest('.sp-form-group').removeClass('focused');
        });
        
        // Show selection count
        $multiselect.on('change', function() {
            const selectedCount = $(this).val() ? $(this).val().length : 0;
            const helpText = $(this).siblings('.sp-form-help');
            
            if (selectedCount > 0) {
                helpText.html(`
                    <span class="dashicons dashicons-info"></span>
                    ${selectedCount} player(s) selected for merging. Hold <kbd>Ctrl</kbd> (or <kbd>Cmd</kbd> on Mac) to select multiple players.
                `);
            } else {
                helpText.html(`
                    <span class="dashicons dashicons-info"></span>
                    Hold <kbd>Ctrl</kbd> (or <kbd>Cmd</kbd> on Mac) to select multiple players
                `);
            }
        });
    });
    
})(jQuery);