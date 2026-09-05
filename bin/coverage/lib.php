<?php
/**
 * Shared plumbing for the standalone coverage runner.
 *
 * The test suite is not PHPUnit: tests/run-all.sh runs every tests/test-*.php
 * in its own `php` subprocess because most of them redeclare the same global
 * WordPress mock functions (get_post(), get_post_meta(), ...) and would fatal
 * on symbol redeclaration if required into a single process. Coverage
 * therefore has to be collected once per subprocess and merged afterwards;
 * see bin/coverage/run-one.php and bin/coverage/merge.php.
 *
 * Everything here lives on one class rather than in plain functions:
 * run-one.php requires this file into the same global scope that then requires
 * a test file, and the test harness owns a large surface of global function
 * names. A class nobody else declares cannot collide with it.
 *
 * @package SportsPress_Player_Merge
 */

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$spm_cov_autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! is_file( $spm_cov_autoload ) ) {
	fwrite( STDERR, "Coverage tooling is not installed. Run `composer install` first.\n" );
	exit( 1 );
}

require_once $spm_cov_autoload;
unset( $spm_cov_autoload );

/**
 * Coverage configuration shared by the per-process runner and the merge step.
 */
final class SPM_Coverage_Support {

	/**
	 * Repository root (the directory holding the main plugin file).
	 *
	 * @return string
	 */
	public static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Directory the static-analysis cache is kept in.
	 *
	 * Shared across all subprocesses so each of the plugin's source files is
	 * parsed once for the whole run instead of once per test file. Lives inside
	 * the gitignored raw-data directory, in a dot-prefixed subdirectory so the
	 * merge step's *.cov glob never picks it up.
	 *
	 * @return string
	 */
	public static function cache_dir(): string {
		return self::root() . '/.coverage-raw/.cache';
	}

	/**
	 * Source files coverage is measured over.
	 *
	 * Shipped plugin code only: the classes, the admin template, and the main
	 * bootstrap file. Deliberately excluded:
	 *
	 * - tests/          the harness itself, not the code under test.
	 * - vendor/         third-party, and dev-only at that.
	 * - uninstall.php   only ever executed by WordPress's uninstall lifecycle,
	 *                   with WP_UNINSTALL_PLUGIN defined and a live $wpdb. The
	 *                   standalone harness cannot require it without side
	 *                   effects, so including it would only pin a permanently
	 *                   0% file into the report that no test could ever move.
	 *
	 * includes/admin-page.php *is* included even though nothing covers it
	 * today: unlike uninstall.php it is ordinary reachable code (rendered on
	 * every plugin admin page load) that the suite could grow to cover, so its
	 * 0% is a real gap worth reporting rather than a measurement artefact.
	 *
	 * @return string[] Absolute file paths.
	 */
	public static function source_files(): array {
		$root  = self::root();
		$files = array( $root . '/sportspress-player-merge.php' );

		foreach ( array( 'classes', 'includes' ) as $directory ) {
			$found = glob( $root . '/' . $directory . '/*.php' );

			if ( $found ) {
				sort( $found );
				$files = array_merge( $files, $found );
			}
		}

		return array_values( array_filter( $files, 'is_file' ) );
	}

	/**
	 * Filter restricted to the plugin's own source files.
	 *
	 * @return Filter
	 */
	public static function filter(): Filter {
		$filter = new Filter();
		$filter->includeFiles( self::source_files() );

		return $filter;
	}

	/**
	 * A CodeCoverage bound to the line-coverage driver (PCOV here and in CI).
	 *
	 * Selector::forLineCoverage() picks PCOV when the extension is loaded and
	 * falls back to Xdebug otherwise; it throws
	 * NoCodeCoverageDriverAvailableException when neither is present, which is
	 * the failure we want rather than a silently empty report.
	 *
	 * @return CodeCoverage
	 */
	public static function coverage(): CodeCoverage {
		$filter = self::filter();

		$coverage = new CodeCoverage(
			( new Selector() )->forLineCoverage( $filter ),
			$filter
		);

		$coverage->cacheStaticAnalysis( self::cache_dir() );

		return $coverage;
	}
}
