---
name: sportspress-internals
description: Complete reference for SportsPress plugin internals, data structures, hooks, and extension points. Use when building plugins that extend SportsPress, working with SportsPress data, or debugging SportsPress behavior.
---

# SportsPress Plugin Internals Reference

## Architecture Overview

SportsPress (v2.7) is a WordPress plugin for league/sports management. It uses a singleton pattern via `SP()` / `SportsPress::instance()`.

### Bootstrap Flow
1. `sportspress.php` defines the `SportsPress` class singleton
2. `define_constants()` sets `SP_PLUGIN_FILE`, `SP_VERSION`, `SP_TEMPLATE_PATH` (default `sportspress/`), `SP_DELIMITER` (`|`)
3. `includes()` loads core files, admin files (if `is_admin()`), frontend files, post types, abstracts, modules, templates, REST API
4. `init()` fires on WordPress `init` at priority 0, instantiates `SP_Modules`, `SP_Countries`, `SP_Formats`, `SP_Templates`, `SP_Feeds`
5. `include_modules()` scans `modules/` directory and includes all `.php` files
6. `do_action('sportspress_loaded')` fires after all includes

### Key Actions in Bootstrap
- `before_sportspress_init` — fires before SP init
- `sportspress_init` — fires after SP init (modules, countries, formats loaded)
- `sportspress_loaded` — fires after all files included

### Autoloader
Classes prefixed with `sp_` are autoloaded:
- `sp_shortcode_*` → `includes/shortcodes/`
- `sp_meta_box*` → `includes/admin/post-types/meta-boxes/`
- `sp_admin*` → `includes/admin/`
- `sp_*` (fallback) → `includes/`

### Class Hierarchy
- `SP_Custom_Post` — abstract base for all data classes (`includes/abstracts/abstract-sp-custom-post.php`)
  - Properties accessed via `__get($key)` which calls `get_post_meta($this->ID, 'sp_' . $key, true)`
  - `get_terms_sorted_by_sp_order($taxonomy)` — returns terms sorted by `sp_order` term meta
- `SP_Event extends SP_Custom_Post`
- `SP_Player extends SP_Custom_Post`
- `SP_Team extends SP_Custom_Post`
- `SP_Staff extends SP_Custom_Post`
- `SP_Calendar extends SP_Custom_Post`
- `SP_League_Table extends SP_Custom_Post`
- `SP_Player_List extends SP_Custom_Post`

### User Roles
- `sp_player` — can edit own player/event/team
- `sp_staff` — can edit own staff/event/team/player
- `sp_event_manager` — full event CRUD, edit teams/players/staff
- `sp_team_manager` — full player/staff/event/list CRUD
- `sp_league_manager` — near-admin capabilities for all SP types

---

## Custom Post Types

### Content Post Types (public, has single pages)

#### `sp_event` — Events/Matches
- **capability_type**: `sp_event`
- **REST base**: `events` (controller: `SP_REST_Posts_Controller`)
- **supports**: title, editor, author, thumbnail, excerpt (+ comments if option enabled)
- **slug option**: `sportspress_event_slug` (default: `event`)
- **Meta keys**:
  - `sp_team` (multiple, stored via `add_post_meta`) — team IDs participating
  - `sp_player` (multiple) — player IDs participating (with 0 delimiters between teams)
  - `sp_results` (single, serialized array) — `array( team_id => array( result_slug => value, 'outcome' => array(outcome_slugs) ) )`
  - `sp_players` (single, serialized array) — `array( team_id => array( player_id => array( perf_slug => value, 'status' => 'lineup'|'sub', 'sub' => player_id, 'number' => N, 'position' => term_id ) ) )`
  - `sp_timeline` (single, serialized array) — `array( team_id => array( player_id => array( perf_slug => array( minute1, minute2, ... ) ) ) )`
  - `sp_officials` (single, serialized array) — `array( duty_term_id => array( official_post_ids ) )`
  - `sp_staff` (multiple) — staff IDs
  - `sp_offense` (multiple) — player IDs on offense
  - `sp_defense` (multiple) — player IDs on defense
  - `sp_format` — event format (default: `league`; used to filter competitive events)
  - `sp_mode` — `team` or `player`
  - `sp_day` — match day string
  - `sp_minutes` — full time minutes (default from `sportspress_event_minutes` option, typically 90)
  - `sp_columns` (single, serialized array) — selected performance columns to display
  - `sp_result_columns` (single, serialized array) — selected result columns
  - `sp_order` (single, serialized array) — player ordering per team
  - `sp_stars` (single, serialized array) — star player selections
  - `sp_specs` (single, serialized array) — event specifications `array( spec_slug => value )`
  - `sp_video` — video embed URL
  - `sp_select` — team selection mode

#### `sp_team` — Teams
- **capability_type**: `sp_team`
- **hierarchical**: true (supports parent teams)
- **REST base**: `teams`
- **supports**: title, editor, author, thumbnail, page-attributes, excerpt
- **slug option**: `sportspress_team_slug` (default: `team`)
- **Meta keys**:
  - `sp_abbreviation` — short team name (e.g., "MUN")
  - `sp_url` — external website URL
  - `sp_redirect` — whether to redirect to external URL
  - `sp_columns` (single, serialized) — `array( league_id => array( season_id => array( column_slug => value ) ) )` — manual column overrides
  - `sp_staff` (multiple) — staff post IDs
  - `sp_table` (multiple) — league table post IDs
  - `sp_list` (multiple) — player list post IDs

