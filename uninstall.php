<?php
/**
 * Uninstall Dragon Content Decay
 *
 * Removes all plugin data when uninstalled through WordPress admin.
 *
 * @package DragonContentDecay
 */

// Exit if not called by WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop all plugin tables.
$tables = [
	$wpdb->prefix . 'dcd_analytics',
	$wpdb->prefix . 'dcd_scores',
];

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// Delete all plugin options.
$options = [
	'dcd_db_version',
	'dcd_google_client_id',
	'dcd_google_client_secret',
	'dcd_google_access_token',
	'dcd_google_refresh_token',
	'dcd_google_property_id',
	'dcd_decay_threshold_warning',
	'dcd_decay_threshold_critical',
	'dcd_comparison_period_days',
	'dcd_min_sessions_threshold',
	'dcd_post_types',
	'dcd_email_notifications_enabled',
	'dcd_email_recipients',
	'dcd_last_sync',
];

foreach ( $options as $option ) {
	delete_option( $option );
}

// Delete any remaining dcd_ options.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dcd\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Clear scheduled cron events.
wp_clear_scheduled_hook( 'dcd_daily_sync' );
wp_clear_scheduled_hook( 'dcd_weekly_digest' );

// Delete any transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%\_transient\_dcd\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%\_transient\_timeout\_dcd\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
