<?php
/**
 * Shared WP-CLI-only helpers.
 *
 * Kept off SP_Merge_Validation deliberately: that class is loaded on every
 * admin and AJAX request, and both methods here call \WP_CLI directly, so
 * they only make sense — and only get loaded — under `wp sp-merge`. Shared
 * across SP_Merge_CLI, SP_Merge_CLI_Backups and SP_Merge_CLI_Batch instead of
 * duplicated on each.
 *
 * @package SportsPress_Player_Merge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SP_Merge_CLI_Support
 */
class SP_Merge_CLI_Support {

	/**
	 * Resolve which user a WP-CLI subcommand should act on behalf of.
	 *
	 * Defaults to the current user. An explicit target is only permitted for a
	 * caller holding delete_sp_players — the same tier the AJAX layer requires
	 * for touching another user's backups at all — so a League Manager cannot
	 * use `--owner` to reach into an Administrator's (or another League
	 * Manager's) backups.
	 *
	 * @param string|null $user_arg Raw --owner value: numeric ID or login, or null/empty for "self".
	 * @return int Resolved user ID.
	 */
	public static function resolve_target_user( ?string $user_arg ): int {
		if ( null === $user_arg || '' === $user_arg ) {
			return get_current_user_id();
		}

		$user = is_numeric( $user_arg ) ? get_user_by( 'id', (int) $user_arg ) : get_user_by( 'login', $user_arg );
		if ( ! $user ) {
			\WP_CLI::error(
				sprintf(
					/* translators: %s: the --owner value (numeric ID or login) that did not match any user */
					__( 'No user found matching "%s".', 'sportspress-player-merge' ),
					$user_arg
				)
			);
		}

		$target_id = (int) $user->ID;

		if ( get_current_user_id() !== $target_id && ! current_user_can( 'delete_sp_players' ) ) {
			\WP_CLI::error( __( 'Only an Administrator (delete_sp_players) can act on another user\'s backups.', 'sportspress-player-merge' ) );
		}

		return $target_id;
	}

	/**
	 * Parse and range-check an integer WP-CLI option.
	 *
	 * A typo like --limit=abc silently (int)-casts to 0, which callers then
	 * clamp to their own minimum rather than reporting the mistake — e.g. a
	 * bad --limit value quietly returning exactly one row instead of what the
	 * operator asked for. This reports it instead.
	 *
	 * @param array  $assoc_args Associative WP-CLI arguments.
	 * @param string $key        Option name.
	 * @param int    $fallback   Value to use when the option is not present at all.
	 * @param int    $min        Minimum accepted value, inclusive.
	 * @param int    $max        Maximum accepted value, inclusive.
	 * @param string $error      Message for \WP_CLI::error() when the value is out of range.
	 * @return int
	 */
	public static function int_option( array $assoc_args, string $key, int $fallback, int $min, int $max, string $error ): int {
		if ( ! isset( $assoc_args[ $key ] ) ) {
			return $fallback;
		}

		$raw = (string) $assoc_args[ $key ];

		if ( 1 !== preg_match( '/^\d+$/', $raw ) || (int) $raw < $min || (int) $raw > $max ) {
			\WP_CLI::error( $error );
		}

		return (int) $raw;
	}
}
