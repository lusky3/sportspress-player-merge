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
                const modal = $('<div class="sp-confirm-modal" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;"></div>');
                const dialog = $('<div class="sp-confirm-dialog" style="background:white;padding:20px;border-radius:5px;max-width:400px;text-align:center;"></div>');
                const messageP = $('<p></p>').text(message);
                const yesBtn = $('<button class="button button-primary sp-confirm-yes">Yes</button>');
                const noBtn = $('<button class="button sp-confirm-no" style="margin-left:10px;">No</button>');
                
                dialog.append(messageP, yesBtn, noBtn);
                modal.append(dialog);
                
                $('body').append(modal);
                
                modal.find('.sp-confirm-yes').on('click', () => {
                    modal.remove();
                    resolve(true);
                });
                
                modal.find('.sp-confirm-no').on('click', () => {
                    modal.remove();
                    resolve(false);
                });
                
                // amazon-q-ignore: javascript-cross-site-scripting-ide - Safe DOM event handling, no user input
                modal.on('click', (e) => {
                    // Check if user has permission to close modal (basic client-side check)
                    if (typeof spMergeAjax !== 'undefined' && spMergeAjax.userCanEdit) {
                        if (e.target === modal[0]) {
                            modal.remove();
                            resolve(false);
                        }
                    }
                });
            });
        },
        
        /**
         * Initialize the application when DOM is ready
         */
        // amazon-q-ignore: javascript-missing-authorization - Authorization handled server-side via nonces
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
            
            // Cancel preview button
            $('#cancel-preview').on('click', this.handleCancelPreview.bind(this));
            
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
            
            // Expandable preview sections
            $(document).on('click', '.sp-expand-toggle', this.handleExpandToggle.bind(this));
        },
        
        /**
         * Sanitize HTML content to prevent XSS while allowing safe formatting
         */
        sanitizeHtml: function(html) {
            const temp = $('<div>').html(html);
            
            temp.find('script').remove();
            temp.find('*').each(function() {
                const attributes = this.attributes;
                for (let i = attributes.length - 1; i >= 0; i--) {
                    const attr = attributes[i];
                    const attrName = (attr.name || '').toString().toLowerCase();
                    
                    // Remove event handlers and dangerous attributes
                    if (attrName.startsWith('on') || 
                        attrName === 'javascript' ||
                        this.getAttribute(attrName)?.toLowerCase().includes('javascript:')) {
                        this.removeAttribute(attr.name);
                    }
                }
            });
            
            return temp.html();
        },
        
        /**
         * Check if there's an existing backup that can be reverted
         */
        checkForExistingBackup: function() {
            // Check if there are any recent backups available
            const hasRecentBackups = $('.sp-revert-backup').length > 0;
            
            if (hasRecentBackups) {
                // Get the most recent backup ID from the first revert button
                const mostRecentBackupId = $('.sp-revert-backup').first().data('backup-id');
                if (mostRecentBackupId) {
                    this.lastBackupId = mostRecentBackupId;
                    $('#revert-merge').show().prop('disabled', false);
                }
            } else {
                // No backups available, hide revert button
                $('#revert-merge').hide();
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
                // Re-enable backup buttons when preview is hidden
                this.updateBackupButtonStates();
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
            console.log('Preview response status:', response.success ? 'success' : 'failed');
            
            if (response.success) {
                // Safely display server-generated preview content with HTML support
                if (response.data && response.data.preview) {
                    console.log('Preview data received, length:', response.data.preview.length);
                    // Sanitize HTML to allow safe formatting while preventing XSS
                    const sanitizedPreview = this.sanitizeHtml(response.data.preview);
                    $('#preview-content').html(sanitizedPreview);
                } else {
                    console.log('No preview data in response');
                    $('#preview-content').text('No preview available');
                }
                $('#merge-preview-card').removeClass('sp-hidden').show();
                
                // Enable execute button
                $('#execute-merge').prop('disabled', false);
                
                // Scroll to preview
                $('html, body').animate({
                    scrollTop: $('#merge-preview-card').offset().top - 50
                }, 500);
                
                this.showMessage('info', 'Preview generated successfully. Review the changes and click "Execute Merge" to proceed.');
                
                // Update backup button states for preview mode
                this.updateBackupButtonStates();
                
            } else {
                console.log('Preview failed - error occurred');
                this.showMessage('error', response.data.message || 'Preview generation failed.');
            }
        },
        
        /**
         * Handle cancel preview button click
         */
        handleCancelPreview: function(e) {
            e.preventDefault();
            
            // Hide preview card
            $('#merge-preview-card').hide();
            
            // Disable execute button
            $('#execute-merge').prop('disabled', true);
            
            // Re-enable backup buttons
            this.updateBackupButtonStates();
            
            // Re-enable revert button if backups exist
            this.checkForExistingBackup();
            
            // Show info message
            this.showMessage('info', 'Preview cancelled. You can generate a new preview or select different players.');
        },
        
        /**
         * Handle expand/collapse toggle in preview
         */
        handleExpandToggle: function(e) {
            e.preventDefault();
            const $toggle = $(e.target);
            const target = $toggle.data('target');
            const $targetDiv = $('#' + target);
            
            // Store original text on first click
            if (!$toggle.data('original-text')) {
                $toggle.data('original-text', $toggle.text());
            }
            
            $targetDiv.toggle();
            const isVisible = $targetDiv.is(':visible');
            
            if (isVisible) {
                $toggle.text('Show Less');
            } else {
                $toggle.text($toggle.data('original-text'));
            }
        },
        
        /**
         * Handle execute merge button click
         */
        handleExecuteMerge: function(e) {
            e.preventDefault();
            
            // Confirm action with user
            this.customConfirm(spMergeAjax.strings.confirmMerge).then((confirmed) => {
                if (!confirmed) return;
                this.proceedWithMerge();
            });
        },
        
        /**
         * Proceed with merge after confirmation
         */
        proceedWithMerge: function() {
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
                
                // Show success message with longer duration
                this.showMessage('success', spMergeAjax.strings.mergeSuccess + ' Backup ID: ' + this.lastBackupId);
                
                // Clear the form selections but don't reset completely
                $('#primary-player').val('');
                $('#duplicate-players').val([]);
                $('#merge-preview-card').hide();
                
                // Re-enable backup buttons after merge completion
                this.updateBackupButtonStates();
                
                // Refresh backup section to show new backup and enable revert button
                this.refreshBackupSection();
                
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
                $('button:not(#cancel-preview)').prop('disabled', true);
                $('.sp-revert-backup, .sp-delete-backup').prop('disabled', true);
            } else {
                $('#sp-merge-loading').hide();
                this.validateForm(); // Re-enable appropriate buttons
                this.updateBackupButtonStates();
            }
        },
        
        /**
         * Display status messages to user
         */
        showMessage: function(type, message, duration) {
            // Remove existing messages
            $('.sp-merge-message').remove();
            
            // amazon-q-ignore: javascript-cross-site-scripting-ide - Using .text() method prevents XSS
            const messageDiv = $('<div></div>').addClass('sp-merge-message').addClass(type);
            const iconSpan = $('<span></span>').addClass('dashicons').addClass('dashicons-' + this.getMessageIcon(type));
            const textSpan = $('<span></span>').text(message);
            
            messageDiv.append(iconSpan, textSpan);
            
            // Add message to container
            $('#sp-merge-messages').html(messageDiv);
            
            // Auto-hide messages with custom or default duration
            if (duration) {
                setTimeout(() => {
                    $('.sp-merge-message').fadeOut();
                }, parseInt(duration, 10) || 0);
            } else if (type === 'success') {
                setTimeout(() => {
                    $('.sp-merge-message').fadeOut();
                }, 10000);
            } else if (type === 'info') {
                setTimeout(() => {
                    $('.sp-merge-message').fadeOut();
                }, 7000);
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
        },
        
        /**
         * Refresh backup section after merge
         */
        refreshBackupSection: function() {
            const formData = {
                action: 'sp_get_recent_backups',
                nonce: spMergeAjax.nonce
            };
            
            $.post(spMergeAjax.ajaxUrl, formData)
                .done((response) => {
                    if (response.success && response.data.html) {
                        // Find the backup section and update it
                        let $backupCard = $('.sp-merge-card').has('h2:contains("Recent Merges")');
                        
                        if ($backupCard.length) {
                            // Update existing backup section
                            $backupCard.find('.sp-merge-card-body').html(response.data.html);
                        } else {
                            // Create new backup section if it doesn't exist
                            this.createBackupSection(response.data.html);
                        }
                        
                        // Update revert button after backup section refresh
                        this.checkForExistingBackup();
                        
                        // Explicitly show revert button since we just created a backup
                        $('#revert-merge').removeClass('sp-hidden').show().prop('disabled', false);
                    }
                })
                .fail(() => {
                    console.log('Failed to refresh backup section');
                });
        },
        
        /**
         * Create backup section when it doesn't exist
         */
        createBackupSection: function(backupHtml) {
            const backupSection = `
                <div class="sp-merge-card sp-backup-section">
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
                        ${backupHtml}
                    </div>
                </div>
            `;
            
            // Insert before the messages section
            $('#sp-merge-messages').before(backupSection);
        },
        
        /**
         * Update backup button states based on current mode
         */
        updateBackupButtonStates: function() {
            const isPreviewMode = $('#merge-preview-card').is(':visible');
            
            if (isPreviewMode) {
                // Disable ALL backup functionality during preview for consistency
                $('.sp-revert-backup, .sp-delete-backup').prop('disabled', true)
                    .attr('title', 'Complete or cancel the current merge preview to use this function');
                
                // Disable bulk delete button
                $('#delete-selected-backups').prop('disabled', true)
                    .attr('title', 'Complete or cancel the current merge preview to use this function');
                
                // Disable checkboxes to prevent confusion
                $('.backup-checkbox, #select-all-backups').prop('disabled', true);
            } else {
                // Re-enable backup buttons when not in preview mode
                $('.sp-revert-backup, .sp-delete-backup').prop('disabled', false)
                    .removeAttr('title');
                
                // Re-enable checkboxes
                $('.backup-checkbox, #select-all-backups').prop('disabled', false);
                
                // Re-enable bulk delete based on checkbox selection
                this.updateDeleteButtonState();
            }
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
                // amazon-q-ignore: javascript-code-injection-ide - selectedCount is integer, text is static
                const icon = $('<span class="dashicons dashicons-info"></span>');
                const text = selectedCount + ' player(s) selected for merging. Hold Ctrl (or Cmd on Mac) to select multiple players.';
                helpText.empty().append(icon, ' ', text);
            } else {
                const icon = $('<span class="dashicons dashicons-info"></span>');
                const text = 'Hold Ctrl (or Cmd on Mac) to select multiple players';
                helpText.empty().append(icon, ' ', text);
            }
        });
    });
    
})(jQuery);