<?php
/**
 * Regression guard: a failed ALTER TABLE during the runtime schema migration
 * must not be recorded as a successful upgrade.
 *
 * Recording DB_VERSION as current when a column failed to add would leave
 * post_hashes silently absent forever (get_option() short-circuits the
 * migration on every later request) — and every revert of a backup created
 * after that point would then refuse with values_changed, since there is
 * nothing to compare a hash against.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable

require_once __DIR__ . '/lib-backup-mocks.php';

echo "Schema upgrade: a failed ALTER TABLE is not recorded as success\n";

sp_test_reset();

// Simulate a table that predates the post_hashes column, and a restricted DB
// user (or row-size limit, or lock) that makes the ALTER fail.
$GLOBALS['wpdb']->columns = array_values(
	array_diff( $GLOBALS['wpdb']->columns, array( 'post_hashes' ) )
);
$GLOBALS['wpdb']->fail_next_alter = true;

$backup = new SP_Merge_Backup();
sp_test_invoke( $backup, 'maybe_upgrade_schema' );

sp_assert(
	'2' !== get_option( 'sp_merge_backup_db_version' ),
	'DB_VERSION is NOT recorded as upgraded when an ALTER TABLE fails'
);

$alter_attempts = array_values(
	array_filter(
		$GLOBALS['wpdb']->statements,
		static function ( $sql ) {
			return 0 === strpos( $sql, 'ALTER TABLE' );
		}
	)
);
sp_assert_same( 1, count( $alter_attempts ), 'exactly one ALTER TABLE was attempted (post_hashes)' );

// A retry on the next request (fresh call, ALTER now succeeds) completes the
// upgrade and records it.
sp_test_invoke( $backup, 'maybe_upgrade_schema' );

sp_assert_same(
	'2',
	get_option( 'sp_merge_backup_db_version' ),
	'a later retry that succeeds does record the upgrade'
);

sp_test_done();
