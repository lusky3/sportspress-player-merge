=== SportsPress Player Merge ===
Contributors: lusky3
Tags: sportspress, players, merge, duplicate, sports
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Advanced player merging tool for SportsPress with structure-aware data handling, transaction safety, and full revert capabilities.

== Description ==

SportsPress Player Merge solves the common problem of duplicate players in SportsPress databases. It provides a merging system that preserves all player data while eliminating duplicates.

= Key Features =

* **Structure-Aware Merging**: Properly handles SportsPress serialized data (sp_players, sp_timeline, sp_stars, sp_order) by unserializing, replacing player ID keys, and re-serializing
* **Transaction Safety**: All merge and revert operations wrapped in database transactions with rollback on failure
* **Dynamic Taxonomy Discovery**: Automatically discovers all taxonomies registered for sp_player via get_object_taxonomies()
* **Complete Data Preservation**: Merges sp_leagues, sp_assignments, sp_statistics, sp_metrics, teams, and all custom fields
* **Preview System**: See exactly what will be merged before execution, including event counts and all taxonomy data
* **Full Revert**: Restores deleted players, original event meta, and all references from comprehensive backups
* **SportsPress Cache Clearing**: Automatically clears post caches, transients, and fires recalculation hooks after operations
* **Security**: Nonce verification, capability checks (edit for read, delete for write), user-scoped backups, input sanitization, output escaping
* **Accessibility**: ARIA dialog attributes, focus trapping, keyboard navigation on confirmation modals

= How It Works =

1. User selects primary player and duplicates
2. Preview shows data comparison with event counts and all taxonomies
3. Backup stores player data AND affected event serialized meta
4. Merge updates simple meta (exact-match), serialized meta (structure-aware), and taxonomies
5. SportsPress caches cleared for recalculation
6. Revert restores exact original values from backup

= Technical Details =

**File Structure:**

* `sportspress-player-merge.php` - Bootstrap with is_admin() guard, plugins_loaded hook
* `classes/class-sp-merge-controller.php` - Coordinates components and hooks
* `classes/class-sp-merge-admin.php` - Admin menu, assets, player query (capped at 500)
* `classes/class-sp-merge-ajax.php` - AJAX handlers with wp_unslash, capability checks
* `classes/class-sp-merge-processor.php` - Core merge logic with transaction wrapping
* `classes/class-sp-merge-backup.php` - Backup/restore with event meta preservation
* `classes/class-sp-merge-preview.php` - Preview with batch cache loading
* `includes/admin-page.php` - Template with full i18n and escaping
* `uninstall.php` - Clean removal without wp_cache_flush

**Database:**

* Backups stored in `wp_sp_merge_backups` table
* Player queries use `posts_per_page => 500` with `no_found_rows => true`
* All queries use `$wpdb->prepare()` for parameterized SQL
* Backup operations scoped to current user_id

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/sportspress-player-merge/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Navigate to SportsPress > Players > Player Merge to access the tool

== Frequently Asked Questions ==

= Can I undo a merge operation? =

Yes. The plugin creates a comprehensive backup before every merge, including affected event meta. Click "Revert" to restore all deleted players and original data.

= What happens to player statistics? =

Statistics (sp_statistics), league assignments (sp_leagues), and display settings (sp_assignments) are intelligently merged. Primary player values take precedence for overlapping data.

= What SportsPress data is handled? =

Event box scores (sp_players), timelines (sp_timeline), star selections (sp_stars), player ordering (sp_order), offense/defense assignments, all taxonomies (leagues, seasons, positions, roles), metrics, and team assignments.

== Changelog ==

= 1.1.0 =
* Fuzzy duplicate detection with 14 matching scenarios (nicknames, prefix normalization, typos, accents, bilingual equivalents, compound names, and more)
* Tiered confidence scoring: High ≥90%, Medium ≥70%, Low <70%
* Email integration: uses spt_email from SportsPress Admin Tools for matching (+20% boost) and display
* Length-aware Levenshtein thresholds prevent false positives on short names
* SportsPress position abbreviations (G), (C), (D) stripped before matching

= 1.0.0 =
* Fix: serialized event meta ordering bug (pre-collect event IDs before simple meta update)
* Fix: XSS in duplicate scan results (escape all player names via escapeHtml helper)
* Fix: unbounded query capped at 2000 players with batched event count query
* Fix: lock race condition (single mechanism based on object cache availability)
* Feature: featured image handling (copy thumbnail from duplicate to primary)
* Feature: richer execute confirmation dialog showing primary name and duplicate count
* Feature: retain backup after revert (mark 'reverted' instead of deleting)
* Feature: plugin_version field in backup data JSON
* Feature: .pot translation file
* Feature: Select2 bundled locally (no CDN dependency)
* Fix: aria-live on message container, scoped button selector
* Fix: readme.txt stable tag and version requirements

= 0.4.0 =
* Real player names in Select2 dropdowns with team, position, and event count
* Per-player event counts with auto-select best primary by events
* Certainty text labels (High/Medium/Low)
* Draggable cards with localStorage persistence
* Table caption for screen readers
* 50-group cap disclosure
* Column centering for Events, Certainty, Action
* Fix: event count query (query events referencing player, not player referencing events)
* GitHub updater for automatic updates from releases

= 0.3.0 =
* **BREAKING**: Backup data format changed; existing v0.2.0 backups may not revert correctly
* Replace blind SQL REPLACE with structure-aware serialized data handling
* Add database transactions (START TRANSACTION/COMMIT/ROLLBACK) on merge and revert
* Merge sp_leagues and sp_assignments instead of skipping them
* Dynamic taxonomy discovery via get_object_taxonomies('sp_player')
* Add SportsPress cache clearing after merge (clean_post_cache, transients, action hook)
* Backup affected event serialized meta before modification
* Revert restores exact original event meta values from backup
* Add wp_unslash() on all $_POST access
* Add esc_html()/esc_attr() on all preview output
* Use delete_sp_players capability for destructive operations
* Scope backup load/delete/revert to current user_id
* Prevent self-merge (primary cannot be in duplicates)
* Deduplicate input IDs
* Cap posts_per_page to 500 with no_found_rows
* Guard plugin loading with is_admin()
* Batch meta/term cache loading in preview
* Remove wp_cache_flush() from uninstall
* Remove all verbose error_log calls
* ARIA dialog on confirmation modal with focus trapping and Escape key
* PHP 8.2+ type hints throughout
* WPCS formatting (tabs, Yoda conditions, spacing)
* Remove dead code throughout

= 0.2.0 =
* Enhanced statistics merging algorithm
* Data corruption prevention for complex structures
* Large dataset optimization
* Improved error handling and reference tracking

= 0.1.0 =
* Initial release

== Upgrade Notice ==

= 1.1.0 =
Adds intelligent fuzzy duplicate detection with 14 matching scenarios. Existing exact-match duplicates will now also show nickname, typo, and bilingual matches.

= 1.0.0 =
Production-ready release. Fixes data integrity bugs, XSS vulnerabilities, and performance issues. Backup table adds 'status' column (auto-migrated).

= 0.3.0 =
Major rewrite addressing security, data integrity, and SportsPress integration. Existing backups from v0.2.0 may not revert correctly. Take a database backup before upgrading.

== AI Disclosure ==

This plugin was developed with AI assistance (Kiro/Claude). All code has undergone multiple rounds of automated security, performance, SportsPress integration, and data integrity review.
