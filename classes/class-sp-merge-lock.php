<?php
/**
 * Shared Merge Lock Class
 *
 * Holds the one lock that serializes every operation which rewrites merged
 * player data — a merge (SP_Merge_Processor::execute_merge()) and a revert
 * (SP_Merge_Backup::revert()) — so the two can never run against the same rows
 * at the same time.
 *
 * @package SportsPress_Player_Merge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SP_Merge_Lock
 *
 * The key, the 300-second timeout and the object-cache-vs-transient split are
 * exactly what SP_Merge_Processor carried privately before this class existed;
 * nothing about the lock's behaviour changed when it moved here, only who can
 * reach it. `wp sp-merge batch` runs unattended for a long time while
 * `wp sp-merge revert` is trivially scriptable from a second shell, so a revert
 * restoring event meta while a merge rewrites the same rows is no longer the
 * theoretical race it was when one human with one browser tab was the only
 * caller.
 */
class SP_Merge_Lock {

	/**
	 * Transient (or object-cache) key the lock is held under.
	 *
	 * @var string
	 */
	public const LOCK_KEY = 'sp_merge_lock';

	/**
	 * Seconds before a lock left behind by a fatal expires on its own.
	 *
	 * @var int
	 */
	private const LOCK_TIMEOUT = 300;

	/**
	 * Acquire the merge lock.
	 *
	 * @return bool True when this caller now holds the lock.
	 */
	public static function acquire(): bool {
		if ( wp_using_ext_object_cache() ) {
			return wp_cache_add( self::LOCK_KEY, get_current_user_id(), 'sp_merge', self::LOCK_TIMEOUT );
		}

		if ( get_transient( self::LOCK_KEY ) ) {
			return false;
		}

		set_transient( self::LOCK_KEY, get_current_user_id(), self::LOCK_TIMEOUT );

		return true;
	}

	/**
	 * Release the merge lock.
	 */
	public static function release(): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( self::LOCK_KEY, 'sp_merge' );
		} else {
			delete_transient( self::LOCK_KEY );
		}
	}
}
