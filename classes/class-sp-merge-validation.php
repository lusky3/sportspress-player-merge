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
