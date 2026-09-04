# SDD Progress Ledger — wp-cli-plan.md

Worktree: /home/cody/git/sportspress-player-merge/.claude/worktrees/sp-merge-wpcli
Branch: worktree-sp-merge-wpcli
Plan: docs/plans/wp-cli-plan.md

Tasks:
1. Bootstrap gate + SP_Merge_Validation extraction — complete
2. Backup cross-user support — complete
3. SP_Merge_CLI skeleton + scan/preview + preview generate_data() — complete
4. merge/revert/backups list/backups delete commands — complete
5. wp sp-merge batch — complete
6. PHPStan wp-cli stubs support — pending

Task 1: complete (commits adb5c5b..1dbbdb2, review clean)
Task 2: complete (commits 1dbbdb2..eee4607, review clean)
Task 3: complete (commits eee4607..e472fff, review: Approved).
  IMPORTANT CORRECTION for Task 4: task-3-report.md's "WP-CLI command-
  registration approach" section is WRONG — it claims backups_list()/
  backups_delete() methods on SP_Merge_CLI will automatically expose as
  the two-word `wp sp-merge backups list` / `backups delete` with no
  second WP_CLI::add_command() call. Verified false against WP-CLI's own
  source: a single class registration only maps each method to ONE leaf
  subcommand token (`backups-list`, hyphenated, not two words). Genuine
  two-word nested subcommands require the explicit additional
  WP_CLI::add_command('sp-merge backups list', array($instance,
  'backups_list')) calls already specified in docs/plans/wp-cli-plan.md's
  Task 4 text (three add_command calls total) — use that, not the Task 3
  report's guidance, when dispatching Task 4.
Task 4: complete (commits de64f82..4014057, review: Approved).
  Registration correction was applied and re-verified independently by the
  Task 4 reviewer (sound per WP-CLI's documented method-name-to-hyphenated-
  leaf convention) — no residual concern for Task 5 or the final review.
  Minor findings recorded for the final whole-branch review (non-blocking):
  no test exercises "--force alone, no --yes" reaching the interactive
  confirm() for merge()/revert() (code is correct, just not directly
  asserted); a couple of user-facing strings (merge success message,
  backups-delete confirmation prompt) were invented since the brief didn't
  specify exact wording — no functional issue.
  Actual WP-CLI registration mechanics still unverified against a real `wp`
  binary (none available in this sandbox) — recommend `wp help sp-merge
  backups list` as a live sanity check before first production use. Carry
  this into the final report to the user.
Task 5: complete (commits db81175..bce6945, review: Approved).
  run_one_merge() extraction verified structurally regression-free against
  merge()'s pre-diff body; --dry-run's non-execution guarantee verified via
  substituted assertions (wpdb->backups / sp_deleted_posts stay empty),
  proven equivalent to the brief's originally-suggested (harness-incompatible)
  assertion. Minor coverage gaps only (empty batch file, malformed row not
  directly tested) — non-blocking, note for final review.
  classes/class-sp-merge-cli.php is now ~1000 lines total across scan/preview/
  merge/revert/backups/batch — flagged by two implementers now, never
  restructured without instruction. Worth the final whole-branch reviewer's
  judgment on whether a follow-up split is warranted (not this branch).
Task 6: complete (commits b0846ac..7180f98, review clean). All 6 tasks done.

Final whole-branch review: Ready to merge, With fixes (1 Critical, 6 Important,
5 Minor addressed in commits 6b0f164, 97963bc). Re-verification pass confirmed
all 12 findings landed correctly (class split for #7 is a clean, complete
extraction; no stale references). Re-verification surfaced one additional
real bug not caught by any prior review layer: the plugin's own --user flag
(meaning "whose backup to target" on revert/backups list/delete) collided
with WP-CLI's own built-in global --user flag (acting identity), which the
WP-CLI runtime strips from $assoc_args before any command body runs — so the
cross-user targeting feature could never actually work over a real `wp`
invocation, only in test mocks that don't model global-flag stripping. Fixed
in commit 53c64f0 by renaming the plugin's flag to --owner everywhere
(code, docblocks, README, tests). Full suite (26 files) green after this fix.

ALL WORK COMPLETE. Branch ready for PR.

--- Post-PR audit round (user request: run through /code-audit-methodology and /wordpress-pro) ---

Two independent audits run against the merged branch (3a3293d..84dd8b4):
a general 8-domain code audit and a WordPress-standards-specific review.
Both flagged, independently, the same top issue (missing Throwable guard
around generate_data()'s call chain, breaking this plugin's own documented
convention) plus ~30 additional findings across security/architecture/
error-handling/concurrency/database/api-design/devops and WP-specific
capability/i18n/hooks/WPCS concerns.

Fix Pass A (10 correctness/safety findings, commits 84dd8b4..6d59daf):
Throwable guards, scan certainty-scoring divergence, event-count divergence,
batch malformed-row rejection, shared merge lock (revert now participates),
batch --yes requirement, backup_id propagation on failure, batch file-path
validation, TOCTOU re-validation in execute_merge(), wp_json_encode() check.
Verified clean by independent reviewer, including a byte-identical AJAX
payload diff (old vs new find_duplicates()) proving zero behavior change
to the certainty-scoring extraction — the highest-risk change in the pass.

Fix Pass B (23 items — 3 regressions found while verifying Pass A, plus 20
audit findings, commits 6d59daf..74a450f): --input-format rename, option
validation, merge --porcelain, backups list --status-before-limit, shared
resolve_target_user(), i18n wrapping + shared revert messages, docblock/
wp-help cleanup, README/readme.txt fixes, release-ZIP exclusions, CI
additions (wp help smoke test + tests/run-all.sh + phpcs config + version
pins). Verified 22/23 correct; one real gap found (merge --porcelain didn't
actually suppress the preview report unless --skip-preview was also passed)
plus a missing /* translators: */ comment — both fixed directly in commit
1c86ef1, confirmed via a new test case (--porcelain alone, no --skip-preview).

Full suite green throughout (28 test files, 640+ checks). All work pushed;
PR #39 remains open for CI + manual wp-binary sanity check before merge.