#### `sp_player` — Players
- **capability_type**: `sp_player`
- **REST base**: `players`
- **supports**: title, editor, author, thumbnail, excerpt, page-attributes
- **slug option**: `sportspress_player_slug` (default: `player`)
- **Meta keys**:
  - `sp_number` — squad number
  - `sp_current_team` (multiple) — current team IDs
  - `sp_past_team` (multiple) — past team IDs
  - `sp_nationality` (multiple) — 3-letter country codes
  - `sp_metrics` (single, serialized array) — `array( metric_slug => value )` (height, weight, etc.)
  - `sp_statistics` (single, serialized array) — `array( league_id => array( season_id => array( stat_slug => value ) ) )` — manual stat overrides
  - `sp_leagues` (single, serialized array) — `array( league_id => array( season_id => team_id ) )` — team assignment per league/season
  - `sp_columns` (single, serialized array) — selected columns to display

#### `sp_staff` — Staff Members
- **capability_type**: `sp_staff`
- **REST base**: `staff`
- **supports**: title, editor, author, thumbnail, excerpt
- **slug option**: `sportspress_staff_slug` (default: `staff`)
- **Meta keys**:
  - `sp_current_team` (multiple) — current team IDs
  - `sp_past_team` (multiple) — past team IDs
  - `sp_nationality` (multiple) — country codes

### Secondary Post Types (public, has single pages, display data views)

#### `sp_calendar` — Event Calendars
- **Meta keys**: `sp_format`, `sp_team`, `sp_columns`, feeds-related meta
- Displays events filtered by league/season/team in various formats (list, calendar, blocks)

#### `sp_table` — League Tables
- **Meta keys**:
  - `sp_team` (multiple) — team IDs (when `sp_select` = `manual`)
  - `sp_teams` (single, serialized) — manual team stat overrides
  - `sp_columns` — selected columns
  - `sp_adjustments` (single, serialized) — `array( team_id => array( column_slug => adjustment_value ) )`
  - `sp_orderby` — column to sort by (default: `default` uses priorities)
  - `sp_order` — ASC/DESC
  - `sp_select` — `auto` or `manual`
  - `sp_date` — date filter (0, w, day, range)
  - `sp_date_from`, `sp_date_to`, `sp_date_relative`, `sp_date_past` — date range params
  - `sp_event_status` — which event statuses to include (publish, future)
  - `sp_mode` — `team` or `player`

#### `sp_list` — Player Lists
- **Meta keys**: `sp_format`, `sp_team`, `sp_players` (serialized), `sp_columns`, `sp_orderby`, `sp_order`

### Configuration Post Types (private, admin-only, `capability_type: sp_config`)

These define the schema for statistics. Their `post_name` (slug) becomes the variable key used in equations and data arrays.

#### `sp_result` — Event Result Types
- Examples: "Goals", "Points", "Sets"
- **Meta keys**: `sp_equation`, `sp_precision`, `sp_priority`, `sp_order`
- The **primary result** is set via `sportspress_primary_result` option

#### `sp_outcome` — Event Outcomes
- Examples: "Win", "Loss", "Draw"
- **Meta keys**:
  - `sp_condition` — `>` (most primary result), `<` (least), `=` (equal), `else` (default)
  - `sp_abbreviation` — single letter (W, L, D)
  - `sp_color` — hex color for display

#### `sp_column` — League Table Columns
- Examples: "P" (played), "W" (wins), "Pts" (points)
- **Meta keys**:
  - `sp_equation` — equation string using `$variables` (e.g., `$w * 3 + $d`)
  - `sp_precision` — decimal places
  - `sp_priority` — sort priority (1 = highest)
  - `sp_order` — `ASC` or `DESC` for this priority

#### `sp_performance` — Player Performance Variables
- Examples: "Goals", "Assists", "Yellow Cards"
- **Meta keys**:
  - `sp_format` — `number`, `time`, `text`, `equation`, `checkbox`
  - `sp_equation` — equation for calculated performance
  - `sp_precision` — decimal places
  - `sp_timed` — whether to track minute-by-minute (for timeline)
  - `sp_sendoff` — whether this is a sendoff event (red card)
  - `sp_section` — `-1` (all), `0` (offense), `1` (defense)
  - `sp_visible` — whether visible by default
  - `sp_color` — display color

#### `sp_statistic` — Player Career Statistics
- Examples: "Goals per Game", "Win Rate"
- **Meta keys**:
  - `sp_equation` — equation using accumulated totals
  - `sp_precision` — decimal places
  - `sp_type` — `total` or `average`
  - `sp_visible` — default visibility
  - `sp_section` — offense/defense/all

#### `sp_metric` — Player Physical Metrics
- Examples: "Height", "Weight", "Date of Birth"
- Stored in player's `sp_metrics` serialized array

#### `sp_spec` — Event Specifications (via module)
- Examples: "Attendance", "Weather"
- Stored in event's `sp_specs` serialized array

---

## Taxonomies

All taxonomies are hierarchical, public, REST-enabled with `SP_REST_Terms_Controller`.

### `sp_league` — Leagues/Competitions
- **Object types**: `sp_event`, `sp_calendar`, `sp_team`, `sp_table`, `sp_player`, `sp_list`, `sp_staff`
- **REST base**: `leagues`
- **Slug option**: `sportspress_league_slug` (default: `league`)
- **Filter**: `sportspress_league_object_types`
- **Registration filter**: `sportspress_register_taxonomy_league`
- **Conditional**: `apply_filters('sportspress_has_leagues', true)`

### `sp_season` — Seasons
- **Object types**: `sp_event`, `sp_calendar`, `sp_team`, `sp_table`, `sp_player`, `sp_list`, `sp_staff`
- **REST base**: `seasons`
- **Slug option**: `sportspress_season_slug` (default: `season`)
- **Filter**: `sportspress_season_object_types`
- **Registration filter**: `sportspress_register_taxonomy_season`
- **Conditional**: `apply_filters('sportspress_has_seasons', true)`

