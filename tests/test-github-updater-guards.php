<?php
/**
 * GitHub updater supply-chain guards: tag-format validation, the package host
 * allowlist, built-asset selection, the failure-cache sentinel and the
 * deployment kill switch.
 *
 * The updater hands its `package` URL straight to WP_Upgrader, which unpacks it
 * over the live plugin directory, so every one of these is a code-execution
 * boundary.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Mocks must use the real WordPress function names.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress is not loaded; these globals are the harness state.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Mocks mirror documented WordPress signatures.
// phpcs:disable WordPress.Files.FileName -- Harness file, not a class file.
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- Single-file harness by design.

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

$GLOBALS['spm_filters']       = array();
$GLOBALS['spm_http_calls']    = 0;
$GLOBALS['spm_http_response'] = array(
	'code' => 200,
	'body' => '{}',
);

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		return 'sportspress-player-merge/' . basename( $file );
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['spm_filters'][] = $hook;

		return true;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standing in for wp_parse_url itself.
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) {
		++$GLOBALS['spm_http_calls'];

		return $GLOBALS['spm_http_response'];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return $response['code'];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return $response['body'];
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standing in for wp_json_encode itself.
	}
}

require_once dirname( __DIR__ ) . '/classes/class-sp-merge-github-updater.php';

/**
 * Call a private method on the updater.
 *
 * @param SP_Merge_GitHub_Updater $updater Updater instance.
 * @param string                  $method  Method name.
 * @param mixed                   ...$args Arguments.
 * @return mixed
 */
function spm_updater_invoke( SP_Merge_GitHub_Updater $updater, string $method, ...$args ) {
	$ref = new ReflectionMethod( SP_Merge_GitHub_Updater::class, $method );
	$ref->setAccessible( true );

	return $ref->invoke( $updater, ...$args );
}

/**
 * Build a release asset object.
 *
 * @param string $name Asset file name.
 * @param string $url  Download URL.
 * @return object
 */
function spm_asset( string $name, string $url ): object {
	return (object) array(
		'name'                 => $name,
		'browser_download_url' => $url,
	);
}

spm_test_header( 'github updater: tag validation, host allowlist, asset selection, kill switch' );

spm_reset();

//
// 1. Tag-name validation. An unvalidated `9999` forces an update everywhere.
//

$valid_tags = array( '1.2.3', 'v1.2.3', '0.0.1', 'v10.20.30' );
foreach ( $valid_tags as $tag ) {
	spm_assert( SP_Merge_GitHub_Updater::is_valid_tag( $tag ), "tag accepted: {$tag}" );
}

$invalid_tags = array( '9999', 'v9999', '1.2', '1.2.3.4', 'v1.2.3-beta', 'latest', '', ' 1.2.3', '1.2.3 ', 'v1.2.3; rm -rf /' );
foreach ( $invalid_tags as $tag ) {
	spm_assert( ! SP_Merge_GitHub_Updater::is_valid_tag( $tag ), "tag rejected: '{$tag}'" );
}

spm_assert( ! SP_Merge_GitHub_Updater::is_valid_tag( null ), 'a missing tag is rejected' );
spm_assert( ! SP_Merge_GitHub_Updater::is_valid_tag( array( '1.2.3' ) ), 'a non-string tag is rejected' );

//
// 2. Package host allowlist.
//

$allowed_urls = array(
	'https://github.com/lusky3/sportspress-player-merge/releases/download/v1.3.0/sportspress-player-merge.zip',
	'https://codeload.github.com/lusky3/sportspress-player-merge/zip/refs/tags/v1.3.0',
	'https://api.github.com/repos/lusky3/sportspress-player-merge/zipball/v1.3.0',
	'https://objects.githubusercontent.com/github-production-release-asset/1/2',
	'https://GITHUB.COM/lusky3/sportspress-player-merge/x.zip',
);
foreach ( $allowed_urls as $url ) {
	spm_assert( SP_Merge_GitHub_Updater::is_allowed_package_url( $url ), 'package host allowed: ' . wp_parse_url( $url )['host'] );
}

$blocked_urls = array(
	'https://evil.example.com/sportspress-player-merge.zip',
	'https://github.com.evil.example.com/sportspress-player-merge.zip',
	'https://evil.example.com/github.com/sportspress-player-merge.zip',
	'http://github.com/lusky3/sportspress-player-merge/x.zip',
	'ftp://github.com/x.zip',
	'//github.com/x.zip',
	'/local/path.zip',
	'',
);
foreach ( $blocked_urls as $url ) {
	spm_assert( ! SP_Merge_GitHub_Updater::is_allowed_package_url( $url ), "package URL rejected: '{$url}'" );
}

spm_assert( ! SP_Merge_GitHub_Updater::is_allowed_package_url( null ), 'a missing package URL is rejected' );

//
// 3. Asset selection — the built release asset, never the source zipball.
//

$updater = new SP_Merge_GitHub_Updater( __DIR__ . '/sportspress-player-merge.php', '1.2.0' );

spm_assert_equals(
	array( 'pre_set_site_transient_update_plugins', 'plugins_api', 'upgrader_post_install' ),
	$GLOBALS['spm_filters'],
	'the updater registers its filters while enabled'
);

$asset_url = 'https://github.com/lusky3/sportspress-player-merge/releases/download/v1.3.0/sportspress-player-merge.zip';

