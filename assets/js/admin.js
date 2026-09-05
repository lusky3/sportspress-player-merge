/**
 * SportsPress Player Merge - Admin JavaScript
 *
 * Handles all frontend interactions for the player merge tool.
 *
 * @package SportsPress_Player_Merge
 */

/* global SlimSelect */

( function( $ ) {
	'use strict';

	var SpMergeApp = {

		lastBackupId: null,

		// Token returned by the preview, binding Execute to the exact selection
		// that was previewed. Cleared whenever either select changes.
		previewToken: null,

		// Warnings the server raised about the previewed selection.
		previewWarnings: [],

		// True while a confirmation dialog is on screen. Every destructive action
		// (execute merge, revert, delete backup) is gated behind customConfirm(),
		// but the triggering button is only disabled once the user has already
		// confirmed and the request is in flight — nothing stopped a second click
		// on the same button from opening a second dialog first, and a second
		// "Yes" from firing a second POST. One flag, checked in the one place
		// every one of those flows funnels through, covers all of them.
		confirmOpen: false,

		// SlimSelect instances for the two player-search dropdowns. Needed to
		// reset the visual UI after a merge: SlimSelect wraps and hides the
		// real <select>, so setting its .val() directly (as jQuery normally
		// would) changes the underlying element without ever telling SlimSelect
		// to re-render — only its own setSelected() does both.
		primarySelect: null,
		duplicatesSelect: null,

		/**
		 * Accessible confirmation dialog with ARIA attributes and focus trapping.
		 *
		 * @param {string} message Confirmation message.
		 * @param {Array} details Optional lines listed under the message.
		 * @return {Promise<boolean>}
		 */
		customConfirm: function( message, details ) {
			var self = this;
			if ( this.confirmOpen ) {
				return Promise.resolve( false );
			}
			this.confirmOpen = true;

			return new Promise( function( resolve ) {
				var $trigger = $( document.activeElement );
				var $modal = $( '<div class="sp-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="sp-confirm-title" tabindex="-1"></div>' );
				var $dialog = $( '<div class="sp-confirm-dialog"></div>' );
				var $title = $( '<p id="sp-confirm-title"></p>' ).text( message );
				var $yesBtn = $( '<button class="button button-primary sp-confirm-yes">Yes</button>' );
				var $noBtn = $( '<button class="button sp-confirm-no" style="margin-left:10px;">No</button>' );

				$dialog.append( $title );

				if ( details && details.length ) {
					var $list = $( '<ul class="sp-confirm-details"></ul>' );
					for ( var d = 0; d < details.length; d++ ) {
						$list.append( $( '<li></li>' ).text( details[ d ] ) );
					}
					$dialog.append( $list );
				}

				$dialog.append( $yesBtn, $noBtn );
				$modal.append( $dialog );
				$( 'body' ).append( $modal );

				function closeModal( result ) {
					$modal.remove();
					$trigger.focus();
					self.confirmOpen = false;
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
			this.initPlayerSearch();
			this.bindEvents();
			this.checkForExistingBackup();
			this.initDraggableCards();
		},

		/**
		 * Initialize SlimSelect AJAX-powered player search on both selects.
		 *
		 * SlimSelect only calls events.search once the user has typed at least
		 * one character — unlike Select2's minimumInputLength: 0, it never
		 * fires its remote-search event for an empty query (search('') is
		 * defined to clear back to the local catalog instead). Both dropdowns
		 * open blank until then; the roster is large enough that "first 20
		 * players alphabetically" wasn't a particularly useful default anyway.
		 */
		initPlayerSearch: function() {
			var self = this;

			/**
			 * Shared remote-search handler for both dropdowns.
			 *
			 * SlimSelect checks the return value with `instanceof Promise`, so a
			 * bare jQuery jqXHR (or its own .then() chain) fails that check — a
			 * jQuery Deferred is thenable but is not a native Promise instance,
			 * even after chaining .then() on it. Wrapping in `new Promise()`
			 * forces a genuine native Promise as the return value; confirmed
			 * live (without this the console read "Search event must return a
			 * promise or an array of data" and no results ever rendered).
			 *
			 * @param {string} search Current search box text.
			 * @return {Promise<Array>} Resolves to a SlimSelect option array.
			 */
			function searchPlayers( search ) {
				return new Promise( function( resolve ) {
					$.ajax( {
						url: spMergeAjax.ajaxUrl,
						method: 'GET',
						dataType: 'json',
						data: {
							action: 'sp_search_players',
							nonce: spMergeAjax.nonce,
							search: search || ''
						}
					} ).done( function( response ) {
						if ( response.success && response.data && response.data.results ) {
							resolve( response.data.results.map( function( item ) {
								return { value: String( item.id ), text: item.text };
							} ) );
							return;
						}

						// A non-success envelope (an expired nonce, most often) looks
						// identical to "no players match" if silently reduced to an
						// empty result — the one search control the whole tool
						// depends on failing with no visible signal. Surface it.
						self.showMessage( 'error', ( response.data && response.data.message ) || 'Player search failed.' );
						resolve( [] );
					} ).fail( function() {
						self.showMessage( 'error', 'Player search failed.' );
						resolve( [] );
					} );
				} );
			}

			this.primarySelect = new SlimSelect( {
				select: '#primary-player',
				settings: {
					placeholderText: $( '#primary-player' ).data( 'placeholder' ),
					allowDeselect: true,
					timeoutDelay: 300
				},
				events: {
					search: searchPlayers
				}
			} );

			this.duplicatesSelect = new SlimSelect( {
				select: '#duplicate-players',
				settings: {
					placeholderText: $( '#duplicate-players' ).data( 'placeholder' ),
					allowDeselect: true,
					maxSelected: 10,
					timeoutDelay: 300
				},
				events: {
					search: searchPlayers
				}
			} );
		},

		bindEvents: function() {
			$( '#preview-merge' ).on( 'click', this.handlePreviewMerge.bind( this ) );
			$( '#execute-merge' ).on( 'click', this.handleExecuteMerge.bind( this ) );
			$( '#revert-merge' ).on( 'click', this.handleRevertMerge.bind( this ) );
			$( '#cancel-preview' ).on( 'click', this.handleCancelPreview.bind( this ) );
			$( document ).on( 'click', '.sp-revert-backup', this.handleBackupRevert.bind( this ) );
			$( document ).on( 'click', '.sp-delete-backup', this.handleBackupDelete.bind( this ) );
			// Delegated, like every other backup-list control: createBackupSection()
			// re-renders this header from scratch whenever the backup list changes
			// (after a merge, revert, or delete), and a direct binding on the old
			// element is gone once that element is replaced.
			$( document ).on( 'click', '#select-all-backups', this.handleSelectAllBackups.bind( this ) );
			$( document ).on( 'click', '#delete-selected-backups', this.handleDeleteSelectedBackups.bind( this ) );
			$( document ).on( 'change', '.backup-checkbox', this.updateDeleteButtonState.bind( this ) );
			// Any change to either select retires the preview: the card on screen
			// must never describe a merge other than the one Execute would run.
			$( '#primary-player, #duplicate-players' ).on( 'change', this.invalidatePreview.bind( this ) );
			$( '#primary-player, #duplicate-players' ).on( 'change', this.validateForm.bind( this ) );
			$( '#scan-duplicates' ).on( 'click', this.handleScanDuplicates.bind( this ) );
			$( document ).on( 'click', '.sp-select-duplicates', this.handleSelectDuplicates.bind( this ) );
			$( document ).on( 'click', '.sp-expand-toggle', this.handleExpandToggle.bind( this ) );
			$( document ).on( 'click', '.sp-force-revert', this.handleForceRevert.bind( this ) );
		},

		/**
		 * Retire the current preview and disable Execute.
		 *
		 * Called on every change to either select, not only when the form becomes
		 * invalid: swapping one valid player for another used to leave a stale
		 * preview on screen with Execute live.
		 */
		invalidatePreview: function() {
			var hadPreview = this.previewToken !== null || $( '#merge-preview-card' ).is( ':visible' );

			this.previewToken = null;
			this.previewWarnings = [];
			$( '#preview-warnings' ).remove();
			$( '#preview-content' ).empty();
			$( '#merge-preview-card' ).hide();
			$( '#execute-merge' ).prop( 'disabled', true );

			if ( hadPreview ) {
				this.showMessage( 'info', spMergeAjax.strings.previewStale );
			}

			this.updateBackupButtonStates();
		},

		/**
		 * Sanitize HTML by removing scripts, dangerous elements and event handlers.
		 *
		 * Parsing MUST happen in an inert document. The previous implementation
		 * used `$( '<div>' ).html( html )` and stripped afterwards, which runs the
		 * very code it then removes: jQuery's .html() tests the string against
		 * /<script|<style|<link/i and, when it matches, SKIPS the innerHTML fast
		 * path and falls through to .append() — which goes through domManip and
		 * evaluates scripts. So a payload containing <script> executed before a
		 * single node was removed. Flagged BLOCKER by SonarQube (jssecurity:S5696).
		 *
		 * DOMParser builds a document with no browsing context: scripts are never
		 * executed and resource-loading attributes such as onerror never fire, so
		 * stripping afterwards is sound.
		 *
		 * @param {string} html Raw HTML.
		 * @return {string} Sanitized HTML.
		 */
		sanitizeHtml: function( html ) {
			var doc;

			try {
				doc = new DOMParser().parseFromString( String( html ), 'text/html' );
			} catch ( e ) {
				return '';
			}

			if ( ! doc || ! doc.body ) {
				return '';
			}

			var $body = $( doc.body );

			$body.find( 'script, iframe, object, embed, link, style, base, meta, form' ).remove();

			$body.find( '*' ).each( function() {
				var attrs = this.attributes;
				for ( var i = attrs.length - 1; i >= 0; i-- ) {
					var name = ( attrs[i].name || '' ).toLowerCase();
					var value = attrs[i].value || '';

					// Inline handlers, and srcdoc which smuggles a whole document.
					if ( 0 === name.indexOf( 'on' ) || 'srcdoc' === name ) {
						this.removeAttribute( attrs[i].name );
						continue;
					}

					// javascript: in any URL-bearing attribute.
					if (
						( 'href' === name || 'src' === name || 'action' === name || 'formaction' === name || 'xlink:href' === name ) &&
						/^\s*javascript:/i.test( value )
					) {
						this.removeAttribute( attrs[i].name );
					}
				}
			} );

			return $body.html();
		},

		/**
		 * Escape HTML entities to prevent XSS.
		 *
		 * @param {string|number} str Value to escape.
		 * @return {string} Escaped string safe for HTML insertion.
		 */
		escapeHtml: function( str ) {
			return String( str ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
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
				this.previewToken = response.data.token || null;
				this.previewWarnings = response.data.warnings || [];

				$( '#preview-content' ).html( this.sanitizeHtml( response.data.preview ) );
				this.renderPreviewWarnings( this.previewWarnings );
				$( '#merge-preview-card' ).removeClass( 'sp-hidden' ).show();
				$( '#execute-merge' ).prop( 'disabled', ! this.previewToken );

				$( 'html, body' ).animate( {
					scrollTop: $( '#merge-preview-card' ).offset().top - 50
				}, 500 );

				this.showMessage( 'info', 'Preview generated. Review the changes and click "Execute Merge" to proceed.' );
				this.updateBackupButtonStates();
			} else {
				this.showMessage( 'error', ( response.data && response.data.message ) || 'Preview generation failed.' );
			}
		},

		/**
		 * Show the survivor-choice warnings above the preview body.
		 *
		 * @param {Array} warnings Server-generated warning strings.
		 */
		renderPreviewWarnings: function( warnings ) {
			$( '#preview-warnings' ).remove();

			if ( ! warnings || ! warnings.length ) {
				return;
			}

			var $box = $( '<div id="preview-warnings" class="sp-preview-warnings"></div>' );
			var $list = $( '<ul></ul>' );

			for ( var i = 0; i < warnings.length; i++ ) {
				$list.append( $( '<li></li>' ).text( warnings[i] ) );
			}

			$box.append( $( '<p></p>' ).append( $( '<strong></strong>' ).text( 'Check the survivor before executing:' ) ), $list );
			$( '#preview-content' ).before( $box );
		},

		handleCancelPreview: function( e ) {
			e.preventDefault();
			this.previewToken = null;
			this.previewWarnings = [];
			$( '#preview-warnings' ).remove();
			$( '#preview-content' ).empty();
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

			if ( ! this.previewToken ) {
				this.showMessage( 'error', spMergeAjax.strings.previewStale );
				return;
			}

			var primaryName = $( '#primary-player option:selected' ).text() || 'selected player';
			var duplicates = $( '#duplicate-players option:selected' ).map( function() {
				return $( this ).text();
			} ).get();

			// Every player about to be deleted is named, not just counted: a
			// swapped duplicate was previously invisible in this dialog.
			var details = duplicates.map( function( name ) {
				return 'DELETE: ' + name;
			} );

			for ( var i = 0; i < this.previewWarnings.length; i++ ) {
				details.push( 'WARNING: ' + this.previewWarnings[i] );
			}

			var message = 'Permanently delete ' + duplicates.length + ' player record(s), merging them into "' + primaryName + '"? This cannot be undone except by reverting from the backup.';

			this.customConfirm( message, details ).then( function( confirmed ) {
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
				preview_token: this.previewToken,
				primary_player: $( '#primary-player' ).val(),
				duplicate_players: $( '#duplicate-players' ).val() || []
			} )
				.done( this.handleExecuteSuccess.bind( this ) )
				.fail( this.handleAjaxError.bind( this ) )
				.always( this.setLoadingState.bind( this, false ) );
		},

		handleExecuteSuccess: function( response ) {
			if ( response.success ) {
				this.previewToken = null;
				this.previewWarnings = [];
				$( '#preview-warnings' ).remove();
				this.lastBackupId = response.data.backup_id;

				$( '#execute-merge' ).prop( 'disabled', true );
				this.showMessage( 'success', spMergeAjax.strings.mergeSuccess + ' Backup ID: ' + this.lastBackupId );

				// setSelected(), not .val(): SlimSelect renders its own UI over
				// the real <select>, so writing the native value directly would
				// clear the underlying element without the visible dropdown
				// ever catching up.
				this.primarySelect.setSelected( [] );
				this.duplicatesSelect.setSelected( [] );
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
			this.executeBackupRevert( this.lastBackupId, false );
		},

		handleAjaxError: function() {
			this.showMessage( 'error', 'Network error occurred. Please try again.' );
		},

		setLoadingState: function( isLoading ) {
			if ( isLoading ) {
				// .sp-hidden's display:none is !important, so a bare .show() (an
				// inline style) never overrides it while the class is still on
				// the element — same fix already applied to #merge-preview-card
				// and #revert-merge elsewhere in this file.
				$( '#sp-merge-loading' ).removeClass( 'sp-hidden' ).show();
				$( '.sp-merge-wrap button:not(#cancel-preview)' ).prop( 'disabled', true );
				$( '.sp-revert-backup, .sp-delete-backup' ).prop( 'disabled', true );
			} else {
				$( '#sp-merge-loading' ).hide();

				// Everything the loading state disabled comes back, then the
				// buttons with their own preconditions are re-evaluated. Without
				// the blanket re-enable, Scan stayed dead after the first scan.
				$( '.sp-merge-wrap button:not(#cancel-preview)' ).prop( 'disabled', false );

				this.validateForm();
				this.updateBackupButtonStates();

				// Execute is gated on a live preview, never on the loading state.
				$( '#execute-merge' ).prop( 'disabled', ! this.previewToken );
				$( '#revert-merge' ).prop( 'disabled', ! this.lastBackupId );
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

		handleBackupRevert: function( e ) {
			e.preventDefault();
			var backupId = $( e.target ).closest( '.sp-revert-backup' ).data( 'backup-id' );
			var self = this;
			this.customConfirm( 'Are you sure you want to revert this merge? This will restore the deleted players.' ).then( function( confirmed ) {
				if ( confirmed ) {
					self.executeBackupRevert( backupId, false );
				}
			} );
		},

		/**
		 * Post a revert.
		 *
		 * @param {string} backupId Backup to revert.
		 * @param {boolean} force Override the "values changed since the merge"
		 *                        refusal. Only ever true after the operator has
		 *                        seen the refusal and confirmed it a second time.
		 */
		executeBackupRevert: function( backupId, force ) {
			var self = this;

			if ( ! backupId ) {
				this.showMessage( 'error', spMergeAjax.strings.noMergeData );
				return;
			}

			this.setLoadingState( true );

			var payload = {
				action: 'sp_revert_merge',
				nonce: spMergeAjax.nonce,
				backup_id: backupId
			};

			if ( force === true ) {
				payload.force = '1';
			}

			$.post( spMergeAjax.ajaxUrl, payload )
				.done( function( response ) {
					if ( response.success ) {
						self.lastBackupId = null;

						self.showMessage( 'success', ( response.data && response.data.message ) || spMergeAjax.strings.revertSuccess );
						setTimeout( function() {
							window.location.reload();
						}, 2000 );
						return;
					}

					var message = ( response.data && response.data.message ) || 'Revert failed.';

					// The backup class refused because values changed after the
					// merge. That refusal is overridable, but only as a separate,
					// deliberate second step — never as part of this click.
					if ( response.data && response.data.force_offered ) {
						self.offerForceRevert( backupId, message );
						return;
					}

					self.showMessage( 'error', message );
				} )
				.fail( this.handleAjaxError.bind( this ) )
				.always( this.setLoadingState.bind( this, false ) );
		},

		/**
		 * Show a refusal alongside an explicit override control.
		 *
		 * @param {string} backupId Backup that was refused.
		 * @param {string} reason Refusal text from the server, naming what changed.
		 */
		offerForceRevert: function( backupId, reason ) {
			this.showMessage( 'error', reason );

			var $button = $( '<button type="button" class="button button-secondary sp-force-revert"></button>' )
				.text( spMergeAjax.strings.overrideLabel )
				.attr( 'data-backup-id', backupId )
				.attr( 'data-reason', reason );

			$( '.sp-merge-message' ).append( $( '<div class="sp-force-revert-wrap"></div>' ).append( $button ) );
		},

		handleForceRevert: function( e ) {
			e.preventDefault();

			var $button = $( e.target ).closest( '.sp-force-revert' );
			var backupId = $button.attr( 'data-backup-id' );
			var reason = $button.attr( 'data-reason' ) || '';
			var self = this;

			this.customConfirm( spMergeAjax.strings.overrideIntro, [ reason ] ).then( function( confirmed ) {
				if ( confirmed ) {
					self.executeBackupRevert( backupId, true );
				}
			} );
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

		handleScanDuplicates: function( e ) {
			e.preventDefault();
			this.setLoadingState( true );

			var self = this;
			$.post( spMergeAjax.ajaxUrl, {
				action: 'sp_find_duplicates',
				nonce: spMergeAjax.nonce
			} )
				.done( function( response ) {
					if ( response.success ) {
						self.renderDuplicates( response.data.groups, response.data );
					} else {
						self.showMessage( 'error', ( response.data && response.data.message ) || 'Scan failed.' );
					}
				} )
				.fail( this.handleAjaxError.bind( this ) )
				.always( this.setLoadingState.bind( this, false ) );
		},

		/**
		 * Certainty band for a score, or null when the matcher gave none.
		 *
		 * @param {number|null} c Certainty.
		 * @return {string} Band label.
		 */
		certaintyLabel: function( c ) {
			if ( c === null || c === undefined || isNaN( c ) ) {
				return 'Unrated';
			}
			return c >= 90 ? 'High' : ( c >= 70 ? 'Medium' : 'Low' );
		},

		/**
		 * Certainty badge class for a score.
		 *
		 * @param {number|null} c Certainty.
		 * @return {string} CSS class.
		 */
		certaintyClass: function( c ) {
			if ( c === null || c === undefined || isNaN( c ) ) {
				return 'sp-certainty-low';
			}
			return c >= 90 ? 'sp-certainty-high' : ( c >= 70 ? 'sp-certainty-medium' : 'sp-certainty-low' );
		},

		renderDuplicates: function( groups, scan ) {
			var $content = $( '#duplicates-content' );
			var header = '';

			// Surface how much of the roster was actually looked at, so a future
			// overflow is visible rather than silent.
			if ( scan && typeof scan.scanned !== 'undefined' ) {
				// Coerced to numbers rather than escaped as strings: these are counts,
				// so a number cannot carry markup at all. That is both more accurate
				// than escaping and provably safe, which escaping a string only is
				// once you trust the escaper (SonarQube jssecurity:S5696 does not).
				var scanned = parseInt( scan.scanned, 10 ) || 0;
				var scanTotal = parseInt( scan.total, 10 ) || 0;
				var coverage = 'Scanned ' + scanned + ' of ' + scanTotal + ' published players.';
				if ( scan.truncated ) {
					header = '<p class="sp-scan-coverage sp-scan-truncated"><strong>' + coverage
						+ ' Some players were not scanned, so duplicates among them will not appear here.</strong></p>';
				} else {
					header = '<p class="sp-scan-coverage">' + coverage + '</p>';
				}
			}

			if ( ! groups || ! groups.length ) {
				$content.html( header + '<p>No duplicate players found.</p>' );
				return;
			}

			var html = header + '<table class="sp-duplicates-table">'
				+ '<caption class="screen-reader-text">Possible duplicate player groups with certainty scores. Tick each player that belongs in the merge.</caption>'
				+ '<thead><tr><th>Players</th><th style="text-align:center">Events</th><th style="text-align:center">Group certainty</th><th style="text-align:center">Action</th></tr></thead><tbody>';

			for ( var i = 0; i < groups.length; i++ ) {
				var g = groups[i];

				// Sort players by events descending so best primary is first.
				var sorted = g.players.slice().sort( function( a, b ) { return b.events - a.events; } );

				var playerList = '<ul class="sp-duplicate-group">';
				for ( var j = 0; j < sorted.length; j++ ) {
					var p = sorted[j];
					var pc = ( typeof p.certainty === 'number' ) ? p.certainty : null;

					// Only high-confidence members start ticked. A member the
					// matcher could not score is treated as low confidence.
					var checked = ( pc !== null && pc >= 90 ) ? ' checked' : '';
					var memberJson = JSON.stringify( {
						id: p.id, name: p.name, team: p.team || '', position: p.position || '', events: p.events, email: p.email || ''
					} );
					var cbId = 'sp-dup-' + this.escapeHtml( i ) + '-' + this.escapeHtml( p.id );

					var meta = [];
					if ( p.team ) { meta.push( this.escapeHtml( p.team ) ); }
					if ( p.position ) { meta.push( this.escapeHtml( p.position ) ); }
					if ( p.email ) { meta.push( this.escapeHtml( p.email ) ); }
					var metaStr = meta.length ? ' (' + meta.join( ' &middot; ' ) + ')' : '';

					var memberBadge = '<span class="sp-certainty-badge ' + this.certaintyClass( pc ) + '">'
						+ ( pc === null ? '' : this.escapeHtml( pc ) + '% &mdash; ' ) + this.certaintyLabel( pc ) + '</span>';

					playerList += '<li>'
						+ '<input type="checkbox" class="sp-dup-member" id="' + cbId + '" value="' + this.escapeHtml( p.id ) + '"'
						+ ' data-player="' + this.escapeHtml( memberJson ) + '"' + checked + '>'
						+ ' <label for="' + cbId + '">' + this.escapeHtml( p.name ) + ' #' + this.escapeHtml( p.id ) + metaStr
						+ ' <small>' + this.escapeHtml( p.events ) + ' events</small> ' + memberBadge + '</label>'
						+ ' <a href="' + this.escapeHtml( p.edit_link ) + '">edit</a>'
						+ '</li>';
				}
				playerList += '</ul>';

				html += '<tr>'
					+ '<td>' + playerList + '</td>'
					+ '<td style="text-align:center">' + this.escapeHtml( sorted.reduce( function( s, p ) { return s + p.events; }, 0 ) ) + '</td>'
					+ '<td style="text-align:center"><span class="sp-certainty-badge ' + this.certaintyClass( g.certainty ) + '">'
					+ this.escapeHtml( g.certainty ) + '% &mdash; ' + this.certaintyLabel( g.certainty ) + '</span></td>'
					+ '<td style="text-align:center"><button type="button" class="button button-small sp-select-duplicates">Select ticked for Merge</button></td>'
					+ '</tr>';
			}

			html += '</tbody></table>';
			if ( groups.length >= 50 ) {
				html += '<p class="description" style="margin-top:8px;">Showing first 50 groups. Merge some duplicates and scan again to find more.</p>';
			}
			$content.html( html );
		},

		handleSelectDuplicates: function( e ) {
			e.preventDefault();

			// Stage only the members the operator ticked, never the whole group.
			var players = [];
			$( e.target ).closest( 'tr' ).find( '.sp-dup-member:checked' ).each( function() {
				try {
					players.push( JSON.parse( $( this ).attr( 'data-player' ) ) );
				} catch ( err ) {
					// Malformed row data; skip this member.
				}
			} );

			if ( players.length < 2 ) {
				this.showMessage( 'error', spMergeAjax.strings.selectMembers );
				return;
			}

			// Rows render in events-descending order, so the first ticked member
			// is the one with the most history: the best survivor.
			var primary = players[0];
			var duplicates = players.slice( 1 );

			var buildLabel = function( p ) {
				var parts = [ p.name + ' #' + p.id ];
				var meta = [];
				if ( p.team ) { meta.push( p.team ); }
				if ( p.position ) { meta.push( p.position ); }
				if ( meta.length ) { parts.push( '(' + meta.join( ' · ' ) + ')' ); }
				parts.push( '— ' + p.events + ' events' );
				return parts.join( ' ' );
			};

			// Set the primary player. addOption() first: setSelected() only
			// picks among options SlimSelect already knows about, and a group
			// staged straight from the scan results was never searched for.
			this.primarySelect.addOption( { value: String( primary.id ), text: buildLabel( primary ) } );
			this.primarySelect.setSelected( String( primary.id ) );

			// Set the duplicate players.
			var duplicateValues = [];
			for ( var i = 0; i < duplicates.length; i++ ) {
				this.duplicatesSelect.addOption( { value: String( duplicates[i].id ), text: buildLabel( duplicates[i] ) } );
				duplicateValues.push( String( duplicates[i].id ) );
			}
			this.duplicatesSelect.setSelected( duplicateValues );

			this.showMessage(
				'info',
				'Staged ' + players.length + ' of this group: keeping ' + primary.name + ' #' + primary.id
					+ ', merging ' + duplicates.length + ' record(s) into it. Preview before executing.'
			);

			$( 'html, body' ).animate( {
				scrollTop: $( '#sp-merge-form' ).offset().top - 50
			}, 500 );
		},

		initDraggableCards: function() {
			var container = document.querySelector( '.sp-merge-container' );
			if ( ! container ) { return; }

			// Restore saved order from localStorage.
			try {
				var saved = JSON.parse( localStorage.getItem( 'sp_merge_card_order' ) || '[]' );
				if ( saved.length ) {
					var cards = Array.from( container.querySelectorAll( '.sp-merge-card' ) );
					var map = {};
					cards.forEach( function( c, i ) {
						var id = c.querySelector( '.sp-merge-card-header h2' );
						map[ id ? id.textContent.trim() : i ] = c;
					} );
					saved.forEach( function( key ) {
						if ( map[ key ] ) { container.appendChild( map[ key ] ); }
					} );
				}
			} catch ( e ) { /* ignore */ }

			// Set up drag events on card headers.
			var dragSrc = null;
			container.querySelectorAll( '.sp-merge-card' ).forEach( function( card ) {
				card.setAttribute( 'draggable', 'true' );

				card.addEventListener( 'dragstart', function( e ) {
					dragSrc = card;
					card.classList.add( 'sp-dragging' );
					e.dataTransfer.effectAllowed = 'move';
				} );

				card.addEventListener( 'dragover', function( e ) {
					e.preventDefault();
					e.dataTransfer.dropEffect = 'move';
					card.classList.add( 'sp-drag-over' );
				} );

				card.addEventListener( 'dragleave', function() {
					card.classList.remove( 'sp-drag-over' );
				} );

				card.addEventListener( 'drop', function( e ) {
					e.preventDefault();
					card.classList.remove( 'sp-drag-over' );
					if ( dragSrc && dragSrc !== card ) {
						var all = Array.from( container.querySelectorAll( '.sp-merge-card' ) );
						var from = all.indexOf( dragSrc );
						var to = all.indexOf( card );
						if ( from < to ) {
							container.insertBefore( dragSrc, card.nextSibling );
						} else {
							container.insertBefore( dragSrc, card );
						}
						// Save order.
						try {
							var order = Array.from( container.querySelectorAll( '.sp-merge-card' ) ).map( function( c ) {
								var h = c.querySelector( '.sp-merge-card-header h2' );
								return h ? h.textContent.trim() : '';
							} );
							localStorage.setItem( 'sp_merge_card_order', JSON.stringify( order ) );
						} catch ( ex ) { /* ignore */ }
					}
				} );

				card.addEventListener( 'dragend', function() {
					card.classList.remove( 'sp-dragging' );
					container.querySelectorAll( '.sp-drag-over' ).forEach( function( c ) {
						c.classList.remove( 'sp-drag-over' );
					} );
				} );
			} );
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
