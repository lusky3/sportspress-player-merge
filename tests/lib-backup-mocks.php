<?php
/**
 * Standalone test harness for SP_Merge_Backup.
 *
 * Mocks the handful of WordPress functions and the $wpdb methods the backup
 * manager touches, so the class can be exercised with plain `php tests/<file>.php`.
 * No PHPUnit, no composer.
 *
 * Set SP_MERGE_TEST_CLASS to point the harness at a different copy of the class
 * (used to prove the tests fail against the pre-fix code).
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'SP_MERGE_VERSION', '1.2.0' );
define( 'SP_MERGE_BACKUP_RETENTION_DAYS', 30 );

$GLOBALS['sp_test_failures']  = 0;
$GLOBALS['sp_test_checks']    = 0;
$GLOBALS['sp_meta_rows']      = array();
$GLOBALS['sp_meta_next_id']   = 1000;
$GLOBALS['sp_options']        = array();
$GLOBALS['sp_user_meta']      = array();
$GLOBALS['sp_current_user']   = 7;
$GLOBALS['sp_posts']          = array();
$GLOBALS['sp_insert_post_id'] = null;
$GLOBALS['sp_deleted_posts']  = array();
$GLOBALS['sp_terms_set']      = array();

/* -------------------------------------------------------------------------
 * Assertions
 * ---------------------------------------------------------------------- */

function sp_assert( bool $condition, string $label ): void {
	++$GLOBALS['sp_test_checks'];
	if ( $condition ) {
		echo "  ok   {$label}\n";
		return;
	}
	++$GLOBALS['sp_test_failures'];
	echo "  FAIL {$label}\n";
}

function sp_assert_same( $expected, $actual, string $label ): void {
	$ok = ( $expected === $actual );
	if ( ! $ok ) {
		$label .= ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')';
	}
	sp_assert( $ok, $label );
}

function sp_assert_contains( string $needle, string $haystack, string $label ): void {
	$ok = ( false !== strpos( $haystack, $needle ) );
	if ( ! $ok ) {
		$label .= ' (missing "' . $needle . '" in "' . $haystack . '")';
	}
	sp_assert( $ok, $label );
}

function sp_assert_not_contains( string $needle, string $haystack, string $label ): void {
	sp_assert( false === strpos( $haystack, $needle ), $label );
}

function sp_test_done(): void {
	$checks   = $GLOBALS['sp_test_checks'];
	$failures = $GLOBALS['sp_test_failures'];
	echo $failures ? "\n{$failures}/{$checks} checks FAILED\n" : "\n{$checks}/{$checks} checks passed\n";
	exit( $failures ? 1 : 0 );
}

/* -------------------------------------------------------------------------
 * WordPress function mocks
 * ---------------------------------------------------------------------- */

function __( $text, $domain = '' ) {
	return $text;
}

function esc_html__( $text, $domain = '' ) {
	return $text;
}

function get_current_user_id() {
	return $GLOBALS['sp_current_user'];
}

function get_user_meta( $user_id, $key, $single = false ) {
	return $GLOBALS['sp_user_meta'][ $key ] ?? '';
}

function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['sp_user_meta'][ $key ] = $value;
	return true;
}

function delete_user_meta( $user_id, $key ) {
	unset( $GLOBALS['sp_user_meta'][ $key ] );
	return true;
}

function get_option( $name, $default = false ) {
	return $GLOBALS['sp_options'][ $name ] ?? $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['sp_options'][ $name ] = $value;
	return true;
}

function wp_json_encode( $data ) {
	return json_encode( $data );
}

function is_serialized( $data ) {
	if ( ! is_string( $data ) || strlen( $data ) < 4 || ':' !== $data[1] ) {
		return false;
	}
	return (bool) preg_match( '/^[aOsbdi]:/', $data );
}

function maybe_serialize( $data ) {
	if ( is_array( $data ) || is_object( $data ) ) {
		return serialize( $data );
	}
	return $data;
}

