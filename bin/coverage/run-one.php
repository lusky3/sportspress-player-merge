<?php
/**
 * Run one standalone test file with line coverage collection.
 *
 *     php bin/coverage/run-one.php <test-file> <output-file>
 *
 * The test file is required into this script's global scope, exactly as
 * `php tests/test-foo.php` would load it, so its assertions run unchanged and
 * tests/bootstrap.php's spm_test_summary() still exit()s with the suite's
 * pass/fail code. Coverage is stopped and written from a shutdown function for
 * precisely that reason — an exit() from the test file skips the rest of this
 * script but still runs shutdown handlers, and returning from a shutdown
 * handler leaves the exit code the test chose untouched.
 *
 * Local variables here are all spm_cov_-prefixed and unset before the require,
 * so nothing in this file can shadow a variable the test harness relies on.
 *
 * @package SportsPress_Player_Merge
 */

use SebastianBergmann\CodeCoverage\Report\PHP as PhpReport;

require __DIR__ . '/lib.php';

if ( $argc < 3 ) {
	fwrite( STDERR, "Usage: php bin/coverage/run-one.php <test-file> <output-file>\n" );
	exit( 1 );
}

$spm_cov_test = realpath( $argv[1] );

if ( false === $spm_cov_test || ! is_file( $spm_cov_test ) ) {
	fwrite( STDERR, "Test file not found: {$argv[1]}\n" );
	exit( 1 );
}

$spm_cov_output   = $argv[2];
$spm_cov_id       = basename( $spm_cov_test );
$spm_cov_coverage = SPM_Coverage_Support::coverage();

$spm_cov_coverage->start( $spm_cov_id );

register_shutdown_function(
	static function () use ( $spm_cov_coverage, $spm_cov_output ): void {
		try {
			$spm_cov_coverage->stop();
			( new PhpReport() )->process( $spm_cov_coverage, $spm_cov_output );
		} catch ( Throwable $spm_cov_error ) {
			fwrite( STDERR, 'Coverage collection failed: ' . $spm_cov_error->getMessage() . "\n" );
			exit( 1 );
		}
	}
);

unset( $spm_cov_coverage, $spm_cov_output, $spm_cov_id );

require $spm_cov_test;
