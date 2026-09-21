# Batch Merge (Admin UI) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an operator tick players across as many duplicate-scan groups as they want and merge them all in one action, instead of manually staging and previewing one group at a time.

**Architecture:** Two new entry points (a per-row "Merge" button, and a "Merge (x)" button shown above and below the results table) both funnel into one shared client-side pipeline: fetch a preview (and its token) for every qualifying group in parallel, show one confirmation dialog listing everything that will happen, then execute each group sequentially against the existing `sp_execute_merge` AJAX action, updating that row with a result badge as it finishes. No PHP changes; this is pure orchestration of the two AJAX actions (`sp_preview_merge`, `sp_execute_merge`) that already exist and are already used by the manual Preview/Execute flow.

**Tech Stack:** Vanilla jQuery (ES5-style — no `let`/`const`, no arrow functions, no template literals, no `Object.assign`; this file uses `var` and plain functions throughout and oxlint runs against it), native `Promise`, WordPress AJAX (`admin-ajax.php` via `spMergeAjax.ajaxUrl`).

**Spec:** No separate spec file — the design was worked out directly in conversation (bounded-scope brainstorming, not the architectural path) and is fully captured in this plan's Global Constraints and per-task descriptions below.

## Global Constraints

- No PHP changes of any kind. `classes/class-sp-merge-ajax.php`, `classes/class-sp-merge-processor.php`, `classes/class-sp-merge-preview.php`, `classes/class-sp-merge-backup.php` are all reused exactly as they exist today, unmodified. No new AJAX actions.
- Only `assets/js/admin.js` and `assets/css/admin.css` get code changes; `readme.txt` and `changelog.txt` get changelog entries. `includes/admin-page.php` is NOT touched — the results table markup is generated entirely by `renderDuplicates()` in `admin.js`.
- Match existing code style exactly: `var`, not `let`/`const`; no arrow functions, no template literals, no `Object.assign` (confirmed absent from the file today — grepped before writing this plan); tabs for indentation (existing file uses tabs); JSDoc comments on every new function, matching the style already used throughout the file.
- The shared Primary/Duplicates SlimSelect picker at the top of the admin page, and its own `#preview-merge`/`#execute-merge` buttons (`handlePreviewMerge`, `handlePreviewSuccess`, `handleExecuteMerge`, `proceedWithMerge`, `handleExecuteSuccess`), are **not touched**. They remain the path for manually searching and merging players not surfaced by a scan.
- `handleSelectDuplicates` (the old per-row "stage into the picker" handler, `assets/js/admin.js:935-991`) is deleted — replaced entirely by the new row-level `handleRowMerge`.
- No JS test harness exists in this repo (confirmed in prior work this session — see the SlimSelect swap and edit-link PRs, both verified with `node --check` plus manual browser verification against Tikal staging, never an automated JS test). Every task's mechanical check is `node --check assets/js/admin.js`; the functional check is manual verification against Tikal (`tikal.lusk.ee`, reachable via the `tikal` SSH host alias already configured on this machine — see Task 3).
- `sp_preview_merge` is read-only and takes no lock (confirmed by grep: neither `SP_Merge_Ajax::preview_merge()` nor `SP_Merge_Preview` reference `SP_Merge_Lock`), so calling it for many groups in parallel is safe. `sp_execute_merge` does take the plugin's merge lock, so groups are executed **sequentially**, one full round-trip at a time, never in parallel.
- The preview token is a stateless hash — `SP_Merge_Ajax::selection_token()` computes `wp_hash( 'sp_merge_selection|' . $primary_id . '|' . implode( ',', $ids ) )` — so a token fetched during the parallel "prepare" phase is still valid when it's used later in the sequential "execute" phase; there is no server-side session state that could go stale between the two phases.
- AJAX request/response shapes (already used by the existing manual flow, reused as-is):
  - `sp_preview_merge` request: `{ action: 'sp_preview_merge', nonce: spMergeAjax.nonce, primary_player: '<id>', duplicate_players: ['<id>', ...] }`. Success response: `{ success: true, data: { preview, token, warnings } }` (`warnings` is always an array, possibly empty). Failure response: `{ success: false, data: { message } }`.
  - `sp_execute_merge` request: `{ action: 'sp_execute_merge', nonce: spMergeAjax.nonce, preview_token: '<token>', primary_player: '<id>', duplicate_players: ['<id>', ...] }`. Success response: `{ success: true, data: { message, backup_id } }`. Failure response: `{ success: false, data: { message } }`.
