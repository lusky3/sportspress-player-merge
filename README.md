# SportsPress Player Merge

A WordPress plugin that provides advanced player merging functionality for SportsPress with comprehensive data preservation, intelligent statistics handling, and full revert capabilities.

## Features

- **Smart Player Merging**: Merge duplicate players while preserving all data including complex statistics structures
- **Advanced Statistics Handling**: Intelligent merging of nested SportsPress statistics arrays without data corruption
- **Reference Tracking**: Tracks and properly handles all database references (player lists, events, etc.)
- **Team Management**: Handles current and past team assignments with deduplication
- **Data Preview**: See exactly what will be merged before execution with expandable sections
- **Full Revert**: Complete undo functionality that restores deleted players and reverts all references
- **Statistics Recalculation**: Automatically triggers SportsPress statistics recalculation after operations
- **Modern Interface**: Clean, responsive admin interface with AJAX interactions
- **Robust Backup System**: Uses dedicated database table with comprehensive data preservation
- **Performance Optimized**: Handles large datasets and complex statistics structures efficiently

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin
3. Navigate to **SportsPress → Players → Player Merge**

## Usage

1. **Select Primary Player**: Choose the player to keep (retains all data)
2. **Select Duplicates**: Choose players to merge and delete
3. **Preview**: Review the detailed merge preview showing data changes
4. **Execute**: Perform the merge operation with full backup creation
5. **Revert**: Undo the merge if needed (restores deleted players and all references)

## Data Handling

### Reference Management

- **Player Lists**: Automatically updates player list memberships
- **Event Assignments**: Preserves all game and event assignments
- **Statistics**: Maintains statistical data and triggers recalculation
- **Custom References**: Handles all SportsPress metadata references

### Team Management

- Preserves current and past team assignments
- Combines team data from all merged players
- Prevents duplicate team assignments
- Maintains team relationship integrity

### Merge Logic

- **Teams**: Combines without duplicates, preserving current/past status
- **Leagues/Divisions**: Merges all league assignments across seasons
- **Seasons**: Combines all season participation
- **Positions**: Merges all position assignments
- **Assignments**: Preserves all sp_assignments data
- **Custom Fields**: Preserves all SportsPress metadata with proper serialization

## Technical Details

- **WordPress Version**: 5.0+
- **PHP Version**: 7.4+
- **Dependencies**: SportsPress plugin
- **Database**: Uses dedicated wp_sp_merge_backups table for backup storage
- **Security**: Comprehensive nonce verification and capability checks
- **Error Handling**: Extensive try-catch blocks with detailed logging

## File Structure

```text
sportspress-player-merge/
├── assets/
│   ├── css/admin.css          # Modern responsive styling
│   └── js/admin.js            # AJAX interactions and UX
├── classes/
│   └── class-player-merge.php # Core functionality (1000+ lines)
├── includes/
│   └── admin-page.php         # Admin interface template
├── sportspress-player-merge.php # Main plugin file
├── uninstall.php              # Cleanup on uninstall
├── README.md
├── readme.txt
└── license.txt
```

## Key Improvements

### Enhanced Statistics Merging (v0.2.0)

- **Intelligent Array Merging**: Properly handles complex nested SportsPress statistics structures
- **Data Corruption Prevention**: Advanced validation prevents statistics data corruption during merge
- **Large Dataset Support**: Optimized for players with extensive statistics (12KB+ data structures)
- **League-Event Structure**: Preserves SportsPress league → event → statistics hierarchy

### Reference Tracking System

- Tracks all database references before merging
- Properly reverts references during undo operations
- Prevents orphaned data in player lists and events
- Dynamic reference lookup for accurate restoration

### Enhanced Backup System

- Dedicated database table for better performance
- Comprehensive data backup including taxonomies
- Automatic cleanup of old backups
- Proper serialization handling with validation

### Statistics Integration

- Automatic statistics recalculation after operations
- Handles sp_assignments data properly
- Triggers SportsPress calculation hooks
- Clears statistics cache when needed

## Development

### Requirements

- WordPress development environment
- SportsPress plugin installed
- PHP debugging enabled (recommended)
- MySQL/MariaDB database

### Architecture

- **Object-oriented design** with comprehensive error handling
- **AJAX-driven interface** for smooth user experience
- **Modular methods** for easy maintenance and extension
- **WordPress coding standards** compliance

### Key Methods

- `find_player_references()` - Tracks all database references
- `revert_player_references()` - Reverts references during undo
- `recalculate_player_statistics()` - Triggers stats recalculation
- `backup_player_data()` - Creates comprehensive backups
- `restore_player_data()` - Restores from backup with validation

### Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly with real SportsPress data
5. Submit a pull request

## License

GPL v2 or later - see license.txt

## Support

For issues and feature requests, please use the GitHub issue tracker.

## AI Disclosure

This plugin was developed with AI assistance (Kiro/Claude). All code has undergone multiple rounds of automated security, performance, SportsPress integration, and data integrity review.
