<?php
/**
 * SP_Merge_Validation must reproduce SP_Merge_Ajax's inline validation and
 * survivor-warning logic exactly, since the AJAX layer now delegates to it and
 * a future WP-CLI layer will call the very same static methods.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable

require_once __DIR__ . '/lib-ajax-mocks.php';

if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}

echo "Shared validation matches the former inline AJAX behaviour\n";

/**
 * Register (or replace) a post the get_post() mock will serve.
 *
 * @param int    $id          Post ID.
 * @param string $post_type   Post type.
 * @param string $post_status Post status.
 */
function sp_test_set_post( int $id, string $post_type = 'sp_player', string $post_status = 'publish' ): void {
	$GLOBALS['sp_posts'][ $id ] = (object) array(
		'post_type'   => $post_type,
		'post_status' => $post_status,
	);
}

/* -------------------------------------------------------------------------
 * validate_merge_selection()
 * ---------------------------------------------------------------------- */

$GLOBALS['sp_posts'] = array();
sp_test_set_post( 10 );
sp_test_set_post( 20 );
sp_test_set_post( 30 );

/* 1. A valid selection passes and normalizes primary/duplicates to ints. */
$result = SP_Merge_Validation::validate_merge_selection( '10', array( '20', '30' ) );
sp_assert_same( true, $result['valid'], 'a valid selection is accepted' );
sp_assert_same( 10, $result['primary_id'], 'the primary ID is returned as an int' );
sp_assert_same( array( 20, 30 ), $result['duplicate_ids'], 'the duplicate IDs are returned as ints' );
sp_assert( ! isset( $result['error'] ), 'a valid result carries no error' );

/* 2. Empty duplicates. */
$result = SP_Merge_Validation::validate_merge_selection( '10', array() );
sp_assert_same( false, $result['valid'], 'empty duplicates is rejected' );
sp_assert_same( 'Invalid player selection', $result['error'], 'empty duplicates reports the same message as before' );

/* 2b. No primary at all is the same failure. */
$result = SP_Merge_Validation::validate_merge_selection( '0', array( '20' ) );
sp_assert_same( false, $result['valid'], 'a zero primary is rejected' );
sp_assert_same( 'Invalid player selection', $result['error'], 'a zero primary reports the same message as before' );

/* 3. Primary also present among the duplicates. */
$result = SP_Merge_Validation::validate_merge_selection( '10', array( '20', '10' ) );
sp_assert_same( false, $result['valid'], 'primary listed as a duplicate is rejected' );
sp_assert_same( 'Primary player cannot also be a duplicate', $result['error'], 'the primary-as-duplicate message matches' );

/* 4. More than 10 duplicates. */
sp_test_set_post( 40 );
$eleven = range( 40, 50 ); // 11 IDs; none need to exist, the count check runs first.
$result = SP_Merge_Validation::validate_merge_selection( '10', $eleven );
sp_assert_same( false, $result['valid'], 'more than 10 duplicates is rejected' );
sp_assert_same( 'Maximum 10 duplicate players per merge operation.', $result['error'], 'the too-many-duplicates message matches' );

/* 5. Primary not found. */
$result = SP_Merge_Validation::validate_merge_selection( '999', array( '20' ) );
sp_assert_same( false, $result['valid'], 'a primary that does not exist is rejected' );
sp_assert_same( 'Primary player not found or not published', $result['error'], 'the missing-primary message matches' );

/* 5b. Primary exists but is not published. */
sp_test_set_post( 11, 'sp_player', 'draft' );
$result = SP_Merge_Validation::validate_merge_selection( '11', array( '20' ) );
sp_assert_same( false, $result['valid'], 'an unpublished primary is rejected' );
sp_assert_same( 'Primary player not found or not published', $result['error'], 'the unpublished-primary message matches' );

/* 5c. Primary exists but is the wrong post type. */
sp_test_set_post( 12, 'post', 'publish' );
$result = SP_Merge_Validation::validate_merge_selection( '12', array( '20' ) );
sp_assert_same( false, $result['valid'], 'a primary of the wrong post type is rejected' );
sp_assert_same( 'Primary player not found or not published', $result['error'], 'the wrong-post-type primary message matches' );

/* 6. A duplicate not found or unpublished. */
$result = SP_Merge_Validation::validate_merge_selection( '10', array( '20', '999' ) );
sp_assert_same( false, $result['valid'], 'a duplicate that does not exist is rejected' );
sp_assert_same( 'One or more duplicate players not found or not published', $result['error'], 'the missing-duplicate message matches' );

sp_test_set_post( 21, 'sp_player', 'draft' );
$result = SP_Merge_Validation::validate_merge_selection( '10', array( '20', '21' ) );
sp_assert_same( false, $result['valid'], 'an unpublished duplicate is rejected' );
sp_assert_same( 'One or more duplicate players not found or not published', $result['error'], 'the unpublished-duplicate message matches' );