- `spMergeAjax.strings.selectMembers` already reads "Select at least two players from this group to merge." (defined in `classes/class-sp-merge-admin.php:337`) — reused as-is when nothing qualifies. No new PHP-localized strings are added; every other new user-facing string in this feature is a plain JS string literal, matching how e.g. `'Player search failed.'` and `'Merge execution failed.'` are already written directly in `admin.js` rather than localized.

---

### Task 1: Build the batch-merge pipeline and wire it into the duplicates card

This is one task, not several, even though it touches multiple functions — right-sizing note: `collectGroupPlayers`, `previewGroup`, `executeGroup`, `setGroupResult`, and `runMergeGroups` all share state and call each other directly by name within the same ~150-line block, and the markup/binding changes only make sense once those functions exist. Splitting this across separate subagents would mean each one has to re-read the others' exact signatures anyway (which the "Interfaces" block below exists to prevent) — for a single already-large file where the pieces are this tightly coupled, one reviewer's gate over the whole diff is more honest than an artificial split. The changelog entries (Task 2) and the live verification (Task 3) are genuinely independent and are split out.

**Files:**
- Modify: `assets/js/admin.js:215-237` (bindEvents — remove one binding, add three)
- Modify: `assets/js/admin.js:850-933` (renderDuplicates — markup changes)
- Modify: `assets/js/admin.js:935-991` (delete handleSelectDuplicates entirely)
- Modify: `assets/js/admin.js` (insert five new methods into the `SpMergeApp` object literal — exact insertion point given in Step 3 below)
- Modify: `assets/css/admin.css` (append new rules; exact block given in Step 5)

