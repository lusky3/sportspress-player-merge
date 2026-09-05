<?php
/**
 * The duplicate scan must look at the whole roster, and say how much it saw.
 *
 * `posts_per_page => 2000` newest-first against a 2121-player roster never
 * looked at the 121 oldest records — the long-history players most likely to be
 * the correct survivor — and reported nothing about it.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable

require_once __DIR__ . '/lib-ajax-mocks.php';

echo "Duplicate scan coverage\n";


/* 1. A roster larger than the old 2000 cap is scanned in full. */
sp_test_seed_roster( 2121, 100 );

$scan = SP_Merge_Validation::collect_scan_players();
$ids  = array_map( static fn( $p ) => $p->ID, $scan['players'] );

sp_assert_same( 2121, $scan['scanned'], 'every player on a 2121-player roster is scanned' );
sp_assert_same( 2121, $scan['total'], 'the published total is reported' );
sp_assert_same( false, $scan['truncated'], 'a fully scanned roster is not flagged as truncated' );
sp_assert_same( 2121, count( array_unique( $ids ) ), 'paging returns each player exactly once' );
sp_assert( in_array( 100, $ids, true ), 'the oldest player is scanned' );
sp_assert( in_array( 2220, $ids, true ), 'the newest player is scanned' );

sp_assert(
	count( $GLOBALS['sp_get_posts_calls'] ) > 1,
	'the roster is paged rather than fetched in one capped query'
);
sp_assert_same( 'ID', $GLOBALS['sp_get_posts_calls'][0]['orderby'] ?? '', 'paging orders by ID for a stable window' );
sp_assert_same( 'ASC', $GLOBALS['sp_get_posts_calls'][0]['order'] ?? '', 'paging starts from the oldest record' );
sp_assert_same( 0, $GLOBALS['sp_get_posts_calls'][0]['offset'] ?? -1, 'the first page starts at offset zero' );

/* 2. An exactly-page-sized roster does not loop forever or double-count. */
sp_test_seed_roster( 500, 1 );

$scan = SP_Merge_Validation::collect_scan_players();

sp_assert_same( 500, $scan['scanned'], 'a roster that is an exact multiple of the page size is scanned once' );
sp_assert_same( false, $scan['truncated'], 'an exactly-paged roster is not flagged as truncated' );

/* 3. An empty roster reports zero rather than failing. */
sp_test_seed_roster( 0 );

$scan = SP_Merge_Validation::collect_scan_players();

sp_assert_same( 0, $scan['scanned'], 'an empty roster scans zero players' );
sp_assert_same( 0, $scan['total'], 'an empty roster totals zero players' );
sp_assert_same( false, $scan['truncated'], 'an empty roster is not flagged as truncated' );

/* 4. Overflow past the hard cap is surfaced, not silent. */
sp_test_seed_roster( 10001, 1 );

$scan = SP_Merge_Validation::collect_scan_players();

sp_assert_same( 10000, $scan['scanned'], 'the hard cap bounds a runaway roster' );
sp_assert_same( 10001, $scan['total'], 'the real published total is still reported' );
sp_assert_same( true, $scan['truncated'], 'an overflowing scan is flagged so the operator can see it' );

/* 5. A published total larger than what the query returns is reported honestly. */
sp_test_seed_roster( 40, 1, 60 );

$scan = SP_Merge_Validation::collect_scan_players();

sp_assert_same( 40, $scan['scanned'], 'only the rows actually returned count as scanned' );
sp_assert_same( 60, $scan['total'], 'the published total is not silently rewritten to the scanned count' );
sp_assert_same( true, $scan['truncated'], 'scanning fewer players than exist is flagged' );

sp_test_done();
