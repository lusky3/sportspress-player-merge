/**
 * SportsPress Player Merge - Admin JavaScript
 *
 * Handles all frontend interactions for the player merge tool.
 *
 * @package SportsPress_Player_Merge
 */

( function( $ ) {
	'use strict';

	var SpMergeApp = {

		lastBackupId: null,

		/**
		 * Accessible confirmation dialog with ARIA attributes and focus trapping.
		 *
		 * @param {string} message Confirmation message.
		 * @return {Promise<boolean>}
		 */
		customConfirm: function( message ) {
			return new Promise( function( resolve ) {
				var $trigger = $( document.activeElement );
				var $modal = $( '<div class="sp-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="sp-confirm-title" tabindex="-1"></div>' );
				var $dialog = $( '<div class="sp-confirm-dialog"></div>' );
				var $title = $( '<p id="sp-confirm-title"></p>' ).text( message );
				var $yesBtn = $( '<button class="button button-primary sp-confirm-yes">Yes</button>' );
				var $noBtn = $( '<button class="button sp-confirm-no" style="margin-left:10px;">No</button>' );

				$dialog.append( $title, $yesBtn, $noBtn );
				$modal.append( $dialog );
				$( 'body' ).append( $modal );

				function closeModal( result ) {
					$modal.remove();
					$trigger.focus();
					resolve( result );
				}

				$yesBtn.on( 'click', function() {
					closeModal( true );
				} );

				$noBtn.on( 'click', function() {
					closeModal( false );
				} );

				// Close on backdrop click.
				$modal.on( 'click', function( e ) {
					if ( e.target === $modal[0] ) {
						closeModal( false );
					}
				} );

				// Escape key and focus trapping.
				$modal.on( 'keydown', function( e ) {
					if ( e.key === 'Escape' ) {
						closeModal( false );
					}
					if ( e.key === 'Tab' ) {
						var $focusable = $modal.find( 'button' );
						var first = $focusable[0];
						var last = $focusable[ $focusable.length - 1 ];
						if ( e.shiftKey && document.activeElement === first ) {
							e.preventDefault();
							last.focus();
						} else if ( ! e.shiftKey && document.activeElement === last ) {
							e.preventDefault();
							first.focus();
						}
					}
				} );

				$noBtn.focus();
			} );
		},

		init: function() {
			this.initSelect2();
			this.bindEvents();
			this.checkForExistingBackup();
		},

		/**
		 * Initialize Select2 AJAX-powered player search on both selects.
		 */
		initSelect2: function() {
			var ajaxConfig = {
				url: spMergeAjax.ajaxUrl,
				dataType: 'json',
				delay: 300,
				data: function( params ) {
					return {
						action: 'sp_search_players',
						nonce: spMergeAjax.nonce,
						search: params.term || ''
					};
				},
				processResults: function( response ) {
					if ( response.success && response.data && response.data.results ) {
						return { results: response.data.results };
					}
					return { results: [] };
				},
				cache: true
			};

			$( '#primary-player' ).select2( {
				ajax: ajaxConfig,
				placeholder: $( '#primary-player' ).data( 'placeholder' ),
				allowClear: true,
				minimumInputLength: 0,
				width: '100%'
			} );

			$( '#duplicate-players' ).select2( {
				ajax: ajaxConfig,
				placeholder: $( '#duplicate-players' ).data( 'placeholder' ),
				allowClear: true,
				minimumInputLength: 0,
				maximumSelectionLength: 10,
				width: '100%'
			} );
		},

		bindEvents: function() {
			$( '#preview-merge' ).on( 'click', this.handlePreviewMerge.bind( this ) );
			$( '#execute-merge' ).on( 'click', this.handleExecuteMerge.bind( this ) );
			$( '#revert-merge' ).on( 'click', this.handleRevertMerge.bind( this ) );
			$( '#cancel-preview' ).on( 'click', this.handleCancelPreview.bind( this ) );
			$( document ).on( 'click', '.sp-revert-backup', this.handleBackupRevert.bind( this ) );
			$( document ).on( 'click', '.sp-delete-backup', this.handleBackupDelete.bind( this ) );
			$( '#select-all-backups' ).on( 'click', this.handleSelectAllBackups.bind( this ) );
			$( '#delete-selected-backups' ).on( 'click', this.handleDeleteSelectedBackups.bind( this ) );
			$( document ).on( 'change', '.backup-checkbox', this.updateDeleteButtonState.bind( this ) );
			$( '#primary-player, #duplicate-players' ).on( 'change', this.validateForm.bind( this ) );
			$( document ).on( 'click', '.sp-expand-toggle', this.handleExpandToggle.bind( this ) );
		},

		/**
		 * Sanitize HTML by removing scripts and event handlers.
		 *
		 * @param {string} html Raw HTML.
		 * @return {string} Sanitized HTML.
		 */
		sanitizeHtml: function( html ) {
			var $temp = $( '<div>' ).html( html );
			$temp.find( 'script, iframe, object, embed' ).remove();
			$temp.find( '*' ).each( function() {
				var attrs = this.attributes;
				for ( var i = attrs.length - 1; i >= 0; i-- ) {
					var name = ( attrs[i].name || '' ).toLowerCase();
					if ( name.indexOf( 'on' ) === 0 ) {
						this.removeAttribute( attrs[i].name );
					}
				}
			} );
			return $temp.html();
		},

		checkForExistingBackup: function() {
			var $revertBtn = $( '.sp-revert-backup' ).first();
			if ( $revertBtn.length ) {
				this.lastBackupId = $revertBtn.data( 'backup-id' );
				$( '#revert-merge' ).show().prop( 'disabled', false );
			} else {
				$( '#revert-merge' ).hide();
			}
		},

		validateForm: function() {
			var primary = $( '#primary-player' ).val();
			var duplicates = $( '#duplicate-players' ).val() || [];
			var isValid = primary && duplicates.length > 0;

			$( '#preview-merge' ).prop( 'disabled', ! isValid );

			if ( ! isValid ) {
				$( '#execute-merge' ).prop( 'disabled', true );
				$( '#merge-preview-card' ).hide();
				this.updateBackupButtonStates();
			}

			if ( primary && duplicates.indexOf( primary ) !== -1 ) {
				this.showMessage( 'error', 'Primary player cannot be selected as a duplicate player.' );
				$( '#preview-merge' ).prop( 'disabled', true );
				return false;
			}

			return isValid;
		},

		handlePreviewMerge: function( e ) {
			e.preventDefault();
			if ( ! this.validateForm() ) {
				this.showMessage( 'error', spMergeAjax.strings.selectPlayers );
				return;
			}

			this.setLoadingState( true );

			$.post( spMergeAjax.ajaxUrl, {
				action: 'sp_preview_merge',
				nonce: spMergeAjax.nonce,
				primary_player: $( '#primary-player' ).val(),
				duplicate_players: $( '#duplicate-players' ).val() || []
			} )
				.done( this.handlePreviewSuccess.bind( this ) )
				.fail( this.handleAjaxError.bind( this ) )
				.always( this.setLoadingState.bind( this, false ) );
		},

		handlePreviewSuccess: function( response ) {
			if ( response.success && response.data && response.data.preview ) {
				$( '#preview-content' ).html( this.sanitizeHtml( response.data.preview ) );
				$( '#merge-preview-card' ).removeClass( 'sp-hidden' ).show();
				$( '#execute-merge' ).prop( 'disabled', false );

				$( 'html, body' ).animate( {
					scrollTop: $( '#merge-preview-card' ).offset().top - 50
				}, 500 );

				this.showMessage( 'info', 'Preview generated. Review the changes and click "Execute Merge" to proceed.' );
				this.updateBackupButtonStates();
			} else {
				this.showMessage( 'error', ( response.data && response.data.message ) || 'Preview generation failed.' );
			}
		},

		handleCancelPreview: function( e ) {
			e.preventDefault();
			$( '#merge-preview-card' ).hide();
			$( '#execute-merge' ).prop( 'disabled', true );
			this.updateBackupButtonStates();
			this.checkForExistingBackup();
			this.showMessage( 'info', 'Preview cancelled.' );
		},

		handleExpandToggle: function( e ) {
			e.preventDefault();
			var $toggle = $( e.target );
			var $target = $( '#' + $toggle.data( 'target' ) );

			if ( ! $toggle.data( 'original-text' ) ) {
				$toggle.data( 'original-text', $toggle.text() );
			}

			$target.toggle();
			$toggle.text( $target.is( ':visible' ) ? 'Show Less' : $toggle.data( 'original-text' ) );
		},

		handleExecuteMerge: function( e ) {
			e.preventDefault();
			var self = this;
			this.customConfirm( spMergeAjax.strings.confirmMerge ).then( function( confirmed ) {
				if ( confirmed ) {
					self.proceedWithMerge();
				}
			} );
		},

		proceedWithMerge: function() {
			this.setLoadingState( true );

			$.post( spMergeAjax.ajaxUrl, {
				action: 'sp_execute_merge',
				nonce: spMergeAjax.nonce,
				primary_player: $( '#primary-player' ).val(),
				duplicate_players: $( '#duplicate-players' ).val() || []
			} )
				.done( this.handleExecuteSuccess.bind( this ) )
				.fail( this.handleAjaxError.bind( this ) )
				.always( this.setLoadingState.bind( this, false ) );
		},

		handleExecuteSuccess: function( response ) {
			if ( response.success ) {
				this.lastBackupId = response.data.backup_id;
				try {
					localStorage.setItem( 'sp_last_backup_id', this.lastBackupId );
				} catch ( e ) {
					// localStorage unavailable (private browsing).
				}

				$( '#execute-merge' ).prop( 'disabled', true );
				this.showMessage( 'success', spMergeAjax.strings.mergeSuccess + ' Backup ID: ' + this.lastBackupId );

				$( '#primary-player' ).val( '' );
				$( '#duplicate-players' ).val( [] );
				$( '#merge-preview-card' ).hide();

				this.updateBackupButtonStates();
				this.refreshBackupSection();
			} else {
				this.showMessage( 'error', ( response.data && response.data.message ) || 'Merge execution failed.' );
			}
		},

		handleRevertMerge: function( e ) {
			e.preventDefault();
			if ( ! this.lastBackupId ) {
				this.showMessage( 'error', spMergeAjax.strings.noMergeData );
				return;
			}
			var self = this;
			this.customConfirm( spMergeAjax.strings.confirmRevert ).then( function( confirmed ) {
				if ( confirmed ) {
					self.executeRevert();
				}
			} );
		},

		executeRevert: function() {
			this.setLoadingState( true );

			$.post( spMergeAjax.ajaxUrl, {
				action: 'sp_revert_merge',
				nonce: spMergeAjax.nonce,
				backup_id: this.lastBackupId
			} )
				.done( this.handleRevertSuccess.bind( this ) )
				.fail( this.handleAjaxError.bind( this ) )
				.always( this.setLoadingState.bind( this, false ) );
		},

		handleRevertSuccess: function( response ) {
			if ( response.success ) {
				this.lastBackupId = null;
				try {
					localStorage.removeItem( 'sp_last_backup_id' );
				} catch ( e ) {
					// localStorage unavailable.
				}

				$( '#revert-merge' ).hide().prop( 'disabled', true );
				$( '#execute-merge' ).prop( 'disabled', true );
				this.showMessage( 'success', spMergeAjax.strings.revertSuccess );
				this.resetForm();

				setTimeout( function() {
					window.location.reload();
				}, 3000 );
			} else {
				this.showMessage( 'error', ( response.data && response.data.message ) || 'Revert operation failed.' );
			}
		},

		handleAjaxError: function() {
			this.showMessage( 'error', 'Network error occurred. Please try again.' );
		},

		setLoadingState: function( isLoading ) {
			if ( isLoading ) {
				$( '#sp-merge-loading' ).show();
				$( 'button:not(#cancel-preview)' ).prop( 'disabled', true );
				$( '.sp-revert-backup, .sp-delete-backup' ).prop( 'disabled', true );
			} else {
				$( '#sp-merge-loading' ).hide();
				this.validateForm();
				this.updateBackupButtonStates();
			}
		},

		showMessage: function( type, message, duration ) {
			$( '.sp-merge-message' ).remove();

			var icons = { success: 'yes-alt', error: 'warning', info: 'info' };
			var $msg = $( '<div class="sp-merge-message"></div>' ).addClass( type );
			$msg.append(
				$( '<span class="dashicons"></span>' ).addClass( 'dashicons-' + ( icons[ type ] || 'info' ) ),
				$( '<span></span>' ).text( message )
			);

			$( '#sp-merge-messages' ).html( $msg );

			var timeout = duration || ( type === 'success' ? 10000 : ( type === 'info' ? 7000 : 0 ) );
			if ( timeout ) {
				setTimeout( function() {
					$( '.sp-merge-message' ).fadeOut();
				}, timeout );
			}

			$( 'html, body' ).animate( {
				scrollTop: $( '#sp-merge-messages' ).offset().top - 100
			}, 300 );
		},

		resetForm: function() {
			$( '#sp-merge-form' )[0].reset();
			$( '#merge-preview-card' ).hide();
			$( '#execute-merge' ).prop( 'disabled', true );
			$( '.sp-merge-message' ).fadeOut();
		},

		handleBackupRevert: function( e ) {
			e.preventDefault();
			var backupId = $( e.target ).closest( '.sp-revert-backup' ).data( 'backup-id' );
			var self = this;
			this.customConfirm( 'Are you sure you want to revert this merge? This will restore the deleted players.' ).then( function( confirmed ) {
				if ( confirmed ) {
					self.executeBackupRevert( backupId );
				}
			} );
		},

		executeBackupRevert: function( backupId ) {
			var self = this;
			this.setLoadingState( true );

			$.post( spMergeAjax.ajaxUrl, {
				action: 'sp_revert_merge',
				nonce: spMergeAjax.nonce,
				backup_id: backupId
			} )
				.done( function( response ) {
					if ( response.success ) {
						self.showMessage( 'success', 'Merge reverted successfully!' );
						setTimeout( function() {
							window.location.reload();
						}, 2000 );
					} else {
						self.showMessage( 'error', ( response.data && response.data.message ) || 'Revert failed.' );
					}
				} )
				.fail( this.handleAjaxError.bind( this ) )
				.always( this.setLoadingState.bind( this, false ) );
		},

		handleBackupDelete: function( e ) {
			e.preventDefault();
			var backupId = $( e.target ).closest( '.sp-delete-backup' ).data( 'backup-id' );
			var self = this;
			this.customConfirm( 'Are you sure you want to delete this backup? This action cannot be undone.' ).then( function( confirmed ) {
				if ( confirmed ) {
					self.deleteBackups( [ backupId ] );
				}
			} );
		},

		handleSelectAllBackups: function( e ) {
			e.preventDefault();
			var $checkboxes = $( '.backup-checkbox' );
			var allChecked = $checkboxes.filter( ':checked' ).length === $checkboxes.length;
			$checkboxes.prop( 'checked', ! allChecked );
			this.updateDeleteButtonState();
		},

		handleDeleteSelectedBackups: function( e ) {
			e.preventDefault();
			var selectedIds = $( '.backup-checkbox:checked' ).map( function() {
				return $( this ).val();
			} ).get();

			if ( ! selectedIds.length ) {
				this.showMessage( 'error', 'No backups selected.' );
				return;
			}

			var self = this;
			this.customConfirm( 'Are you sure you want to delete ' + selectedIds.length + ' backup(s)? This action cannot be undone.' ).then( function( confirmed ) {
				if ( confirmed ) {
					self.deleteBackups( selectedIds );
				}
			} );
		},

		deleteBackups: function( backupIds ) {
			var self = this;
			this.setLoadingState( true );

			$.post( spMergeAjax.ajaxUrl, {
				action: 'sp_delete_backup',
				nonce: spMergeAjax.nonce,
				backup_ids: backupIds
			} )
				.done( function( response ) {
					if ( response.success ) {
						self.showMessage( 'success', response.data.message );
						if ( $( '.backup-checkbox' ).length === backupIds.length ) {
							$( '#revert-merge' ).hide();
						}
						setTimeout( function() {
							window.location.reload();
						}, 1500 );
					} else {
						self.showMessage( 'error', ( response.data && response.data.message ) || 'Delete failed.' );
					}
				} )
				.fail( this.handleAjaxError.bind( this ) )
				.always( this.setLoadingState.bind( this, false ) );
		},

		updateDeleteButtonState: function() {
			$( '#delete-selected-backups' ).prop( 'disabled', $( '.backup-checkbox:checked' ).length === 0 );
		},

		refreshBackupSection: function() {
			var self = this;

			$.post( spMergeAjax.ajaxUrl, {
				action: 'sp_get_recent_backups',
				nonce: spMergeAjax.nonce
			} )
				.done( function( response ) {
					if ( response.success && response.data.html ) {
						var $backupCard = $( '.sp-backup-section' );

						if ( $backupCard.length ) {
							$backupCard.find( '.sp-merge-card-body' ).html( self.sanitizeHtml( response.data.html ) );
						} else {
							self.createBackupSection( response.data.html );
						}

						self.checkForExistingBackup();
						$( '#revert-merge' ).removeClass( 'sp-hidden' ).show().prop( 'disabled', false );
					}
				} );
		},

		createBackupSection: function( backupHtml ) {
			var section = '<div class="sp-merge-card sp-backup-section">'
				+ '<div class="sp-merge-card-header">'
				+ '<h2><span class="dashicons dashicons-backup"></span> Recent Merges (Available for Revert)</h2>'
				+ '<div class="sp-backup-actions">'
				+ '<button type="button" id="select-all-backups" class="button button-secondary">Select All</button>'
				+ '<button type="button" id="delete-selected-backups" class="button button-secondary" disabled>'
				+ '<span class="dashicons dashicons-trash"></span> Delete Selected</button>'
				+ '</div></div>'
				+ '<div class="sp-merge-card-body">' + this.sanitizeHtml( backupHtml ) + '</div></div>';

			$( '#sp-merge-messages' ).before( section );
		},

		updateBackupButtonStates: function() {
			var isPreview = $( '#merge-preview-card' ).is( ':visible' );

			if ( isPreview ) {
				$( '.sp-revert-backup, .sp-delete-backup' ).prop( 'disabled', true );
				$( '#delete-selected-backups' ).prop( 'disabled', true );
				$( '.backup-checkbox, #select-all-backups' ).prop( 'disabled', true );
			} else {
				$( '.sp-revert-backup, .sp-delete-backup' ).prop( 'disabled', false );
				$( '.backup-checkbox, #select-all-backups' ).prop( 'disabled', false );
				this.updateDeleteButtonState();
			}
		}
	};

	$( document ).ready( function() {
		SpMergeApp.init();
	} );

}( jQuery ) );