**Interfaces:**
- Consumes: `spMergeAjax.ajaxUrl`, `spMergeAjax.nonce`, `spMergeAjax.strings.selectMembers` (all already defined and localized; no changes needed to PHP). `this.customConfirm(message, details)` (existing, returns `Promise<boolean>`, defined at `admin.js:49`). `this.showMessage(type, message)` (existing, `admin.js:576`). `this.setLoadingState(isLoading)` (existing, `admin.js:550`). `this.escapeHtml(str)` (existing, `admin.js:329`). `this.refreshBackupSection()` (existing, `admin.js:767`).
- Produces (for Task 3's manual verification, and for any future work): `SpMergeApp.collectGroupPlayers($tr)`, `SpMergeApp.previewGroup(players)`, `SpMergeApp.executeGroup(prepared)`, `SpMergeApp.setGroupResult($tr, badgeHtml)`, `SpMergeApp.runMergeGroups($rows)`, `SpMergeApp.handleRowMerge(e)`, `SpMergeApp.handleBatchMerge(e)`, `SpMergeApp.updateBatchMergeCount()`. New DOM: button class `.sp-row-merge` (one per group row, inside a `.sp-group-action` wrapper span), button class `.sp-batch-merge` (two on the page, above and below the table), checkbox class `.sp-dup-member` (already exists, now also has a delegated `change` handler), result badge classes `.sp-group-result`, `.sp-group-result-success`, `.sp-group-result-error`.

- [ ] **Step 1: Remove the old per-row staging handler**

Delete `handleSelectDuplicates` entirely — the whole method, `admin.js:935-991` in the current file (from the `handleSelectDuplicates: function( e ) {` line through its closing `},`, inclusive). This method staged a row's ticked players into the shared Primary/Duplicates picker; it's superseded by `handleRowMerge` in Step 4, which executes the group directly instead.

- [ ] **Step 2: Verify the file still parses after the deletion**

Run: `node --check assets/js/admin.js`
Expected: no output, exit code 0 (deleting a complete, self-contained method should never break parsing, but confirm before building on top of it).

- [ ] **Step 3: Add the five new pipeline/handler methods**

Insert the following five methods into the `SpMergeApp` object literal, immediately after the `certaintyClass` method and immediately before `renderDuplicates` (i.e., right after the `},` that closes `certaintyClass` at what is currently `admin.js:848`, right before the `renderDuplicates: function( groups, scan ) {` line). This ordering matters only for readability — `renderDuplicates` (modified in Step 4) references `updateBatchMergeCount`, so having it defined just above keeps the read order natural:

```js
		/**
		 * Ticked players within one duplicate-group row, in tick order. Rows
		 * render events-descending, so the first ticked member is the one
		 * with the most history — the best survivor — and becomes primary
		 * for that group.
		 *
		 * @param {jQuery} $tr Table row for one duplicate group.
		 * @return {Array} Player objects parsed from each checked box's data-player.
		 */
		collectGroupPlayers: function( $tr ) {
			var players = [];
			$tr.find( '.sp-dup-member:checked' ).each( function() {
				try {
					players.push( JSON.parse( $( this ).attr( 'data-player' ) ) );
				} catch ( err ) {
					// Malformed row data; skip this member.
				}
			} );
			return players;
		},

		/**
		 * Fetch a preview and its binding token for one group. Never
		 * rejects — a failed preview resolves with ok:false so a batch of
		 * many groups (each independently previewed via Promise.all) never
		 * aborts the rest over one bad group.
		 *
		 * @param {Array} players Ticked players for this group, primary first.
		 * @return {Promise<Object>} {ok:true, players, token, warnings} or
		 *                           {ok:false, players, message}.
		 */
		previewGroup: function( players ) {
			var primary = players[0];
			var duplicateIds = players.slice( 1 ).map( function( p ) { return String( p.id ); } );

			return new Promise( function( resolve ) {
				$.post( spMergeAjax.ajaxUrl, {
					action: 'sp_preview_merge',
					nonce: spMergeAjax.nonce,
					primary_player: String( primary.id ),
					duplicate_players: duplicateIds
				} ).done( function( response ) {
					if ( response.success && response.data && response.data.token ) {
						resolve( { ok: true, players: players, token: response.data.token, warnings: response.data.warnings || [] } );
						return;
					}
					resolve( { ok: false, players: players, message: ( response.data && response.data.message ) || 'Preview failed.' } );
				} ).fail( function() {
					resolve( { ok: false, players: players, message: 'Network error occurred. Please try again.' } );
				} );
			} );
		},

		/**
		 * Execute one already-previewed group. Never rejects, same
		 * reasoning as previewGroup().
		 *
		 * @param {Object} prepared {players, token} from a successful previewGroup().
		 * @return {Promise<Object>} {ok:true, backupId} or {ok:false, message}.
		 */
		executeGroup: function( prepared ) {
			var primary = prepared.players[0];
			var duplicateIds = prepared.players.slice( 1 ).map( function( p ) { return String( p.id ); } );

			return new Promise( function( resolve ) {
				$.post( spMergeAjax.ajaxUrl, {
					action: 'sp_execute_merge',
					nonce: spMergeAjax.nonce,
					preview_token: prepared.token,
					primary_player: String( primary.id ),
					duplicate_players: duplicateIds
				} ).done( function( response ) {
					if ( response.success ) {
						resolve( { ok: true, backupId: response.data.backup_id } );
						return;
					}
					resolve( { ok: false, message: ( response.data && response.data.message ) || 'Merge execution failed.' } );
				} ).fail( function() {
					resolve( { ok: false, message: 'Network error occurred. Please try again.' } );
				} );
			} );
		},

		/**
		 * Replace a group row's action cell with a static result badge, and
		 * retire its checkboxes so the row can't be actioned a second time.
		 * Keeps the "Merge (x)" live count honest: a merged or failed row's
		 * ticked boxes no longer count toward anything actionable.
		 *
		 * @param {jQuery} $tr Table row for the group.
		 * @param {string} badgeHtml HTML for the badge (build with escapeHtml()
		 *                           for any interpolated text before calling this).
		 */
		setGroupResult: function( $tr, badgeHtml ) {
			$tr.find( '.sp-group-action' ).html( badgeHtml );
			$tr.find( '.sp-dup-member' ).prop( 'checked', false ).prop( 'disabled', true );
			this.updateBatchMergeCount();
		},

		/**
		 * Shared pipeline for both the single-row "Merge" button and the
		 * top/bottom "Merge (x)" batch button — the only difference between
		 * them is how many rows are passed in. Rows with fewer than two
		 * ticked players are skipped and counted, never reaching preview or
		 * execute; a row with zero ticked players isn't "skipped", it's
		 * simply not part of this action, so it isn't counted or mentioned.
		 *
		 * @param {jQuery} $rows Candidate group rows (one row, or every row).
		 */
		runMergeGroups: function( $rows ) {
			var self = this;
			var qualifying = [];
			var skipped = 0;

			$rows.each( function() {
				var $tr = $( this );
				var players = self.collectGroupPlayers( $tr );
				if ( players.length >= 2 ) {
					qualifying.push( { $tr: $tr, players: players } );
				} else if ( players.length > 0 ) {
					skipped++;
				}
			} );

			if ( ! qualifying.length ) {
				this.showMessage( 'error', spMergeAjax.strings.selectMembers );
				return;
			}

			this.setLoadingState( true );

			Promise.all( qualifying.map( function( q ) {
				return self.previewGroup( q.players ).then( function( result ) {
					result.$tr = q.$tr;
					return result;
				} );
			} ) ).then( function( previewed ) {
				var ready = previewed.filter( function( r ) { return r.ok; } );
				var previewFailed = previewed.filter( function( r ) { return ! r.ok; } );

				self.setLoadingState( false );

				if ( ! ready.length ) {
					self.showMessage( 'error', 'Nothing could be previewed: ' + previewFailed.map( function( r ) { return r.message; } ).join( ' ' ) );
					return;
				}

				var details = [];
				ready.forEach( function( r ) {
					var primary = r.players[0];
					r.players.slice( 1 ).forEach( function( dup ) {
						details.push( 'DELETE: ' + dup.name + ' #' + dup.id + ' (into ' + primary.name + ' #' + primary.id + ')' );
					} );
					r.warnings.forEach( function( w ) {
						details.push( 'WARNING (' + primary.name + '): ' + w );
					} );
				} );

				var groupWord = ready.length === 1 ? 'group' : 'groups';
				var message = 'Permanently delete ' + details.filter( function( d ) { return d.indexOf( 'DELETE:' ) === 0; } ).length
					+ ' player record(s) across ' + ready.length + ' ' + groupWord
					+ '? This cannot be undone except by reverting each backup individually.';

				self.customConfirm( message, details ).then( function( confirmed ) {
					if ( ! confirmed ) {
						return;
					}

					self.setLoadingState( true );

					var results = { merged: 0, failed: 0 };

					// Sequential, not parallel: sp_execute_merge takes the
					// plugin's merge lock, and processing in row order keeps
					// success/failure attribution unambiguous.
					var chain = Promise.resolve();
					ready.forEach( function( r ) {
						chain = chain.then( function() {
							return self.executeGroup( r ).then( function( outcome ) {
								if ( outcome.ok ) {
									results.merged++;
									self.setGroupResult( r.$tr, '<span class="sp-group-result sp-group-result-success">Merged &mdash; Backup #' + self.escapeHtml( outcome.backupId ) + '</span>' );
								} else {
									results.failed++;
									self.setGroupResult( r.$tr, '<span class="sp-group-result sp-group-result-error">Failed: ' + self.escapeHtml( outcome.message ) + '</span>' );
								}
							} );
						} );
					} );

					previewFailed.forEach( function( r ) {
						self.setGroupResult( r.$tr, '<span class="sp-group-result sp-group-result-error">Failed: ' + self.escapeHtml( r.message ) + '</span>' );
					} );

					chain.then( function() {
						self.setLoadingState( false );

						var summary = results.merged + ' merged, ' + results.failed + ' failed';
						if ( previewFailed.length ) {
							summary += ', ' + previewFailed.length + ' could not be previewed';
						}
						if ( skipped ) {
							summary += ', ' + skipped + ' skipped (fewer than 2 players ticked)';
						}

						self.showMessage( ( results.failed || previewFailed.length ) ? 'error' : 'success', summary + '.' );
						self.refreshBackupSection();
					} );
				} );
			} );
		},

		/**
		 * Row-level entry point: run the pipeline for just the one group
		 * containing the clicked button.
		 *
		 * @param {jQuery.Event} e Click event from a .sp-row-merge button.
		 */
		handleRowMerge: function( e ) {
			e.preventDefault();
			this.runMergeGroups( $( e.target ).closest( 'tr' ) );
		},

		/**
		 * Batch entry point: run the pipeline for every group row on the
		 * page (runMergeGroups() itself filters down to qualifying rows).
		 *
		 * @param {jQuery.Event} e Click event from a .sp-batch-merge button.
		 */
		handleBatchMerge: function( e ) {
			e.preventDefault();
			this.runMergeGroups( $( '.sp-duplicates-table tbody tr' ) );
		},

		/**
		 * Keep both "Merge (x)" buttons' live count in sync with how many
		 * player checkboxes are ticked across every group on the page.
		 */
		updateBatchMergeCount: function() {
			var count = $( '.sp-dup-member:checked' ).length;
			$( '.sp-batch-merge' ).text( 'Merge (' + count + ')' );
		},

```

- [ ] **Step 4: Run `node --check` again**

Run: `node --check assets/js/admin.js`
Expected: no output, exit code 0.

- [ ] **Step 5: Update `renderDuplicates()`'s markup**

The current method (before this step) reads, starting at the `if ( ! groups || ! groups.length )` early return:

```js
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
						+ ' <a href="' + this.escapeHtml( p.edit_link ) + '" target="_blank" rel="noopener noreferrer">edit</a>'
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
```

Replace it with:

```js
			if ( ! groups || ! groups.length ) {
				$content.html( header + '<p>No duplicate players found.</p>' );
				return;
			}

			var batchButton = '<p class="sp-batch-actions"><button type="button" class="button button-primary sp-batch-merge">Merge (0)</button></p>';

			var html = header + batchButton + '<table class="sp-duplicates-table">'
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
						+ ' <a href="' + this.escapeHtml( p.edit_link ) + '" target="_blank" rel="noopener noreferrer">edit</a>'
						+ '</li>';
				}
				playerList += '</ul>';

				html += '<tr>'
					+ '<td>' + playerList + '</td>'
					+ '<td style="text-align:center">' + this.escapeHtml( sorted.reduce( function( s, p ) { return s + p.events; }, 0 ) ) + '</td>'
					+ '<td style="text-align:center"><span class="sp-certainty-badge ' + this.certaintyClass( g.certainty ) + '">'
					+ this.escapeHtml( g.certainty ) + '% &mdash; ' + this.certaintyLabel( g.certainty ) + '</span></td>'
					+ '<td style="text-align:center"><span class="sp-group-action"><button type="button" class="button button-small sp-row-merge">Merge</button></span></td>'
					+ '</tr>';
			}

			html += '</tbody></table>';
			if ( groups.length >= 50 ) {
				html += '<p class="description" style="margin-top:8px;">Showing first 50 groups. Merge some duplicates and scan again to find more.</p>';
			}
			html += batchButton;
			$content.html( html );
			this.updateBatchMergeCount();
		},
```

The only differences from the original: a `batchButton` string built once and reused top and bottom; the row action cell's button class/text/wrapper (`sp-select-duplicates` / "Select ticked for Merge" → wrapped `sp-group-action` span containing `sp-row-merge` / "Merge"); and a trailing `this.updateBatchMergeCount()` call so the button(s) show the real initial count (some players start pre-ticked at ≥90% certainty) instead of a hardcoded "Merge (0)".

- [ ] **Step 6: Run `node --check` again**

Run: `node --check assets/js/admin.js`
Expected: no output, exit code 0.

- [ ] **Step 7: Update `bindEvents()`**

Change (`admin.js:234`, currently):

```js
			$( document ).on( 'click', '.sp-select-duplicates', this.handleSelectDuplicates.bind( this ) );
```

to:

```js
			$( document ).on( 'click', '.sp-row-merge', this.handleRowMerge.bind( this ) );
			$( document ).on( 'click', '.sp-batch-merge', this.handleBatchMerge.bind( this ) );
			$( document ).on( 'change', '.sp-dup-member', this.updateBatchMergeCount.bind( this ) );
```

- [ ] **Step 8: Run `node --check` one more time**

Run: `node --check assets/js/admin.js`
Expected: no output, exit code 0. This is the last JS edit in this task — confirm the whole file still parses cleanly end to end.

- [ ] **Step 9: Add the new CSS**

Append to the end of `assets/css/admin.css`:

```css

/* Batch merge (Possible Duplicates card) */
.sp-batch-actions {
    margin: 12px 0;
}

.sp-group-result {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.sp-group-result-success {
    background: #d1e7dd;
    color: #0f5132;
}

.sp-group-result-error {
    background: #f8d7da;
    color: #842029;
}
```

These reuse the exact color pairs already established for `.sp-certainty-high` (success) and `.sp-merge-message.error` (failure) elsewhere in this file, and the exact padding/radius/font values already established for `.sp-certainty-badge`, so the new badges look native to the rest of the page rather than introducing a new visual language.

- [ ] **Step 10: Full-suite PHP regression check**

This task touches no PHP, but run the existing suite anyway to confirm nothing was accidentally broken by editing the wrong file or leaving stray output:

Run (from the repository root):
```bash
cd tests && total_fail=0; for f in test-*.php; do out=$(php "$f" 2>&1); code=$?; if [ $code -ne 0 ]; then echo "FAIL: $f"; echo "$out" | tail -20; total_fail=$((total_fail+1)); fi; done; echo "files with failures: $total_fail"; cd ..
```
Expected: `files with failures: 0`.

- [ ] **Step 11: Commit**

```bash
git add assets/js/admin.js assets/css/admin.css
git commit -m "feat(js): batch-merge ticked players across duplicate-scan groups

Adds a 'Merge (x)' button above and below the Possible Duplicates
results table (x = live count of ticked players across every group),
and renames each row's own action button from 'Select ticked for
Merge' to 'Merge'. Both now execute immediately -- one preview+confirm
+execute pipeline shared by both entry points, replacing the row
button's old behavior of staging into the shared Primary/Duplicates
picker for a separate manual Preview/Execute click.

Every qualifying group (>=2 ticked players) is previewed in parallel
(sp_preview_merge is read-only, takes no lock), then a single
confirmation dialog lists everything that will happen -- including any
survivor-choice warnings the preview returned, preserving the one
safety signal the old manual flow surfaced before executing. On
confirm, groups execute sequentially against sp_execute_merge (which
does take the merge lock) in row order; a failure in one group does
not stop the rest, and each row gets a live result badge as it
resolves. No PHP changes -- both AJAX actions are reused exactly as
they exist today.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 2: Document the change in both changelogs

Independent of Task 1's code — this only touches `readme.txt` and `changelog.txt`, following the exact structural conventions already established in both files (confirmed by reading the current `= 1.3.1 =` / `## Version 1.3.1` entries before writing this task).

**Files:**
- Modify: `readme.txt` (the `== Changelog ==` section)
- Modify: `changelog.txt` (the top of the file)

**Interfaces:**
- Consumes: nothing from Task 1 (this is prose describing the shipped behavior, not code).
- Produces: nothing consumed elsewhere.

- [ ] **Step 1: Add an "Unreleased" entry to `readme.txt`**

The file currently reads, starting at `== Changelog ==`:

```
== Changelog ==

= 1.3.1 =
* Fix: the release ZIP was missing `assets/vendor/` (the bundled SlimSelect library) because the build's file-exclusion pattern matched any directory named `vendor` at any depth, not just the top-level one. Anyone who installed 1.3.0 from the GitHub release asset (rather than a git checkout) had non-functional player-picker dropdowns.
* Change: the "edit" link beside each player in the duplicate scan results now opens in a new tab, so reviewing a player's edit screen no longer loses the scan results in progress.
```

Insert a new `= Unreleased =` section directly above `= 1.3.1 =`:

```
== Changelog ==

= Unreleased =
* New: a "Merge" button on each duplicate-scan group now merges that group immediately, and a "Merge (x)" button above and below the results table merges every ticked group in one pass (x = how many players are currently ticked). One confirmation lists everything that will happen before anything runs; each group gets its own result shown inline, and one group failing doesn't stop the rest.

= 1.3.1 =
* Fix: the release ZIP was missing `assets/vendor/` (the bundled SlimSelect library) because the build's file-exclusion pattern matched any directory named `vendor` at any depth, not just the top-level one. Anyone who installed 1.3.0 from the GitHub release asset (rather than a git checkout) had non-functional player-picker dropdowns.
* Change: the "edit" link beside each player in the duplicate scan results now opens in a new tab, so reviewing a player's edit screen no longer loses the scan results in progress.
```

- [ ] **Step 2: Verify the heading was inserted correctly**

Run: `grep -n "^= Unreleased =$" readme.txt`
Expected: one match, on the line immediately after `== Changelog ==` (and its blank line).

- [ ] **Step 3: Add an "Unreleased" entry to `changelog.txt`**

The file currently starts:

```
# SportsPress Player Merge - Changelog

## Version 1.3.1

### Bug Fixes
- The release ZIP was missing `assets/vendor/` (the bundled SlimSelect library) because the build's rsync exclusion pattern (`--exclude='vendor'`) matched any directory named `vendor` at any depth in the tree, not just the top-level composer one. Anchored it to the repository root (`--exclude='/vendor'`) so `assets/vendor/` ships again. Anyone who installed 1.3.0 from the GitHub release asset — rather than a git checkout — had non-functional player-picker dropdowns as a result.

### Changes
- The "edit" link beside each player in the duplicate scan results now opens in a new tab, so reviewing a player's edit screen no longer loses the scan results in progress.
```

Insert a new `## Unreleased` section at the very top, directly above `## Version 1.3.1`:

```
# SportsPress Player Merge - Changelog

## Unreleased

### New Features
- Added a "Merge" button on each duplicate-scan group that merges that group immediately, and a "Merge (x)" button above and below the results table (x = how many players are currently ticked across every group) that merges every ticked group in one pass. Every qualifying group is previewed first; one confirmation dialog lists everything that will happen (including any survivor-choice warnings) before anything executes. Groups then execute one at a time, each showing its own result inline; one group failing doesn't stop the rest.

## Version 1.3.1

### Bug Fixes
- The release ZIP was missing `assets/vendor/` (the bundled SlimSelect library) because the build's rsync exclusion pattern (`--exclude='vendor'`) matched any directory named `vendor` at any depth in the tree, not just the top-level composer one. Anchored it to the repository root (`--exclude='/vendor'`) so `assets/vendor/` ships again. Anyone who installed 1.3.0 from the GitHub release asset — rather than a git checkout — had non-functional player-picker dropdowns as a result.

### Changes
- The "edit" link beside each player in the duplicate scan results now opens in a new tab, so reviewing a player's edit screen no longer loses the scan results in progress.
```

- [ ] **Step 4: Verify the heading was inserted correctly**

Run: `grep -n "^## Unreleased$" changelog.txt`
Expected: one match, as the first heading in the file (line 3, right after the title and a blank line).

- [ ] **Step 5: Commit**

```bash
git add readme.txt changelog.txt
git commit -m "docs: changelog entries for the batch-merge admin UI feature

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 3: Manual verification against Tikal staging

No JS test harness exists in this repo, so this is the actual test for Task 1's behavior — it must run after Task 1 (and ideally Task 2) are both committed. This task needs live SSH and browser access to the `tikal` staging host (already configured this session as an SSH alias — `ssh tikal` connects directly, no separate credentials needed) and is NOT independently parallelizable with Task 1, since there is nothing to verify until Task 1 exists.

**Files:** none (verification only — if this task finds a real bug, fix it in `assets/js/admin.js`/`assets/css/admin.css` directly and re-run from Step 1).

**Interfaces:**
- Consumes: everything Task 1 produced (see Task 1's "Produces" list) — this task exercises it end-to-end through the browser, not by calling the functions directly.

- [ ] **Step 1: Deploy the current branch to Tikal**

Tikal's plugin directory is a plain extracted copy, not a git checkout (confirmed earlier this session), so deploy by copying the changed files directly over SSH:

```bash
scp assets/js/admin.js assets/css/admin.css \
  tikal:/tmp/spm-batch-verify/
ssh tikal "set -e
DEST=/var/lib/docker/volumes/staging_wp_data/_data/wp-content/plugins/sportspress-player-merge
sudo cp /tmp/spm-batch-verify/admin.js \"\$DEST/assets/js/admin.js\"
sudo cp /tmp/spm-batch-verify/admin.css \"\$DEST/assets/css/admin.css\"
sudo chown www-data:www-data \"\$DEST/assets/js/admin.js\" \"\$DEST/assets/css/admin.css\"
rm -rf /tmp/spm-batch-verify
docker restart staging-wp
"
```

(Create the remote `/tmp/spm-batch-verify/` directory with `ssh tikal "mkdir -p /tmp/spm-batch-verify"` first if `scp` refuses to create it.)

Expected: no errors; `docker restart staging-wp` prints `staging-wp`.

- [ ] **Step 2: Confirm the deployed file has the new code**

Run: `ssh tikal "grep -c 'runMergeGroups\|sp-batch-merge\|sp-row-merge' /var/lib/docker/volumes/staging_wp_data/_data/wp-content/plugins/sportspress-player-merge/assets/js/admin.js"`
Expected: a count greater than 0 (confirms the deploy actually landed, not a stale cached copy).

- [ ] **Step 3: Load the admin page in a browser and open the console**

Navigate to the SportsPress Player Merge admin screen on Tikal (`https://tikal.lusk.ee` or the Tailscale MagicDNS name, port 8443, under SportsPress → Players → Player Merge — check `~/.knowledge/hosts/tikal.md` for the exact current URL/port if unsure) and open the browser console before doing anything else, so any JS error is visible immediately.

Expected: page loads with no console errors, and no mention of `sp-select-duplicates` or `handleSelectDuplicates` (confirms the old code path is gone, not just added-to).

- [ ] **Step 4: Click "Scan for Duplicates" and confirm the new buttons render**

Expected:
- A "Merge (x)" button appears directly above the results table, and another directly below it (or below the "Showing first 50 groups" note, if present). Both show the same number.
- x reflects however many players start pre-ticked (any player scored ≥90% certainty starts checked) — not "Merge (0)" if any group has a high-certainty pre-ticked member.
- Each group row's action button reads "Merge" (not "Select ticked for Merge").

- [ ] **Step 5: Tick/untick checkboxes and confirm the live count**

Tick a box in a group that started unticked; expected: both "Merge (x)" buttons' count increases by 1 immediately, no page reload. Untick it; expected: the count decreases by 1 again.

- [ ] **Step 6: Single-row "Merge" with fewer than two players ticked**

Untick every box in one group except one (or zero), leaving only one ticked, then click that row's "Merge" button.

Expected: an error message reading "Select at least two players from this group to merge." (existing `spMergeAjax.strings.selectMembers` string) — no confirmation dialog opens, no network request fires (check the Network tab).

- [ ] **Step 7: Single-row "Merge" with two or more players ticked**

Find (or arrange, by ticking a second box in) a group with at least two players ticked and click that row's "Merge" button.

Expected: one confirmation dialog opens, listing exactly the ticked duplicates in that one group as `DELETE: <name> #<id> (into <primary name> #<primary id>)` lines (plus any `WARNING (...)` lines, if the preview returned warnings for this group) — matching the primary/duplicate split the row already used (first-ticked-by-events = primary). Click "No" — expected: dialog closes, nothing happens, no request beyond the preview fetch that already ran to build the dialog.

Click that row's "Merge" again and click "Yes" this time. Expected: the loading overlay shows briefly, then that row's action cell replaces its button with a green "Merged — Backup #<some number>" badge, the row's checkboxes become unticked and disabled, a success message appears summarizing "1 merged, 0 failed.", and the Backups list at the bottom of the page gains a new entry for that backup ID.

- [ ] **Step 8: Batch "Merge (x)" across multiple groups**

Scan again (Step 4) to get a fresh set of groups. Tick at least two players each in two or three different groups (leave at least one other group with zero or one player ticked, to exercise the "skipped" count), then click either "Merge (x)" button (top or bottom — confirm both are present and both work by using the top one this time).

Expected: one confirmation dialog opens, listing every `DELETE:`/`WARNING` line across all the groups you ticked (not just one). Click "Yes". Expected: groups execute one at a time (not all at once — watch the Network tab; each group's `sp_preview_merge` request already happened before the dialog, but `sp_execute_merge` requests should appear one at a time, each waiting for the previous one's response before the next fires), each row gets its own "Merged — Backup #<id>" badge as it finishes, and the final message reads something like "3 merged, 0 failed, 1 skipped (fewer than 2 players ticked)." — with the skipped count matching however many groups you deliberately left under-ticked. The Backups list gains one new entry per merged group.

- [ ] **Step 9: Confirm the shared picker at the top of the page is untouched**

Manually search for and select two players in the Primary/Duplicates dropdowns at the top of the page (not from the scan results), click "Preview Merge", confirm the existing preview card renders as before, then click "Cancel" rather than executing (to avoid an unplanned real merge as part of a verification pass).

Expected: this flow behaves exactly as it did before this feature — full preview card, separate Preview/Execute buttons — confirming Task 1 didn't regress the manual entry point.

- [ ] **Step 10: Confirm the file the deploy touched doesn't leave a discrepancy with git**

Run: `ssh tikal "md5sum /var/lib/docker/volumes/staging_wp_data/_data/wp-content/plugins/sportspress-player-merge/assets/js/admin.js"` and compare against `md5sum assets/js/admin.js` run locally against the committed file from Task 1.

Expected: identical hashes — confirms Tikal is running exactly the committed code, not a manually-patched variant that could mask a real bug.

- [ ] **Step 11: Report results**

If every expectation above held, this task is done — no commit needed (verification only). If any expectation failed, fix the root cause in `assets/js/admin.js` (or `admin.css`), commit the fix on top of Task 1's commit with a message describing what was wrong, and restart this task from Step 1 with the corrected file.
