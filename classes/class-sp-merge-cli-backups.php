<?php
/**
 * WP-CLI Backup Subcommands Class
 *
 * Registers the `wp sp-merge backups <verb>` command family.
 *
 * @package SportsPress_Player_Merge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Kept on its own class, registered at the `sp-merge backups` namespace (see
 * sportspress-player-merge.php), rather than as `backups_list()`/
 * `backups_delete()` methods on SP_Merge_CLI: WP-CLI maps every public method
 * of a registered class to its own hyphenated leaf subcommand, so methods of
 * those names living on SP_Merge_CLI (which is separately registered at the
 * bare `sp-merge` namespace) would additionally surface as
 * `wp sp-merge backups-list` / `wp sp-merge backups-delete` — a second,
 * confusing spelling of the same destructive operation, alongside the
 * intended two-word `sp-merge backups list` / `sp-merge backups delete`
 * this class's own namespace registration produces. Naming the methods here
 * `list()`/`delete()` (valid method names since PHP 7, despite being
 * reserved words as bare functions) makes the one true spelling read
 * naturally: the class supplies the leaf verb, the registration supplies the
 * `sp-merge backups` namespace above it.
 */
/**
 * List and delete recent SportsPress Player Merge backups from the command
 * line.
 */
class SP_Merge_CLI_Backups {

	/**
	 * List recent merge backups.
	 *
	 * Everyone with edit_sp_players can list their own backups; seeing every
	 * user's backups is a step up in exposure (names and IDs of records other
	 * League Managers merged) and needs delete_sp_players, same as deleting one.
	 *
	 * ## OPTIONS
	 *
	 * [--owner=<id|login>]
	 * : List backups owned by another user instead of the current user.
	 * Ignored when --all-users is passed. Targeting anyone else requires the
	 * delete_sp_players capability.
	 *
	 * [--all-users]
	 * : List backups for every user, including which user owns each one.
	 * Requires the delete_sp_players capability.
	 *
	 * [--status=<status>]
	 * : Only list backups with this status (e.g. active, pending, failed, reverted).
	 * Applied before --limit, so --status=failed --limit=10 returns the 10
	 * newest failed backups, not the failed ones among the 10 newest overall.
	 *
	 * [--limit=<n>]
	 * : Maximum number of backups to return.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp sp-merge backups list --all-users --status=active
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments: owner, all-users, status, limit, format.
	 */
	public function list( $args, $assoc_args ): void {
		$all_users    = isset( $assoc_args['all-users'] );
		$required_cap = $all_users ? 'delete_sp_players' : 'edit_sp_players';

		if ( ! current_user_can( $required_cap ) ) {
			\WP_CLI::error(
				$all_users
					? __( 'Only an Administrator (delete_sp_players) can list every user\'s backups.', 'sportspress-player-merge' )
					: __( 'Insufficient permissions.', 'sportspress-player-merge' )
			);
		}

		$user_id = $all_users ? null : SP_Merge_Validation::resolve_target_user( $assoc_args['owner'] ?? null );
		$status  = $assoc_args['status'] ?? null;
		$format  = $assoc_args['format'] ?? 'table';
		$admin   = new SP_Merge_Admin();

		// Pre-set so the catch below can hand control to WP_CLI::error() (which
		// exits the process) without leaving a possibly-undefined read after it.
		$backups = false;

		try {
			$backups = isset( $assoc_args['limit'] )
				? $admin->get_recent_backups( (int) $assoc_args['limit'], $user_id, $all_users, $status )
				: $admin->get_recent_backups( user_id: $user_id, all_users: $all_users, status: $status );
		} catch ( Throwable $e ) {
			// Throwable, not Exception: the rows being read carry decade-old
			// serialized payloads, and malformed data raises a TypeError, which is
			// not an Exception in PHP 8. Matches SP_Merge_Ajax::get_recent_backups().
			\WP_CLI::error( $e->getMessage() );
		}

		if ( false === $backups ) {
			\WP_CLI::error( __( 'Failed to retrieve backup data.', 'sportspress-player-merge' ) );
		}

		// --status is applied by get_recent_backups() itself now, in SQL, before
		// its own LIMIT — filtering the already-limited result set here instead
		// would return the matching subset of the N newest backups overall, not
		// the N newest matching backups, on --status=<x> --limit=<n>.

		// duplicate_names comes back from get_recent_backups() as a PHP array
		// (it is a JSON_EXTRACT()ed column, decoded); format_items() only knows
		// how to render a flat scalar per cell, so table/csv/yaml would otherwise
		// emit "Array to string conversion" warnings and silently drop the
		// column. json/yaml can carry the array as-is (yaml's stub here logs the
		// raw $items array rather than a rendered string, same as json), so only
		// flatten for the formats that actually need a scalar cell.
		//
		// The same pass also resolves 'date': --format=csv/json get the
		// machine-sortable value instead of --format=table's locale-translated,
		// human-friendly one, and 'date_machine' — never a real column — is
		// dropped either way.
		$backups = array_map(
			static function ( array $backup ) use ( $format ): array {
				if ( in_array( $format, array( 'csv', 'json' ), true ) ) {
					$backup['date'] = $backup['date_machine'];
				}
				unset( $backup['date_machine'] );

				if ( ! in_array( $format, array( 'json', 'yaml' ), true ) ) {
					$backup['duplicate_names'] = implode( ', ', (array) $backup['duplicate_names'] );
				}

				return $backup;
			},
			$backups
		);

		$fields = array( 'id', 'date', 'status', 'primary_name', 'duplicate_names' );

		// user_id only earns a column when it can vary across the printed rows:
		// a single-user listing is implicitly the caller's own, and repeating
		// that ID on every row would just be noise.
		if ( $all_users ) {
			array_splice( $fields, 1, 0, 'user_id' );
		}

		\WP_CLI\Utils\format_items( $format, $backups, $fields );
	}