### `sp_venue` — Venues
- **Object types**: `sp_event`, `sp_calendar`, `sp_team`
- **REST base**: `venues`
- **Slug option**: `sportspress_venue_slug` (default: `venue`)
- **Filter**: `sportspress_event_object_types`
- **Term meta**: stored in `taxonomy_{term_id}` option with keys like `sp_address`, `sp_latitude`, `sp_longitude`
- **Conditional**: `apply_filters('sportspress_has_venues', true)`

### `sp_position` — Player Positions
- **Object types**: `sp_player`, `sp_list`
- **REST base**: `positions`
- **Slug option**: `sportspress_position_slug` (default: `position`)
- **Filter**: `sportspress_position_object_types`
- **Term meta**: `sp_sections` — which performance sections apply, `sp_order` — sort order
- **Conditional**: `apply_filters('sportspress_has_positions', true)`

### `sp_role` — Staff Roles/Jobs
- **Object types**: `sp_staff`
- **REST base**: `roles`
- **Slug option**: `sportspress_role_slug` (default: `role`)
- **Filter**: `sportspress_role_object_types`
- **Conditional**: `apply_filters('sportspress_has_roles', true)`

### `sp_duty` — Official Duties (via officials module)
- **Object types**: `sp_official`
- **Term meta**: `sp_order` — sort order

### Taxonomy Hooks
- `sportspress_after_register_taxonomy` — fires after all taxonomies registered
- Terms are sorted by `sp_order` term meta via `sp_sort_terms()`

---

## Data Storage Patterns

### Event Results (`sp_results` post meta)
Serialized associative array keyed by team ID:
```php
array(
    123 => array(          // team_id
        'goals' => '2',    // result slug => value
        'outcome' => array('win'),  // outcome slugs
    ),
    456 => array(
        'goals' => '1',
        'outcome' => array('loss'),
    ),
)
```

### Event Performance / Box Score (`sp_players` post meta)
Serialized nested array keyed by team ID, then player ID:
```php
array(
    123 => array(          // team_id
        789 => array(      // player_id
            'goals' => '1',
            'assists' => '0',
            'status' => 'lineup',  // 'lineup' or 'sub'
            'sub' => 0,           // player_id being substituted for (if status=sub)
            'number' => '10',
            'position' => 5,      // sp_position term_id
        ),
    ),
)
```

### Event Timeline (`sp_timeline` post meta)
Tracks minute-by-minute events:
```php
array(
    123 => array(          // team_id
        789 => array(      // player_id
            'goals' => array('23', '67'),  // minutes
            'yellowcards' => array('45'),
            'sub' => array('70'),          // substitution minute
        ),
    ),
)
```

### Player Statistics (`sp_statistics` post meta)
Manual stat overrides per league/season:
```php
array(
    10 => array(           // league term_id
        20 => array(       // season term_id
            'goals' => '5',
            'assists' => '3',
        ),
    ),
)
```

### Player League Assignments (`sp_leagues` post meta)
Maps which team a player was on per league/season:
```php
array(
    10 => array(           // league term_id
        20 => 123,         // season term_id => team_id
        21 => 456,         // different team in different season
    ),
)
```

### Team Column Data (`sp_columns` on sp_team)
Manual column overrides per league/season:
```php
array(
    10 => array(           // league term_id
        20 => array(       // season term_id
            'p' => '10',   // column slug => value
            'w' => '7',
        ),
    ),
)
```

### League Table Data (`sp_teams` on sp_table)
Manual team stat overrides:
```php
array(
    123 => array(          // team_id
        'p' => '10',       // column slug => value
    ),
)
```

### League Table Adjustments (`sp_adjustments` on sp_table)
Point adjustments (e.g., deductions):
```php
array(
    123 => array(          // team_id
        'pts' => '-3',     // column slug => adjustment
    ),
)
```

### Player Metrics (`sp_metrics` on sp_player)
```php
array(
    'height' => '180',     // metric slug => value
    'weight' => '75',
)
```

### Event Specs (`sp_specs` on sp_event)
```php
array(
    'attendance' => '50000',  // spec slug => value
)
```

---

## Statistics Calculation

### How Player Stats Are Calculated (`SP_Player::data()`)

The `SP_Player::data($league_id, $admin, $section)` method calculates all statistics for a player in a given league.

**Process:**
1. Get all seasons the player is assigned to
2. Get manual stat overrides from `sp_statistics` meta
3. Get league/season team assignments from `sp_leagues` meta
4. For each season:
   a. Query all `sp_event` posts where `sp_player` meta = player ID, filtered by league/season
   b. Only include events with `sp_format` in `apply_filters('sportspress_competitive_event_formats', array('league'))`
   c. For each event, accumulate:
      - **`eventsattended`** — incremented for every event with an outcome
      - **`eventsplayed`** — incremented if player status != 'sub' OR player has a sub value (was subbed on)
      - **`eventsstarted`** — incremented if status = 'lineup'
      - **`eventssubbed`** — incremented if status = 'sub' AND has sub value
      - **`eventminutes`** — calculated from event minutes, adjusted for substitution/sendoff times
      - **Performance totals** — each `sp_performance` slug accumulated: `$totals[$key] += floatval($value)`
      - **Outcome counts** — each `sp_outcome` slug incremented (win, loss, draw)
      - **Result for/against** — `{result_slug}for` and `{result_slug}against` accumulated
      - **Streak** — consecutive same-outcome count from most recent events
      - **Last 5/10** — outcome counts for last 5 and 10 events
