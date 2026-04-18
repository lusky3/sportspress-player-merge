<?php
/**
 * GitHub Updater Class
 *
 * Checks GitHub releases for plugin updates and integrates with
 * the WordPress plugin update system.
 *
 * @package SportsPress_Player_Merge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SP_Merge_GitHub_Updater
 */
class SP_Merge_GitHub_Updater {

	/**
	 * GitHub repository owner/name.
	 *
	 * @var string
	 */
	private string $repo = 'lusky3/sportspress-player-merge';

	/**
	 * Plugin basename (e.g., sportspress-player-merge/sportspress-player-merge.php).
	 *
	 * @var string
	 */
	private string $basename;

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	private string $slug = 'sportspress-player-merge';

	/**
	 * Current plugin version.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Cached GitHub release data.
	 *
	 * @var object|null
	 */
	private ?object $github_release = null;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Main plugin file path.
	 * @param string $version     Current plugin version.
	 */
	public function __construct( string $plugin_file, string $version ) {
		$this->basename = plugin_basename( $plugin_file );
		$this->version  = $version;

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'post_install' ), 10, 3 );
	}

	/**
	 * Check GitHub for a newer release and inject into the update transient.
	 *
	 * @param object $transient The update_plugins transient.
	 * @return object Modified transient.
	 */
	public function check_update( object $transient ): object {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$remote_version = ltrim( $release->tag_name, 'v' );

		if ( version_compare( $this->version, $remote_version, '<' ) ) {
			$transient->response[ $this->basename ] = (object) array(
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => $remote_version,
				'url'         => "https://github.com/{$this->repo}",
				'package'     => $release->zipball_url,
				'icons'       => array(),
				'banners'     => array(),
			);
		}

		return $transient;
	}

	/**
	 * Provide plugin info for the WordPress plugin details modal.
	 *
	 * @param false|object|array $result The result object or array.
	 * @param string             $action The API action.
	 * @param object             $args   Plugin API arguments.
	 * @return false|object
	 */
	public function plugin_info( $result, string $action, object $args ) {
		if ( 'plugin_information' !== $action || $this->slug !== ( $args->slug ?? '' ) ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		$remote_version = ltrim( $release->tag_name, 'v' );

		return (object) array(
			'name'            => 'SportsPress Player Merge',
			'slug'            => $this->slug,
			'version'         => $remote_version,
			'author'          => '<a href="https://github.com/lusky3">Cody (lusky3)</a>',
			'homepage'        => "https://github.com/{$this->repo}",
			'requires'        => '6.0',
			'tested'          => '6.7',
			'requires_php'    => '8.2',
			'download_link'   => $release->zipball_url,
			'trunk'           => $release->zipball_url,
			'last_updated'    => $release->published_at ?? '',
			'sections'        => array(
				'description' => 'Advanced tool to merge duplicate SportsPress players with data preservation and revert functionality.',
				'changelog'   => nl2br( esc_html( $release->body ?? '' ) ),
			),
		);
	}

	/**
	 * After install, rename the extracted folder to match the plugin slug.
	 *
	 * GitHub zipballs extract to owner-repo-hash/ which doesn't match the expected plugin directory name.
	 *
	 * @param bool  $response   Install response.
	 * @param array $hook_extra Extra arguments.
	 * @param array $result     Install result.
	 * @return array Modified result.
	 */
	public function post_install( bool $response, array $hook_extra, array $result ): array {
		if ( ! isset( $hook_extra['plugin'] ) || $this->basename !== $hook_extra['plugin'] ) {
			return $result;
		}

		global $wp_filesystem;

		$proper_dir = WP_PLUGIN_DIR . '/' . $this->slug;
		$wp_filesystem->move( $result['destination'], $proper_dir );
		$result['destination'] = $proper_dir;

		// Re-activate if it was active before the update.
		if ( is_plugin_active( $this->basename ) ) {
			activate_plugin( $this->basename );
		}

		return $result;
	}

	/**
	 * Fetch the latest release from GitHub API. Cached for 6 hours.
	 *
	 * @return object|null Release data or null.
	 */
	private function get_latest_release(): ?object {
		if ( null !== $this->github_release ) {
			return $this->github_release;
		}

		$cache_key = 'sp_merge_github_release';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			$this->github_release = $cached;
			return $this->github_release;
		}

		$response = wp_remote_get(
			"https://api.github.com/repos/{$this->repo}/releases/latest",
			array(
				'timeout' => 5,
				'headers' => array(
					'Accept' => 'application/vnd.github.v3+json',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// Cache the failure for 1 hour to avoid hammering the API.
			set_transient( $cache_key, false, HOUR_IN_SECONDS );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );
		if ( ! $body || ! isset( $body->tag_name ) ) {
			set_transient( $cache_key, false, HOUR_IN_SECONDS );
			return null;
		}

		$this->github_release = $body;
		set_transient( $cache_key, $body, 6 * HOUR_IN_SECONDS );

		return $this->github_release;
	}
}