function maybe_unserialize( $data ) {
	if ( is_string( $data ) && is_serialized( $data ) ) {
		return @unserialize( $data );
	}
	return $data;
}

function sp_test_add_meta( int $post_id, string $key, $value, ?int $meta_id = null ): int {
	$meta_id                              = $meta_id ?? $GLOBALS['sp_meta_next_id']++;
	$GLOBALS['sp_meta_rows'][ $meta_id ] = array(
		'post_id'    => $post_id,
		'meta_key'   => $key,
		'meta_value' => (string) maybe_serialize( $value ),
	);
	return $meta_id;
}

function get_post_meta( $post_id, $key = '', $single = false ) {
	$grouped = array();
	foreach ( $GLOBALS['sp_meta_rows'] as $row ) {
		if ( (int) $row['post_id'] !== (int) $post_id ) {
			continue;
		}
		$grouped[ $row['meta_key'] ][] = $row['meta_value'];
	}

	if ( '' === $key ) {
		return $grouped;
	}

	$values = $grouped[ $key ] ?? array();

	if ( $single ) {
		return count( $values ) ? maybe_unserialize( $values[0] ) : '';
	}

	return array_map( 'maybe_unserialize', $values );
}

function add_post_meta( $post_id, $key, $value, $unique = false ) {
	return sp_test_add_meta( (int) $post_id, (string) $key, $value );
}

function update_post_meta( $post_id, $key, $value ) {
	foreach ( $GLOBALS['sp_meta_rows'] as $meta_id => $row ) {
		if ( (int) $row['post_id'] === (int) $post_id && $row['meta_key'] === $key ) {
			unset( $GLOBALS['sp_meta_rows'][ $meta_id ] );
		}
	}
	return sp_test_add_meta( (int) $post_id, (string) $key, $value );
}

function delete_post_meta( $post_id, $key ) {
	foreach ( $GLOBALS['sp_meta_rows'] as $meta_id => $row ) {
		if ( (int) $row['post_id'] === (int) $post_id && $row['meta_key'] === $key ) {
			unset( $GLOBALS['sp_meta_rows'][ $meta_id ] );
		}
	}
	return true;
}

function update_postmeta_cache( $ids ) {
	return true;
}

function clean_post_cache( $id ) {}

function delete_transient( $key ) {
	return true;
}

function wp_cache_delete( $key, $group = '' ) {
	return true;
}

function current_time( $type ) {
	return gmdate( 'Y-m-d H:i:s' );
}

function get_the_title( $id ) {
	return 'Player ' . $id;
}

function mysql2date( $format, $date ) {
	return $date;
}

function wp_generate_password( $length = 12, $special = true ) {
	return substr( str_repeat( 'a1b2c3d4', 4 ), 0, $length );
}

