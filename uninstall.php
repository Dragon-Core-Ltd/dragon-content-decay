<?php
/**
 * Uninstall Dragon Content Decay
 *
 * Removes all plugin data when uninstalled through WordPress admin.
 *
 * @package DragonContentDecay
 */

namespace DragonContentDecay;

// Exit if not called by WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Respect the site owner's data: nothing is removed unless they explicitly
// opted in (the "Delete all data on uninstall" setting). Without the opt-in,
// tables and options survive so a reinstall picks up exactly where it left off.
if ( ! get_option( 'dragoncontentdecay_delete_data_on_uninstall' ) ) {
	return;
}

/**
 * Remove all plugin tables, options, transients and cron events.
 */
function dragoncontentdecay_uninstall(): void {
	global $wpdb;

	// Drop all plugin tables.
	$tables = array(
		$wpdb->prefix . 'dcd_analytics',
		$wpdb->prefix . 'dcd_scores',
	);

	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// Delete all plugin options: the current namespace-derived prefix and the
	// pre-1.0.1 dcd_ prefix (covers installs removed before the migration ran,
	// and the stored Google OAuth tokens under either prefix).
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dragoncontentdecay\_%' OR option_name LIKE 'dcd\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	// Clear scheduled cron events (current and pre-1.0.1 hook names).
	foreach ( array( 'daily_sync', 'weekly_digest', 'monthly_digest' ) as $dragoncontentdecay_hook ) {
		wp_clear_scheduled_hook( 'dragoncontentdecay_' . $dragoncontentdecay_hook );
		wp_clear_scheduled_hook( 'dcd_' . $dragoncontentdecay_hook );
	}

	// Delete any transients (both prefixes).
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%\_transient\_dragoncontentdecay\_%' OR option_name LIKE '%\_transient\_dcd\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%\_transient\_timeout\_dragoncontentdecay\_%' OR option_name LIKE '%\_transient\_timeout\_dcd\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

dragoncontentdecay_uninstall();
