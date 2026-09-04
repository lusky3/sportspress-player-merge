<?php
/**
 * Shared Merge Validation Class
 *
 * Holds the selection-validation and survivor-warning logic shared by the
 * AJAX layer (SP_Merge_Ajax) and the WP-CLI layer, so both entry points
 * validate a merge selection and warn about survivor choice identically.
 *
 * @package SportsPress_Player_Merge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SP_Merge_Validation
 */
class SP_Merge_Validation {

	/**
	 * A duplicate carrying at least this multiple of the primary's event count
	 * makes the survivor choice worth questioning.
	 */
	private const HISTORY_WARNING_RATIO = 2;

	/**
	 * Certainty added when every member holds the same email address.
	 */
	private const EMAIL_BOOST = 20;

	/**
	 * Certainty added when every member plays for the same current team.
	 */
	private const TEAM_BOOST = 5;

	/**
	 * Certainty removed when members are listed at different positions.
	 */
	private const POSITION_PENALTY = 20;

	/**
	 * Floor the position penalty can never push a score below.
	 */
	private const POSITION_PENALTY_FLOOR = 50;

	/**
	 * Validate a primary/duplicate player selection.
	 *
	 * @param mixed $primary_id_raw    Raw primary player ID.
	 * @param array $duplicate_ids_raw Raw duplicate player IDs.
	 * @return array{valid: bool, primary_id?: int, duplicate_ids?: int[], error?: string}
	 */
	public static function validate_merge_selection( $primary_id_raw, array $duplicate_ids_raw ): array {
		$primary_id = absint( $primary_id_raw );

		$duplicate_ids = array_unique( array_map( 'absint', $duplicate_ids_raw ) );
		$duplicate_ids = array_values( array_filter( $duplicate_ids ) );

		if ( ! $primary_id || empty( $duplicate_ids ) ) {
			return array(
				'valid' => false,
				'error' => __( 'Invalid player selection', 'sportspress-player-merge' ),
			);
		}

		// Limit number of duplicates per merge.
		if ( count( $duplicate_ids ) > 10 ) {
			return array(
				'valid' => false,
				'error' => __( 'Maximum 10 duplicate players per merge operation.', 'sportspress-player-merge' ),
			);
		}

		// Prevent merging a player into itself.
		if ( in_array( $primary_id, $duplicate_ids, true ) ) {
			return array(
				'valid' => false,
				'error' => __( 'Primary player cannot also be a duplicate', 'sportspress-player-merge' ),
			);
		}

		$primary_post = get_post( $primary_id );
		if ( ! $primary_post || 'sp_player' !== $primary_post->post_type || 'publish' !== $primary_post->post_status ) {
			return array(
				'valid' => false,
				'error' => __( 'Primary player not found or not published', 'sportspress-player-merge' ),
			);
		}

		foreach ( $duplicate_ids as $dup_id ) {
			$dup_post = get_post( $dup_id );
			if ( ! $dup_post || 'sp_player' !== $dup_post->post_type || 'publish' !== $dup_post->post_status ) {
				return array(
					'valid' => false,
					'error' => __( 'One or more duplicate players not found or not published', 'sportspress-player-merge' ),
				);
			}
		}

		return array(
			'valid'         => true,
			'primary_id'    => $primary_id,
			'duplicate_ids' => $duplicate_ids,
		);
	}

	/**
	 * Read the signals a group's certainty is adjusted by, for one player.
	 *
	 * Shared by the AJAX duplicate scan and `wp sp-merge scan` so the score the
	 * browser shows and the score `--min-certainty` filters on are computed from
	 * exactly the same three inputs, read exactly the same way.
	 *
	 * @param int $player_id Player ID.
	 * @return array{team: string, team_id: int, position: string, email: string}
	 */
	public static function certainty_signals( int $player_id ): array {
		$team    = '';
		$team_id = 0;

		$team_ids = (array) get_post_meta( $player_id, 'sp_current_team' );
		foreach ( array_reverse( $team_ids ) as $tid ) {
			if ( $tid && '0' !== $tid ) {
				$team_post = get_post( (int) $tid );
				if ( $team_post && 'sp_team' === $team_post->post_type ) {
					$team    = $team_post->post_title;
					$team_id = (int) $team_post->ID;
					break;
				}
			}
		}

		$positions = wp_get_post_terms( $player_id, 'sp_position', array( 'fields' => 'names' ) );
		$position  = is_array( $positions ) && ! empty( $positions ) ? implode( ', ', $positions ) : '';

		return array(
			'team'     => $team,
			'team_id'  => $team_id,
			'position' => $position,
			'email'    => get_post_meta( $player_id, 'spt_email', true ) ?: '',
		);
	}