function get_post( $id ) {
	return $GLOBALS['sp_posts'][ (int) $id ] ?? null;
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

class WP_Error {
	public $message;

	public function __construct( $code = '', $message = '' ) {
		$this->message = $message;
	}
}

function wp_insert_post( $args, $wp_error = false ) {
	return $GLOBALS['sp_insert_post_id'];
}

function wp_delete_post( $id, $force = false ) {
	$GLOBALS['sp_deleted_posts'][] = (int) $id;
	return true;
}

function wp_set_object_terms( $object_id, $terms, $taxonomy ) {
	$GLOBALS['sp_terms_set'][ $object_id ][ $taxonomy ] = $terms;
	return $terms;
}

function wp_get_object_terms( $object_id, $taxonomy, $args = array() ) {
	return array();
}

function get_object_taxonomies( $type ) {
	return array();
}

function wp_list_pluck( $list, $field ) {
	$out = array();
	foreach ( (array) $list as $item ) {
		$item = (array) $item;
		if ( isset( $item[ $field ] ) ) {
			$out[] = $item[ $field ];
		}
	}
	return $out;
}

/* -------------------------------------------------------------------------
 * $wpdb mock
 * ---------------------------------------------------------------------- */

/**
 * Minimal in-memory stand-in for wpdb, covering only the queries the backup
 * manager issues. prepare() stashes its arguments so handlers read real values
 * instead of re-parsing interpolated SQL.
 */
class SP_Test_WPDB {

	public $prefix   = 'wp_';
	public $postmeta = 'wp_postmeta';
	public $posts    = 'wp_posts';

	/** Backup table rows. */
	public $backups = array();

	/** Columns the fake backup table has. */
	public $columns = array( 'id', 'backup_id', 'user_id', 'backup_data', 'created_at', 'status', 'touched_posts', 'post_hashes' );

	/** Executed non-select statements, for assertions. */
	public $statements = array();

	private $preps    = array();
	private $next_id  = 1;

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

	public function insert( $table, $data, $formats = null ) {
		if ( 'wp_sp_merge_backups' !== $table ) {
			return false;
		}
		$data['id']      = $this->next_id++;
		$this->backups[] = $data;
		return 1;
	}

	public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
		if ( $this->postmeta === $table ) {
			$meta_id = (int) ( $where['meta_id'] ?? 0 );
			if ( isset( $GLOBALS['sp_meta_rows'][ $meta_id ] ) ) {
				$GLOBALS['sp_meta_rows'][ $meta_id ]['meta_value'] = (string) $data['meta_value'];
				return 1;
			}
			return 0;
		}

		$updated = 0;
		foreach ( $this->backups as $index => $row ) {
			$match = true;
			foreach ( $where as $column => $value ) {
				if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
					$match = false;
					break;
				}
			}
			if ( ! $match ) {
				continue;
			}
			foreach ( $data as $column => $value ) {
				$this->backups[ $index ][ $column ] = $value;
			}
			++$updated;
		}
		return $updated;
	}

	public function query( $query ) {
		list( $sql, $args ) = $this->unpack( $query );
		$this->statements[] = $sql;

		if ( preg_match( '/^(START TRANSACTION|COMMIT|ROLLBACK)/', $sql ) ) {
			return true;
		}

		if ( 0 === strpos( $sql, 'ALTER TABLE' ) ) {
			return true;
		}

		// UPDATE ... SET status = 'active', post_hashes = %s WHERE backup_id = %s AND user_id = %d AND status = pending.
		if ( false !== strpos( $sql, "SET status = 'active'" ) ) {
			$updated = 0;
			foreach ( $this->backups as $index => $row ) {
				$status = $row['status'] ?? 'active';
				if ( $row['backup_id'] === $args[1] && (int) $row['user_id'] === (int) $args[2] && 'pending' === $status ) {
					$this->backups[ $index ]['status']      = 'active';
					$this->backups[ $index ]['post_hashes'] = $args[0];
					++$updated;
				}
			}
			return $updated;
		}

		// DELETE FROM wp_postmeta WHERE post_id = %d AND meta_key NOT IN (...).
		if ( false !== strpos( $sql, 'DELETE FROM wp_postmeta' ) && false !== strpos( $sql, 'NOT IN' ) ) {
			$post_id = (int) array_shift( $args );
			$deleted = 0;
			foreach ( $GLOBALS['sp_meta_rows'] as $meta_id => $row ) {
				if ( (int) $row['post_id'] === $post_id && ! in_array( $row['meta_key'], $args, true ) ) {
					unset( $GLOBALS['sp_meta_rows'][ $meta_id ] );
					++$deleted;
				}
			}
			return $deleted;
		}

		// DELETE FROM wp_postmeta WHERE post_id = %d AND meta_key LIKE %s (pre-fix code path).
		if ( false !== strpos( $sql, 'DELETE FROM wp_postmeta' ) && false !== strpos( $sql, 'LIKE' ) ) {
			$post_id = (int) $args[0];
			$like    = str_replace( array( '\\_', '%' ), array( '_', '' ), (string) $args[1] );
			$deleted = 0;
			foreach ( $GLOBALS['sp_meta_rows'] as $meta_id => $row ) {
				if ( (int) $row['post_id'] === $post_id && 0 === strpos( $row['meta_key'], $like ) ) {
					unset( $GLOBALS['sp_meta_rows'][ $meta_id ] );
					++$deleted;
				}
			}
			return $deleted;
		}

		// DELETE FROM wp_sp_merge_backups WHERE backup_id IN (...) AND user_id = %d.
		if ( 0 === strpos( $sql, 'DELETE FROM wp_sp_merge_backups WHERE backup_id IN' ) ) {
			$user_id    = (int) array_pop( $args );
			$backup_ids = $args;
			$before     = count( $this->backups );

			$this->backups = array_values(
				array_filter(
					$this->backups,
					static function ( $row ) use ( $backup_ids, $user_id ) {
						return ! ( in_array( $row['backup_id'], $backup_ids, true ) && (int) $row['user_id'] === $user_id );
					}
				)
			);

			return $before - count( $this->backups );
		}

		if ( false !== strpos( $sql, 'DELETE FROM wp_sp_merge_backups' ) ) {
			return 0;
		}

		return 0;
	}

	public function get_var( $query ) {
		list( $sql, $args ) = $this->unpack( $query );

		if ( 0 === strpos( $sql, 'SHOW TABLES LIKE' ) ) {
			return $args[0];
		}

		if ( false !== strpos( $sql, "SELECT COALESCE(status, 'active')" ) ) {
			foreach ( $this->backups as $row ) {
				if ( $row['backup_id'] === $args[0] && (int) $row['user_id'] === (int) $args[1] ) {
					return $row['status'] ?? 'active';
				}
			}
			return null;
		}

		if ( false !== strpos( $sql, 'SELECT backup_data FROM wp_sp_merge_backups WHERE id' ) ) {
			foreach ( $this->backups as $row ) {
				if ( (int) $row['id'] === (int) $args[0] ) {
					return $row['backup_data'];
				}
			}
			return null;
		}

		return null;
	}

	public function get_col( $query ) {
		list( $sql, $args ) = $this->unpack( $query );

		if ( 0 === strpos( $sql, 'SHOW COLUMNS FROM' ) ) {
			return $this->columns;
		}

		return array();
	}

	public function get_row( $query ) {
		list( $sql, $args ) = $this->unpack( $query );

		if ( false !== strpos( $sql, 'FROM wp_sp_merge_backups' ) ) {
			$backup_id = $args[0];
			$user_id   = (int) $args[1];
			$statuses  = array_slice( $args, 2 );

			// Older revisions inlined the status filter instead of binding it.
			$excludes_reverted = ( false !== strpos( $sql, "!= 'reverted'" ) );

			foreach ( $this->backups as $row ) {
				$status = $row['status'] ?? 'active';
				if ( $row['backup_id'] !== $backup_id || (int) $row['user_id'] !== $user_id ) {
					continue;
				}
				if ( ! empty( $statuses ) && ! in_array( $status, $statuses, true ) ) {
					continue;
				}
				if ( $excludes_reverted && 'reverted' === $status ) {
					continue;
				}
				return (object) $row;
			}
		}

		return null;
	}

	public function get_results( $query ) {
		list( $sql, $args ) = $this->unpack( $query );

		// SP_Merge_Admin::get_recent_backups() — WHERE user_id is present unless
		// the caller asked for every owner.
		if ( false !== strpos( $sql, 'SELECT backup_id, user_id, created_at,' ) ) {
			if ( false !== strpos( $sql, 'WHERE user_id = %d' ) ) {
				$user_id = (int) array_shift( $args );
				$limit   = (int) array_shift( $args );
			} else {
				$user_id = null;
				$limit   = (int) array_shift( $args );
			}

			$rows = array_filter(
				$this->backups,
				static function ( $row ) use ( $user_id ) {
					return null === $user_id || (int) $row['user_id'] === $user_id;
				}
			);

			usort(
				$rows,
				static function ( $a, $b ) {
					return strcmp( (string) $b['created_at'], (string) $a['created_at'] );
				}
			);

			$rows = array_slice( $rows, 0, $limit );

			$out = array();
			foreach ( $rows as $row ) {
				$data = json_decode( (string) $row['backup_data'], true );
				$out[] = (object) array(
					'backup_id'       => $row['backup_id'],
					'user_id'         => $row['user_id'],
					'created_at'      => $row['created_at'],
					'status'          => $row['status'] ?? 'active',
					'primary_name'    => json_encode( $data['primary_name'] ?? null ),
					'duplicate_names' => json_encode( $data['duplicate_names'] ?? array() ),
				);
			}
			return $out;
		}

		if ( false !== strpos( $sql, 'SELECT id, backup_id, created_at, touched_posts' ) ) {
			$out = array();
			foreach ( $this->backups as $row ) {
				$status = $row['status'] ?? 'active';
				if ( (int) $row['id'] > (int) $args[0] && 'reverted' !== $status ) {
					$out[] = (object) array(
						'id'            => $row['id'],
						'backup_id'     => $row['backup_id'],
						'created_at'    => $row['created_at'],
						'touched_posts' => $row['touched_posts'] ?? null,
					);
				}
			}
			return $out;
		}

		if ( false !== strpos( $sql, 'SELECT meta_id, meta_value FROM wp_postmeta WHERE post_id IN' ) ) {
			$out = array();
			foreach ( $GLOBALS['sp_meta_rows'] as $meta_id => $row ) {
				if ( in_array( (int) $row['post_id'], array_map( 'intval', $args ), true ) ) {
					$out[] = (object) array(
						'meta_id'    => $meta_id,
						'meta_value' => $row['meta_value'],
					);
				}
			}
			return $out;
		}

		return array();
	}
}

