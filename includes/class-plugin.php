<?php
/**
 * Main Plugin Class
 *
 * @package DragonContentDecay
 */

namespace DragonContentDecay;

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
        $this->init_components();
        $this->init_hooks();
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
     * Initialize WordPress hooks
     */
    private function init_hooks(): void {
        add_action( 'init', [ $this, 'load_textdomain' ] );
    }

    /**
     * Load plugin textdomain
     */
    public function load_textdomain(): void {
        load_plugin_textdomain(
            'dragon-content-decay',
            false,
            dirname( DCD_PLUGIN_BASENAME ) . '/languages'
        );
    }

    /**
     * Plugin activation
     */
    public static function activate(): void {
        self::create_tables();
        self::set_default_options();

        // Schedule cron events
        if ( ! wp_next_scheduled( 'dcd_daily_sync' ) ) {
            wp_schedule_event( time(), 'daily', 'dcd_daily_sync' );
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public static function deactivate(): void {
        // Clear scheduled events
        wp_clear_scheduled_hook( 'dcd_daily_sync' );

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
        $sql_analytics = "CREATE TABLE $table_analytics (
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
        $sql_scores = "CREATE TABLE $table_scores (
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
        update_option( 'dcd_db_version', DCD_VERSION );
    }

    /**
     * Set default plugin options
     */
    private static function set_default_options(): void {
        $defaults = [
            'dcd_decay_threshold'    => -20,
            'dcd_comparison_period'  => 30,
            'dcd_email_frequency'    => 'off',
            'dcd_post_types'         => [ 'post' ],
            'dcd_ga4_property_id'    => '',
            'dcd_google_client_id'   => '',
            'dcd_google_client_secret' => '',
        ];

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
