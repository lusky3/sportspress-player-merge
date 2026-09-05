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
	 * Hard ceiling on the number of players a single scan will load.
	 */
	private const MAX_SCAN_PLAYERS = 10000;

	/**
	 * Players fetched per scan page.
	 */
	private const SCAN_PAGE_SIZE = 500;

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
	 * Load every published player the duplicate scan should consider.
	 *
	 * Paged rather than a single capped query: `posts_per_page => 2000` with the
	 * default newest-first ordering silently dropped the oldest records on a
	 * roster larger than the cap, and those long-history records are exactly the
	 * ones most likely to be the correct survivor. Ordering by ID ascending also
	 * keeps the paging stable across batches.
	 *
	 * Shared by SP_Merge_Ajax::find_duplicates() and `wp sp-merge scan` so both
	 * entry points scan the same roster the same way — the CLI command no
	 * longer has to instantiate the AJAX transport class just to reach this.
	 *
	 * @return array{players: array, scanned: int, total: int, truncated: bool}
	 */
	public static function collect_scan_players(): array {
		$players = array();
		$loaded  = 0;

		while ( $loaded < self::MAX_SCAN_PLAYERS ) {
			$batch = get_posts(
				array(
					'post_type'      => 'sp_player',
					'posts_per_page' => self::SCAN_PAGE_SIZE,
					'offset'         => $loaded,
					'no_found_rows'  => true,
					'post_status'    => 'publish',
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);

			$batch_size = count( $batch );
			if ( 0 === $batch_size ) {
				break;
			}

			$players = array_merge( $players, $batch );
			$loaded += $batch_size;

			if ( $batch_size < self::SCAN_PAGE_SIZE ) {
				break;
			}
		}

		$players = array_slice( $players, 0, self::MAX_SCAN_PLAYERS );
		$scanned = count( $players );

		$counts = wp_count_posts( 'sp_player' );
		$total  = isset( $counts->publish ) ? (int) $counts->publish : $scanned;
		$total  = max( $total, $scanned );

		return array(
			'players'   => $players,
			'scanned'   => $scanned,
			'total'     => $total,
			'truncated' => $scanned < $total,
		);
	}

	/**
	 * Resolve a player's current team.
	 *
	 * Walks sp_current_team in reverse — SportsPress appends new assignments,
	 * so the most recent one is the last entry — skipping the reserved "0"
	 * team-totals sentinel until a value resolves to a live sp_team post.
	 *
	 * Shared by certainty_signals(), SP_Merge_Ajax's player search and
	 * SP_Merge_Preview's team-name lookup: three copies of this same walk had
	 * already diverged (only the preview copy guarded against a non-numeric
	 * sp_current_team value before casting it).
	 *
	 * @param int $player_id Player ID.
	 * @return array{id: int, name: string}|null Team id/name, or null when the player has no current team.
	 */
	public static function current_team( int $player_id ): ?array {
		$team_ids = (array) get_post_meta( $player_id, 'sp_current_team' );

		foreach ( array_reverse( $team_ids ) as $team_id ) {
			if ( $team_id && '0' !== $team_id && is_numeric( $team_id ) ) {
				$team_post = get_post( (int) $team_id );
				if ( $team_post && 'sp_team' === $team_post->post_type ) {
					return array(
						'id'   => (int) $team_post->ID,
						'name' => $team_post->post_title,
					);
				}
			}
		}

		return null;
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
		$current_team = self::current_team( $player_id );

		$positions = wp_get_post_terms( $player_id, 'sp_position', array( 'fields' => 'names' ) );
		$position  = is_array( $positions ) && ! empty( $positions ) ? implode( ', ', $positions ) : '';

		return array(
			'team'     => $current_team['name'] ?? '',
			'team_id'  => $current_team['id'] ?? 0,
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
		$shared_email     = self::shared_email( $members );
		$team_boost       = self::share_a_team( $members );
		$position_penalty = self::positions_differ( $members );

		// The group's own badge earns the email boost from the address being
		// shared at all; a member earns it only by actually holding that address.
		$certainty = self::adjust_score( (int) ( $group['certainty'] ?? 0 ), '' !== $shared_email, $team_boost, $position_penalty );

		// The same three signals are applied to each member's own score so the
		// per-member checkboxes (or CLI rows) and the group badge cannot tell
		// different stories.
		foreach ( $members as $index => $member ) {
			if ( null === ( $member['certainty'] ?? null ) ) {
				continue;
			}

			$members[ $index ]['certainty'] = self::adjust_score(
				(int) $member['certainty'],
				'' !== $shared_email && ( $member['email'] ?? '' ) === $shared_email,
				$team_boost,
				$position_penalty
			);
		}

		return array(
			'certainty' => $certainty,
			'members'   => $members,
		);
	}

	/**
	 * Move one score by whichever of the three signals apply to it.
	 *
	 * The order matters and is the order the signals are documented in: the
	 * boosts are capped at 100 as they are added, and only then can the penalty
	 * take the result down — so a penalised 100 lands on 80, not on the 100 a
	 * cap applied last would have produced.
	 *
	 * @param int  $score            Score to adjust.
	 * @param bool $email_boost      Whether the shared-email boost applies to this score.
	 * @param bool $team_boost       Whether the shared-team boost applies.
	 * @param bool $position_penalty Whether the differing-positions penalty applies.
	 * @return int
	 */
	private static function adjust_score( int $score, bool $email_boost, bool $team_boost, bool $position_penalty ): int {
		if ( $email_boost ) {
			$score = min( 100, $score + self::EMAIL_BOOST );
		}

		if ( $team_boost ) {
			$score = min( 100, $score + self::TEAM_BOOST );
		}

		if ( $position_penalty ) {
			$score = max( self::POSITION_PENALTY_FLOOR, $score - self::POSITION_PENALTY );
		}

		return $score;
	}

	/**
	 * The email address every member holding one holds, if they all hold the same.
	 *
	 * Needs at least two addresses to be worth anything: one member with an email
	 * and one without says nothing about whether they are the same person.
	 *
	 * @param array $members Members as passed to apply_certainty_adjustments().
	 * @return string The shared address, or '' when there is none.
	 */
	private static function shared_email( array $members ): string {
		$emails = array_filter( array_column( $members, 'email' ), 'strlen' );

		if ( count( $emails ) >= 2 && count( array_unique( $emails ) ) === 1 ) {
			return (string) reset( $emails );
		}

		return '';
	}

	/**
	 * Do all the members play for the same current team?
	 *
	 * Every member has to have one: a member with no current team is not
	 * corroboration, so the count of teams found must match the member count.
	 *
	 * @param array $members Members as passed to apply_certainty_adjustments().
	 * @return bool
	 */
	private static function share_a_team( array $members ): bool {
		$team_ids = array_filter( array_column( $members, 'team_id' ) );

		return ! empty( $team_ids ) && count( array_unique( $team_ids ) ) === 1 && count( $team_ids ) === count( $members );
	}

	/**
	 * Are the members listed at more than one position?
	 *
	 * The strongest cheap signal that two same-named records are two different
	 * people — and, like the email signal, it takes two stated positions to say
	 * anything at all.
	 *
	 * @param array $members Members as passed to apply_certainty_adjustments().
	 * @return bool
	 */
	private static function positions_differ( array $members ): bool {
		$positions = array_filter( array_column( $members, 'position' ), 'strlen' );

		return count( $positions ) >= 2 && count( array_unique( $positions ) ) > 1;
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
