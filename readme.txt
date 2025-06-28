=== SportsPress Player Merge ===
Contributors: yourname
Tags: sportspress, players, merge, duplicate, sports
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Advanced player merging tool for SportsPress with reference tracking, intelligent data handling, and full revert capabilities.

== Description ==

SportsPress Player Merge is a powerful WordPress plugin designed to solve the common problem of duplicate players in SportsPress databases. It provides a sophisticated merging system that preserves all player data while eliminating duplicates.

= Key Features =

* **Reference Tracking**: Tracks and properly handles all database references (player lists, events, assignments)
* **Intelligent Merging**: Combines all player data while preventing duplicates
* **Data Preservation**: All statistics, teams, leagues, assignments, and custom fields are preserved
* **Preview System**: See exactly what will be merged before execution with expandable sections
* **Full Revert**: Complete undo functionality restores deleted players and reverts all references
* **Statistics Recalculation**: Automatically triggers SportsPress statistics recalculation
* **Modern Interface**: Clean, responsive admin interface with AJAX interactions
* **Backup System**: Uses dedicated database table for efficient backup storage
* **Performance**: Optimized to handle hundreds of players efficiently with proper memory management

= How It Works =

The plugin uses a sophisticated algorithm to merge player data:

1. **Data Analysis**: Analyzes all player data and relationships
2. **Team Classification**: Identifies current and past team assignments
3. **Data Merging**: Combines all data without duplication
4. **Backup Creation**: Creates complete backup before any changes
5. **Reference Updates**: Updates all database references to merged players

= Technical Architecture =

**File Structure:**
* `sportspress-player-merge.php` - Main plugin file and initialization
* `classes/class-player-merge.php` - Core functionality with 1000+ lines of detailed documentation
* `includes/admin-page.php` - HTML template for admin interface
* `assets/css/admin.css` - Modern styling with responsive design
* `assets/js/admin.js` - JavaScript for AJAX interactions and UX
* `uninstall.php` - Proper cleanup on plugin removal

**Data Flow:**
1. User selects primary player and duplicates
2. AJAX preview request generates comparison table
3. User reviews merge preview with expandable sections
4. Execute request creates backup and performs merge
5. Revert option available to undo changes

**Database Operations:**
* Uses WordPress `get_posts()` for efficient player queries
* Leverages `wp_get_object_terms()` for taxonomy data
* Stores backups in dedicated `wp_sp_merge_backups` table for better performance
* Tracks and reverts all database references using `find_player_references()` and `revert_player_references()`
* Updates references using direct SQL for performance with proper sanitization

= Merge Logic Details =

**Current Team Detection:**
```php
// Checks multiple meta keys for team assignments
$meta_keys = ['sp_current_team', 'sp_team', '_sp_current_team'];

// Falls back to taxonomy terms if no meta data
$team_terms = wp_get_object_terms($player_id, 'sp_team');
```

**Data Deduplication:**
```php
// Prevents duplicate team assignments
$merged_teams = array_unique(array_merge($primary_teams, $duplicate_teams));

// Only updates if new data exists
if (count($merged_teams) > count($primary_teams)) {
    wp_set_object_terms($primary_id, $merged_teams, 'sp_team');
}
```

= Backup System =

**Backup Creation:**
* Stores complete player post data
* Preserves all meta fields and custom data with proper serialization
* Saves taxonomy relationships (teams, leagues, seasons, positions)
* Tracks all database references before merging
* Generates unique backup ID with timestamp
* Uses dedicated database table for better performance

**Enhanced Backup Structure:**
```php
$backup_data = [
    'timestamp' => current_time('mysql'),
    'primary_id' => $primary_id,
    'duplicate_ids' => $duplicate_ids,
    'primary_backup' => $this->backup_player_data($primary_id),
    'duplicate_backups' => [/* individual player backups */],
    'reference_changes' => [/* tracked database references */]
];
```

**Enhanced Revert Process:**
1. Retrieves backup data using stored ID
2. Reverts all database references from primary back to duplicates
3. Restores primary player to original state
4. Recreates deleted duplicate players with original IDs
5. Restores all meta data and taxonomy relationships with validation
6. Triggers statistics recalculation if assignments exist
7. Cleans up backup data after successful revert

= AJAX Architecture =

**Security:**
* All AJAX requests use WordPress nonces for security
* Input sanitization with `sanitize_text_field()` and `intval()`
* Capability checks ensure only authorized users can merge

**Error Handling:**
* Comprehensive try-catch blocks prevent fatal errors
* Detailed error logging for debugging
* User-friendly error messages in admin interface
* Network error detection and retry suggestions