5. Solve equations from `sp_statistic` posts using `sp_solve()`
6. Merge calculated placeholders with manual overrides (manual takes precedence when non-empty)
7. Calculate career totals across all seasons

**Key filter**: `sportspress_player_performance_add_value` — filters the value added for each performance key
**Key filter**: `sportspress_player_data_event_args` — filters the WP_Query args for event lookup

### Built-in Calculated Variables
These are available in equations:
- `$eventsplayed`, `$eventsattended`, `$eventsstarted`, `$eventssubbed`
- `$eventminutes`
- `${outcome_slug}` — e.g., `$win`, `$loss`, `$draw`
- `${result_slug}for`, `${result_slug}against` — e.g., `$goalsfor`, `$goalsagainst`
- `${performance_slug}` — e.g., `$goals`, `$assists`
- `$streak`, `$last5`, `$last10`
- Player metrics are also available in the equation context

### Equation System (`sp_solve()`)
Uses the `eqEOS` library (`includes/libraries/class-eqeos.php`) to solve mathematical equations.

```php
sp_solve( $equation, $vars, $precision, $default = 0, $post_id = 0 )
```

- Variables in equations are prefixed with `$` (e.g., `$goals / $eventsplayed`)
- Special preset equations: `$gamesback`, `$streak`, `$form`, `$last5`, `$last10`, `$homerecord`, `$awayrecord`
- Division by zero returns 0
- Filter: `sportspress_equation_solve_for_presets` — add custom preset equations
- Filter: `sportspress_equation_presets` — list of preset equation names

---

## League Tables

### How Standings Are Calculated (`SP_League_Table::data()`)

**Process:**
1. Get league/season terms from the table post
2. Get teams (auto from taxonomy or manual from `sp_team` meta)
3. Initialize totals per team with:
   - `eventsplayed`, `eventsplayed_home`, `eventsplayed_away`, `eventsplayed_venue`
   - `eventminutes` (and home/away/venue variants)
   - `{result_slug}for`, `{result_slug}against` (and home/away/venue variants)
   - `{outcome_slug}` counts (and home/away/venue variants)
   - `streak`, `form`, `last5`, `last10`, `homerecord`, `awayrecord`
4. Query all `sp_event` posts in the league/season with `sp_format` in competitive formats
5. For each event, for each team:
   - Parse results and outcomes
   - Track home (index 0) vs away (index > 0) based on position in results array
   - Track venue stats using `sp_is_home_venue()`
   - Accumulate result for/against values
   - Build streak, form, last5, last10, home/away records
6. Get `sp_column` posts (table column definitions) with their equations and priorities
7. Solve each column equation using accumulated totals
8. Apply adjustments from `sp_adjustments` meta
9. Sort teams using priority-based sorting:
   - Each `sp_column` can have a `sp_priority` (1 = highest) and `sp_order` (ASC/DESC)
   - Teams are compared on highest priority column first, then next, etc.
   - Ties fall through to next priority; final tiebreaker is alphabetical
10. Optional head-to-head tiebreaker (`sportspress_table_tiebreaker` option = `h2h`)
11. Calculate positions (tied teams can share position based on `sportspress_table_increment` option)
12. Calculate games back if any column uses `$gamesback` equation

**Key filter**: `sportspress_table_data_event_args` — filter event query args
**Key filter**: `sportspress_competitive_event_formats` — which event formats count (default: `array('league')`)

---

## Event Results

### How Results Work

Results are defined by `sp_result` config posts. Each result type has a slug (e.g., `goals`, `points`) used as keys in the `sp_results` meta.

**Primary Result**: Set via `sportspress_primary_result` option. This is the main result used for:
- Determining outcomes (win/loss/draw)
- Display in event lists and blocks
- `SP_Event::main_results()` returns the primary result value per team

**Outcome Assignment** (`SP_Event::update_main_results()`):
1. Compare primary result values between teams
2. If all equal → assign outcomes with `sp_condition = '='`
3. If different:
   - Team with highest value → outcomes with `sp_condition = '>'`
   - Team with lowest value → outcomes with `sp_condition = '<'`
   - Others → outcomes with `sp_condition = 'else'`

**Event Status Detection** (`SP_Event::status()`):
- Returns `'results'` if any team has non-empty result data
- Otherwise returns the post status (publish/future)

**Winner Detection** (`SP_Event::winner()`):
- Gets the first `sp_outcome` post (by menu_order)
- Finds which team has that outcome assigned
- Returns team ID or null

---

## Template System

### Template Loading (`sp_get_template()` and `sp_locate_template()`)

SportsPress uses a WooCommerce-style template override system.

**Load order for `sp_locate_template($template_name)`:**
1. `yourtheme/sportspress/$template_name`
2. `yourtheme/$template_name`
3. `sportspress/templates/$template_name` (plugin default)

**Template path** is filterable: `apply_filters('SP_TEMPLATE_PATH', 'sportspress/')`

**Key functions:**
```php
// Load a template with variables
sp_get_template( 'event-results.php', array( 'id' => $id ) );

// Get a template part (slug-name.php pattern)
sp_get_template_part( 'event', 'results' );

// Locate a template (returns path)
sp_locate_template( 'event-results.php' );
```

**Template hooks:**
- `sportspress_before_template` — fires before any template is included
- `sportspress_after_template` — fires after any template is included
- `sportspress_get_template_part` — filter the template part path
- `sportspress_locate_template` — filter the located template path

### Single Post Template System (`SP_Template_Loader`)

For single SP post types, the template loader intercepts `the_content` filter and builds a structured layout:

