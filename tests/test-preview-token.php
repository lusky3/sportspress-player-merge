<?php
/**
 * The preview must bind to the merge that executes.
 *
 * Swapping the primary or a duplicate after previewing used to leave a stale
 * preview card on screen with Execute live, and the execution posted whatever
 * the dropdowns currently held.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable

require_once __DIR__ . '/lib-ajax-mocks.php';

echo "Preview token binds preview to execution\n";

$ajax = new SP_Merge_Ajax();

/* 1. The token is stable and order-independent. */
$token = SP_Merge_Ajax::selection_token( 10, array( 3, 7 ) );

sp_assert( '' !== $token, 'a token is produced' );
sp_assert_same( $token, SP_Merge_Ajax::selection_token( 10, array( 3, 7 ) ), 'the same selection produces the same token' );
sp_assert_same( $token, SP_Merge_Ajax::selection_token( 10, array( 7, 3 ) ), 'duplicate order does not change the token' );
sp_assert_same( $token, SP_Merge_Ajax::selection_token( 10, array( '7', '3', '7' ) ), 'string IDs and repeats normalise to the same token' );

/* 2. Any change of selection produces a different token. */
sp_assert( $token !== SP_Merge_Ajax::selection_token( 11, array( 3, 7 ) ), 'swapping the primary changes the token' );
sp_assert( $token !== SP_Merge_Ajax::selection_token( 10, array( 3, 8 ) ), 'swapping a duplicate changes the token' );
sp_assert( $token !== SP_Merge_Ajax::selection_token( 10, array( 3 ) ), 'dropping a duplicate changes the token' );
sp_assert( $token !== SP_Merge_Ajax::selection_token( 10, array( 3, 7, 9 ) ), 'adding a duplicate changes the token' );
sp_assert( $token !== SP_Merge_Ajax::selection_token( 3, array( 10, 7 ) ), 'exchanging primary and duplicate changes the token' );

/**
 * Run verify_preview_token() against a POSTed token.
 *
 * @param SP_Merge_Ajax $ajax          AJAX handler.
 * @param mixed         $posted_token  Token to post, or null to post none.
 * @param int           $primary_id    Primary player ID.
 * @param array         $duplicate_ids Duplicate player IDs.
 * @return array{result: bool|null, error: string}
 */
function sp_test_verify( SP_Merge_Ajax $ajax, $posted_token, int $primary_id, array $duplicate_ids ): array {
	unset( $_POST['preview_token'] );
	if ( null !== $posted_token ) {
		$_POST['preview_token'] = $posted_token;
	}

	try {
		return array(
			'result' => sp_test_invoke( $ajax, 'verify_preview_token', array( $primary_id, $duplicate_ids ) ),
			'error'  => '',
		);
	} catch ( SP_Test_Json_Response $e ) {
		return array(
			'result' => null,
			'error'  => (string) ( $e->payload['message'] ?? '' ),
		);
	}
}

/* 3. A matching token is accepted. */
$check = sp_test_verify( $ajax, $token, 10, array( 3, 7 ) );
sp_assert_same( true, $check['result'], 'the token from the preview verifies' );

$check = sp_test_verify( $ajax, $token, 10, array( 7, 3 ) );
sp_assert_same( true, $check['result'], 'the token still verifies when the POST re-orders the duplicates' );

/* 4. A selection that changed after the preview is refused. */
$check = sp_test_verify( $ajax, $token, 10, array( 3, 8 ) );
sp_assert_same( null, $check['result'], 'a swapped duplicate is refused' );
sp_assert_contains( 'Preview the current selection again', $check['error'], 'the refusal tells the operator to preview again' );

$check = sp_test_verify( $ajax, $token, 11, array( 3, 7 ) );
sp_assert_same( null, $check['result'], 'a swapped primary is refused' );

/* 5. A missing or forged token is refused. */
$check = sp_test_verify( $ajax, null, 10, array( 3, 7 ) );
sp_assert_same( null, $check['result'], 'executing with no token at all is refused' );

$check = sp_test_verify( $ajax, 'not-a-real-token', 10, array( 3, 7 ) );
sp_assert_same( null, $check['result'], 'a forged token is refused' );

$check = sp_test_verify( $ajax, substr( $token, 0, -1 ), 10, array( 3, 7 ) );
sp_assert_same( null, $check['result'], 'a truncated token is refused' );

unset( $_POST['preview_token'] );

sp_test_done();