/* 7. Repeats and string IDs normalize exactly as absint()+array_unique() would. */
$result = SP_Merge_Validation::validate_merge_selection( '10', array( '20', 20, '020', '0', '', 30 ) );
sp_assert_same( true, $result['valid'], 'a duplicate list with repeats and string IDs still validates' );
sp_assert_same( array( 20, 30 ), $result['duplicate_ids'], 'repeats, zero/blank entries and string IDs collapse the same way absint()+array_unique() would' );

/* -------------------------------------------------------------------------
 * get_event_counts() / survivor_warnings()
 * ---------------------------------------------------------------------- */

/**
 * A wpdb stand-in that answers the event-count query with canned rows,
 * falling back to the shared backup-mocks behaviour for everything else.
 */
class SP_Test_WPDB_With_Events extends SP_Test_WPDB {

	/** @var array<int,int> Player ID => event count, for the canned query response. */
	public array $event_counts = array();

	/** @var int[] The player IDs bound into the most recently prepared query. */
	private array $last_prepared_ids = array();

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		if ( false !== strpos( $query, 'meta_value AS player_id' ) ) {
			$this->last_prepared_ids = array_map( 'intval', $args );
		}
		return parent::prepare( $query, ...$args );
	}

	public function get_results( $query ) {
		if ( false !== strpos( $query, 'meta_value AS player_id' ) ) {
			// Mirrors the real query's "IN (...)" clause: only rows for the
			// IDs actually bound into this call are ever returned.
			$rows = array();
			foreach ( $this->event_counts as $player_id => $cnt ) {
				if ( ! in_array( (int) $player_id, $this->last_prepared_ids, true ) ) {
					continue;
				}
				$rows[] = (object) array(
					'player_id' => (string) $player_id,
					'cnt'       => $cnt,
				);
			}
			return $rows;
		}

		return parent::get_results( $query );
	}
}

$GLOBALS['wpdb']                = new SP_Test_WPDB_With_Events();
$GLOBALS['wpdb']->event_counts  = array(
	1 => 10,
	2 => 5,
	3 => 25,
	4 => 15,
);

/* 8. get_event_counts() zero-fills players the query has no rows for. */
$counts = SP_Merge_Validation::get_event_counts( array( 1, 2, 3, 4, 999 ) );
sp_assert_same(
	array(
		1   => 10,
		2   => 5,
		3   => 25,
		4   => 15,
		999 => 0,
	),
	$counts,
	'get_event_counts() reports the queried counts and zero-fills the rest, as SP_Merge_Ajax::get_event_counts() did'
);

/* 8b. Duplicate/zero player IDs are normalized before the count map is built. */
$counts = SP_Merge_Validation::get_event_counts( array( 1, '1', 0 ) );
sp_assert_same( array( 1 => 10 ), $counts, 'duplicate and zero player IDs are collapsed before counting' );

/* 8c. An empty player list short-circuits to an empty array. */
sp_assert_same( array(), SP_Merge_Validation::get_event_counts( array() ), 'an empty player list returns an empty array' );

/* 9. survivor_warnings(): a duplicate with substantially more history warns. */
$warnings = SP_Merge_Validation::survivor_warnings( 1, array( 2, 3, 4 ) );
sp_assert_same( 1, count( $warnings ), 'exactly one duplicate crosses the warning threshold' );
sp_assert_contains( 'Player 3', $warnings[0], 'the warning names the duplicate with the outsized history' );
sp_assert_contains( '25 event(s)', $warnings[0], "the warning states the duplicate's event count" );
sp_assert_contains( 'Player 1', $warnings[0], 'the warning names the surviving primary' );
sp_assert_contains( '10', $warnings[0], "the warning states the primary's event count" );

/* 9b. A duplicate with fewer or only mildly more events raises no warning. */
$quiet = SP_Merge_Validation::survivor_warnings( 1, array( 2, 4 ) );
sp_assert_same( array(), $quiet, 'a duplicate at or under 2x the primary count raises no warning' );

/* 9c. A primary with no history at all warns on any duplicate history. */
// Real event-count rows only ever exist for players with at least one event,
// so player 5 (the primary) simply has no row and zero-fills, same as a real
// query against a player with no sp_event history.
$GLOBALS['wpdb']->event_counts = array(
	6 => 1,
);
$warnings = SP_Merge_Validation::survivor_warnings( 5, array( 6 ) );
sp_assert_same( 1, count( $warnings ), 'any duplicate history warns when the primary has none at all' );
sp_assert_contains( 'Player 6', $warnings[0], 'the zero-history warning still names the duplicate' );

sp_test_done();