1. Gets the template order from `sportspress_{type}_template_order` option
2. Gets available templates from `SP()->templates->{type}` (defined in `SP_Templates`)
3. Each template section has:
   - `title` — display name
   - `option` — WordPress option controlling visibility (e.g., `sportspress_event_show_results`)
   - `action` — callback function to render the section
   - `default` — default visibility ('yes'/'no')
4. Sections before `tabs` key render as stacked blocks
5. Sections after `tabs` key render as tabbed interface

**Template definitions per post type** (from `SP_Templates`):

**Event templates:**
- `logos` → `sportspress_output_event_logos` (option: `sportspress_event_show_logos`)
- `excerpt` → `sportspress_output_post_excerpt`
- `content` → article body
- `video` → `sportspress_output_event_video`
- `details` → `sportspress_output_event_details`
- `venue` → `sportspress_output_event_venue`
- `results` → `sportspress_output_event_results`
- `performance` → `sportspress_output_event_performance`

**Player templates:**
- `selector` → `sportspress_output_player_selector`
- `photo` → `sportspress_output_player_photo`
- `details` → `sportspress_output_player_details`
- `excerpt` → `sportspress_output_post_excerpt`
- `content` → profile body
- `statistics` → `sportspress_output_player_statistics`

**Team templates:**
- `logo` → `sportspress_output_team_logo`
- `excerpt` → `sportspress_output_post_excerpt`
- `content` → profile body
- `link` → `sportspress_output_team_link`
- `details` → `sportspress_output_team_details`
- `staff` → `sportspress_output_team_staff`

**Staff templates:**
- `selector`, `photo`, `details`, `excerpt`, `content`

**Adding custom template sections:**
```php
// Add before the main content
add_filter( 'sportspress_before_event_template', function( $templates ) {
    $templates['my_section'] = array(
        'title'   => 'My Section',
        'option'  => 'sportspress_event_show_my_section',
        'action'  => 'my_custom_callback',
        'default' => 'yes',
    );
    return $templates;
});

// Add after the main content
add_filter( 'sportspress_after_event_template', function( $templates ) { ... });
```

Available filter pairs: `sportspress_before_{type}_template` / `sportspress_after_{type}_template` for: `event`, `calendar`, `team`, `table`, `player`, `list`, `staff`.

### Template Files (in `templates/` directory)
- `event-results.php`, `event-performance.php`, `event-details.php`, `event-venue.php`, `event-logos.php`, `event-video.php`, `event-overview.php`
- `event-list.php`, `event-calendar.php`, `event-blocks.php`, `event-fixtures-results.php`
- `event-officials.php`, `event-officials-list.php`, `event-officials-table.php`
- `event-performance-table.php`, `event-logos-block.php`, `event-logos-inline.php`
- `league-table.php`
- `player-details.php`, `player-statistics.php`, `player-statistics-league.php`, `player-photo.php`, `player-selector.php`, `player-events.php`
- `player-list.php`, `player-gallery.php`, `player-gallery-thumbnail.php`
- `team-details.php`, `team-logo.php`, `team-link.php`, `team-events.php`, `team-tables.php`, `team-lists.php`, `team-staff.php`
- `team-gallery.php`, `team-gallery-thumbnail.php`
- `staff-details.php`, `staff-photo.php`, `staff-selector.php`, `staff-header.php`, `staff-content.php`, `staff-excerpt.php`
- `countdown.php`, `birthdays.php`, `venue-map.php`, `post-excerpt.php`
- `official-details.php`

---

## Hooks Reference

### Lifecycle Actions
| Hook | When |
|------|------|
| `before_sportspress_init` | Before SP initializes on `init` |
| `sportspress_init` | After SP initializes (modules loaded) |
| `sportspress_loaded` | After all SP files included |
| `sportspress_updated` | After version upgrade |
| `sportspress_register_post_type` | Before post types registered |
| `sportspress_after_register_post_type` | After post types registered |
| `sportspress_after_register_taxonomy` | After taxonomies registered |

### Template Actions
| Hook | When |
|------|------|
| `sportspress_before_template` | Before any template file included |
| `sportspress_after_template` | After any template file included |
| `sportspress_before_single_{type}` | Before single post type content (event, player, team, etc.) |
| `sportspress_after_single_{type}` | After single post type content |
| `sportspress_single_{type}_content` | Inside the content section |

### REST API Actions
| Hook | When |
|------|------|
| `sportspress_register_rest_fields` | After all REST fields registered (add custom fields here) |

### Key Filters — Post Type Registration
| Filter | Purpose |
|--------|---------|
| `sportspress_register_post_type_{type}` | Filter post type args (event, team, player, staff, result, outcome, column, metric, performance, statistic) |
| `sportspress_register_taxonomy_{tax}` | Filter taxonomy args (league, season, venue, position, role) |
| `sportspress_has_leagues` | Enable/disable leagues taxonomy (return false to disable) |
| `sportspress_has_seasons` | Enable/disable seasons taxonomy |
| `sportspress_has_venues` | Enable/disable venues taxonomy |
| `sportspress_has_positions` | Enable/disable positions taxonomy |
| `sportspress_has_roles` | Enable/disable roles taxonomy |
| `sportspress_league_object_types` | Which post types get the league taxonomy |
| `sportspress_season_object_types` | Which post types get the season taxonomy |
| `sportspress_position_object_types` | Which post types get the position taxonomy |
| `sportspress_role_object_types` | Which post types get the role taxonomy |

