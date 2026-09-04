<?php
/**
 * Standalone test harness for SP_Merge_CLI.
 *
 * Builds on lib-ajax-mocks.php (which itself builds on lib-backup-mocks.php),
 * reusing the roster/meta/wpdb mocks scan and preview already need, and adds
 * only what is CLI-specific: a WP_CLI stub, a WP_CLI\Utils\format_items() stub,
 * get_user_by(), and a $wpdb replacement that answers the postmeta-scan queries
 * SP_Merge_Preview and SP_Merge_Validation::get_event_counts() issue directly
 * (lib-backup-mocks.php's SP_Test_WPDB only understands the backup table's own
 * queries, not those).
 *
 * Uses bracketed namespace blocks throughout — PHP does not allow mixing
 * bracketed and unbracketed namespace declarations in one file, and
 * \WP_CLI\Utils\format_items() has to live in a real WP_CLI\Utils namespace.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable

namespace {

	require_once __DIR__ . '/lib-ajax-mocks.php';

	if ( ! function_exists( 'absint' ) ) {
		function absint( $maybeint ) {
			return abs( (int) $maybeint );
		}
	}

	if ( ! function_exists( 'remove_accents' ) ) {
		function remove_accents( $text ) {
			return strtr(
				$text,
				array(
					'á' => 'a',
					'à' => 'a',
					'â' => 'a',
					'ä' => 'a',
					'é' => 'e',
					'è' => 'e',
					'ê' => 'e',
					'ë' => 'e',
					'í' => 'i',
					'î' => 'i',
					'ó' => 'o',
					'ô' => 'o',
					'ö' => 'o',
					'ú' => 'u',
					'ù' => 'u',
					'û' => 'u',
					'ç' => 'c',
					'ñ' => 'n',
				)
			);
		}
	}

	$GLOBALS['spm_cli_log']   = array();
	$GLOBALS['spm_cli_users'] = array();

	/**
	 * Look up a stub user by field/value, from a table tests seed directly.
	 *
	 * Needed by a later change (backups/merge subcommands resolve --user by
	 * login/email/ID); added now so it lives with the rest of the CLI mocks.
	 *
	 * @param string     $field Field name: 'id', 'login', 'email', etc.
	 * @param string|int $value Value to look up.
	 * @return object|false Stub user object with an ID property, or false.
	 */
	function get_user_by( $field, $value ) {
		$key = $field . ':' . $value;

		if ( isset( $GLOBALS['spm_cli_users'][ $key ] ) ) {
			return (object) array( 'ID' => $GLOBALS['spm_cli_users'][ $key ] );
		}

		return false;
	}

	/**
	 * Raised by the WP_CLI stub's error() in place of a real WP-CLI fatal, so
	 * tests can assert a command halted the way they assert on
	 * SP_Test_Json_Response for the AJAX layer.
	 */
	class SP_Test_CLI_Error extends \Exception {}

	/**
	 * Stand-in for the real WP_CLI class. Every output method appends to
	 * $GLOBALS['spm_cli_log'] instead of touching real stdout, so tests assert
	 * on structured entries rather than parsing terminal output.
	 */
	class WP_CLI {

		public static function log( $message ) {
			$GLOBALS['spm_cli_log'][] = array(
				'level'   => 'log',
				'message' => (string) $message,
			);
		}

		public static function warning( $message ) {
			$GLOBALS['spm_cli_log'][] = array(
				'level'   => 'warning',
				'message' => (string) $message,
			);
		}

		public static function success( $message ) {
			$GLOBALS['spm_cli_log'][] = array(
				'level'   => 'success',
				'message' => (string) $message,
			);
		}

		public static function error( $message ) {
			throw new SP_Test_CLI_Error( (string) $message );
		}

		public static function confirm( $question, $assoc_args = array() ) {
			if ( isset( $assoc_args['yes'] ) ) {
				return true;
			}

			// An un-mocked interactive prompt in a test must fail loudly, not
			// hang or silently return false.
			throw new SP_Test_CLI_Error( 'confirm() called without --yes in tests: ' . $question );
		}

		public static function add_command( $name, $class, $args = array() ) {
			// No-op: tests instantiate SP_Merge_CLI and call its methods directly.
		}
	}

	/**
	 * $wpdb stand-in for the postmeta-scan queries SP_Merge_Preview and
	 * SP_Merge_Validation::get_event_counts() issue directly against
	 * $GLOBALS['sp_meta_rows'] — the same meta store get_post_meta()/
	 * sp_test_add_meta() already use. lib-backup-mocks.php's SP_Test_WPDB does
	 * not answer these query shapes (it only knows the backup table's own
	 * queries), so this replaces $GLOBALS['wpdb'] rather than extending it.
	 *
	 * Every event a test seeds is a row where meta_key = 'sp_player' and
	 * meta_value = the player ID: exactly how SportsPress stores event rosters,
	 * and how the real queries this stands in for are written.
	 */
	class SP_Test_CLI_WPDB {

		public $prefix   = 'wp_';
		public $postmeta = 'wp_postmeta';
		public $posts    = 'wp_posts';

		/** @var array[] Prepared statements, keyed by an opaque token. */
		private $preps = array();

		public function get_charset_collate() {
			return '';
		}

		public function esc_like( $text ) {
			return addcslashes( $text, '_%\\' );
		}

		public function prepare( $query, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			$this->preps[] = array(
				'sql'  => $query,
				'args' => array_values( $args ),
			);
			return '##PREP' . ( count( $this->preps ) - 1 ) . '##' . $query;
		}

		private function unpack( $query ) {
			if ( preg_match( '/^##PREP(\d+)##(.*)$/s', $query, $m ) ) {
				$prep = $this->preps[ (int) $m[1] ];
				return array( $prep['sql'], $prep['args'] );
			}
			return array( $query, array() );
		}

		public function get_var( $query ) {
			list( $sql, $args ) = $this->unpack( $query );

			if ( false !== strpos( $sql, 'SELECT COUNT(*)' ) && false !== strpos( $sql, "meta_key = 'sp_player'" ) ) {
				return (string) count( $this->matching_post_ids( (string) $args[0] ) );
			}

			return null;
		}

		public function get_col( $query ) {
			list( $sql, $args ) = $this->unpack( $query );

			if ( 0 === strpos( $sql, 'SELECT post_id FROM' ) && false !== strpos( $sql, "meta_key = 'sp_player'" ) ) {
				return $this->matching_post_ids( (string) $args[0] );
			}

			return array();
		}

		public function get_results( $query ) {
			list( $sql, $args ) = $this->unpack( $query );

			// SP_Merge_Validation::get_event_counts(): grouped counts for a batch of player IDs.
			if ( false !== strpos( $sql, 'GROUP BY pm.meta_value' ) ) {
				$wanted = array_map( 'strval', $args );
				$counts = array();

				foreach ( $GLOBALS['sp_meta_rows'] as $row ) {
					if ( 'sp_player' !== $row['meta_key'] || ! in_array( (string) $row['meta_value'], $wanted, true ) ) {
						continue;
					}
					$counts[ $row['meta_value'] ] = ( $counts[ $row['meta_value'] ] ?? 0 ) + 1;
				}

				$out = array();
				foreach ( $counts as $player_id => $cnt ) {
					$out[] = (object) array(
						'player_id' => $player_id,
						'cnt'       => $cnt,
					);
				}
				return $out;
			}

			return array();
		}

		/**
		 * Post IDs of every 'sp_player' meta row carrying the given value.
		 *
		 * @param string $value Player ID as stored in meta_value.
		 * @return int[] Matching post IDs (event IDs, in production use).
		 */
		private function matching_post_ids( string $value ): array {
			$ids = array();
			foreach ( $GLOBALS['sp_meta_rows'] as $row ) {
				if ( 'sp_player' === $row['meta_key'] && (string) $row['meta_value'] === $value ) {
					$ids[] = $row['post_id'];
				}
			}
			return $ids;
		}
	}

	$GLOBALS['wpdb'] = new SP_Test_CLI_WPDB();

	/**
	 * Seed an event: a post where meta_key 'sp_player' points at a player ID.
	 *
	 * Mirrors how SportsPress actually stores an event roster, one 'sp_player'
	 * meta row per player. Two calls with the same $event_id and different
	 * player IDs is exactly what a same-event collision looks like.
	 *
	 * @param int $event_id  Event post ID.
	 * @param int $player_id Player ID appearing in that event.
	 */
	function sp_test_seed_event( int $event_id, int $player_id ): void {
		sp_test_add_meta( $event_id, 'sp_player', (string) $player_id );
	}

	/**
	 * Reset the CLI test harness's mutable state between scenarios.
	 *
	 * Mirrors sp_test_reset() from lib-backup-mocks.php, but re-installs
	 * SP_Test_CLI_WPDB rather than SP_Test_WPDB so the postmeta-scan queries
	 * keep working after the reset.
	 */
	function sp_test_cli_reset(): void {
		$GLOBALS['wpdb']            = new SP_Test_CLI_WPDB();
		$GLOBALS['sp_meta_rows']    = array();
		$GLOBALS['sp_meta_next_id'] = 1000;
		$GLOBALS['sp_posts']        = array();
		$GLOBALS['sp_denied_caps']  = array();
		$GLOBALS['spm_cli_log']     = array();
		$GLOBALS['spm_cli_users']   = array();
		sp_test_seed_roster( 0 );
	}

	// Loaded in production dependency order: SP_Merge_Preview calls into
	// SP_Merge_Processor, and SP_Merge_CLI calls into all of these plus
	// SP_Merge_Ajax/SP_Merge_Validation, already required by lib-ajax-mocks.php.
	require_once dirname( __DIR__ ) . '/classes/class-sp-merge-name-matcher.php';
	require_once dirname( __DIR__ ) . '/classes/class-sp-merge-processor.php';
	require_once dirname( __DIR__ ) . '/classes/class-sp-merge-preview.php';
	require_once dirname( __DIR__ ) . '/classes/class-sp-merge-cli.php';
}

namespace WP_CLI\Utils {

	/**
	 * Stand-in for \WP_CLI\Utils\format_items(). Tests assert on the underlying
	 * data, not on ASCII table rendering, so anything but 'json' just tags the
	 * raw $items array with its format and appends it to the log.
	 *
	 * @param string $format Output format: table, csv, json, yaml.
	 * @param array  $items  Rows (or, for a nested payload, a single-element array).
	 * @param array  $fields Column/field names (unused by the stub).
	 */
	function format_items( $format, $items, $fields ) {
		if ( 'json' === $format ) {
			$GLOBALS['spm_cli_log'][] = array(
				'level'   => 'json',
				'message' => json_encode( $items ),
			);
			return;
		}

		$GLOBALS['spm_cli_log'][] = array(
			'level'   => $format,
			'message' => $items,
		);
	}
}
