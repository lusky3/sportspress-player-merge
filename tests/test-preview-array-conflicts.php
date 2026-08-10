<?php
/**
 * The preview's "Result After Merge" column reads as a clean union, which the
 * serialized array fields are not: they are merged cell by cell, so a season's
 * statistic can only come from one of the two players.
 *
 * This guards the warning block that says which way each contested cell will go,
 * and that it distinguishes "the duplicate's value will be used because the
 * primary is blank" from "the primary's value is kept and the duplicate's
 * discarded".
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Mocks must use the real WordPress function names.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Mocks mirror documented WordPress signatures.

require_once __DIR__ . '/bootstrap.php';

function esc_html__( $text, $domain = '' ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

require_once dirname( __DIR__ ) . '/classes/class-sp-merge-preview.php';

spm_test_header( 'the preview names the cells the merge has to resolve (F-3)' );

/**
 * Render the array-field warning block for a seeded meta store.
 *
 * @param array $meta Meta store: post_id => key => array of values.
 * @return string HTML.
 */
function spm_render_array_warning( array $meta ): string {
	spm_reset( $meta );

	$preview    = new SP_Merge_Preview();
	$reflection = new ReflectionMethod( SP_Merge_Preview::class, 'render_array_field_warning' );
	$reflection->setAccessible( true );

	return $reflection->invokeArgs( $preview, array( 100, array( 200 ) ) );
}

/*
 * 1. Nothing contested: no warning at all. The preview must not cry wolf on the
 *    ordinary case where the two players' seasons do not overlap.
 */
$quiet = spm_render_array_warning(
	array(
		100 => array( 'sp_statistics' => array( array( 7 => array( 30 => array( 'goals' => '3' ) ) ) ) ),
		200 => array( 'sp_statistics' => array( array( 8 => array( 40 => array( 'goals' => '9' ) ) ) ) ),
	)
);

spm_assert_equals( '', $quiet, 'a merge with no contested cell renders no warning' );

/*
 * 2. Both directions, in one merge: a blank primary cell that the duplicate
 *    fills, and a real disagreement the primary wins.
 */
$html = spm_render_array_warning(
	array(
		100 => array(
			'sp_statistics' => array(
				array(
					7 => array(
						30 => array(
							'goals'   => '',
							'assists' => '2',
						),
					),
				),
			),
			'sp_leagues'    => array( array( 7 => array( 30 => 0 ) ) ),
		),
		200 => array(
			'sp_statistics' => array(
				array(
					7 => array(
						30 => array(
							'goals'   => '12',
							'assists' => '<script>x</script>',
						),
					),
				),
			),
			'sp_leagues'    => array( array( 7 => array( 30 => 55 ) ) ),
		),
	)
);

spm_assert( '' !== $html, 'a contested merge renders a warning' );

// The gap the duplicate fills is named as such, with the value that will be used.
spm_assert( false !== strpos( $html, 'sp_statistics[7][30][goals]' ), 'the filled statistic cell is addressed exactly' );
spm_assert( false !== strpos( $html, 'sp_leagues[7][30]' ), 'the blank league cell is addressed exactly' );
spm_assert( false !== strpos( $html, 'the duplicate\'s value "12" will be used' ), "the filled cell says the duplicate's value will be used" );
spm_assert( false !== strpos( $html, "the primary's cell is blank" ), 'the filled cells are explained by the primary being blank' );

// The conflict is reported as a discard, not as a union.
spm_assert( false !== strpos( $html, 'sp_statistics[7][30][assists]' ), 'the conflicting cell is addressed exactly' );
spm_assert( false !== strpos( $html, 'keeping "2"' ), "the conflict states the primary's kept value" );
spm_assert( false !== strpos( $html, 'is discarded' ), 'the conflict says the duplicate value is discarded' );
spm_assert( false !== strpos( $html, 'player 200' ), 'each line names the duplicate it came from' );

// Everything new is escaped.
spm_assert( false === strpos( $html, '<script>' ), 'a value containing markup is escaped' );
spm_assert( false !== strpos( $html, '&lt;script&gt;' ), 'the escaped value is still shown so it can be recovered' );

// The block closes and reopens the table the same way render_collision_warning does.
$reopen = '<table class="merge-preview-table" style="display:none;"><tbody>';
spm_assert( 0 === strpos( $html, '</tbody></table>' ), 'the block closes the open table first' );
spm_assert( substr( $html, -strlen( $reopen ) ) === $reopen, 'the block reopens a table for the caller to close' );

/*
 * 3. A hand-entered '0' on the primary is real data, so it is a conflict, not a
 *    gap — the preview must not claim the duplicate's value will be used.
 */
$zero = spm_render_array_warning(
	array(
		100 => array( 'sp_statistics' => array( array( 7 => array( 30 => array( 'goals' => '0' ) ) ) ) ),
		200 => array( 'sp_statistics' => array( array( 7 => array( 30 => array( 'goals' => '12' ) ) ) ) ),
	)
);

spm_assert( false !== strpos( $zero, 'keeping "0", discarding "12"' ), "a primary '0' is previewed as a kept value, not a gap" );
spm_assert( false === strpos( $zero, 'will be used' ), 'no cell is claimed to be taken from the duplicate' );

/*
 * 4. A primary with no value at all for the field is copied wholesale by
 *    merge_array_field(), so there is nothing to resolve and nothing to warn about.
 */
$wholesale = spm_render_array_warning(
	array(
		100 => array(),
		200 => array( 'sp_statistics' => array( array( 7 => array( 30 => array( 'goals' => '12' ) ) ) ) ),
	)
);

spm_assert_equals( '', $wholesale, 'copying a field onto an empty primary raises no warning' );

spm_test_summary();