### Key Filters — Data & Calculation
| Filter | Parameters | Purpose |
|--------|-----------|---------|
| `sportspress_competitive_event_formats` | `array('league')` | Which event formats count for standings/stats |
| `sportspress_player_performance_add_value` | `$value, $key` | Modify value added to player performance totals |
| `sportspress_player_data_event_args` | `$args, $data, $div_id, $selected_team` | Filter event query for player stats |
| `sportspress_player_data_season_ids` | `$div_ids, $league_stats` | Filter which seasons to include |
| `sportspress_player_performance_table_placeholder` | `$value, $key` | Filter individual stat placeholder |
| `sportspress_player_performance_table_placeholders` | `$career` | Filter career total placeholders |
| `sportspress_table_data_event_args` | `$args` | Filter event query for league tables |
| `sportspress_team_data_event_args` | `$args` | Filter event query for team data |
| `sportspress_event_performance_labels` | `$labels, $event` | Filter performance column labels |
| `sportspress_get_event_performance` | `$performance` | Filter final event performance data |
| `sportspress_equation_solve_for_presets` | `$solution, $equation, $post_id` | Add custom equation presets |
| `sportspress_equation_presets` | `array(...)` | List of preset equation names |

### Key Filters — Display & Templates
| Filter | Purpose |
|--------|---------|
| `sportspress_{type}_templates` | Filter available templates for a post type |
| `sportspress_before_{type}_template` | Add sections before content for a post type |
| `sportspress_after_{type}_template` | Add sections after content for a post type |
| `sportspress_{type}_content_priority` | Filter content priority (default 10) |
| `sportspress_shortcode_wrapper` | Filter shortcode wrapper HTML |
| `sportspress_event_performance_icons` | `$icon, $post_id, $count` — filter performance icons |
| `sportspress_performance_sections` | Filter available sections (offense/defense/all) |
| `sportspress_performance_formats` | Filter available performance formats |
| `sportspress_locate_template` | Filter located template path |
| `sportspress_get_template_part` | Filter template part path |

### Key Filters — REST API
| Filter | Purpose |
|--------|---------|
| `sportspress_rest_meta_keys` | Map REST field names to meta keys |

### Key Filters — Admin
| Filter | Purpose |
|--------|---------|
| `sportspress_modules` | Add/modify available modules |
| `sportspress_post_types` | Filter capability types for role assignment |
| `sportspress_statuses` | Filter available event statuses |
| `sportspress_dates` | Filter available date filter options |
| `sportspress_docs_url` | Filter documentation URL |
| `sportspress_pro_url` | Filter upgrade URL |

---

## Shortcodes

Registered in `SP_Shortcodes::init()`. All shortcodes are wrapped in `<div class="sportspress">`.

| Shortcode | Handler Class | Key Attributes |
|-----------|--------------|----------------|
| `[event_results]` | `SP_Shortcode_Event_Results` | `id` |
| `[event_details]` | `SP_Shortcode_Event_Details` | `id` |
| `[event_performance]` | `SP_Shortcode_Event_Performance` | `id` |
| `[event_venue]` | `SP_Shortcode_Event_Venue` | `id` |
| `[event_officials]` | `SP_Shortcode_Event_Officials` | `id` |
| `[event_teams]` | `SP_Shortcode_Event_Teams` | `id` |
| `[event_full]` | `SP_Shortcode_Event_Full` | `id` |
| `[countdown]` | `SP_Shortcode_Countdown` | `id`, `team` |
| `[event_calendar]` | `SP_Shortcode_Event_Calendar` | `id`, `team`, `league`, `season`, `status`, `date`, `number` |
| `[event_list]` | `SP_Shortcode_Event_List` | `id`, `team`, `league`, `season`, `status`, `date`, `number`, `order` |
| `[event_blocks]` | `SP_Shortcode_Event_Blocks` | `id`, `team`, `league`, `season`, `status`, `date`, `number` |
| `[league_table]` | `SP_Shortcode_League_Table` | `id`, `columns`, `show_full_table_link` |
| `[team_standings]` | (alias for `league_table`) | same as above |
| `[team_gallery]` | `SP_Shortcode_Team_Gallery` | `id`, `columns`, `league`, `season` |
| `[player_details]` | `SP_Shortcode_Player_Details` | `id` |
| `[player_statistics]` | `SP_Shortcode_Player_Statistics` | `id`, `league` |
| `[player_list]` | `SP_Shortcode_Player_List` | `id`, `columns`, `number`, `orderby`, `order` |
| `[player_gallery]` | `SP_Shortcode_Player_Gallery` | `id`, `columns`, `number` |
| `[staff]` | `SP_Shortcode_Staff` | `id` |
| `[staff_profile]` | `SP_Shortcode_Staff_Profile` | `id` |

Shortcode classes are autoloaded from `includes/shortcodes/`.

---

## Widgets

Registered via the `sportspress-widgets` module. All extend `WP_Widget`.

| Widget Class | Purpose |
|-------------|---------|
| `SP_Widget_Countdown` | Next event countdown timer |
| `SP_Widget_Event_List` | List of upcoming/recent events |
| `SP_Widget_Event_Blocks` | Event blocks with logos and scores |
| `SP_Widget_Event_Calendar` | Monthly calendar view |
| `SP_Widget_League_Table` | League standings table |
| `SP_Widget_Player_List` | Player ranking/roster list |
| `SP_Widget_Player_Gallery` | Player photo gallery |
| `SP_Widget_Team_Gallery` | Team logo gallery |
| `SP_Widget_Birthdays` | Upcoming player birthdays |
| `SP_Widget_Staff` | Staff member display |

Widget classes are in `includes/widgets/`.

---

## Admin & Settings

### Admin Class Hierarchy
- `SP_Admin` — main admin class, includes all admin sub-classes
- `SP_Admin_Menus` — registers admin menu pages
- `SP_Admin_Settings` — settings page framework (WooCommerce-style)
- `SP_Admin_Post_Types` — customizes admin columns and filters for SP post types
- `SP_Admin_Taxonomies` — customizes taxonomy admin screens
- `SP_Admin_Meta_Boxes` — registers all meta boxes for SP post types
- `SP_Admin_Assets` — enqueues admin CSS/JS
- `SP_Admin_Setup_Wizard` — first-run setup wizard

