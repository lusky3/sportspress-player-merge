# Plan: `wp sp-merge` WP-CLI command

## Context

SportsPress Player Merge currently exposes merge/revert/backup operations only
through `admin-ajax.php` actions gated by a nonce tied to a live wp-admin
session. This plan adds a WP-CLI command (`wp sp-merge ...`) that reuses the
existing domain classes (`SP_Merge_Processor`, `SP_Merge_Backup`,
`SP_Merge_Preview`, `SP_Merge_Name_Matcher`) directly rather than through
`SP_Merge_Ajax`, which is HTTP-request-shaped ($_POST, nonces).

Full behavioral spec (agreed with the user in conversation, reproduce verbatim
for implementers — do not re-derive):

- No REST API exists and none is being added. This is WP-CLI only.
- `SportsPress_Player_Merge_Init::init()` currently returns early unless
  `is_admin() || wp_doing_ajax()`. Neither is true under WP-CLI, so the whole
  plugin is inert there today — this must change (Task 1).
- Auth model: WP-CLI's own global `--user=<id|login>` flag sets the acting
  identity (framework-level, not something we implement). Capability checks
  (`current_user_can()`) are preserved exactly as the AJAX layer enforces them
  — never bypassed for CLI. There is no nonce under CLI (no cross-request
  replay risk to defend against — everything happens in one process).
- A *separate* concept, our own `--user=<id|login>` assoc-arg on
  `revert`, `backups list`, and `backups delete` only, means "whose backup to
  target" (backups are stored with an owning `user_id`). Defaults to the
  acting user. Targeting anyone else requires `delete_sp_players` — the same
  capability tier the plugin already reserves for backup deletion. This is a
  capability-gated widening of existing behavior, not a new tier.
- `merge` has no such flag — a merge is always attributed to the acting user,
  exactly like the AJAX path today.
