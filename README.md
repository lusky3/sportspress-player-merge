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
│   ├── class-sp-merge-github-updater.php # Auto-update from GitHub releases
│   ├── class-sp-merge-name-matcher.php   # Fuzzy matching engine (14 scenarios)
│   ├── class-sp-merge-preview.php        # Merge preview generation
│   └── class-sp-merge-processor.php      # Core merge logic
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