### Settings Pages (under SportsPress menu)
Settings are organized into tabs, each a class extending `SP_Settings_Page`:

| Class | Tab | Key Options |
|-------|-----|-------------|
| `SP_Settings_General` | General | `sportspress_sport`, `sportspress_mode` (team/player), `sportspress_frontend_styles` |
| `SP_Settings_Events` | Events | `sportspress_event_slug`, `sportspress_event_minutes`, `sportspress_primary_result`, `sportspress_event_performance_columns`, `sportspress_event_performance_sections` |
| `SP_Settings_Teams` | Teams | `sportspress_team_slug`, `sportspress_link_teams`, `sportspress_team_column_editing` |
| `SP_Settings_Players` | Players | `sportspress_player_slug`, `sportspress_player_columns` (auto/manual), `sportspress_player_show_total` |
| `SP_Settings_Staff` | Staff | `sportspress_staff_slug` |
| `SP_Settings_Modules` | Modules | Enable/disable individual modules |
| `SP_Settings_Licenses` | Licenses | Extension license keys |
| `SP_Settings_Text` | Text | Custom text overrides for any translatable string |

### Key Options
| Option | Default | Purpose |
|--------|---------|---------|
| `sportspress_sport` | null | Selected sport preset |
| `sportspress_mode` | `team` | `team` or `player` mode |
| `sportspress_primary_result` | null | Slug of the primary result type |
| `sportspress_event_minutes` | `90` | Default event duration |
| `sportspress_player_columns` | `auto` | `auto` (visibility-based) or `manual` (per-player selection) |
| `sportspress_table_tiebreaker` | `none` | `none` or `h2h` (head-to-head) |
| `sportspress_table_increment` | `no` | Whether tied teams get sequential positions |
| `sportspress_form_limit` | `5` | Number of recent form results to show |
| `sportspress_event_performance_columns` | `auto` | `auto` or `manual` column selection |
| `sportspress_event_result_columns` | `auto` | `auto` or `manual` |
| `sportspress_link_events` | `yes` | Link events in form display |
| `sportspress_link_teams` | `no` | Link team names in player stats |
| `sportspress_{type}_template_order` | array | Ordered array of template section keys |

### Meta Boxes
Meta box classes are autoloaded from `includes/admin/post-types/meta-boxes/`. Named `SP_Meta_Box_{PostType}_{Section}`. Examples:
- `SP_Meta_Box_Event_Details`, `SP_Meta_Box_Event_Teams`, `SP_Meta_Box_Event_Results`, `SP_Meta_Box_Event_Performance`
- `SP_Meta_Box_Player_Details`, `SP_Meta_Box_Player_Statistics`, `SP_Meta_Box_Player_Metrics`
- `SP_Meta_Box_Team_Details`, `SP_Meta_Box_Team_Columns`
- `SP_Meta_Box_Table_Data`, `SP_Meta_Box_Table_Details`
- `SP_Meta_Box_Column_Equation`, `SP_Meta_Box_Performance_Equation`, `SP_Meta_Box_Statistic_Equation`

---

## REST API

SportsPress extends the WordPress REST API using custom controllers and `register_rest_field()`.

### Endpoints
All public post types are exposed via REST with custom controllers:
- `SP_REST_Posts_Controller` extends `WP_REST_Posts_Controller` — used for `sp_event`, `sp_team`, `sp_player`, `sp_staff`
- `SP_REST_Terms_Controller` extends `WP_REST_Terms_Controller` — used for all taxonomies

| Endpoint | REST Base |
|----------|-----------|
| `/wp/v2/events` | Events |
| `/wp/v2/teams` | Teams |
| `/wp/v2/players` | Players |
| `/wp/v2/staff` | Staff |
| `/wp/v2/leagues` | Leagues taxonomy |
| `/wp/v2/seasons` | Seasons taxonomy |
| `/wp/v2/venues` | Venues taxonomy |
| `/wp/v2/positions` | Positions taxonomy |
| `/wp/v2/roles` | Roles taxonomy |

### Custom REST Fields

**On `sp_event`:**
- `teams` (array, read/write) — team IDs
- `main_results` (array, read-only) — primary result per team
- `outcome` (array, read-only) — outcome per team
- `winner` (integer, read-only) — winning team ID
- `format`, `mode`, `day`, `minutes` (read/write)
- `players` (array, read/write) — performance data
- `offense`, `defense`, `staff` (arrays, read/write)
- `results` (object, read/write) — full results data
- `performance` (object, read/write) — full box score data

**On `sp_team`:**
- `staff`, `tables`, `lists` (arrays, read/write)
- `events` (array, read-only) — event IDs involving this team
- `abbreviation`, `url` (strings, read/write)

**On `sp_player`:**
- `number` (integer, read/write)
- `teams`, `current_teams`, `past_teams` (arrays, read/write)
- `nationalities` (array, read/write)
- `metrics` (object, read/write)
- `statistics` (object, read/write) — full statistics data

**On `sp_staff`:**
- `teams`, `current_teams`, `past_teams`, `nationalities`

### Meta Key Mapping
The `SP_REST_API::meta_key()` method maps REST field names to actual meta keys:
```php
'current_teams' => 'sp_current_team'
'past_teams'    => 'sp_past_team'
'performance'   => 'sp_players'
'players'       => 'sp_player'
'teams'         => 'sp_team'
'tables'        => 'sp_table'
'lists'         => 'sp_list'
'nationalities' => 'sp_nationality'
'events'        => 'sp_event'
```

