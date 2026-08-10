<?php
/**
 * Minimal standalone test harness for SP_Merge_Processor.
 *
 * No PHPUnit, no Composer, no WordPress. Every WordPress function the processor
 * touches is mocked against an in-memory meta store so the merge logic can be
 * driven directly and asserted on.
 *
 * Run a single test with `php tests/<file>.php`, or all of them with
 * `bash tests/run-all.sh`.
 *
 * @package SportsPress_Player_Merge
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Mocks must use the real WordPress function names.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress is not loaded; these globals are the harness state.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Mocks mirror documented WordPress signatures.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI harness output, never HTML.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI harness output, never HTML.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Single-file harness by design.
// phpcs:disable WordPress.Files.FileName -- Harness file, not a class file.
// phpcs:disable PEAR.NamingConventions.ValidClassName.Invalid -- Matches the $wpdb class name it stands in for.
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- Single-file harness by design.

define( 'ABSPATH', __DIR__ . '/' );

//
// Assertion helpers.
//

$GLOBALS['spm_pass'] = 0;
$GLOBALS['spm_fail'] = 0;

function spm_assert( bool $condition, string $label ): void {
	if ( $condition ) {
		++$GLOBALS['spm_pass'];
		echo "  PASS  {$label}\n";
		return;
	}

	++$GLOBALS['spm_fail'];
	echo "  FAIL  {$label}\n";
}

function spm_assert_equals( $expected, $actual, string $label ): void {
	if ( $expected === $actual ) {
		++$GLOBALS['spm_pass'];
		echo "  PASS  {$label}\n";
		return;
	}

	++$GLOBALS['spm_fail'];
	echo "  FAIL  {$label}\n";
	echo '        expected: ' . str_replace( "\n", '', var_export( $expected, true ) ) . "\n";
	echo '        actual:   ' . str_replace( "\n", '', var_export( $actual, true ) ) . "\n";
}

function spm_test_header( string $title ): void {
	echo "\n{$title}\n";
	echo str_repeat( '-', strlen( $title ) ) . "\n";
}

function spm_test_summary(): void {
	$pass = $GLOBALS['spm_pass'];
	$fail = $GLOBALS['spm_fail'];

	echo "\n{$pass} passed, {$fail} failed\n";
	exit( $fail > 0 ? 1 : 0 );
}

//
// In-memory state.
//

/**
 * Reset every mock store. Call at the top of each scenario.
 *
 * @param array $meta Initial meta store: post_id => key => array of values.
 */
function spm_reset( array $meta = array() ): void {
	$GLOBALS['spm_meta']        = $meta;
	$GLOBALS['spm_posts']       = array();
	$GLOBALS['spm_terms']       = array();
	$GLOBALS['spm_taxonomies']  = array();
	$GLOBALS['spm_transients']  = array();
	$GLOBALS['spm_cache_purge'] = array();
	$GLOBALS['spm_clean_post']  = array();
	$GLOBALS['spm_deleted']     = array();
	$GLOBALS['spm_throw']       = array();
	$GLOBALS['spm_backup_log']  = array();

	$GLOBALS['wpdb'] = new SPM_Test_wpdb();
}

/**
 * Arm a Throwable to be raised from a mocked WordPress function.
 *
 * Mirrors what happens on a real install when third-party code hooked into
 * add_post_meta / update_post_meta / wp_set_object_terms raises an Error part
 * way through the merge transaction.
 *
 * @param string $function  Mocked function name.
 * @param int    $post_id   Post ID that triggers the throw.
 * @param string $class_name Throwable class to raise.
 */
function spm_throw_on( string $function, int $post_id, string $class_name = 'TypeError' ): void {
	$GLOBALS['spm_throw'][ $function ][ $post_id ] = $class_name;
}

function spm_maybe_throw( string $function, int $post_id ): void {
	if ( ! isset( $GLOBALS['spm_throw'][ $function ][ $post_id ] ) ) {
		return;
	}

	$class_name = $GLOBALS['spm_throw'][ $function ][ $post_id ];
	unset( $GLOBALS['spm_throw'][ $function ][ $post_id ] );

	throw new $class_name( "injected {$class_name} from {$function}() on post {$post_id}" );
}

