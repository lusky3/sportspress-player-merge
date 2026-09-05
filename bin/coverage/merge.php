<?php
/**
 * Merge per-process coverage snapshots and emit the combined report.
 *
 *     php bin/coverage/merge.php <raw-data-dir> <output-dir>
 *
 * Reads every *.cov file bin/coverage/run-one.php wrote (each one a
 * SebastianBergmann\CodeCoverage\Report\PHP dump: a PHP file that returns an
 * unserialized CodeCoverage object), folds them into a single CodeCoverage via
 * CodeCoverage::merge(), then writes Clover XML to <output-dir>/clover.xml and
 * prints a per-file line-coverage table to stdout.
 *
 * Clover is the one format written because it is the one format both consumers
 * need: SonarCloud reads it through sonar.php.coverage.reportPaths and Codecov
 * parses it natively.
 *
 * @package SportsPress_Player_Merge
 */

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Node\File as FileNode;
use SebastianBergmann\CodeCoverage\Report\Clover;

require __DIR__ . '/lib.php';

if ( $argc < 3 ) {
	fwrite( STDERR, "Usage: php bin/coverage/merge.php <raw-data-dir> <output-dir>\n" );
	exit( 1 );
}

$raw_dir    = rtrim( $argv[1], '/' );
$output_dir = rtrim( $argv[2], '/' );
$snapshots  = glob( $raw_dir . '/*.cov' );

if ( ! $snapshots ) {
	fwrite( STDERR, "No coverage snapshots found in {$raw_dir}/\n" );
	exit( 1 );
}

sort( $snapshots );

/*
 * The combined object is built from the shared filter rather than from the
 * first snapshot, so every plugin source file is present in the report even
 * when no test ever loaded it. CodeCoverage::includeUncoveredFiles() is on by
 * default and turns those into honest 0% entries; without them the percentage
 * would only ever be computed over the files the suite happens to touch.
 */
$combined = SPM_Coverage_Support::coverage();

foreach ( $snapshots as $snapshot ) {
	$loaded = require $snapshot;

	if ( ! $loaded instanceof CodeCoverage ) {
		fwrite( STDERR, "Not a coverage snapshot: {$snapshot}\n" );
		exit( 1 );
	}

	$combined->merge( $loaded );
}

$clover = $output_dir . '/clover.xml';
( new Clover() )->process( $combined, $clover, 'SportsPress Player Merge' );

//
// Human/CI-log summary: one line per source file, then the totals.
//

$report = $combined->getReport();
$rows   = array();

foreach ( $report as $node ) {
	if ( ! $node instanceof FileNode ) {
		continue;
	}

	$rows[ $node->pathAsString() ] = array(
		'executed'   => $node->numberOfExecutedLines(),
		'executable' => $node->numberOfExecutableLines(),
		'percent'    => $node->percentageOfExecutedLines()->asString(),
	);
}

ksort( $rows );

$root     = SPM_Coverage_Support::root() . '/';
$labels   = array();
$measured = array();

foreach ( $rows as $path => $row ) {
	$labels[ $path ] = str_replace( $root, '', $path );
	$measured[]      = strlen( $labels[ $path ] );
}

$width = $measured ? max( $measured ) : 0;

echo "\nLine coverage by file\n";
echo str_repeat( '-', $width + 26 ) . "\n";

foreach ( $rows as $path => $row ) {
	printf(
		"%-{$width}s  %8s  (%d/%d)\n",
		$labels[ $path ],
		$row['percent'],
		$row['executed'],
		$row['executable']
	);
}

echo str_repeat( '-', $width + 26 ) . "\n";

printf(
	"%-{$width}s  %8s  (%d/%d)\n",
	'TOTAL',
	$report->percentageOfExecutedLines()->asString(),
	$report->numberOfExecutedLines(),
	$report->numberOfExecutableLines()
);

printf(
	"\nMerged %d coverage snapshot(s).\nClover XML written to %s\n",
	count( $snapshots ),
	$clover
);