**Response Format:**
```javascript
// Success response
{
    success: true,
    data: {
        message: 'Operation completed',
        backup_id: 'merge_1234567890_abc123'
    }
}

// Error response
{
    success: false,
    data: {
        message: 'Detailed error description'
    }
}
```

= Performance Optimizations =

**Database Queries:**
* Uses `posts_per_page => -1` to load all players in single query
* Implements `array_unique()` for efficient deduplication
* Direct SQL updates for reference changes to avoid multiple queries

**Memory Management:**
* Processes players individually to avoid memory limits
* Cleans up backup data after successful operations
* Uses WordPress caching where applicable

**User Interface:**
* Lazy loading of expandable sections
* AJAX requests prevent page reloads
* Loading states provide user feedback
* Debounced form validation

= Compatibility =

**WordPress:**
* Minimum: WordPress 5.0
* Tested up to: WordPress 6.4
* Uses standard WordPress APIs throughout

**SportsPress:**
* Compatible with SportsPress 2.7+
* Supports all standard SportsPress taxonomies
* Works with custom SportsPress configurations

**PHP:**
* Minimum: PHP 7.4
* Uses modern PHP syntax with backward compatibility
* Follows WordPress coding standards

= Troubleshooting =

**Common Issues:**

1. **Preview shows "None" for teams:**
   - Check if teams are stored as taxonomy terms or meta fields
   - Verify SportsPress team assignments are correct

2. **Revert function fails:**
   - Ensure backup data exists (check wp_sp_merge_backups table)
   - Verify user has sufficient permissions
   - Check PHP error logs for detailed error messages

3. **Player missing from lists after revert:**
   - This was a known issue in earlier versions, now fixed with reference tracking
   - The plugin now properly reverts all database references

4. **Statistics showing as zero after revert:**
   - Plugin now automatically triggers statistics recalculation
   - Uses multiple methods to ensure SportsPress recalculates stats

5. **Performance issues with many players:**
   - Increase PHP memory limit if needed
   - Consider processing in smaller batches for very large datasets

**Debug Mode:**
Enable WordPress debug logging to see detailed operation logs:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

= Developer Notes =

**Extending the Plugin:**
The plugin is designed with extensibility in mind. Key extension points:

* `generate_merge_preview()` - Customize preview display
* `merge_single_player()` - Modify merge logic
* `backup_player_data()` - Add additional backup data
* `find_player_references()` - Customize reference tracking
* `revert_player_references()` - Modify reference reverting logic
* `recalculate_player_statistics()` - Add custom statistics handling

**Hooks and Filters:**
While not implemented in current version, the architecture supports adding WordPress hooks for customization.

**Code Quality:**
* Comprehensive inline documentation
* Follows WordPress coding standards
* Modular architecture for maintainability
* Extensive error handling and validation

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/sportspress-player-merge-api/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Navigate to SportsPress → Players → Player Merge to access the tool

== Frequently Asked Questions ==

= Can I undo a merge operation? =

Yes! The plugin creates a complete backup before every merge. Click the "Revert" button to restore all deleted players and undo the merge.

= What happens to player statistics? =

All statistics and custom fields are preserved and combined. The primary player retains all original data plus data from merged players.

= Can players be on multiple teams? =

Yes, the plugin supports players being on multiple teams simultaneously, especially useful for players active in multiple leagues or seasons.

= How does the plugin handle seasons? =

The plugin analyzes season data to determine current vs past teams. Players with more recent season activity determine the "current" team status.

== Screenshots ==

1. Main merge interface with player selection
2. Detailed merge preview showing data comparison
3. Expandable sections for detailed data review
4. Success message with revert option
5. Modern, responsive admin interface

== Changelog ==

= 0.1 =
* Initial release with comprehensive functionality
* Advanced player merging with reference tracking
* Season-aware team logic for current vs past team determination
* Complete backup system using dedicated database table
* Full revert functionality that restores all references
* Statistics recalculation after merge/revert operations
* Modern responsive admin interface with AJAX interactions
* Expandable preview sections for detailed data review
* Comprehensive error handling and logging
* Performance optimizations for large datasets
* Enhanced security with proper nonce verification and capability checks
* Proper handling of player assignments and database references
* Automatic cleanup of old backups
* Support for complex SportsPress data structures

== Upgrade Notice ==

= 0.1 =
Initial release with comprehensive player merging functionality. Includes reference tracking, full revert capabilities, and statistics recalculation. Backup your database before use as recommended for all data operations.