//
// WordPress function mocks.
//

/**
 * Translation mock: returns the string untouched.
 *
 * @param string $text   Text to "translate".
 * @param string $domain Text domain.
 * @return string
 */
function __( $text, $domain = '' ) {
	return $text;
}

function get_post_meta( $post_id, $key = '', $single = false ) {
	$post_id = (int) $post_id;
	spm_maybe_throw( 'get_post_meta', $post_id );

	$all = $GLOBALS['spm_meta'][ $post_id ] ?? array();

	if ( '' === $key ) {
		return $all;
	}

	$values = $all[ $key ] ?? array();

	if ( $single ) {
		return $values ? reset( $values ) : '';
	}

	return array_values( $values );
}

function add_post_meta( $post_id, $key, $value, $unique = false ) {
	$post_id = (int) $post_id;
	spm_maybe_throw( 'add_post_meta', $post_id );

	if ( $unique && ! empty( $GLOBALS['spm_meta'][ $post_id ][ $key ] ) ) {
		return false;
	}

	$GLOBALS['spm_meta'][ $post_id ][ $key ][] = $value;

	return true;
}

function update_post_meta( $post_id, $key, $value ) {
	$post_id = (int) $post_id;
	spm_maybe_throw( 'update_post_meta', $post_id );

	$GLOBALS['spm_meta'][ $post_id ][ $key ] = array( $value );

	return true;
}

function delete_post_meta( $post_id, $key, $value = '' ) {
	$post_id = (int) $post_id;
	unset( $GLOBALS['spm_meta'][ $post_id ][ $key ] );

	return true;
}

function update_postmeta_cache( $post_ids ) {
	return true;
}

function get_post( $post_id ) {
	$post_id = (int) $post_id;

	return $GLOBALS['spm_posts'][ $post_id ] ?? null;
}

function wp_delete_post( $post_id, $force = false ) {
	$post_id                    = (int) $post_id;
	$GLOBALS['spm_deleted'][]   = $post_id;
	unset( $GLOBALS['spm_posts'][ $post_id ] );

	return true;
}

function has_post_thumbnail( $post_id ) {
	return ! empty( $GLOBALS['spm_meta'][ (int) $post_id ]['_thumbnail_id'] );
}

function get_post_thumbnail_id( $post_id ) {
	$values = $GLOBALS['spm_meta'][ (int) $post_id ]['_thumbnail_id'] ?? array();

	return $values ? reset( $values ) : false;
}

function set_post_thumbnail( $post_id, $thumbnail_id ) {
	$GLOBALS['spm_meta'][ (int) $post_id ]['_thumbnail_id'] = array( $thumbnail_id );

	return true;
}

function get_object_taxonomies( $object_type ) {
	return $GLOBALS['spm_taxonomies'];
}

function wp_get_object_terms( $object_id, $taxonomy, $args = array() ) {
	return $GLOBALS['spm_terms'][ (int) $object_id ][ $taxonomy ] ?? array();
}

function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {
	spm_maybe_throw( 'wp_set_object_terms', (int) $object_id );

	$GLOBALS['spm_terms'][ (int) $object_id ][ $taxonomy ] = $terms;

	return $terms;
}

function is_wp_error( $thing ) {
	return false;
}

function clean_post_cache( $post_id ) {
	$GLOBALS['spm_clean_post'][] = (int) $post_id;
}

function wp_cache_delete( $key, $group = '' ) {
	$GLOBALS['spm_cache_purge'][] = $group . ':' . $key;

	return true;
}

function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
	return true;
}

function wp_using_ext_object_cache() {
	return false;
}

function get_transient( $key ) {
	return $GLOBALS['spm_transients'][ $key ] ?? false;
}

function set_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['spm_transients'][ $key ] = $value;

	return true;
}

function delete_transient( $key ) {
	unset( $GLOBALS['spm_transients'][ $key ] );

	return true;
}

function get_current_user_id() {
	return 1;
}

function do_action( $hook, ...$args ) {
	return null;
}

