<?php
/**
 * Regression guard: SP_Merge_Admin::enqueue_scripts() registers the right
 * handles, sources, dependencies and versions — including the SlimSelect
 * vendor bundle that replaced Select2, and the unminified-source fallback
 * that stops both player-picker dropdowns going permanently inert on a
 * git-cloned install with no built .min. assets.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'SP_MERGE_PLUGIN_URL', 'https://example.test/wp-content/plugins/sportspress-player-merge/' );
define( 'SP_MERGE_PLUGIN_PATH', dirname( __DIR__ ) . '/' );
define( 'SP_MERGE_VERSION', '1.2.0' );

$GLOBALS['spm_test_pass']        = 0;
$GLOBALS['spm_test_fail']        = 0;
$GLOBALS['spm_enqueued_styles']  = array();
$GLOBALS['spm_enqueued_scripts'] = array();
$GLOBALS['spm_localized']        = array();

function spm_admin_assert( bool $condition, string $label ): void {
	if ( $condition ) {
		++$GLOBALS['spm_test_pass'];
		echo "  PASS  {$label}\n";
		return;
	}
	++$GLOBALS['spm_test_fail'];
	echo "  FAIL  {$label}\n";
}

function __( $text, $domain = '' ) {
	return $text;
}

function wp_enqueue_style( $handle, $src, $deps, $ver ) {
	$GLOBALS['spm_enqueued_styles'][ $handle ] = array(
		'src'  => $src,
		'deps' => $deps,
		'ver'  => $ver,
	);
}

function wp_enqueue_script( $handle, $src, $deps, $ver, $in_footer ) {
	$GLOBALS['spm_enqueued_scripts'][ $handle ] = array(
		'src'       => $src,
		'deps'      => $deps,
		'ver'       => $ver,
		'in_footer' => $in_footer,
	);
}

function wp_localize_script( $handle, $object_name, $data ) {
	$GLOBALS['spm_localized'][ $handle ][ $object_name ] = $data;
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . $path;
}

function wp_create_nonce( $action ) {
	return 'test-nonce-' . $action;
}

require_once dirname( __DIR__ ) . '/classes/class-sp-merge-admin.php';

echo "SP_Merge_Admin::enqueue_scripts()\n";

$admin = new SP_Merge_Admin();

// Off-page hook suffixes must not enqueue anything.
$admin->enqueue_scripts( 'edit.php' );
spm_admin_assert( empty( $GLOBALS['spm_enqueued_scripts'] ), 'an unrelated admin page enqueues nothing' );

// The real hook suffix WordPress passes for a submenu registered under
// edit.php?post_type=sp_player with menu slug 'sp-player-merge'.
$admin->enqueue_scripts( 'sp_player_page_sp-player-merge' );

spm_admin_assert( isset( $GLOBALS['spm_enqueued_styles']['sp-merge-admin-css'] ), "the plugin's own admin CSS is enqueued" );
spm_admin_assert( isset( $GLOBALS['spm_enqueued_styles']['sp-merge-slimselect-css'] ), 'the SlimSelect CSS is enqueued' );
spm_admin_assert( isset( $GLOBALS['spm_enqueued_scripts']['sp-merge-slimselect-js'] ), 'the SlimSelect JS is enqueued' );
spm_admin_assert( isset( $GLOBALS['spm_enqueued_scripts']['sp-merge-admin-js'] ), "the plugin's own admin JS is enqueued" );
spm_admin_assert( ! isset( $GLOBALS['spm_enqueued_styles']['sp-merge-select2-css'] ), 'Select2 is not enqueued — it was swapped for SlimSelect' );
spm_admin_assert( ! isset( $GLOBALS['spm_enqueued_scripts']['sp-merge-select2-js'] ), 'Select2 JS is not enqueued either' );

spm_admin_assert(
	false !== strpos( $GLOBALS['spm_enqueued_scripts']['sp-merge-slimselect-js']['src'], 'assets/vendor/slimselect/slimselect.min.js' ),
	'SlimSelect JS points at the vendored bundle'
);
spm_admin_assert(
	false !== strpos( $GLOBALS['spm_enqueued_styles']['sp-merge-slimselect-css']['src'], 'assets/vendor/slimselect/slimselect.min.css' ),
	'SlimSelect CSS points at the vendored bundle'
);
spm_admin_assert(
	array() === $GLOBALS['spm_enqueued_scripts']['sp-merge-slimselect-js']['deps'],
	'SlimSelect has no script dependencies of its own — it is not a jQuery plugin'
);

spm_admin_assert(
	in_array( 'jquery', $GLOBALS['spm_enqueued_scripts']['sp-merge-admin-js']['deps'], true ),
	'the plugin admin JS still depends on jquery'
);
spm_admin_assert(
	in_array( 'sp-merge-slimselect-js', $GLOBALS['spm_enqueued_scripts']['sp-merge-admin-js']['deps'], true ),
	'the plugin admin JS depends on SlimSelect loading first'
);

spm_admin_assert(
	isset( $GLOBALS['spm_localized']['sp-merge-admin-js']['spMergeAjax'] ),
	'spMergeAjax is localized onto the admin JS handle'
);
$localized = $GLOBALS['spm_localized']['sp-merge-admin-js']['spMergeAjax'] ?? array();
spm_admin_assert(
	isset( $localized['ajaxUrl'], $localized['nonce'], $localized['strings'] ),
	'the localized payload carries ajaxUrl, nonce and strings'
);
spm_admin_assert(
	isset( $localized['strings']['confirmRevert'] ) && ! isset( $localized['strings']['confirmMerge'] ),
	'the removed confirmMerge string is gone; confirmRevert (still used) remains'
);

// Source files ship unminified in this checkout — asset_suffix() must fall
// back to the plain file rather than pointing at a .min. file that doesn't
// exist here. That exact 404 (a .min.js reference with nothing built) once
// left both player-picker dropdowns permanently inert with no error shown.
spm_admin_assert(
	false === strpos( $GLOBALS['spm_enqueued_scripts']['sp-merge-admin-js']['src'], '.min.js' ),
	'the plugin admin JS falls back to the unminified source in this checkout'
);
spm_admin_assert(
	false === strpos( $GLOBALS['spm_enqueued_styles']['sp-merge-admin-css']['src'], '.min.css' ),
	'the plugin admin CSS falls back to the unminified source in this checkout'
);

echo "\n{$GLOBALS['spm_test_pass']} passed, {$GLOBALS['spm_test_fail']} failed\n";
exit( $GLOBALS['spm_test_fail'] > 0 ? 1 : 0 );