$release_with_asset = (object) array(
	'tag_name'    => 'v1.3.0',
	'zipball_url' => 'https://api.github.com/repos/lusky3/sportspress-player-merge/zipball/v1.3.0',
	'assets'      => array(
		spm_asset( 'sbom-sportspress-player-merge.spdx.json', 'https://github.com/lusky3/sportspress-player-merge/releases/download/v1.3.0/sbom.spdx.json' ),
		spm_asset( 'sportspress-player-merge.zip', $asset_url ),
	),
);

spm_assert_equals(
	$asset_url,
	spm_updater_invoke( $updater, 'get_package_url', $release_with_asset ),
	'the built .zip asset is chosen over the SBOM and the zipball'
);

$release_without_asset = (object) array(
	'tag_name'    => 'v1.3.0',
	'zipball_url' => 'https://api.github.com/repos/lusky3/sportspress-player-merge/zipball/v1.3.0',
	'assets'      => array(),
);

spm_assert_equals(
	null,
	spm_updater_invoke( $updater, 'get_package_url', $release_without_asset ),
	'a source-only release yields no package — the zipball is never installed'
);

$release_offsite_asset = (object) array(
	'tag_name'    => 'v1.3.0',
	'zipball_url' => 'https://api.github.com/repos/lusky3/sportspress-player-merge/zipball/v1.3.0',
	'assets'      => array(
		spm_asset( 'sportspress-player-merge.zip', 'https://evil.example.com/sportspress-player-merge.zip' ),
	),
);

spm_assert_equals(
	null,
	spm_updater_invoke( $updater, 'get_package_url', $release_offsite_asset ),
	'an asset hosted off GitHub is refused'
);

//
// 4. The failure cache actually caches. Storing `false` was indistinguishable
// from a cache miss, so every failed check re-hit a 60/hour API limit.
//

$GLOBALS['spm_http_calls']    = 0;
$GLOBALS['spm_http_response'] = array(
	'code' => 403,
	'body' => '',
);

$failing = new SP_Merge_GitHub_Updater( __DIR__ . '/sportspress-player-merge.php', '1.2.0' );
spm_assert_equals( null, spm_updater_invoke( $failing, 'get_latest_release' ), 'a 403 yields no release' );
spm_assert_equals( 1, $GLOBALS['spm_http_calls'], 'the first failed lookup hits the API once' );

// A fresh instance must honour the cached failure rather than call out again.
$failing_again = new SP_Merge_GitHub_Updater( __DIR__ . '/sportspress-player-merge.php', '1.2.0' );
spm_assert_equals( null, spm_updater_invoke( $failing_again, 'get_latest_release' ), 'the cached failure still yields no release' );
spm_assert_equals( 1, $GLOBALS['spm_http_calls'], 'the cached failure suppresses the second API call' );

//
// 5. A bogus tag is treated as a failed lookup, not as an available update.
//

spm_reset();
$GLOBALS['spm_http_calls']    = 0;
$GLOBALS['spm_http_response'] = array(
	'code' => 200,
	'body' => wp_json_encode(
		array(
			'tag_name'    => '9999',
			'zipball_url' => 'https://api.github.com/repos/lusky3/sportspress-player-merge/zipball/9999',
			'assets'      => array(
				array(
					'name'                 => 'sportspress-player-merge.zip',
					'browser_download_url' => $asset_url,
				),
			),
		)
	),
);

$bogus_tag = new SP_Merge_GitHub_Updater( __DIR__ . '/sportspress-player-merge.php', '1.2.0' );
spm_assert_equals( null, spm_updater_invoke( $bogus_tag, 'get_latest_release' ), 'a tag of 9999 is refused' );

$transient = (object) array(
	'checked'  => array( 'sportspress-player-merge/sportspress-player-merge.php' => '1.2.0' ),
	'response' => array(),
);
$result    = $bogus_tag->check_update( $transient );
spm_assert_equals( array(), $result->response, 'a refused tag offers no update' );

//
// 6. The deployment kill switch.
//

spm_assert( ! SP_Merge_GitHub_Updater::is_disabled(), 'the updater is enabled by default' );

define( 'SP_MERGE_DISABLE_UPDATER', true );

spm_assert( SP_Merge_GitHub_Updater::is_disabled(), 'SP_MERGE_DISABLE_UPDATER switches the updater off' );

spm_reset();
$GLOBALS['spm_filters']       = array();
$GLOBALS['spm_http_calls']    = 0;
$GLOBALS['spm_http_response'] = array(
	'code' => 200,
	'body' => wp_json_encode(
		array(
			'tag_name'    => 'v9.9.9',
			'zipball_url' => 'https://api.github.com/repos/lusky3/sportspress-player-merge/zipball/v9.9.9',
			'assets'      => array(
				array(
					'name'                 => 'sportspress-player-merge.zip',
					'browser_download_url' => $asset_url,
				),
			),
		)
	),
);

$disabled = new SP_Merge_GitHub_Updater( __DIR__ . '/sportspress-player-merge.php', '1.2.0' );
spm_assert_equals( array(), $GLOBALS['spm_filters'], 'a disabled updater registers no filters' );

$transient_disabled = (object) array(
	'checked'  => array( 'sportspress-player-merge/sportspress-player-merge.php' => '1.2.0' ),
	'response' => array(),
);
$result_disabled    = $disabled->check_update( $transient_disabled );
spm_assert_equals( array(), $result_disabled->response, 'a disabled updater offers no update even for a valid newer release' );
spm_assert_equals( 0, $GLOBALS['spm_http_calls'], 'a disabled updater never calls the API' );

spm_test_summary();