	/**
	 * Delete one or more merge backups.
	 *
	 * A deleted backup is the only recovery path for its merge, so this always
	 * requires delete_sp_players — there is no lower "delete your own backup"
	 * tier the way merge/revert have a League Manager tier.
	 *
	 * ## OPTIONS
	 *
	 * <backup-id>...
	 * : Backup ID(s) to delete.
	 *
	 * [--owner=<id|login>]
	 * : Delete backup(s) owned by another user instead of the current user.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sp-merge backups delete merge_1700000000_abcd1234 --yes
	 *
	 * @param array $args       Positional arguments: backup ID(s).
	 * @param array $assoc_args Associative arguments: owner, yes.
	 */
	public function delete( $args, $assoc_args ): void {
		if ( ! current_user_can( 'delete_sp_players' ) ) {
			\WP_CLI::error( __( 'Insufficient permissions.', 'sportspress-player-merge' ) );
		}

		if ( empty( $args ) ) {
			\WP_CLI::error( __( 'Usage: wp sp-merge backups delete <backup-id>...', 'sportspress-player-merge' ) );
		}

		// The helper's own capability check always passes here — delete_sp_players
		// was already required above — but it still resolves --owner consistently
		// with every other subcommand, so it is reused rather than duplicated.
		$owner_id = SP_Merge_Validation::resolve_target_user( $assoc_args['owner'] ?? null );

		\WP_CLI::warning( __( 'Deleting a backup permanently removes the only recovery path for that merge.', 'sportspress-player-merge' ) );
		\WP_CLI::confirm(
			sprintf(
				/* translators: %d: number of backups to delete */
				__( 'Delete %d backup(s)? This cannot be undone.', 'sportspress-player-merge' ),
				count( $args )
			),
			$assoc_args
		);

		$deleted = 0;

		try {
			$deleted = ( new SP_Merge_Backup() )->delete_backups( $args, $owner_id );
		} catch ( Throwable $e ) {
			// As on list() above, and matching SP_Merge_Ajax::delete_backup().
			\WP_CLI::error( $e->getMessage() );
		}

		\WP_CLI::success(
			sprintf(
				/* translators: %d: number of backups deleted */
				__( '%d backup(s) deleted.', 'sportspress-player-merge' ),
				$deleted
			)
		);
	}
}