	/**
	 * Apply the safety adjustments to a matched group's raw matcher score.
	 *
	 * The fuzzy matcher scores names and nothing else. These three signals are
	 * what turns that into a score worth acting on: a shared email address is
	 * near-proof, a shared current team is corroboration, and two different
	 * positions is the strongest cheap signal that two same-named records are two
	 * different people. The penalty matters most — a pair the browser demotes to
	 * 70% ("low confidence", left unchecked by default) must not read as 90% to
	 * `wp sp-merge scan --min-certainty=90`, on a tool that permanently deletes
	 * posts.
	 *
	 * @param array $group   Group from SP_Merge_Name_Matcher::find_groups(); only its `certainty` is read.
	 * @param array $members One entry per group member, each
	 *                       array{email: string, position: string, team_id: int, certainty: int|null}.
	 *                       A null member certainty means the matcher supplied none and is left null.
	 * @return array{certainty: int, members: array} The adjusted group certainty, and $members with
	 *                                               each non-null certainty adjusted by the same signals.
	 */
	public static function apply_certainty_adjustments( array $group, array $members ): array {
		$certainty = (int) ( $group['certainty'] ?? 0 );

		// Boost certainty when players share the same email address. Only the
		// members actually holding that address earn the per-member boost.
		$emails       = array_filter( array_column( $members, 'email' ), 'strlen' );
		$shared_email = '';
		if ( count( $emails ) >= 2 && count( array_unique( $emails ) ) === 1 ) {
			$shared_email = (string) reset( $emails );
			$certainty    = min( 100, $certainty + self::EMAIL_BOOST );
		}

		// Boost certainty when all players share the same team.
		$team_boost = false;
		$team_ids   = array_filter( array_column( $members, 'team_id' ) );
		if ( ! empty( $team_ids ) && count( array_unique( $team_ids ) ) === 1 && count( $team_ids ) === count( $members ) ) {
			$team_boost = true;
			$certainty  = min( 100, $certainty + self::TEAM_BOOST );
		}

		// Reduce certainty when players have different positions.
		$position_penalty = false;
		$all_positions    = array_filter( array_column( $members, 'position' ), 'strlen' );
		if ( count( $all_positions ) >= 2 && count( array_unique( $all_positions ) ) > 1 ) {
			$position_penalty = true;
			$certainty        = max( self::POSITION_PENALTY_FLOOR, $certainty - self::POSITION_PENALTY );
		}

		// Apply the same signals to each member's own score so the per-member
		// checkboxes (or CLI rows) and the group badge cannot tell different stories.
		foreach ( $members as $index => $member ) {
			if ( null === ( $member['certainty'] ?? null ) ) {
				continue;
			}

			$score = (int) $member['certainty'];

			if ( '' !== $shared_email && ( $member['email'] ?? '' ) === $shared_email ) {
				$score = min( 100, $score + self::EMAIL_BOOST );
			}
			if ( $team_boost ) {
				$score = min( 100, $score + self::TEAM_BOOST );
			}
			if ( $position_penalty ) {
				$score = max( self::POSITION_PENALTY_FLOOR, $score - self::POSITION_PENALTY );
			}

			$members[ $index ]['certainty'] = $score;
		}

		return array(
			'certainty' => $certainty,
			'members'   => $members,
		);
	}

