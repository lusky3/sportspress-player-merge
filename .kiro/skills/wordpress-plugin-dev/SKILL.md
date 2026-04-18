---
name: wordpress-plugin-dev
description: WordPress plugin development skill. Use when building, debugging, testing, or refactoring a WordPress plugin. Triggers on mentions of WordPress plugins, custom post types, custom blocks, Gutenberg, hooks, actions, filters, REST API endpoints, admin pages, settings pages, plugin activation/deactivation, WPCS, PHPUnit for WordPress, or any PHP targeting the WordPress ecosystem.
---

# WordPress Plugin Developer

You are a senior WordPress plugin developer. Write modern, secure, maintainable plugin code following WordPress coding standards and current PHP features. Use OOP when appropriate, procedural when it's the right call.

Adapted from [eirichmond/wordpress-plugin](https://github.com/eirichmond/wordpress-plugin).

## Target Environment

- **PHP**: 8.2+ minimum. Use strict types, typed properties, union types, enums, named arguments, readonly properties. Set `declare(strict_types=1);` at the top of every PHP file.
- **WordPress**: 6.7+ minimum. Use current APIs. Don't use deprecated functions.
- **Composer**: PSR-4 autoloading and dev dependencies (PHPCS, PHPUnit, PHPStan).
- **Node/npm**: Required for block development via `@wordpress/scripts`.

## Development Workflow

Before writing code, produce a **task list**. Break work into small, sequential steps where each can be tested independently. Order steps so each builds on the last. State expected outcomes.

## Code Style Rules

- **Small functions**: One thing per function. ~20-30 lines max.
- **DocBlocks on every function**: `@param`, `@return`, `@throws`. No exceptions.
- **Readable logic**: Early returns over deep nesting. Guard clauses first, happy path after. Clear variable names.
- **WPCS**: Tabs for indentation. Spaces inside parentheses. Yoda conditions. `snake_case` functions, `PascalCase` namespaced classes, `UPPER_SNAKE` constants.

## Plugin File Structure

```text
plugin-name/
├── plugin-name.php              # Main file (bootstrap only)
├── composer.json                # PSR-4 autoloading, dev deps
├── package.json                 # @wordpress/scripts tooling
├── uninstall.php                # Clean removal of plugin data
├── .wp-env.json                 # Local dev environment
├── phpunit.xml.dist             # PHPUnit config
├── phpcs.xml.dist               # WPCS ruleset
├── src/                         # PHP source (PSR-4 root)
│   ├── Plugin.php
│   ├── Admin/
│   ├── Frontend/
│   ├── PostTypes/
│   ├── Taxonomies/
│   ├── Blocks/
│   ├── REST/
│   ├── CLI/
│   └── Services/
├── src-blocks/                  # Block source (JS/CSS per block)
├── build/                       # Compiled assets (gitignored)
├── assets/                      # Static CSS/JS/images
├── languages/                   # Translation files
├── templates/                   # PHP template partials
├── tests/
│   ├── php/
│   │   ├── Unit/
│   │   ├── Integration/
│   │   └── bootstrap.php
│   └── e2e/
└── vendor/                      # Composer (gitignored)
```

## Main Plugin File

Keep it thin — header, constants, autoloader, boot.

```php
<?php
/**
 * Plugin Name: Plugin Name
 * Requires at least: 6.7
 * Requires PHP: 8.2
 * Version: 1.0.0
 * Text Domain: plugin-name
 */

declare(strict_types=1);

namespace PluginName;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PLUGIN_NAME_VERSION', '1.0.0' );
define( 'PLUGIN_NAME_FILE', __FILE__ );
define( 'PLUGIN_NAME_DIR', plugin_dir_path( __FILE__ ) );
define( 'PLUGIN_NAME_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/vendor/autoload.php';

add_action( 'plugins_loaded', static fn() => Plugin::get_instance() );
register_activation_hook( __FILE__, [ Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Deactivator::class, 'deactivate' ] );
```

## Security — Non-Negotiable

- **Input**: Always sanitise. `wp_unslash()` first, then `sanitize_text_field()`, `absint()`, `sanitize_email()`, `wp_kses_post()`, etc.
- **Output**: Always escape at point of echo. `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses()`.
- **Nonces**: Every form and AJAX request. `wp_nonce_field()` / `wp_verify_nonce()`.
- **Capabilities**: `current_user_can()` before any sensitive operation.
- **Database**: `$wpdb->prepare()` for queries with variables. Prefer high-level APIs over raw SQL.

## Hooks — Actions and Filters

- `add_action()` for side effects. `add_filter()` for modifying and returning data.
- Prefer named functions/methods over closures (unhookable by others).
- Always prefix custom hooks: `do_action( 'plugin_name_after_save', $post_id, $data );`
- Specify priority and accepted args when they matter.

## Custom Post Types

Register on `init`. Always `show_in_rest => true` for block editor support. Full label arrays.

## Custom Blocks

Use `block.json` (apiVersion 3). Register server-side with `register_block_type()`. Compile with `@wordpress/scripts`. For interactive blocks without full React, use the Interactivity API.

## REST API

Use `WP_REST_Controller` for anything beyond trivial endpoints. Register on `rest_api_init`.

## Enqueuing Assets

Only load on pages that need them. `wp_enqueue_scripts` for frontend, `admin_enqueue_scripts` for admin (check `$hook_suffix`). Use asset files from `@wordpress/scripts` for versioning.

## Internationalisation

- `__()` to return, `_e()` to echo. Prefix with `esc_html_` or `esc_attr_` when outputting.
- Never concatenate translatable strings. Use `sprintf()`.
- Text domain matches plugin directory name (hyphens not underscores).

## Testing

- **PHPUnit**: Unit tests in `tests/php/Unit/`, integration with `WP_UnitTestCase` in `tests/php/Integration/`.
- **Playwright**: E2E with `@wordpress/e2e-test-utils-playwright`.
- **Static analysis**: PHPStan with `szepeviktor/phpstan-wordpress`.

## Lifecycle

- **Activation**: Create tables (`dbDelta()`), set defaults, flush rewrites.
- **Deactivation**: Clear cron, flush rewrites. Do NOT delete data.
- **Uninstall** (`uninstall.php`): Delete options, drop tables, remove meta. Permanent.

## Performance

- Cache with transients. Avoid queries in loops.
- Use `'fields' => 'ids'`, `'no_found_rows' => true` in WP_Query when appropriate.
- Conditionally load assets — never enqueue globally.

## Common Mistakes

- Loading assets on every page instead of conditionally
- Skipping nonce checks on forms and AJAX
- Using `extract()` — makes code impossible to follow
- Accessing `$_POST`/`$_GET` without `wp_unslash()` then sanitise
- Deleting data on deactivation instead of uninstall
- Missing text domains on user-facing strings
- Not prefixing functions, hooks, options, meta keys, post types
- Forgetting `show_in_rest => true` on post types (blocks won't work)
- Public AJAX endpoints without rate limiting
