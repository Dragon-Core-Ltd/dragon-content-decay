<?php
/**
 * Main Plugin Class
 *
 * @package DragonContentDecay
 */

namespace DragonContentDecay;

defined( 'ABSPATH' ) || exit;

class Plugin {

	/**
	 * Singleton instance
	 */
	private static ?Plugin $instance = null;

	/**
	 * Admin instance
	 */
	private ?Admin $admin = null;

	/**
	 * OAuth instance
	 */
	private ?OAuth $oauth = null;

	/**
	 * GA4 API instance
	 */
	private ?API_GA4 $api_ga4 = null;
	private ?API_GSC $api_gsc = null;

	/**
	 * Analyzer instance
	 */
	private ?Analyzer $analyzer = null;

	/**
	 * Scheduler instance
	 */
	private ?Scheduler $scheduler = null;

	/**
	 * Notifications instance
	 */
	private ?Notifications $notifications = null;

	/**
	 * Get singleton instance
	 */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		self::migrate_legacy_prefix();
		add_action( 'init', array( __CLASS__, 'migrate_legacy_cron' ) );
		self::maybe_upgrade();
		$this->init_components();
	}

	/**
	 * Run schema upgrades when the plugin is updated in place.
	 *
	 * create_tables() only runs on activation, so a plugin *update* (which does
	 * not re-activate) would never pick up new columns, e.g. the Search Console
	 * columns added to dcd_scores. The stored db version is compared on every load
	 * (a cheap autoloaded-option read) and dbDelta (idempotent) is re-run only
	 * when it differs, so the ALTER runs once per update and is in place before any
	 * sync writes a score row.
	 */
	private static function maybe_upgrade(): void {
		if ( get_option( 'dragoncontentdecay_db_version' ) === DRAGONCONTENTDECAY_VERSION ) {
			return;
		}

		self::create_tables();
	}

	/**
	 * Option names (without prefix) the pre-1.0.1 releases stored under dcd_.
	 * uninstall.php lists the same names.
	 */
	private const LEGACY_OPTIONS = array(
		'comparison_period',
		'decay_threshold',
		'email_frequency',
		'ga4_property_id',
		'google_client_id',
		'google_client_secret',
		'google_tokens',
		'last_sync',
		'last_sync_count',
		'post_types',
	);

	/**
	 * Move options and scheduled events off the pre-1.0.1 three-letter (dcd_)
	 * prefix.
	 *
	 * The prefix was renamed to the namespace-derived `dragoncontentdecay_` to
	 * satisfy the WordPress.org uniqueness rule. Option values (including the
	 * stored Google OAuth tokens, so the GA4 connection survives) are carried
	 * across once, and the sync/digest cron events are re-pointed at the renamed
	 * hooks. The analytics and scores tables keep their names (matched by exact
	 * name), so cached report data is untouched.
	 *
	 * It runs once per site: a stored flag stops it from reading the legacy
	 * names on every request, or from copying a later dcd_ option over the
	 * live settings.
	 */
	private static function migrate_legacy_prefix(): void {
		if ( get_option( 'dragoncontentdecay_legacy_migrated' ) ) {
			return;
		}

		// db_version is a schema marker managed by activation/create_tables, not
		// user data; just drop the legacy copy.
		delete_option( 'dcd_db_version' );

		// Copy each legacy value onto the new name, then remove the legacy copy -
		// per option, so the delete only ever runs after a successful copy. (A
		// single shared guard would delete on a deactivate/reactivate cycle, where
		// activation re-stamps the new db_version before the copy could run.)
		foreach ( self::LEGACY_OPTIONS as $name ) {
			$legacy = get_option( 'dcd_' . $name, null );
			if ( null !== $legacy ) {
				update_option( 'dragoncontentdecay_' . $name, $legacy );
				delete_option( 'dcd_' . $name );
			}
		}

		update_option( 'dragoncontentdecay_legacy_migrated', 1 );
	}

	/**
	 * Re-point pre-1.0.1 (dcd_) cron events at the renamed hooks. Runs on
	 * init: scheduling events earlier is not supported by WP-Cron.
	 */
	public static function migrate_legacy_cron(): void {
		$crons = array(
			'dcd_daily_sync'     => 'dragoncontentdecay_daily_sync',
			'dcd_weekly_digest'  => 'dragoncontentdecay_weekly_digest',
			'dcd_monthly_digest' => 'dragoncontentdecay_monthly_digest',
		);
		foreach ( $crons as $old => $new ) {
			$timestamp = wp_next_scheduled( $old );
			if ( $timestamp ) {
				$recurrence = wp_get_schedule( $old );
				wp_unschedule_event( $timestamp, $old );
				if ( $recurrence && ! wp_next_scheduled( $new ) ) {
					wp_schedule_event( time(), $recurrence, $new );
				}
			}
		}
	}

	/**
	 * Initialize plugin components
	 */
	private function init_components(): void {
		$this->oauth         = new OAuth();
		$this->api_ga4       = new API_GA4( $this->oauth );
		$this->api_gsc       = new API_GSC( $this->oauth );
		$this->analyzer      = new Analyzer( $this->api_ga4, $this->api_gsc );
		$this->scheduler     = new Scheduler( $this->analyzer );
		$this->notifications = new Notifications();
		$this->admin         = new Admin( $this->oauth, $this->analyzer );
	}

	/**
	 * Plugin activation
	 */
	public static function activate(): void {
		self::create_tables();
		self::set_default_options();

		// Schedule cron events
		if ( ! wp_next_scheduled( 'dragoncontentdecay_daily_sync' ) ) {
			wp_schedule_event( time(), 'daily', 'dragoncontentdecay_daily_sync' );
		}

		// Flush rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation
	 */
	public static function deactivate(): void {
		// Clear scheduled events (the digests are rebooked on init after
		// reactivation from the saved frequency).
		wp_clear_scheduled_hook( 'dragoncontentdecay_daily_sync' );
		wp_clear_scheduled_hook( Notifications::WEEKLY_HOOK );
		wp_clear_scheduled_hook( Notifications::MONTHLY_HOOK );

		// Flush rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * Create database tables
	 */
	private static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Decay scores cache table. The search_* columns hold the optional Google
		// Search Console signal (clicks/impressions/position, current vs previous
		// period); they stay 0 until GSC is connected and enabled.
		$table_scores = $wpdb->prefix . 'dcd_scores';
		$sql_scores   = "CREATE TABLE $table_scores (
            post_id bigint(20) unsigned NOT NULL,
            decay_score float NOT NULL DEFAULT 0,
            trend varchar(20) NOT NULL DEFAULT 'stable',
            pageviews_current int(11) NOT NULL DEFAULT 0,
            pageviews_previous int(11) NOT NULL DEFAULT 0,
            search_clicks_current int(11) NOT NULL DEFAULT 0,
            search_clicks_previous int(11) NOT NULL DEFAULT 0,
            search_impressions_current int(11) NOT NULL DEFAULT 0,
            search_impressions_previous int(11) NOT NULL DEFAULT 0,
            search_position float NOT NULL DEFAULT 0,
            last_calculated timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (post_id)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_scores );

		// dbDelta() does not report a failed CREATE or ALTER. Store the database
		// version only once the table and its newest column exist, so a failed
		// upgrade is retried on the next load. Names are compared without case,
		// as MySQL may report them in lower case.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check right after dbDelta(); no cache applies.
		$found_table = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_scores ) ) );
		if ( 0 !== strcasecmp( $found_table, $table_scores ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema check on a plugin-owned table; the table name is the site prefix plus a fixed basename.
		$found_column = (string) $wpdb->get_var( "SHOW COLUMNS FROM {$table_scores} LIKE 'search_position'" );
		if ( 0 !== strcasecmp( $found_column, 'search_position' ) ) {
			return;
		}

		update_option( 'dragoncontentdecay_db_version', DRAGONCONTENTDECAY_VERSION );
	}

	/**
	 * Set default plugin options
	 */
	private static function set_default_options(): void {
		$defaults = array(
			'dragoncontentdecay_decay_threshold'      => -20,
			'dragoncontentdecay_comparison_period'    => 30,
			'dragoncontentdecay_email_frequency'      => 'off',
			'dragoncontentdecay_post_types'           => array( 'post' ),
			'dragoncontentdecay_ga4_property_id'      => '',
			'dragoncontentdecay_google_client_id'     => '',
			'dragoncontentdecay_google_client_secret' => '',
		);

		foreach ( $defaults as $option => $value ) {
			if ( false === get_option( $option ) ) {
				add_option( $option, $value );
			}
		}
	}

	/**
	 * Get Admin instance
	 */
	public function get_admin(): Admin {
		return $this->admin;
	}

	/**
	 * Get OAuth instance
	 */
	public function get_oauth(): OAuth {
		return $this->oauth;
	}

	/**
	 * Get API_GA4 instance
	 */
	public function get_api_ga4(): API_GA4 {
		return $this->api_ga4;
	}

	/**
	 * Get Analyzer instance
	 */
	public function get_analyzer(): Analyzer {
		return $this->analyzer;
	}
}