- `--force` and `--yes` are orthogonal, never conflated:
  - `--yes` (WP-CLI global flag) skips the interactive confirmation prompt.
  - `--force` overrides one specific safety gate (`merge`'s survivor warning,
    or `revert`'s "values changed since merge" guard) — it does **not** imply
    `--yes`. `--force` alone still prompts interactively, and the prompt text
    must name what is being overridden. `--force --yes` together is the
    correct unattended/batch invocation.
- `wp sp-merge preview` prints the *structured data* the HTML preview is built
  from (team/taxonomy/event comparisons, collision count, array-field
  fill/conflict resolutions), not rendered HTML, and is not capped at 25
  resolutions the way the on-screen preview is (that cap exists for screen
  space, which doesn't apply to a terminal/log).

## Global constraints (apply to every task)

- PHP 8.2+, matches the existing `declare` style (no `declare(strict_types=1)`
  is used anywhere in this codebase — do not introduce it).
- Match existing code style exactly: tabs for indentation, WordPress
  Coding Standards docblocks (`@param`, `@return`, `@throws`), `if (
  ! defined( 'ABSPATH' ) ) { exit; }` guard at the top of every new class
  file, `SP_Merge_` class name prefix, one class per file named
  `class-sp-merge-<slug>.php` in `classes/`.
- Every new/changed method gets a docblock in the same voice as the existing
  ones (explains *why*, not *what* — see any existing method for the
  house style; e.g. `class-sp-merge-backup.php`'s `find_conflicting_backups()`
  docblock is a good model).
- No behavior change to any existing AJAX-facing behavior. Every existing
  test in `tests/` must still pass unmodified after each task
  (`bash tests/run-all.sh`) unless a task explicitly says to touch a test
  file.
- New tests follow the existing house convention exactly: no PHPUnit, no
  Composer — plain PHP scripts using `sp_assert()` / `sp_assert_same()` /
  `sp_assert_contains()` from `tests/lib-backup-mocks.php`, run via
  `php tests/test-*.php` or `bash tests/run-all.sh`. Read
  `tests/lib-backup-mocks.php` and `tests/lib-ajax-mocks.php` in full before
  writing new tests or a new mock lib — do not invent a different mocking
  style.
- Do not add a REST API, do not add `wp_ajax_nopriv_*` hooks, do not add
  authentication mechanisms beyond what's specified above.
- CI runs PHPStan level 5 against `classes/`, `includes/`,
  `sportspress-player-merge.php`, `uninstall.php` (see `phpstan.neon`) using
  only `php-stubs/wordpress-stubs` (see `.github/workflows/security.yml`).
  `WP_CLI` is not a known class to that stub set today — Task 6 fixes this.
  Until Task 6 lands, new code referencing `\WP_CLI` will fail CI PHPStan;
  this is expected and resolved by Task 6, not by avoiding the reference.

## Task 1: Bootstrap gate + shared validation/warning extraction

**Files:** `sportspress-player-merge.php`, new
`classes/class-sp-merge-validation.php`, `classes/class-sp-merge-ajax.php`,
`tests/lib-ajax-mocks.php`, new `tests/test-shared-validation.php`.

**Do:**

1. In `sportspress-player-merge.php`, change the early-return gate in
   `SportsPress_Player_Merge_Init::init()` from
   `if ( ! is_admin() && ! wp_doing_ajax() ) { return; }` to also allow
   WP-CLI: `if ( ! is_admin() && ! wp_doing_ajax() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) { return; }`.
   Do **not** add `class-sp-merge-cli.php` to `$class_files` or call
   `WP_CLI::add_command()` here — that wiring belongs to Task 3, once the
   class exists. This task only makes the *existing* classes reachable under
   WP-CLI; nothing about CLI commands yet.

2. Create `classes/class-sp-merge-validation.php`, class
   `SP_Merge_Validation`, with these public static methods (extracted
   verbatim in behavior from `SP_Merge_Ajax`, so both the AJAX layer and the
   future CLI layer share one implementation):

   - `validate_merge_selection( $primary_id_raw, array $duplicate_ids_raw ): array`
     Replicates the exact validation currently inline in
     `SP_Merge_Ajax::validate_merge_input()` (read that method first), in
     this exact order, returning
     `array{valid: bool, primary_id?: int, duplicate_ids?: int[], error?: string}`:
     a. `$primary_id = absint( $primary_id_raw )`.
     b. `$duplicate_ids = array_values( array_filter( array_unique( array_map( 'absint', $duplicate_ids_raw ) ) ) )`.
     c. If `! $primary_id || empty( $duplicate_ids )` →
        `{valid: false, error: 'Invalid player selection'}`.
     d. If `count( $duplicate_ids ) > 10` →
        `{valid: false, error: 'Maximum 10 duplicate players per merge operation.'}`.
     e. If `in_array( $primary_id, $duplicate_ids, true )` →
        `{valid: false, error: 'Primary player cannot also be a duplicate'}`.
     f. Primary post must exist, be `sp_player`, be `publish` →
        `{valid: false, error: 'Primary player not found or not published'}`.
     g. Every duplicate post must exist, be `sp_player`, be `publish` →
        `{valid: false, error: 'One or more duplicate players not found or not published'}`.
     h. Else `{valid: true, primary_id: $primary_id, duplicate_ids: $duplicate_ids}`.
     All error strings must be translatable exactly as today
     (`__( '...', 'sportspress-player-merge' )`) — copy the exact existing
     strings so translators are not asked to re-translate.
     The `$_POST`-specific "is this even an array" check
     (`'Invalid input format'`) stays in `SP_Merge_Ajax::validate_merge_input()`
     since it's about `$_POST` shape, not selection validity — do not move it.

   - `get_event_counts( array $player_ids ): array` — move verbatim from
     `SP_Merge_Ajax::get_event_counts()` (identical body, identical
     `$wpdb` query).

   - `survivor_warnings( int $primary_id, array $duplicate_ids ): array` —
     move verbatim from `SP_Merge_Ajax::survivor_warnings()`, calling
     `self::get_event_counts()` instead of `$this->get_event_counts()`.

3. Update `classes/class-sp-merge-ajax.php`:
   - `validate_merge_input()` keeps its current signature and still reads
     `$_POST`, still calls `$this->send_error()` and returns `false` on any
     failure (external behavior identical) — but its body becomes: extract
     `$primary_id` and `$raw_duplicates` from `$_POST` exactly as today,
     keep the `is_array( $raw_duplicates )` format check as-is, then call
     `SP_Merge_Validation::validate_merge_selection( $primary_id, $raw_duplicates )`
     and translate its result into the existing `send_error()` / return
     shape.
   - Delete the private `get_event_counts()` and `survivor_warnings()`
     methods; replace their call sites (`find_duplicates()` and
     `preview_merge()`/wherever `survivor_warnings()` is called) with
     `SP_Merge_Validation::get_event_counts( ... )` and
     `SP_Merge_Validation::survivor_warnings( ... )`.

4. Update `sportspress-player-merge.php`'s `$class_files` array: insert
   `'class-sp-merge-validation.php'` **before** `'class-sp-merge-ajax.php'`
   (load order matters — Ajax now depends on it).

5. Update `tests/lib-ajax-mocks.php`: it currently ends with
   `require_once $sp_ajax_class_file;` — add
   `require_once dirname( __DIR__ ) . '/classes/class-sp-merge-validation.php';`
   immediately before that line (Ajax now needs the class loaded first).

6. Write `tests/test-shared-validation.php` (require
   `tests/lib-ajax-mocks.php`, which already sets up `get_posts`,
   `current_user_can`, a seeded roster, etc.) covering, at minimum:
   `validate_merge_selection`: valid selection; empty duplicates; primary
   also in duplicates; >10 duplicates; primary not found/unpublished;
   a duplicate not found/unpublished; duplicate ID list with repeats/string
   IDs normalizes the same as `absint`+`array_unique` would. `get_event_counts`
   and `survivor_warnings`: at least one case each proving they still work
   identically when called as `SP_Merge_Validation::` static methods (reuse
   any existing seeded-roster/event-count fixtures the mocks lib already
   provides; do not invent a new roster-seeding mechanism if one exists).

**Acceptance:** `bash tests/run-all.sh` passes (every existing test plus the
new one). No change to any AJAX response shape or wording.

## Task 2: Backup cross-user support

**Depends on:** nothing (independent of Task 1).

**Files:** `classes/class-sp-merge-backup.php`,
`classes/class-sp-merge-admin.php`, new `tests/test-backup-cross-user.php`.

**Do:**

1. In `classes/class-sp-merge-backup.php`:
   - `public function revert( string $backup_id, bool $force = false, ?int $owner_user_id = null ): array`
     — add the third parameter. Every internal use of `get_current_user_id()`
     *for scoping which backup row to load* becomes
     `$owner_user_id ?? get_current_user_id()`. This is the only change to
     `revert()`'s behavior; existing callers (`SP_Merge_Ajax::revert_merge()`)
     pass no third argument and see byte-identical behavior.
   - `public function delete_backups( array $backup_ids, ?int $owner_user_id = null ): int`
     — same pattern: the `WHERE ... user_id = %d` scoping uses
     `$owner_user_id ?? get_current_user_id()`.
   - `load_backup_row()` and `get_backup_status()` (both private, called by
     `revert()`/`mark_active()`/`mark_failed()`/`delete_backups()`) need an
     optional user-id parameter threaded through from the two public methods
     above; `mark_active()`/`mark_failed()`/`create_merge_backup()` keep
     calling them with no override (always the current actor — a backup is
     always created and promoted/failed by whoever ran the merge, that part
     never changes).
   - Do **not** add an "all users" mode to `SP_Merge_Backup` itself — revert
     and delete always target one specific backup owned by one specific,
     explicitly-named user. There is no scenario where either operates
     across "all users" at once.

2. In `classes/class-sp-merge-admin.php`, extend
   `get_recent_backups( int $limit = self::MAX_LISTED_BACKUPS, ?int $user_id = null, bool $all_users = false ): array|false`:
   - Existing call sites (`render_admin_page()`, `SP_Merge_Ajax::get_recent_backups()`)
     pass no new arguments and must see identical behavior (their own
     backups only, current limit default).
   - `$all_users === true` drops the `WHERE user_id = %d` clause from the
     existing query entirely (keep every other clause — status coalesce,
     ordering, limit — unchanged).
   - `$all_users === false && $user_id !== null` scopes to that user instead
     of `get_current_user_id()`.
   - Do not duplicate the schema-column-detection logic
     (`SHOW COLUMNS FROM ...`) — it must still run exactly once per call, as
     today, just with the WHERE clause parameterized as described.

3. Write `tests/test-backup-cross-user.php` using
   `tests/lib-backup-mocks.php` (it already has `sp_test_seed_backup()` with
   a `$user_id` param — use it to seed backups under at least two different
   user IDs). Cover:
   - `revert()`/`delete_backups()` with no `$owner_user_id` behave exactly as
     before (scoped to `$GLOBALS['sp_current_user']`, i.e. `get_current_user_id()`'s
     mock return).
   - `revert()`/`delete_backups()` with an explicit `$owner_user_id` reach a
     backup owned by a *different* user than the mocked "current" one, and
     fail to reach one that doesn't exist under that owner.
   - `get_recent_backups()` (via `SP_Merge_Admin`) with `$all_users = true`
     returns rows across every seeded owner; with a specific `$user_id`
     returns only that owner's rows; with neither, only the mocked current
     user's rows (must add `SP_Merge_Admin` to the required class list in the
     test if `lib-backup-mocks.php` doesn't already load it — check first).

**Acceptance:** `bash tests/run-all.sh` passes. No AJAX-facing behavior
changes (grep the diff for any change to `class-sp-merge-ajax.php` — there
should be none from this task).

## Task 3: `SP_Merge_CLI` skeleton + `scan`/`preview` + structured preview data

**Depends on:** Task 1 (uses `SP_Merge_Validation::survivor_warnings()` is
NOT needed here — that's Task 4 — but this task's `preview` reuses
`SP_Merge_Preview`, unaffected by Task 1/2).

**Files:** new `classes/class-sp-merge-cli.php`,
`classes/class-sp-merge-preview.php`, `sportspress-player-merge.php`, new
`tests/lib-cli-mocks.php`, new `tests/test-cli-scan.php`,
new `tests/test-cli-preview.php`.

**Do:**

1. In `classes/class-sp-merge-preview.php`, refactor without changing any
   HTML output byte-for-byte (existing test
   `tests/test-preview-array-conflicts.php` must still pass unmodified):
   - Extract the event-count query currently inline in
     `render_event_count_row()` into a private helper (e.g.
     `get_event_count_for_player( int $player_id ): int`), called from both
     the existing renderer and the new method below.
   - Extract the same-event collision *count* logic currently inline at the
     top of `render_collision_warning()` into a private helper (e.g.
     `count_collision_events( int $primary_id, array $duplicate_ids ): int`),
     reused the same way.
   - Add `public function generate_data( int $primary_id, array $duplicate_ids ): array`
     returning (no HTML, no escaping — this is for a terminal, not a
     browser):
     ```
     array{
       primary: array{id:int, name:string},
       duplicates: array<array{id:int, name:string}>,
       current_team: array{primary:?string, duplicates:string[], result:string[]},
       past_teams: array{primary:string[], duplicates:string[], result:string[]},
       taxonomies: array<string, array{label:string, primary:string[], duplicates:string[], result:string[]}>,
       events: array{primary:int, duplicates:int, result:int},
       collision_count: int,
       array_field_filled: array,    // SP_Merge_Processor resolution shape, 'filled' action only
       array_field_conflicts: array, // same shape, 'conflict' action only
     }
     ```
     Build this by calling the same private getters `generate()` already
     uses (`get_player_details`, `get_current_team`, `get_past_teams`,
     `get_taxonomy_terms`, the two new helpers above) plus the same
     `SP_Merge_Processor::preview_array_field_merge()` replay loop
     `render_array_field_warning()` already does — extract that replay loop
     into a private helper (e.g. `compute_array_field_resolutions( int $primary_id, array $duplicate_ids ): array{filled: array, conflicts: array}`)
     used by both the HTML renderer and `generate_data()`. Do **not** cap
     the returned resolutions at `MAX_LISTED_RESOLUTIONS` — that constant is
     screen-space-specific to the HTML table and must not affect
     `generate_data()`'s return.

2. Create `classes/class-sp-merge-cli.php`:
   - Class docblock: `Registers the wp sp-merge WP-CLI command family.` Then
     one line per subcommand this task implements, one line each for the
     three implemented by Task 4/5 marked "(implemented in a later change)"
     — no, actually: just document what exists; do not reference future
     tasks in shipped code comments.
   - `public function scan( $args, $assoc_args ): void` implementing
     `wp sp-merge scan [--min-certainty=<0-100>] [--scenario=<name>] [--limit=<n>] [--format=<table|csv|json|yaml>]`:
     call `( new SP_Merge_Ajax() )->collect_scan_players()` (public method,
     safe to call outside an HTTP request — it only touches `get_posts()`/
     `wp_count_posts()`) to get `players`, then
     `SP_Merge_Name_Matcher::find_groups( $players )`. Default
     `--limit=50` (matches the AJAX cap), default `--min-certainty=0`.
     Filter groups by `--scenario` (exact string match against the group's
     `scenario` key) and `--min-certainty` before applying `--limit`. Flatten
     to one row per group member for output (`group_certainty`, `scenario`,
     `player_id`, `name`, `member_certainty`, `events` — event counts via
     `SP_Merge_Validation::get_event_counts()`... — **note:** if Task 1 has
     not landed yet in your worktree when you start, use
     `( new SP_Merge_Ajax() )` and call its (still-private-at-that-point)
     event count logic is NOT available to you; in that case inline a
     minimal duplicate of the same query rather than reaching into a private
     method, and leave a one-line TODO-free note is not needed — just inline
     it. (In practice Task 1 will have landed first since tasks run in
     order; this fallback is a safety note only.) Render via
     `\WP_CLI\Utils\format_items( $format, $rows, array( 'group_certainty', 'scenario', 'player_id', 'name', 'member_certainty', 'events' ) )`
     where `$format = $assoc_args['format'] ?? 'table'`. After the table,
     `\WP_CLI::log()` a one-line summary using the scan's `scanned`/`total`/
     `truncated` fields (e.g. "Scanned 2121 of 2121 players." or, when
     truncated, a `\WP_CLI::warning()` naming how many were skipped).
   - `public function preview( $args, $assoc_args ): void` implementing
     `wp sp-merge preview <primary-id> <duplicate-id>... [--format=<table|json|yaml>] [--porcelain]`:
     positional args are `$args[0]` (primary) and `array_slice( $args, 1 )`
     (duplicates) — `absint()` each. Call
     `( new SP_Merge_Preview() )->generate_data( $primary_id, $duplicate_ids )`.
     `--porcelain`: print only `OK` (no conflicts, no collisions) or `WARN`
     (either present) and exit — nothing else. Otherwise render the
     structured data as a human-readable multi-section report (team/taxonomy/
     event comparison, collision count if >0, then the filled/conflict
     resolution lists in full) or as `--format=json|yaml` via
     `\WP_CLI\Utils\format_items()`/`json_encode()` as appropriate for
     nested data (use `\WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT ) )`
     for `--format=json` since the payload is nested, not tabular —
     `format_items()` is for flat row tables only, which `scan` needs and
     `preview` mostly does not). Always exit 0 (informational only).
   - Do **not** add `merge`, `revert`, `backups`, or `batch` methods in this
     task — those are Tasks 4 and 5.
   - Do **not** call `WP_CLI::add_command()` from inside this file.

3. Wire it up in `sportspress-player-merge.php`: immediately after the
   `$class_files` foreach loop (still inside `init()`), add:
   ```php
   if ( defined( 'WP_CLI' ) && WP_CLI ) {
       require_once SP_MERGE_PLUGIN_PATH . 'classes/class-sp-merge-cli.php';
       WP_CLI::add_command( 'sp-merge', 'SP_Merge_CLI' );
   }
   ```
   placed after `if ( class_exists( 'SP_Merge_Controller' ) ) { ... }` so the
   rest of the plugin is fully initialized first.

4. Create `tests/lib-cli-mocks.php` modeled directly on
   `tests/lib-ajax-mocks.php` (read it first): `require_once` both
   `lib-backup-mocks.php` and the pieces of `lib-ajax-mocks.php` you need
   (or require `lib-ajax-mocks.php` itself if that doesn't double-load
   anything — check for `require_once` guards / duplicate function
   declarations before deciding; if `lib-ajax-mocks.php` isn't safely
   re-requirable standalone for this purpose, factor what `scan`/`preview`
   need out explicitly rather than duplicating mock functions). Add:
   - A `WP_CLI` class stub with static `log`, `error`, `warning`, `success`,
     `confirm`, `add_command` methods. `error()` must behave like a real
     WP-CLI fatal for test purposes: throw a dedicated exception
     (e.g. `SP_Test_CLI_Error extends Exception`, carrying the message) so
     tests can assert a command halted, mirroring how
     `tests/lib-ajax-mocks.php`'s `SP_Test_Json_Response` lets tests assert
     on `wp_send_json_*` outcomes. `log`/`warning`/`success` append to a
     `$GLOBALS['spm_cli_log']` array (`array{level:string, message:string}`)
     so tests can assert on output without capturing real stdout.
     `confirm( $question, $assoc_args = array() )`: return
     `isset( $assoc_args['yes'] )`, else throw `SP_Test_CLI_Error` (a test
     that hits an un-mocked interactive prompt should fail loudly, not hang
     or silently return false).
   - A `WP_CLI\Utils\format_items( $format, $items, $fields )` stub: for
     `'json'` return/log `wp_json_encode( $items )`; for anything else
     (`'table'`, `'csv'`, `'yaml'`) it's enough for the test harness to
     append the raw `$items` array to `$GLOBALS['spm_cli_log']` tagged with
     the format — tests assert on the underlying data, not on ASCII table
     rendering.
   - `get_user_by( $field, $value )`: return a stub object
     `(object) array( 'ID' => ... )` from a `$GLOBALS['spm_cli_users']`
     lookup table tests can seed (needed by Task 4, add it now since it's
     small and belongs with the rest of the CLI mocks).
   - `require_once` the real `classes/class-sp-merge-name-matcher.php`,
     `classes/class-sp-merge-preview.php`, `classes/class-sp-merge-processor.php`,
     and `classes/class-sp-merge-cli.php` at the end (in dependency order —
     `SP_Merge_Preview` needs `SP_Merge_Processor` to exist first, same as
     production load order).

5. `tests/test-cli-scan.php` and `tests/test-cli-preview.php`: seed rosters/
   meta the same way existing tests do (reuse `sp_test_seed_roster()` /
   `sp_test_add_meta()` from the libs you just required), call
   `( new SP_Merge_CLI() )->scan( array(), array() )` /
   `->preview( array( (string) $primary_id, (string) $dup_id ), array() )`
   directly (no real `wp` binary involved), and assert against
   `$GLOBALS['spm_cli_log']`. Cover at minimum: scan respects
   `--min-certainty` and `--limit`; scan's summary line reflects a truncated
   roster; preview's `--porcelain` prints `OK` for a clean pair and `WARN`
   when a same-event collision or an array-field conflict exists; preview's
   non-porcelain output includes the resolved cell paths from a seeded
   `sp_statistics` conflict (mirror the fixture in
   `tests/test-preview-array-conflicts.php` rather than inventing a new one).

**Acceptance:** `bash tests/run-all.sh` passes including the new files.
`tests/test-preview-array-conflicts.php` output is byte-identical to before
this task (the HTML refactor must not change it).

## Task 4: `merge`, `revert`, `backups list`, `backups delete`

**Depends on:** Tasks 1, 2, 3 (all must be complete and merged into this
worktree's history before starting).

**Files:** `classes/class-sp-merge-cli.php` (adds to the file Task 3
created — do not recreate it), new `tests/test-cli-merge.php`, new
`tests/test-cli-revert.php`, new `tests/test-cli-backups.php`.

**Do:**

Add a private helper first, used by every subcommand below:

```php
private function resolve_target_user( ?string $user_arg ): int {
    if ( null === $user_arg || '' === $user_arg ) {
        return get_current_user_id();
    }
    $user = is_numeric( $user_arg ) ? get_user_by( 'id', (int) $user_arg ) : get_user_by( 'login', $user_arg );
    if ( ! $user ) {
        \WP_CLI::error( sprintf( 'No user found matching "%s".', $user_arg ) );
    }
    $target_id = (int) $user->ID;
    if ( $target_id !== get_current_user_id() && ! current_user_can( 'delete_sp_players' ) ) {
        \WP_CLI::error( 'Only an Administrator (delete_sp_players) can act on another user\'s backups.' );
    }
    return $target_id;
}
```

(Adjust to house style/docblock conventions; the logic above is the exact
contract — do not change the capability name or the "defaults to acting user,
delete_sp_players required to target anyone else" rule.)

1. `public function merge( $args, $assoc_args ): void` —
   `wp sp-merge merge <primary-id> <duplicate-id>... [--skip-preview] [--force] [--yes]`:
   a. Capability: `current_user_can( 'manage_sportspress' ) || current_user_can( 'delete_sp_players' )`
      else `\WP_CLI::error( 'Insufficient permissions.' )`.
   b. `$primary_raw = $args[0] ?? null; $duplicate_raw = array_slice( $args, 1 );`
      — if no primary or no duplicates, `\WP_CLI::error( 'Usage: wp sp-merge merge <primary-id> <duplicate-id>...' )`.
   c. `$result = SP_Merge_Validation::validate_merge_selection( $primary_raw, $duplicate_raw );`
      — if `! $result['valid']`, `\WP_CLI::error( $result['error'] )`.
   d. Unless `--skip-preview`: call and print
      `( new SP_Merge_Preview() )->generate_data( $result['primary_id'], $result['duplicate_ids'] )`
      the same way `preview()` does (reuse a private
      `render_preview_data( array $data ): void` helper shared by both
      commands — do not duplicate the rendering).
   e. `$warnings = SP_Merge_Validation::survivor_warnings( $result['primary_id'], $result['duplicate_ids'] );`
      — if non-empty and `! isset( $assoc_args['force'] )`: print every
      warning via `\WP_CLI::warning()`, then
      `\WP_CLI::error( 'Merge refused: survivor warning(s) above. Re-run with --force to override.' )`.
   f. Build the confirmation question: base text
      `"Merge {n} player(s) into #{primary_id}? This permanently deletes the duplicate posts."`;
      if warnings existed and were overridden by `--force`, prepend
      `"Survivor warning overridden — see above. "` to the question.
      Call `\WP_CLI::confirm( $question, $assoc_args )` (the stub already
      keys off `$assoc_args['yes']`, matching real WP-CLI's own
      `--yes`-skips-confirm behavior — do not reimplement that check
      yourself).
   g. `$merge_result = ( new SP_Merge_Processor() )->execute_merge( $result['primary_id'], $result['duplicate_ids'] );`
   h. On success: `\WP_CLI::success( ... )` naming the `backup_id`, then log
      every resolution (`filled`/`conflict`) from
      `$merge_result['resolutions']` using
      `SP_Merge_Processor::format_resolution_path()` /
      `::format_resolution_value()` (both already public static — reuse them,
      do not reformat differently).
   i. On failure: `\WP_CLI::error( $merge_result['message'] )` (this message
      already names the retained backup ID when one exists, per the
      processor's existing behavior — do not add your own wording).

2. `public function revert( $args, $assoc_args ): void` —
   `wp sp-merge revert <backup-id> [--user=<id|login>] [--force] [--yes]`:
   a. Capability: same check as `merge` step (a).
   b. `$backup_id = $args[0] ?? null;` — missing → `\WP_CLI::error(...)`.
   c. `$owner_id = $this->resolve_target_user( $assoc_args['user'] ?? null );`
   d. `$result = ( new SP_Merge_Backup() )->revert( $backup_id, isset( $assoc_args['force'] ), $owner_id );`
      — **first call is always unforced-confirmation-gated the same way
      regardless of the `--force` flag's boolean value**: i.e. do not skip
      confirmation just because `$result['success']` might come back true;
      the sequence is: call `\WP_CLI::confirm()` BEFORE calling `revert()`
      only when `isset( $assoc_args['force'] )` is true (an unforced revert
      that hits `values_changed` needs no upfront confirmation — the
      "confirmation" IS the operator having to notice the refusal and add
      `--force` themselves in a second invocation, exactly like the web UI's
      two-step). Concretely:
      - Call `revert()` first with the actual `--force` value.
      - If it fails with `code === 'values_changed'` and `--force` was not
        passed: print the message via `\WP_CLI::error()` (do not
        auto-retry).
      - If `--force` **was** passed: before calling `revert()`, print the
        pending refusal reason is not known yet without calling it once —
        so instead: call `revert()` once with `$force = false` first purely
        to discover whether a `values_changed` refusal *would* occur; if it
        would, print every changed item from the message, then
        `\WP_CLI::confirm( "Override and revert anyway? Everything listed above was written after the merge and will be permanently discarded.", $assoc_args )`,
        then call `revert()` again with the real `$force = true`. If the
        dry-run call fails with `code === 'conflict'` (later merge overlap),
        stop regardless of `--force` — this refusal has no override, per
        `SP_Merge_Backup::revert()`'s own contract; print the message and
        `\WP_CLI::error()`.
      - If the dry-run call (or the only call, when `--force` was never
        passed) succeeds outright, no confirmation is needed at all — a
        revert with nothing to override is not a "your data will be
        discarded" moment.
   e. On success: `\WP_CLI::success( 'Merge reverted.' )` (plus "using the
      override" suffix when `--force` was used, matching the AJAX message
      wording in `SP_Merge_Ajax::revert_merge()` — reuse the same two
      strings rather than writing new ones).

3. `public function backups( $args, $assoc_args ): void` dispatching on
   `$args[0]` (`'list'` or `'delete'`) — WP-CLI supports either one method
   per subcommand registered individually
   (`WP_CLI::add_command( 'sp-merge backups list', ... )`) or a single
   dispatcher; **use one method per subcommand** (`backups_list()` and
   `backups_delete()`), registered as separate commands in the wiring step
   below, for consistency with `merge`/`revert`/`scan`/`preview` each being
   their own method — do not build a manual sub-dispatcher.
   - `public function backups_list( $args, $assoc_args ): void` —
     `wp sp-merge backups list [--user=<id|login>] [--all-users] [--status=...] [--limit=<n>] [--format=<table|csv|json>]`:
     capability `edit_sp_players`, escalating to requiring
     `delete_sp_players` only when `--all-users` is passed (check this
     directly — do not route through `resolve_target_user()`, which is for
     "target one specific other user", a different check than "show
     everyone's"). Resolve the target user via `resolve_target_user()` only
     when `--all-users` is absent. Call
     `( new SP_Merge_Admin() )->get_recent_backups( $limit, $user_id, $all_users )`
     (the Task 2 signature). Filter by `--status` client-side if given (the
     method itself doesn't take a status filter — do not add one to
     `SP_Merge_Admin` for this; filter the returned array here). Render via
     `\WP_CLI\Utils\format_items()`.
   - `public function backups_delete( $args, $assoc_args ): void` —
     `wp sp-merge backups delete <backup-id>... [--user=<id|login>] [--yes]`:
     capability `delete_sp_players` unconditionally (matches the AJAX
     tier — there is no "delete your own backup" lower tier to preserve).
     `$owner_id = $this->resolve_target_user(...)` (will always pass its
     internal capability check here since we already required
     `delete_sp_players` above — that's fine, the helper is idempotent).
     Print a reminder — "Deleting a backup permanently removes the only
     recovery path for that merge." — then
     `\WP_CLI::confirm( ..., $assoc_args )`, then
     `( new SP_Merge_Backup() )->delete_backups( $args, $owner_id )`.

4. Update the wiring in `sportspress-player-merge.php` (from Task 3) to
   register the two backup subcommands explicitly, since WP-CLI needs each
   distinct command path registered:
   ```php
   WP_CLI::add_command( 'sp-merge', 'SP_Merge_CLI' );
   WP_CLI::add_command( 'sp-merge backups list', array( new SP_Merge_CLI(), 'backups_list' ) );
   WP_CLI::add_command( 'sp-merge backups delete', array( new SP_Merge_CLI(), 'backups_delete' ) );
   ```
   (Verify against real WP-CLI command-registration conventions for a class
   with mixed top-level and namespaced-subcommand methods — if
   `WP_CLI::add_command( 'sp-merge', 'SP_Merge_CLI' )` alone would already
   expose `backups_list`/`backups_delete` as `wp sp-merge backups-list` /
   `wp sp-merge backups-delete` by WP-CLI's own method-name-to-subcommand
   convention and that's close enough to the spec'd `backups list`/
   `backups delete` two-word form, prefer relying on that single
   registration over three separate calls — but the *dispatch prompt for
   this task must state clearly which approach was chosen and why* in the
   implementer's report, since this is a WP-CLI framework detail this
   plugin's codebase has no prior example of.)

5. Tests: `tests/test-cli-merge.php`, `tests/test-cli-revert.php`,
   `tests/test-cli-backups.php`, extending `tests/lib-cli-mocks.php` from
   Task 3 as needed (e.g. it will now need `SP_Merge_Backup` fully mocked
   with `$wpdb`-backed rows — reuse `tests/lib-backup-mocks.php`'s
   `SP_Test_WPDB` rather than building a second one). Cover at minimum:
   - `merge`: happy path produces a `backup_id` and prints resolutions;
     a survivor warning without `--force` halts (assert on the thrown
     `SP_Test_CLI_Error`); the same warning with `--force --yes` proceeds
     without hitting the mocked `confirm()`'s throw-when-uninitialized path.
   - `revert`: an unforced revert against a seeded "values changed" backup
     refuses without confirming; the same case with `--force --yes`
     succeeds; a `conflict`-coded refusal is never overridden even with
     `--force`.
   - `backups list`/`backups delete`: `--all-users` requires
     `delete_sp_players` (assert refusal for a mocked non-admin
     `current_user_can`); targeting another user's backup without
     `delete_sp_players` refuses via `resolve_target_user()`.

**Acceptance:** `bash tests/run-all.sh` passes including all new files.

## Task 5: `wp sp-merge batch`

**Depends on:** Task 4.

**Files:** `classes/class-sp-merge-cli.php` (adds to it), new
`tests/test-cli-batch.php`, new fixture file(s) under `tests/fixtures/`
(create the directory if it doesn't exist).

**Do:**

1. `public function batch( $args, $assoc_args ): void` —
   `wp sp-merge batch <file> [--format=<csv|json>] [--stop-on-error|--continue-on-error] [--dry-run] [--yes] --log=<path>`:
   - `--log` is required — if absent, `\WP_CLI::error( '--log is required.' )`
     (per spec: the admin UI only lists the 10 most recent backups per page,
     so an externally recorded log of every backup ID a batch run produces
     is the only way to track more than that).
   - Default mode is `--stop-on-error` (i.e. `--continue-on-error` is the
     opt-in flag that flips it) — matches the spec's stated default.
   - Input format: sniff from the file extension when `--format` is absent
     (`.csv` → csv, `.json` → json); error on an unrecognized extension with
     neither flag given.
   - CSV row shape: `primary_id,duplicate_ids` where `duplicate_ids` is
     `;`-joined (e.g. `101,205;309`). JSON shape: an array of
     `{"primary_id": 101, "duplicate_ids": [205, 309]}` objects. Parse into a
     uniform internal list of `array{primary_id:int, duplicate_ids:int[]}`
     before processing.
   - For each row, in file order: reuse the exact same
     `SP_Merge_Validation::validate_merge_selection()` →
     preview/`generate_data()` → `SP_Merge_Validation::survivor_warnings()`
     → confirm → `execute_merge()` sequence `merge()` already implements —
     **extract `merge()`'s body (after its own argument-parsing prologue)
     into a private `run_one_merge( int $primary_id, array $duplicate_ids, array $assoc_args ): array` returning `array{success:bool, backup_id:?string, message:?string}`, shared by both `merge()` and `batch()`** rather than duplicating that sequence.
     `--dry-run` mode calls only the preview/warning steps, never
     `execute_merge()`, and reports what *would* happen per row.
   - Append one line per processed row to the `--log` file immediately after
     that row finishes (not buffered to the end — a crash mid-batch must not
     lose already-completed rows' backup IDs): JSON Lines format,
     `{"primary_id":.., "duplicate_ids":[..], "success":.., "backup_id":.., "message":..}`.
   - `--stop-on-error` (default): halt the loop on the first row where
     `success` is false, after logging that row; print a final summary of
     rows completed vs. remaining.
   - `--continue-on-error`: keep going; final summary counts successes and
     failures.
   - The existing `sp_merge_lock` transient/object-cache lock inside
     `SP_Merge_Processor::execute_merge()` already serializes merges — do
     **not** add any additional locking in `batch()`; it is a plain
     sequential loop.

2. `tests/test-cli-batch.php`: create small CSV and JSON fixture strings
   in-test (via a temp file — `sys_get_temp_dir()` — not committed fixture
   files, to avoid path-portability issues in CI) covering: a clean batch of
   2+ rows all succeed, log file has one JSON line per row in order;
   `--stop-on-error` halts after the first induced failure (seed one row to
   fail — e.g. an unpublished duplicate) and does not process later rows;
   `--continue-on-error` processes all rows despite the same induced
   failure and reports both counts; `--dry-run` produces log entries but
   never calls the real merge path (assert via
   `$GLOBALS['spm_backup_log']` from `lib-backup-mocks.php` staying empty of
   `create_merge_backup` calls); missing `--log` errors immediately without
   touching any input row.

**Acceptance:** `bash tests/run-all.sh` passes including the new file.

## Task 6: PHPStan support for `\WP_CLI`

**Depends on:** nothing functionally, but do this last so the class file it
concerns (`class-sp-merge-cli.php`) already exists and this task doesn't
block earlier ones on CI infrastructure changes.

**Files:** `.github/workflows/security.yml`, `phpstan.neon`.

**Do:**

1. In `.github/workflows/security.yml`, extend the existing
   `composer global require phpstan/phpstan szepeviktor/phpstan-wordpress php-stubs/wordpress-stubs --no-interaction`
   line to also require `php-stubs/wp-cli-stubs`.
2. In `phpstan.neon`, add a `scanFiles` (or `bootstrapFiles`, whichever the
   installed stub package's own structure calls for — check what
   `php-stubs/wp-cli-stubs` actually ships, e.g. via
   `composer show php-stubs/wp-cli-stubs` if composer is available locally,
   or its Packagist page structure) entry pointing at the stub file(s) so
   `\WP_CLI`, `\WP_CLI\Utils\format_items`, and any other WP-CLI symbol used
   in `class-sp-merge-cli.php` resolves. Use a path expression consistent
   with how `%rootDir%/../../szepeviktor/phpstan-wordpress/extension.neon`
   is already resolved in this same file (same global-composer install
   location convention) rather than inventing a different path scheme.
3. If you have no way to actually run `phpstan` in this environment to
   verify the config resolves (no local composer/vendor tree), say so
   explicitly in your report rather than claiming it was verified — this is
   a CI-config change and its correctness will be confirmed by the next CI
   run, not by this task.

**Acceptance:** the config changes are internally consistent with the
existing file's conventions; `bash tests/run-all.sh` still passes (this task
touches no PHP application code).

## Final step (not a task — controller does this)

After Task 6's review is clean, dispatch the final whole-branch code
reviewer, then use `superpowers:finishing-a-development-branch`.
