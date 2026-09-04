<?php
/**
 * Standalone test harness for SP_Merge_CLI.
 *
 * Builds on lib-ajax-mocks.php (which itself builds on lib-backup-mocks.php),
 * reusing the roster/meta/wpdb mocks scan and preview already need, and adds
 * what is CLI-specific: a WP_CLI stub, a WP_CLI\Utils\format_items() stub,
 * get_user_by(), and a $wpdb replacement that answers the postmeta-scan queries
 * SP_Merge_Preview and SP_Merge_Validation::get_event_counts() issue directly
 * (lib-backup-mocks.php's SP_Test_WPDB only understands the backup table's own
 * queries, not those).
 *
 * `merge`/`revert`/`backups` drive the real SP_Merge_Backup and SP_Merge_Admin
 * classes (already loaded by lib-backup-mocks.php/this file) end to end rather
 * than a stand-in, per the house convention of testing the real merge pipeline
 * wherever practical. That needs a $wpdb that understands both the backup
 * table's own queries (SP_Test_WPDB, from lib-backup-mocks.php) and the
 * postmeta-scan queries above, so SP_Test_CLI_Backup_WPDB below extends
 * SP_Test_WPDB rather than duplicating it, adding only the scan patterns and
 * falling through to the parent for everything else — including every
 * DISTINCT-post-id query the merge/backup classes issue to rewire event and
 * list references, which this harness deliberately leaves unanswered (an empty
 * result, same as production hitting no matching rows): these tests are not
 * exercising that rewiring, only that a merge with nothing to rewire still
 * completes and produces a real backup.
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

	if ( ! function_exists( 'has_post_thumbnail' ) ) {
		/**
		 * Featured-image presence, backed by the same _thumbnail_id meta row a
		 * real install stores it in — merge_featured_image() reads exactly this.
		 *
		 * @param int $post_id Post ID.
		 * @return bool
		 */
		function has_post_thumbnail( $post_id ) {
			return '' !== get_post_meta( $post_id, '_thumbnail_id', true );
		}
	}

	if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
		/**
		 * @param int $post_id Post ID.
		 * @return int|string|false
		 */
		function get_post_thumbnail_id( $post_id ) {
			$id = get_post_meta( $post_id, '_thumbnail_id', true );
			return '' === $id ? false : $id;
		}
	}

	if ( ! function_exists( 'set_post_thumbnail' ) ) {
		/**
		 * @param int $post_id      Post ID.
		 * @param int $thumbnail_id Attachment ID.
		 * @return bool
		 */
		function set_post_thumbnail( $post_id, $thumbnail_id ) {
			update_post_meta( $post_id, '_thumbnail_id', $thumbnail_id );
			return true;
		}
	}

	if ( ! function_exists( 'update_object_term_cache' ) ) {
		/**
		 * No-op: lib-backup-mocks.php's get_posts()/get_post() mocks answer term
		 * lookups directly rather than through a real term cache, so priming one
		 * has nothing to do here. Needed because SP_Merge_Preview::generate_data()
		 * (like generate()) primes this cache before reading term data, and no
		 * existing mock lib defined the function until the CLI layer started
		 * calling generate_data() directly.
		 *
		 * @param int[]  $object_ids Object IDs.
		 * @param string $object_type Object type (e.g. 'sp_player').
		 * @return bool
		 */
		function update_object_term_cache( $object_ids, $object_type ) {
			return true;
		}
	}

	if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
		function wp_using_ext_object_cache() {
			return false;
		}
	}

	if ( ! function_exists( 'wp_cache_add' ) ) {
		function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
			return true;
		}
	}

	if ( ! function_exists( 'get_transient' ) ) {
		/**
		 * The merge lock is never held across two calls within one test, so a
		 * constant "not locked" is enough — acquire_lock()/release_lock() just
		 * need to not fatal.
		 *
		 * @param string $key Transient key.
		 * @return false
		 */
		function get_transient( $key ) {
			return false;
		}
	}

	if ( ! function_exists( 'set_transient' ) ) {
		function set_transient( $key, $value, $expiration = 0 ) {
			return true;
		}
	}

	if ( ! function_exists( 'do_action' ) ) {
		function do_action( $hook, ...$args ) {
			return null;
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
	 * $wpdb stand-in combining lib-backup-mocks.php's SP_Test_WPDB (the backup
	 * table, schema upgrade, and everything create_merge_backup()/revert()/
	 * mark_active() need) with the postmeta-scan query shapes SP_Merge_Preview
	 * and SP_Merge_Validation::get_event_counts() issue directly against
	 * $GLOBALS['sp_meta_rows'] — the one raw-query shape the backup mock does
	 * not answer. Every pattern this subclass does not recognize falls through
	 * to the parent, so backup-table queries keep working unchanged.
	 *
	 * Every event a test seeds is a row where meta_key = 'sp_player' and
	 * meta_value = the player ID: exactly how SportsPress stores event rosters,
	 * and how the real queries this stands in for are written.
	 */
	class SP_Test_CLI_Backup_WPDB extends \SP_Test_WPDB {

		/**
		 * Prepared statements this subclass has decoded, keyed the same way as
		 * the parent's own (private, and so unreadable from here) $preps array.
		 *
		 * @var array<int, array{sql: string, args: array}>
		 */
		private $cli_preps = array();

		public function prepare( $query, ...$args ) {
			// Let the parent do the real bookkeeping (its own get_*() fallbacks
			// depend on it); just also decode the same call for our own patterns.
			$wrapped = parent::prepare( $query, ...$args );

			if ( preg_match( '/^##PREP(\d+)##/', $wrapped, $m ) ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				$this->cli_preps[ (int) $m[1] ] = array(
					'sql'  => $query,
					'args' => array_values( $args ),
				);
			}

			return $wrapped;
		}

		private function cli_unpack( $query ) {
			if ( preg_match( '/^##PREP(\d+)##/', $query, $m ) && isset( $this->cli_preps[ (int) $m[1] ] ) ) {
				$prep = $this->cli_preps[ (int) $m[1] ];
				return array( $prep['sql'], $prep['args'] );
			}
			return array( $query, array() );
		}

		public function get_var( $query ) {
			list( $sql, $args ) = $this->cli_unpack( $query );

			if ( false !== strpos( $sql, 'SELECT COUNT(*)' ) && false !== strpos( $sql, "meta_key = 'sp_player'" ) ) {
				return (string) count( $this->matching_post_ids( (string) $args[0] ) );
			}

			return parent::get_var( $query );
		}

		public function get_col( $query ) {
			list( $sql, $args ) = $this->cli_unpack( $query );

			if ( 0 === strpos( $sql, 'SELECT post_id FROM' ) && false !== strpos( $sql, "meta_key = 'sp_player'" ) ) {
				return $this->matching_post_ids( (string) $args[0] );
			}

			return parent::get_col( $query );
		}

		public function get_results( $query ) {
			list( $sql, $args ) = $this->cli_unpack( $query );

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

			return parent::get_results( $query );
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

	$GLOBALS['wpdb'] = new SP_Test_CLI_Backup_WPDB();

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
	 * Mirrors sp_test_reset() from lib-backup-mocks.php (now also resetting the
	 * globals SP_Merge_Backup itself depends on — merge/revert/backups drive
	 * that class for real), but re-installs SP_Test_CLI_Backup_WPDB rather than
	 * SP_Test_WPDB so the postmeta-scan queries keep working after the reset.
	 */
	function sp_test_cli_reset(): void {
		$GLOBALS['wpdb']              = new SP_Test_CLI_Backup_WPDB();
		$GLOBALS['sp_meta_rows']      = array();
		$GLOBALS['sp_meta_next_id']   = 1000;
		$GLOBALS['sp_posts']          = array();
		$GLOBALS['sp_denied_caps']    = array();
		$GLOBALS['spm_cli_log']       = array();
		$GLOBALS['spm_cli_users']     = array();
		$GLOBALS['sp_options']        = array();
		$GLOBALS['sp_user_meta']      = array();
		$GLOBALS['sp_current_user']   = 7;
		$GLOBALS['sp_insert_post_id'] = null;
		$GLOBALS['sp_deleted_posts']  = array();
		$GLOBALS['sp_terms_set']      = array();
		sp_test_seed_roster( 0 );
	}

	// Loaded in production dependency order: SP_Merge_Preview calls into
	// SP_Merge_Processor, and SP_Merge_CLI/SP_Merge_CLI_Backups call into all of
	// these plus SP_Merge_Ajax/SP_Merge_Validation/SP_Merge_Backup (already
	// required by lib-ajax-mocks.php -> lib-backup-mocks.php) and SP_Merge_Admin.
	require_once dirname( __DIR__ ) . '/classes/class-sp-merge-name-matcher.php';
	require_once dirname( __DIR__ ) . '/classes/class-sp-merge-processor.php';
	require_once dirname( __DIR__ ) . '/classes/class-sp-merge-preview.php';
	require_once dirname( __DIR__ ) . '/classes/class-sp-merge-admin.php';
	require_once dirname( __DIR__ ) . '/classes/class-sp-merge-cli.php';
	require_once dirname( __DIR__ ) . '/classes/class-sp-merge-cli-backups.php';
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