/**
 * Stand-in for the global $wpdb.
 */
class SPM_Test_wpdb {

	/**
	 * Post meta table name.
	 *
	 * @var string
	 */
	public $postmeta = 'wp_postmeta';

	/**
	 * Posts table name.
	 *
	 * @var string
	 */
	public $posts = 'wp_posts';

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Every raw query string passed to query().
	 *
	 * @var string[]
	 */
	public $queries = array();

	/**
	 * FIFO of results handed back by successive get_col() calls.
	 *
	 * @var array[]
	 */
	public $col_queue = array();

	/**
	 * Every update() call, for assertions.
	 *
	 * @var array[]
	 */
	public $updates = array();

	public function query( $sql ) {
		$this->queries[] = $sql;

		return true;
	}

	public function prepare( $sql, ...$args ) {
		return $sql;
	}

	public function get_col( $sql ) {
		return array_shift( $this->col_queue ) ?? array();
	}

	public function get_results( $sql ) {
		return array();
	}

	public function get_row( $sql ) {
		return null;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$this->updates[] = array(
			'table' => $table,
			'data'  => $data,
			'where' => $where,
		);

		return 1;
	}

	public function insert( $table, $data, $format = null ) {
		return 1;
	}

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}
}

//
// SP_Merge_Backup stub, implementing the shared status contract only. The real
// class lives in classes/class-sp-merge-backup.php and is deliberately NOT
// loaded, so these tests assert the processor's use of the contract rather than
// the backup implementation.
//

define( 'SPM_TEST_BACKUP_ID', 'merge_1754700000_abcd1234' );

if ( defined( 'SPM_LEGACY_BACKUP' ) && SPM_LEGACY_BACKUP ) {

	/**
	 * Pre-contract backup class: no mark_failed() / mark_active().
	 * Used to prove the processor's method_exists() guards degrade safely.
	 */
	class SP_Merge_Backup {

		public function create_merge_backup( int $primary_id, array $duplicate_ids ): string {
			$GLOBALS['spm_backup_log'][] = array( 'create_merge_backup', SPM_TEST_BACKUP_ID );

			return SPM_TEST_BACKUP_ID;
		}

		public function delete_backup( string $backup_id ): bool {
			$GLOBALS['spm_backup_log'][] = array( 'delete_backup', $backup_id );

			return true;
		}
	}
} else {

	/**
	 * Backup stub implementing the shared status contract.
	 */
	class SP_Merge_Backup {

		public function create_merge_backup( int $primary_id, array $duplicate_ids ): string {
			$GLOBALS['spm_backup_log'][] = array( 'create_merge_backup', SPM_TEST_BACKUP_ID );

			return SPM_TEST_BACKUP_ID;
		}

		public function delete_backup( string $backup_id ): bool {
			$GLOBALS['spm_backup_log'][] = array( 'delete_backup', $backup_id );

			return true;
		}

		public function mark_failed( string $backup_id ): bool {
			$GLOBALS['spm_backup_log'][] = array( 'mark_failed', $backup_id );

			return true;
		}

		public function mark_active( string $backup_id ): bool {
			$GLOBALS['spm_backup_log'][] = array( 'mark_active', $backup_id );

			return true;
		}
	}
}

/**
 * Names of the backup methods the processor called, in order.
 *
 * @return string[]
 */
function spm_backup_calls(): array {
	return array_map(
		static function ( $entry ) {
			return $entry[0];
		},
		$GLOBALS['spm_backup_log']
	);
}

/**
 * Invoke a private method on the processor.
 *
 * @param SP_Merge_Processor $processor Processor instance.
 * @param string             $method    Method name.
 * @param mixed              ...$args   Arguments.
 * @return mixed
 */
function spm_invoke( SP_Merge_Processor $processor, string $method, ...$args ) {
	$reflection = new ReflectionMethod( SP_Merge_Processor::class, $method );
	$reflection->setAccessible( true );

	return $reflection->invokeArgs( $processor, $args );
}

spm_reset();

require_once dirname( __DIR__ ) . '/classes/class-sp-merge-processor.php';