Filter: `sportspress_rest_meta_keys` — add custom meta key mappings.

---

## Extension Points

### Building a SportsPress Add-on

**1. Module Pattern (built-in modules)**

Modules are PHP files in the `modules/` directory, auto-included on load. They check an option to enable/disable:

```php
// modules/sportspress-my-module.php
if ( get_option( 'sportspress_load_my_module_module', 'yes' ) !== 'yes' ) return;

// Register the module in the modules list
add_filter( 'sportspress_modules', function( $modules ) {
    $modules['other']['my_module'] = array(
        'label' => 'My Module',
        'icon'  => 'dashicons dashicons-star-filled',
        'desc'  => 'Description of my module.',
    );
    return $modules;
});

// Your module code here...
```

**2. External Plugin Pattern**

For standalone plugins that extend SportsPress:

```php
// Wait for SportsPress to load
add_action( 'sportspress_init', 'my_sp_extension_init' );

function my_sp_extension_init() {
    // SportsPress is loaded, SP() is available
    // Add your hooks, filters, post types, etc.
}
```

**3. Adding Custom Template Sections**

```php
add_filter( 'sportspress_after_player_template', function( $templates ) {
    $templates['my_section'] = array(
        'title'   => 'My Custom Section',
        'option'  => 'sportspress_player_show_my_section',
        'action'  => 'my_render_function',
        'default' => 'yes',
    );
    return $templates;
});

function my_render_function() {
    sp_get_template( 'my-custom-template.php' );
}
```

**4. Overriding Templates**

Copy any template from `sportspress/templates/` to `yourtheme/sportspress/` and modify it.

**5. Adding Custom Statistics**

Create `sp_statistic` or `sp_performance` posts programmatically:
```php
wp_insert_post( array(
    'post_type'   => 'sp_statistic',
    'post_title'  => 'Goals Per Game',
    'post_name'   => 'goalsper',
    'post_status' => 'publish',
    'menu_order'  => 10,
    'meta_input'  => array(
        'sp_equation'  => '$goals / $eventsplayed',
        'sp_precision' => 2,
        'sp_type'      => 'average',
        'sp_visible'   => 1,
    ),
));
```

**6. Adding Custom Equation Presets**

```php
add_filter( 'sportspress_equation_solve_for_presets', function( $solution, $equation, $post_id ) {
    if ( '$mycustom' === $equation ) {
        // Return your custom calculated value
        return $my_value;
    }
    return $solution;
}, 10, 3 );

add_filter( 'sportspress_equation_presets', function( $presets ) {
    $presets[] = '$mycustom';
    return $presets;
});
```

**7. Modifying Player/Team Data Queries**

```php
// Add custom meta to player stat calculations
add_filter( 'sportspress_player_data_event_args', function( $args, $data, $div_id, $team ) {
    // Modify WP_Query args for event lookup
    return $args;
}, 10, 4 );

// Modify table event query
add_filter( 'sportspress_table_data_event_args', function( $args ) {
    return $args;
});
```

**8. Adding REST API Fields**

```php
add_action( 'sportspress_register_rest_fields', function() {
    register_rest_field( 'sp_player', 'my_custom_field', array(
        'get_callback' => function( $object ) {
            return get_post_meta( $object['id'], 'my_custom_meta', true );
        },
        'schema' => array(
            'description' => 'My custom field',
            'type'        => 'string',
            'context'     => array( 'view', 'edit' ),
        ),
    ));
});
```

**9. Adding Custom Event Formats**

```php
// Make custom format count for standings
add_filter( 'sportspress_competitive_event_formats', function( $formats ) {
    $formats[] = 'cup';
    return $formats;
});
```

**10. Working with SP Data Classes**

```php
// Get event data
$event = new SP_Event( $event_id );
$results = $event->results();       // Returns array with labels at index 0
$performance = $event->performance(); // Returns box score with labels at index 0
$main = $event->main_results();      // Returns array of primary result values
$winner = $event->winner();          // Returns winning team ID
$timeline = $event->timeline();      // Returns timeline data
$status = $event->status();          // 'results' or post_status

// Get player data
$player = new SP_Player( $player_id );
$data = $player->data( $league_id );  // Returns stats for a league
$stats = $player->statistics();        // Returns stats for all leagues
$metrics = $player->metrics();         // Returns physical metrics
$teams = $player->current_teams();     // Returns current team IDs
$past = $player->past_teams();         // Returns past team IDs

// Get team data
$team = new SP_Team( $team_id );
$columns = $team->columns( $league_id ); // Returns array( $labels, $data, $placeholders )
$staff = $team->staff();                  // Returns staff members
$tables = $team->tables();               // Returns league tables
$lists = $team->lists();                  // Returns player lists
$next = $team->next_event();             // Returns next scheduled event

// Get league table data
$table = new SP_League_Table( $table_id );
$data = $table->data();  // Returns sorted standings with labels at index 0

// Get staff data
$staff = new SP_Staff( $staff_id );
$role = $staff->role();           // Returns primary role term
$teams = $staff->current_teams(); // Returns current team IDs
```

### Sport Presets
SportsPress ships with sport-specific presets in the `presets/` directory. These define default result types, outcomes, columns, performance variables, and metrics for each sport. Sport-specific extensions (e.g., `sportspress-for-soccer`) are recommended via TGMPA.

### Image Sizes
- `sportspress-crop-medium` — 300×300, cropped
- `sportspress-fit-medium` — 300×300, proportional
- `sportspress-fit-icon` — 128×128, proportional
- `sportspress-fit-mini` — 32×32, proportional
