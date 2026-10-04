<?php
/**
 * Stand-in for wp-admin/includes/upgrade.php (ABSPATH is the tests directory).
 *
 * @package DragonContentDecay
 */

// Every SQL statement handed to dbDelta().
$GLOBALS['dragoncontentdecay_test_dbdelta'] = array();

if ( ! function_exists( 'dbDelta' ) ) {
	/**
	 * Core creates or alters the tables and returns a list of what it did,
	 * whether or not each statement worked.
	 *
	 * @param string|string[] $queries SQL.
	 * @param bool            $execute Whether to run it.
	 * @return array
	 */
	function dbDelta( $queries = '', $execute = true ) {
		unset( $execute );
		$GLOBALS['dragoncontentdecay_test_dbdelta'][] = $queries;
		return array();
	}
}
