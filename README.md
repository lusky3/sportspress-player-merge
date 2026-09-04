# SportsPress Player Merge

A WordPress plugin that detects and merges duplicate SportsPress players using intelligent fuzzy name matching, with full data preservation and revert capabilities.

[![Lint](https://github.com/lusky3/sportspress-player-merge/actions/workflows/lint.yml/badge.svg)](https://github.com/lusky3/sportspress-player-merge/actions/workflows/lint.yml)
[![Security](https://github.com/lusky3/sportspress-player-merge/actions/workflows/security.yml/badge.svg)](https://github.com/lusky3/sportspress-player-merge/actions/workflows/security.yml)
[![Compatibility](https://github.com/lusky3/sportspress-player-merge/actions/workflows/compat.yml/badge.svg)](https://github.com/lusky3/sportspress-player-merge/actions/workflows/compat.yml)

## Features

- **Fuzzy Duplicate Detection**: 14 matching scenarios including nicknames, prefix normalization (Mac/Mc/O'), typos, accents, French/English bilingual equivalents, and more
- **Tiered Confidence Scoring**: High (≥90%), Medium (≥70%), Low (<70%) with team/position adjustments
- **Smart Player Merging**: Preserves all data including complex serialized statistics structures
- **Featured Image Handling**: Copies thumbnail from duplicate to primary if primary has none
- **Email Integration**: Optionally uses `spt_email` from SportsPress Admin Tools for matching and display
- **Data Preview**: See exactly what will be merged before execution
- **Full Revert**: Complete undo that restores deleted players and all references (backups retained, not deleted)
- **Draggable UI Cards**: Reorder interface sections with localStorage persistence
- **Accessible**: ARIA live regions, screen reader captions, keyboard-navigable confirmation dialogs
- **Auto-Updates**: Built-in GitHub updater — updates appear in WordPress plugin dashboard

## Requirements

- **WordPress**: 6.0+
- **PHP**: 8.2+
- **SportsPress**: Required (any version)

## Installation

1. Download the latest release from [GitHub Releases](https://github.com/lusky3/sportspress-player-merge/releases)
2. Upload to `/wp-content/plugins/sportspress-player-merge/`
3. Activate through WordPress admin
4. Navigate to **SportsPress → Players → Player Merge**

Updates are delivered automatically via the built-in GitHub updater.

## Duplicate Detection

The scanner identifies potential duplicates using 14 matching scenarios:

| Scenario | Example | Certainty |
|----------|---------|-----------|
| Exact match | Mike Scott = mike scott | 100% |
| Accent/prefix normalization | O'Connor=OConnor, MacBeth=McBeth | 95% |
| French compound first names | Jean-Pierre ↔ Jean Pierre | 85% |
| Nicknames/diminutives | Richard=Rick=Dick, Michael=Mike | 70% |
| French/English bilingual | Marc=Mark, Denis=Dennis | 70% |
| Typos (Levenshtein) | Cooper=Coopper, Mathew=Matthew | 65% |
| Middle name difference | John Michael Smith ↔ John Smith | 60% |
| Suffix variations | James Porter Sr. ↔ James Porter | 100% |
| Initial match | J. Smith ↔ John Smith | 50% |
| Name reversal | Smith, John ↔ John Smith | 50% |

Scoring adjustments: +5% same team, -20% different positions, +20% matching email.

## Permissions

| Action | Capability | Minimum Role |
|--------|-----------|--------------|
| View merge tool / scan / preview | `edit_sp_players` | Editor |
| Execute merge / revert | `manage_sportspress` | League Manager |
| Delete backup | `delete_sp_players` | Administrator |

Editors can browse and preview potential merges. League Managers can execute and revert merges. Only Administrators can permanently delete backup data.

## WP-CLI

The same merge/revert/backup functionality is available headless via `wp sp-merge`, for scripting and bulk operations the admin screen isn't built for:

| Subcommand | Description |
|------------|-------------|
| `wp sp-merge scan` | Run the duplicate-name scan and print matched groups, filterable by certainty/scenario. |
| `wp sp-merge preview` | Show what a merge of an explicit player selection would do, without executing it. |
| `wp sp-merge merge` | Execute a merge of an explicit player selection. |
| `wp sp-merge revert` | Revert a previously executed merge from its backup ID. |
| `wp sp-merge backups list` | List recent merge backups. |
| `wp sp-merge backups delete` | Permanently delete one or more merge backups. |
| `wp sp-merge batch` | Run many merges from a CSV or JSON file in one pass, logging one record per row. Requires `--yes` — it is not interactive. |

Run `wp help sp-merge <subcommand>` for full flag documentation.

WP-CLI has no logged-in web session, so it relies entirely on its own global `--user=<id|login>` flag to set the *acting* identity — `current_user_can()` is checked exactly as it is for the AJAX-driven admin page. **Every** `wp sp-merge` subcommand requires `--user` to point at an account holding the relevant capability; without it, there is no logged-in user and the command refuses with "Insufficient permissions." The required capability varies by subcommand:

| Capability | Subcommands |
|-------------|-------------|
| `edit_sp_players` | `scan`, `preview`, `backups list` (your own backups) |
| `manage_sportspress` or `delete_sp_players` | `merge`, `revert`, `batch` |
| `delete_sp_players` | `backups delete`, and `--owner`/`--all-users` on any subcommand that accepts them |

For example: `wp sp-merge scan --user=admin`.

`revert`, `backups list`, and `backups delete` additionally accept the plugin's own `--owner=<id|login>` flag, which is a completely different thing: it targets *whose* backup to act on, defaulting to the acting user's own backups. These are deliberately two separate flags rather than one, because WP-CLI's runtime consumes its own global `--user` before any command code runs — a command can never read `--user` back out of its arguments — so the plugin cannot reuse that name for a second, unrelated meaning. Targeting anyone else's backups via `--owner` requires the acting user (set by `--user`) to hold `delete_sp_players`. For example, an Administrator reverting a League Manager's merge runs `wp sp-merge revert merge_123 --user=admin --owner=league_manager_login`.

## File Structure

```text
sportspress-player-merge/
├── .github/workflows/       # CI/CD (lint, security, compat, release, plugin-check)
├── assets/
│   ├── css/admin.css        # Admin styling
│   ├── js/admin.js          # AJAX interactions, Select2, drag-and-drop
│   └── vendor/select2/      # Bundled Select2 (no CDN dependency)
├── classes/
│   ├── class-sp-merge-admin.php          # Admin menu and asset enqueue
│   ├── class-sp-merge-ajax.php           # AJAX handlers
│   ├── class-sp-merge-backup.php         # Backup/restore system
│   ├── class-sp-merge-controller.php     # Component coordinator
│   ├── class-sp-merge-cli.php            # wp sp-merge scan/preview/merge/revert/batch
│   ├── class-sp-merge-cli-backups.php    # wp sp-merge backups list/delete
│   ├── class-sp-merge-github-updater.php # Auto-update from GitHub releases
│   ├── class-sp-merge-lock.php           # The one lock a merge or revert holds
│   ├── class-sp-merge-name-matcher.php   # Fuzzy matching engine (14 scenarios)
│   ├── class-sp-merge-preview.php        # Merge preview generation
│   ├── class-sp-merge-processor.php      # Core merge logic
│   └── class-sp-merge-validation.php     # Selection validation, event counts, certainty adjustments
├── includes/
│   └── admin-page.php       # Admin page template
├── languages/
│   └── sportspress-player-merge.pot  # Translation template
├── sportspress-player-merge.php  # Main plugin file
├── uninstall.php            # Clean removal
├── phpstan.neon             # Static analysis config
└── .oxlintrc.json           # JS linting config
```

## CI/CD

All PRs and pushes to main run:

- **Lint**: PHP syntax, oxlint (JS), Semgrep (security patterns), jscpd (copy-paste detection), accessibility checks
- **Security**: PHPStan level 5 with WordPress stubs
- **Compatibility**: PHP 8.2/8.3/8.4 × WordPress 6.0/latest matrix
- **Plugin Check**: WordPress Plugin Check (general, a11y, performance, security categories)
- **Release**: Version consistency validation on tag push + auto GitHub Release

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Ensure all CI checks pass
5. Submit a pull request

## License

GPL v2 or later — see license.txt

## AI Disclosure

This plugin was developed with AI assistance (Kiro/Claude). All code has undergone automated security, performance, and data integrity review via PHPStan, Semgrep, and WordPress Plugin Check.
