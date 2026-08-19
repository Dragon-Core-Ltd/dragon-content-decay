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
		$this->init_components();
	}

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
	 */
	private static function migrate_legacy_prefix(): void {
		// db_version is a schema marker managed by activation/create_tables, not
		// user data; just drop the legacy copy.
		delete_option( 'dcd_db_version' );

		$options = array(
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

		// Copy each legacy value onto the new name, then remove the legacy copy —
		// per option, so the delete only ever runs after a successful copy. (A
		// single shared guard would delete on a deactivate/reactivate cycle, where
		// activation re-stamps the new db_version before the copy could run.)
		foreach ( $options as $name ) {
			$legacy = get_option( 'dcd_' . $name, null );
			if ( null !== $legacy ) {
				update_option( 'dragoncontentdecay_' . $name, $legacy );
				delete_option( 'dcd_' . $name );
			}
		}

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
		$this->analyzer      = new Analyzer( $this->api_ga4 );
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
		// Clear scheduled events
		wp_clear_scheduled_hook( 'dragoncontentdecay_daily_sync' );

		// Flush rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * Create database tables
	 */
	private static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Analytics data table
		$table_analytics = $wpdb->prefix . 'dcd_analytics';
		$sql_analytics   = "CREATE TABLE $table_analytics (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            date date NOT NULL,
            pageviews int(11) NOT NULL DEFAULT 0,
            sessions int(11) NOT NULL DEFAULT 0,
            avg_time_on_page float NOT NULL DEFAULT 0,
            created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_post_date (post_id, date),
            KEY idx_date (date)
        ) $charset_collate;";

		// Decay scores cache table
		$table_scores = $wpdb->prefix . 'dcd_scores';
		$sql_scores   = "CREATE TABLE $table_scores (
            post_id bigint(20) unsigned NOT NULL,
            decay_score float NOT NULL DEFAULT 0,
            trend varchar(20) NOT NULL DEFAULT 'stable',
            pageviews_current int(11) NOT NULL DEFAULT 0,
            pageviews_previous int(11) NOT NULL DEFAULT 0,
            last_calculated timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (post_id)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_analytics );
		dbDelta( $sql_scores );

		// Store database version
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
