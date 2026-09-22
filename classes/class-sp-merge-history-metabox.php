<?php
/**
 * Merge History Meta Box
 *
 * Handles the read-only "Merge History" box on the sp_player edit screen.
 *
 * @package SportsPress_Player_Merge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SP_Merge_History_Metabox
 */
class SP_Merge_History_Metabox {

	/**
	 * Register the meta box, only on players that actually have history.
	 *
	 * A merge is written by SP_Merge_Processor::record_merge_history(); most
	 * players never absorb a duplicate, so the box stays off their edit screen
	 * entirely rather than rendering an empty "nothing merged here" box.
	 *
	 * @param string $post_type Current post type.
	 * @param object $post      Current post (WP_Post).
	 */
	public function register_meta_box( string $post_type, object $post ): void {
		if ( 'sp_player' !== $post_type ) {
			return;
		}

		if ( empty( get_post_meta( $post->ID, '_sp_merge_history', false ) ) ) {
			return;
		}

		add_meta_box(
			'sp-merge-history',
			__( 'Merge History', 'sportspress-player-merge' ),
			array( $this, 'render' ),
			'sp_player',
			'side',
			'low'
		);
	}

	/**
	 * Render the meta box body.
	 *
	 * Every value here — title, slug, duplicate ID — was captured from the
	 * duplicate at merge time and can contain arbitrary player-entered text, so
	 * everything is escaped on the way out same as the rest of this plugin's
	 * admin output.
	 *
	 * @param object $post Current player post (WP_Post).
	 */
	public function render( object $post ): void {
		$entries = get_post_meta( $post->ID, '_sp_merge_history', false );

		if ( empty( $entries ) ) {
			echo '<p>' . esc_html__( 'No merge history recorded.', 'sportspress-player-merge' ) . '</p>';
			return;
		}

		usort(
			$entries,
			static function ( $a, $b ) {
				$a_time = is_array( $a ) ? (string) ( $a['merged_at'] ?? '' ) : '';
				$b_time = is_array( $b ) ? (string) ( $b['merged_at'] ?? '' ) : '';
				return strcmp( $b_time, $a_time );
			}
		);

		echo '<ul class="sp-merge-history-list">';
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$title        = (string) ( $entry['title'] ?? '' );
			$duplicate_id = (int) ( $entry['duplicate_id'] ?? 0 );
			$merged_at    = (string) ( $entry['merged_at'] ?? '' );

			$when = $merged_at
				? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $merged_at )
				: __( 'unknown time', 'sportspress-player-merge' );

			printf(
				'<li>%1$s <span class="description">(#%2$d)</span><br><span class="description">%3$s</span></li>',
				esc_html( $title ),
				$duplicate_id,
				esc_html(
					sprintf(
						/* translators: %s: date and time the merge happened */
						__( 'Merged %s', 'sportspress-player-merge' ),
						$when
					)
				)
			);
		}
		echo '</ul>';
	}
}