	/**
	 * Count the events each player appears in.
	 *
	 * @param int[] $player_ids Player IDs.
	 * @return array<int, int> Player ID => event count, zero-filled.
	 */
	public static function get_event_counts( array $player_ids ): array {
		global $wpdb;

		$player_ids = array_values( array_unique( array_filter( array_map( 'intval', $player_ids ) ) ) );
		if ( empty( $player_ids ) ) {
			return array();
		}

		$counts       = array_fill_keys( $player_ids, 0 );
		$placeholders = implode( ',', array_fill( 0, count( $player_ids ), '%s' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT pm.meta_value AS player_id, COUNT(*) AS cnt FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.post_type = 'sp_event' AND pm.meta_key = 'sp_player' AND pm.meta_value IN ($placeholders) GROUP BY pm.meta_value",
				...$player_ids
			)
		);

		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row->player_id ] = (int) $row->cnt;
		}

		return $counts;
	}

	/**
	 * List the events each player appears in.
	 *
	 * The counting sibling above cannot answer "which events do these two players
	 * share", which is what the preview's same-event collision warning needs, so
	 * this returns the IDs themselves — restricted to `sp_event` by the same JOIN,
	 * because `sp_list` posts carry `sp_player` meta too and two players merely
	 * sharing a squad list are not colliding in an event.
	 *
	 * @param int[] $player_ids Player IDs.
	 * @return array<int, int[]> Player ID => event IDs, zero-filled with empty arrays.
	 */
	public static function get_event_ids( array $player_ids ): array {
		global $wpdb;

		$player_ids = array_values( array_unique( array_filter( array_map( 'intval', $player_ids ) ) ) );
		if ( empty( $player_ids ) ) {
			return array();
		}

		$events       = array_fill_keys( $player_ids, array() );
		$placeholders = implode( ',', array_fill( 0, count( $player_ids ), '%s' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT pm.meta_value AS player_id, pm.post_id AS event_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.post_type = 'sp_event' AND pm.meta_key = 'sp_player' AND pm.meta_value IN ($placeholders)",
				...$player_ids
			)
		);

		foreach ( (array) $rows as $row ) {
			$player_id = (int) $row->player_id;
			if ( ! isset( $events[ $player_id ] ) ) {
				continue;
			}
			$events[ $player_id ][] = (int) $row->event_id;
		}

		return $events;
	}

	/**
	 * The operator-facing success message for a completed revert.
	 *
	 * Shared by SP_Merge_Ajax::revert_merge() and SP_Merge_CLI::revert() so
	 * there is exactly one translatable definition of this wording, rather than
	 * two hand-copied literals that had to be kept in sync by hand whenever it
	 * changed.
	 *
	 * @param bool $forced Whether this revert used the values-changed override.
	 * @return string
	 */
	public static function revert_success_message( bool $forced ): string {
		return $forced
			? __( 'Merge reverted using the override. Values changed since the merge were discarded.', 'sportspress-player-merge' )
			: __( 'Merge reverted successfully', 'sportspress-player-merge' );
	}

	/**
	 * Resolve which user a WP-CLI subcommand should act on behalf of.
	 *
	 * Defaults to the current user. An explicit target is only permitted for a
	 * caller holding delete_sp_players — the same tier the AJAX layer requires
	 * for touching another user's backups at all — so a League Manager cannot
	 * use `--owner` to reach into an Administrator's (or another League
	 * Manager's) backups.
	 *
	 * Shared by SP_Merge_CLI::revert() and SP_Merge_CLI_Backups::list()/
	 * delete(): previously an identical private method duplicated byte-for-byte
	 * on both classes.
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
			\WP_CLI::error( sprintf( __( 'No user found matching "%s".', 'sportspress-player-merge' ), $user_arg ) );
		}

		$target_id = (int) $user->ID;

		if ( get_current_user_id() !== $target_id && ! current_user_can( 'delete_sp_players' ) ) {
			\WP_CLI::error( __( 'Only an Administrator (delete_sp_players) can act on another user\'s backups.', 'sportspress-player-merge' ) );
		}

		return $target_id;
	}

	/**
	 * Warn when the chosen survivor holds much less history than a duplicate.
	 *
	 * The merge keeps the primary and permanently deletes the duplicates, so
	 * picking the emptier record is the expensive mistake to make.
	 *
	 * @param int   $primary_id    Primary player ID.
	 * @param int[] $duplicate_ids Duplicate player IDs.
	 * @return string[] Operator-facing warnings; empty when the choice looks sound.
	 */
	public static function survivor_warnings( int $primary_id, array $duplicate_ids ): array {
		$counts        = self::get_event_counts( array_merge( array( $primary_id ), $duplicate_ids ) );
		$primary_count = $counts[ $primary_id ] ?? 0;
		$warnings      = array();

		foreach ( $duplicate_ids as $duplicate_id ) {
			$duplicate_count = $counts[ (int) $duplicate_id ] ?? 0;

			if ( $duplicate_count <= $primary_count ) {
				continue;
			}

			// "Substantially": the survivor has no history at all, or the
			// duplicate carries at least twice as much.
			if ( 0 !== $primary_count && $duplicate_count < $primary_count * self::HISTORY_WARNING_RATIO ) {
				continue;
			}

			$warnings[] = sprintf(
				/* translators: 1: duplicate player name, 2: duplicate event count, 3: primary player name, 4: primary event count */
				__( '%1$s appears in %2$d event(s) but is about to be deleted into %3$s, which appears in %4$d. The record with the longer history is usually the one to keep.', 'sportspress-player-merge' ),
				get_the_title( (int) $duplicate_id ),
				$duplicate_count,
				get_the_title( $primary_id ),
				$primary_count
			);
		}

		return $warnings;
	}
}
