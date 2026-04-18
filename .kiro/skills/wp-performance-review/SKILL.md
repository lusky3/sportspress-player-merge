---
name: wp-performance-review
description: WordPress performance code review and optimization. Use when reviewing WordPress PHP/JS code for performance issues, auditing plugins for scalability, optimizing WP_Query, analyzing caching strategies, or when user mentions slow WordPress, slow queries, high-traffic, timeouts, 500 errors, out of memory, or performance review.
---

# WordPress Performance Review

Systematic performance code review for WordPress plugins and themes. Scan critical issues first (OOM, unbounded queries, cache bypass), then warnings, then optimizations. Report with line numbers and severity levels.

Adapted from [elvismdev/claude-wordpress-skills](https://github.com/elvismdev/claude-wordpress-skills).

## Severity Levels

| Severity | Description |
|----------|-------------|
| **Critical** | Will cause failures at scale (OOM, 500 errors, DB locks) |
| **Warning** | Degrades performance under load |
| **Info** | Optimization opportunity |

## Critical Anti-Patterns

### Database Queries

- `posts_per_page => -1` or `numberposts => -1` — CRITICAL: Unbounded query, causes OOM
- `query_posts()` — CRITICAL: Never use, breaks main query and pagination
- `LIKE '%term%'` (leading wildcard) — WARNING: Full table scan
- `post__not_in` with large arrays — WARNING: Slow exclusion, filter in PHP instead
- Missing `no_found_rows => true` when not paginating — INFO: Unnecessary COUNT query
- `meta_query` with `value` comparisons — WARNING: Unindexed column scan
- Database queries inside loops (N+1) — CRITICAL: Query multiplication

```php
// ❌ CRITICAL
'posts_per_page' => -1

// ✅ GOOD
'posts_per_page' => 100,
'no_found_rows'  => true,
```

### Hooks & Actions

- Expensive code on `init` without context check — WARNING: Runs every request
- `update_option`/`add_option` on frontend page loads — WARNING: DB writes on every request
- `session_start()` — CRITICAL: Bypasses ALL page cache

```php
// ❌ WARNING
add_action( 'init', 'expensive_function' );

// ✅ GOOD
add_action( 'init', function() {
    if ( is_admin() || wp_doing_cron() ) {
        return;
    }
    // Frontend-only code here.
} );
```

### Caching Issues

- `wp_remote_get`/`wp_remote_post` without caching — WARNING: Blocking HTTP on page load
- `url_to_postid()`, `attachment_url_to_postid()`, `count_user_posts()` uncached — WARNING
- Dynamic transient keys (`set_transient("user_{$id}_data")`) — WARNING: Table bloat without object cache
- Large data in transients on shared hosting — WARNING: DB bloat

```php
// ✅ Wrap expensive lookups with object cache
function prefix_cached_url_to_postid( $url ) {
    $cache_key = 'url_to_postid_' . md5( $url );
    $post_id   = wp_cache_get( $cache_key, 'url_lookups' );
    if ( false === $post_id ) {
        $post_id = url_to_postid( $url );
        wp_cache_set( $cache_key, $post_id, 'url_lookups', HOUR_IN_SECONDS );
    }
    return $post_id;
}
```

### AJAX & JavaScript

- `setInterval` + fetch/ajax — CRITICAL: Self-DDoS risk (polling pattern)
- POST method for read operations — WARNING: Bypasses cache
- `admin-ajax.php` for new endpoints — INFO: REST API is leaner
- `import _ from 'lodash'` — WARNING: Full library import bloats bundle

### Asset Loading

- `wp_enqueue_script`/`wp_enqueue_style` without conditional check — WARNING: Assets load globally
- Missing `defer`/`async` strategy — INFO: Blocks rendering
- Missing version constant — INFO: Cache busting issues

```php
// ❌ WARNING: Loads on every page
wp_enqueue_script( 'contact-form-js', ... );

// ✅ GOOD: Conditional enqueue
if ( is_page( 'contact' ) ) {
    wp_enqueue_script( 'contact-form-js', ... );
}
```

### WP-Cron

- Long-running cron callbacks (loops over all users/posts) — CRITICAL: Blocks cron queue
- `wp_schedule_event` without `wp_next_scheduled` check — WARNING: Duplicate schedules
- Missing `DISABLE_WP_CRON` — INFO: Cron runs on page requests

```php
// ✅ Batch processing with rescheduling
$users = get_users( array( 'number' => 100, 'offset' => $offset ) );
if ( empty( $users ) ) {
    delete_option( 'sync_offset' );
    return;
}
foreach ( $users as $user ) {
    sync_user_data( $user );
}
update_option( 'sync_offset', $offset + 100 );
wp_schedule_single_event( time() + 60, 'my_batch_sync' );
```

### PHP Code

- `in_array()` without `true` strict param — WARNING: Type juggling + O(n)
- Heredoc with unescaped variables — WARNING: Prevents late escaping

```php
// ❌ O(n) lookup
in_array( $value, $array );

// ✅ O(1) lookup
$allowed = array( 'foo' => true, 'bar' => true );
if ( isset( $allowed[ $value ] ) ) { ... }
```

## Quick Detection Commands

```bash
# Critical issues
grep -rn "posts_per_page.*-1\|numberposts.*-1" .
grep -rn "query_posts\s*(" .
grep -rn "session_start\s*(" .
grep -rn "setInterval.*fetch\|setInterval.*ajax" .

# DB writes on frontend
grep -rn "update_option\|add_option" . | grep -v "admin\|activate\|install"

# Uncached expensive functions
grep -rn "url_to_postid\|attachment_url_to_postid\|count_user_posts" .

# External HTTP without caching
grep -rn "wp_remote_get\|wp_remote_post" .

# Global asset loading
grep -rn "wp_enqueue_script\|wp_enqueue_style" . | grep -v "is_page\|is_singular\|is_admin"

# Cron without schedule check
grep -rn "wp_schedule_event" . | grep -v "wp_next_scheduled"
```

## Output Format

```markdown
## Performance Review: [filename/component]

### Critical Issues
- **Line X**: [Issue] — [Explanation] — [Fix]

### Warnings
- **Line X**: [Issue] — [Explanation] — [Fix]

### Recommendations
- [Optimization opportunities]

### Summary
- Total issues: X Critical, Y Warnings, Z Info
- Estimated impact: [High/Medium/Low]
```
