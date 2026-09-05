<?php
/**
 * Standalone test harness for SP_Merge_Ajax.
 *
 * Builds on lib-backup-mocks.php (assertions plus the shared WordPress stubs)
 * and adds the handful of functions the AJAX layer needs: the JSON responders,
 * the roster queries the duplicate scan pages through, and wp_hash().
 *
 * Set SP_MERGE_TEST_AJAX_CLASS to point the harness at a different copy of the
 * class (used to prove the tests fail against the pre-fix code).
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable

require_once __DIR__ . '/lib-backup-mocks.php';

$GLOBALS['sp_scan_roster']     = array();
$GLOBALS['sp_scan_total']      = null;
$GLOBALS['sp_get_posts_calls'] = array();

/**
 * Raised in place of the JSON responses that would end the request.
 */
class SP_Test_Json_Response extends Exception {

	/** @var bool */
	public $ok;

	/** @var mixed */
	public $payload;

	public function __construct( bool $ok, $payload ) {
		parent::__construct( $ok ? 'json_success' : 'json_error' );
		$this->ok      = $ok;
		$this->payload = $payload;
	}
}

function wp_send_json_success( $data = null ) {
	throw new SP_Test_Json_Response( true, $data );
}

function wp_send_json_error( $data = null ) {
	throw new SP_Test_Json_Response( false, $data );
}

function wp_hash( $data, $scheme = 'auth' ) {
	return hash_hmac( 'sha256', (string) $data, 'sp-merge-test-salt|' . $scheme );
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function current_user_can( $capability ) {
	return ! in_array( $capability, $GLOBALS['sp_denied_caps'] ?? array(), true );
}

function wp_verify_nonce( $nonce, $action ) {
	return 'valid-nonce' === $nonce;
}

/**
 * Serve the seeded roster, honouring posts_per_page / offset / order.
 *
 * @param array $args Query args.
 * @return object[]
 */
function get_posts( $args = array() ) {
	$GLOBALS['sp_get_posts_calls'][] = $args;

	$roster = $GLOBALS['sp_scan_roster'];

	if ( isset( $args['order'] ) && 'DESC' === strtoupper( (string) $args['order'] ) ) {
		$roster = array_reverse( $roster );
	}

	$offset = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
	$limit  = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 10;

	if ( $limit < 0 ) {
		$limit = count( $roster );
	}

	return array_slice( $roster, $offset, $limit );
}

/**
 * No-op cache priming: the meta mocks answer from $GLOBALS['sp_meta_rows']
 * directly, so there is no cache to warm. Needed because
 * SP_Merge_Ajax::find_duplicates() primes both caches before reading.
 *
 * @param string $meta_type Object type.
 * @param int[]  $object_ids Object IDs.
 * @return bool
 */
function update_meta_cache( $meta_type, $object_ids ) {
	return true;
}

/**
 * Stand-in edit link, so the duplicate-scan payload can be built without a
 * WordPress admin.
 *
 * @param int    $post_id Post ID.
 * @param string $context Link context.
 * @return string
 */
function get_edit_post_link( $post_id, $context = 'display' ) {
	return 'https://example.test/wp-admin/post.php?post=' . (int) $post_id . '&action=edit';
}

function wp_count_posts( $post_type = 'post' ) {
	$total = $GLOBALS['sp_scan_total'];

	return (object) array( 'publish' => null === $total ? count( $GLOBALS['sp_scan_roster'] ) : (int) $total );
}

/**
 * Seed a published roster of $count players with ascending IDs.
 *
 * @param int $count    Number of players.
 * @param int $first_id ID of the oldest player.
 * @param int $total    Published total reported by wp_count_posts(), or 0 to match the roster.
 */
function sp_test_seed_roster( int $count, int $first_id = 100, int $total = 0 ): void {
	$roster = array();

	for ( $i = 0; $i < $count; $i++ ) {
		$roster[] = (object) array(
			'ID'         => $first_id + $i,
			'post_title' => 'Player ' . ( $first_id + $i ),
			'post_type'  => 'sp_player',
		);
	}

	$GLOBALS['sp_scan_roster']     = $roster;
	$GLOBALS['sp_scan_total']      = $total > 0 ? $total : $count;
	$GLOBALS['sp_get_posts_calls'] = array();
}

$sp_ajax_class_file = getenv( 'SP_MERGE_TEST_AJAX_CLASS' );
if ( ! $sp_ajax_class_file ) {
	$sp_ajax_class_file = dirname( __DIR__ ) . '/classes/class-sp-merge-ajax.php';
}
require_once dirname( __DIR__ ) . '/classes/class-sp-merge-validation.php';
require_once $sp_ajax_class_file;
