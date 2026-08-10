<?php
/**
 * Fresh-install schema parity.
 *
 * The backup table is only created by the activation hook, and the GitHub
 * updater replaces files without re-activating — which is why SP_Merge_Backup
 * carries a runtime migration. A fresh install should never need that path, so
 * every column the migration adds must also be in the activation CREATE TABLE,
 * with an identical definition.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output.
// phpcs:disable WordPress.Files.FileName -- Harness file, not a class file.

require_once __DIR__ . '/bootstrap.php';

spm_test_header( 'fresh-install schema matches the runtime migration' );

$plugin_file = file_get_contents( dirname( __DIR__ ) . '/sportspress-player-merge.php' );
$backup_file = file_get_contents( dirname( __DIR__ ) . '/classes/class-sp-merge-backup.php' );

spm_assert( false !== $plugin_file, 'the main plugin file is readable' );
spm_assert( false !== $backup_file, 'the backup class is readable' );

// Isolate the activation CREATE TABLE statement.
$matched = preg_match( '/CREATE TABLE \{\$table_name\} \((.*?)\) \{\$charset_collate\}/s', (string) $plugin_file, $create );
spm_assert_equals( 1, $matched, 'the activation CREATE TABLE statement is present' );

$create_sql = $create[1] ?? '';

// Every column the migration can add must already be in the CREATE TABLE.
preg_match_all( '/ADD COLUMN (\w+) ([^"]+)"/', (string) $backup_file, $additions, PREG_SET_ORDER );

spm_assert( count( $additions ) >= 3, 'the runtime migration still declares its columns' );

foreach ( $additions as $addition ) {
	$column     = $addition[1];
	$definition = rtrim( $addition[2] );

	spm_assert(
		str_contains( $create_sql, $column . ' ' . $definition ),
		"fresh installs already have `{$column} {$definition}`"
	);
}

// Named explicitly so a silently dropped column cannot pass on an empty match.
foreach ( array( 'status', 'touched_posts', 'post_hashes' ) as $column ) {
	spm_assert( str_contains( $create_sql, $column . ' ' ), "CREATE TABLE declares {$column}" );
}

spm_test_summary();
