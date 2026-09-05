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
	 * Transient value meaning "the last lookup failed".
	 *
	 * Storing `false` is indistinguishable from a cache miss, so the failure
	 * cache never took effect and every failed check re-hit the API — 60 calls
	 * an hour unauthenticated. A sentinel string is distinguishable.
	 *
	 * @var string
	 */
	private const FAILURE_SENTINEL = 'sp_merge_release_lookup_failed';

	/**
	 * Hosts a release package may be downloaded from.
	 *
	 * The `package` URL is handed straight to WP_Upgrader, which fetches and
	 * unpacks it over the live plugin directory. Nothing but GitHub's own
	 * download hosts may reach that code path.
	 *
	 * @var string[]
	 */
	private const ALLOWED_PACKAGE_HOSTS = array(
		'github.com',
		'codeload.github.com',
		'api.github.com',
		'objects.githubusercontent.com',
	);

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

		if ( self::is_disabled() ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
	}

	/**
	 * Whether self-updating is switched off for this deployment.
	 *
	 * This plugin permanently deletes posts, so an operator part-way through a
	 * batch of merges may reasonably want the code frozen. Define
	 * `SP_MERGE_DISABLE_UPDATER` as true in wp-config.php to freeze it.
	 *
	 * @return bool
	 */
	public static function is_disabled(): bool {
		return defined( 'SP_MERGE_DISABLE_UPDATER' ) && SP_MERGE_DISABLE_UPDATER;
	}

	/**
	 * Whether a GitHub tag name is a plain semantic version.
	 *
	 * An unvalidated tag goes straight into version_compare(), so a tag of
	 * `9999` — or anything else a compromised or careless release produces —
	 * would force an update on every site.
	 *
	 * @param mixed $tag Tag name from the API response.
	 * @return bool
	 */
	public static function is_valid_tag( $tag ): bool {
		return is_string( $tag ) && 1 === preg_match( '/^v?\d+\.\d+\.\d+$/', $tag );
	}

	/**
	 * Whether a package URL may be handed to WP_Upgrader.
	 *
	 * @param mixed $url Candidate download URL.
	 * @return bool
	 */
	public static function is_allowed_package_url( $url ): bool {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}

		if ( 'https' !== strtolower( $parts['scheme'] ) ) {
			return false;
		}

		return in_array( strtolower( $parts['host'] ), self::ALLOWED_PACKAGE_HOSTS, true );
	}

	/**
	 * Pick the built release asset to install.
	 *
	 * `zipball_url` is GitHub's *source* archive. The release workflow minifies
	 * assets at build time and only the uploaded asset carries the resulting
	 * `admin.min.js` / `admin.min.css`, which the admin screen enqueues in
	 * production. Installing the zipball leaves the whole admin UI inert, so
	 * when no built asset is present no update is offered at all.
	 *
	 * @param object $release Release object from the GitHub API.
	 * @return string|null Download URL, or null when the release has no built asset.
	 */
	private function get_package_url( object $release ): ?string {
		if ( empty( $release->assets ) || ! is_array( $release->assets ) ) {
			return null;
		}

		$exact    = $this->slug . '.zip';
		$fallback = null;

		foreach ( $release->assets as $asset ) {
			$name = is_object( $asset ) ? ( $asset->name ?? '' ) : '';
			$url  = is_object( $asset ) ? ( $asset->browser_download_url ?? '' ) : '';

			if ( ! is_string( $name ) || ! self::is_allowed_package_url( $url ) ) {
				continue;
			}

			if ( $exact === $name ) {
				return $url;
			}

			if ( null === $fallback && str_starts_with( $name, $this->slug ) && str_ends_with( $name, '.zip' ) ) {
				$fallback = $url;
			}
		}

		return $fallback;
	}

	/**
	 * Check GitHub for a newer release and inject into the update transient.
	 *
	 * @param object $transient The update_plugins transient.
	 * @return object Modified transient.
	 */
	public function check_update( object $transient ): object {
		if ( self::is_disabled() || empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$package = $this->get_package_url( $release );
		if ( null === $package ) {
			// Source-only release: installing it would strip the built assets.
			return $transient;
		}

		$remote_version = ltrim( $release->tag_name, 'v' );

		if ( version_compare( $this->version, $remote_version, '<' ) ) {
			$transient->response[ $this->basename ] = (object) array(
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => $remote_version,
				'url'         => "https://github.com/{$this->repo}",
				'package'     => $package,
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
		if ( self::is_disabled() || 'plugin_information' !== $action || $this->slug !== ( $args->slug ?? '' ) ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		$package = $this->get_package_url( $release );
		if ( null === $package ) {
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
			'download_link'   => $package,
			'trunk'           => $package,
			'last_updated'    => $release->published_at ?? '',
			'sections'        => array(
				'description' => 'Advanced tool to merge duplicate SportsPress players with data preservation and revert functionality.',
				'changelog'   => nl2br( esc_html( $release->body ?? '' ) ),
			),
		);
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

		if ( self::FAILURE_SENTINEL === $cached ) {
			return null;
		}

		if ( is_object( $cached ) && self::is_valid_tag( $cached->tag_name ?? null ) ) {
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
			set_transient( $cache_key, self::FAILURE_SENTINEL, HOUR_IN_SECONDS );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );
		if ( ! is_object( $body ) || ! self::is_valid_tag( $body->tag_name ?? null ) ) {
			// Malformed response, or a tag that is not a plain semver — a tag
			// like `9999` would otherwise force an update on every site.
			set_transient( $cache_key, self::FAILURE_SENTINEL, HOUR_IN_SECONDS );
			return null;
		}

		$this->github_release = $body;
		set_transient( $cache_key, $body, 6 * HOUR_IN_SECONDS );

		return $this->github_release;
	}
}