$GLOBALS['wpdb'] = new SP_Test_WPDB();

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/**
 * Reset all mutable fixture state between scenarios.
 */
function sp_test_reset(): void {
	$GLOBALS['wpdb']              = new SP_Test_WPDB();
	$GLOBALS['sp_meta_rows']      = array();
	$GLOBALS['sp_meta_next_id']   = 1000;
	$GLOBALS['sp_options']        = array();
	$GLOBALS['sp_user_meta']      = array();
	$GLOBALS['sp_posts']          = array();
	$GLOBALS['sp_insert_post_id'] = null;
	$GLOBALS['sp_deleted_posts']  = array();
	$GLOBALS['sp_terms_set']      = array();
}

/**
 * Insert a backup row directly.
 *
 * @param string     $backup_id     Backup ID.
 * @param array      $data          Backup data payload.
 * @param string     $status        Row status.
 * @param array|null $touched       Touched post IDs, or null to leave unset.
 * @param array|null $post_hashes   Post-merge hashes, or null to leave unset.
 * @param int|null   $user_id       Owner.
 */
function sp_test_seed_backup( string $backup_id, array $data, string $status = 'active', ?array $touched = null, ?array $post_hashes = null, ?int $user_id = null ): void {
	$GLOBALS['wpdb']->insert(
		'wp_sp_merge_backups',
		array(
			'backup_id'     => $backup_id,
			'user_id'       => $user_id ?? $GLOBALS['sp_current_user'],
			'backup_data'   => json_encode( $data ),
			'created_at'    => '2026-08-01 10:00:00',
			'status'        => $status,
			'touched_posts' => null === $touched ? null : json_encode( $touched ),
			'post_hashes'   => null === $post_hashes ? null : json_encode( $post_hashes ),
		)
	);
}

/**
 * Call a private/protected method.
 *
 * @param object $object Target.
 * @param string $method Method name.
 * @param array  $args   Arguments.
 * @return mixed
 */
function sp_test_invoke( object $object, string $method, array $args = array() ) {
	$reflection = new ReflectionMethod( $object, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $object, $args );
}

$sp_class_file = getenv( 'SP_MERGE_TEST_CLASS' );
if ( ! $sp_class_file ) {
	$sp_class_file = dirname( __DIR__ ) . '/classes/class-sp-merge-backup.php';
}
require_once $sp_class_file;
