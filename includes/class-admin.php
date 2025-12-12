<?php
/**
 * Admin Class
 *
 * Handles admin pages, menus, and assets
 *
 * @package DragonContentDecay
 */

namespace DragonContentDecay;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

    /**
     * OAuth instance
     */
    private OAuth $oauth;

    /**
     * Analyzer instance
     */
    private Analyzer $analyzer;

    /**
     * Constructor
     */
    public function __construct( OAuth $oauth, Analyzer $analyzer ) {
        $this->oauth    = $oauth;
        $this->analyzer = $analyzer;

        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_filter( 'manage_posts_columns', [ $this, 'add_decay_column' ] );
        add_action( 'manage_posts_custom_column', [ $this, 'render_decay_column' ], 10, 2 );
        add_filter( 'manage_edit-post_sortable_columns', [ $this, 'make_decay_column_sortable' ] );
        add_action( 'pre_get_posts', [ $this, 'sort_by_decay_score' ] );
        add_filter( 'post_row_actions', [ $this, 'add_analytics_link' ], 10, 2 );
    }

    /**
     * Add admin menu pages
     */
    public function add_admin_menu(): void {
        // Main page under Tools menu
        add_management_page(
            __( 'Dragon Content Decay', 'dragon-content-decay' ),
            __( 'Content Decay', 'dragon-content-decay' ),
            'manage_options',
            'dragon-content-decay',
            [ $this, 'render_admin_page' ]
        );
    }

    /**
     * Render admin page with tabs
     */
    public function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'dragon-content-decay' ) );
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'dashboard';

        if ( 'settings' === $tab ) {
            $this->render_settings_page();
        } else {
            $this->render_dashboard_page();
        }
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_assets( string $hook ): void {
        // Only load on our plugin pages
        if ( ! str_contains( $hook, 'dragon-content-decay' ) ) {
            return;
        }

        wp_enqueue_style(
            'dcd-admin',
            DCD_PLUGIN_URL . 'admin/css/admin.css',
            [],
            DCD_VERSION
        );

        wp_enqueue_script(
            'dcd-admin',
            DCD_PLUGIN_URL . 'admin/js/admin.js',
            [ 'jquery' ],
            DCD_VERSION,
            true
        );

        wp_localize_script( 'dcd-admin', 'dcdAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'dcd_admin_nonce' ),
            'i18n'    => [
                'syncing'  => __( 'Syncing...', 'dragon-content-decay' ),
                'synced'   => __( 'Sync complete!', 'dragon-content-decay' ),
                'error'    => __( 'An error occurred.', 'dragon-content-decay' ),
            ],
        ] );
    }

    /**
     * Render dashboard page
     */
    public function render_dashboard_page(): void {
        // Get data for dashboard
        $is_connected    = $this->oauth->is_connected();
        $decay_threshold = get_option( 'dcd_decay_threshold', -20 );
        $posts_data      = $is_connected ? $this->get_dashboard_data() : [];
        $current_tab     = 'dashboard';

        include DCD_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    /**
     * Render settings page
     */
    public function render_settings_page(): void {
        // Handle form submission
        if ( isset( $_POST['dcd_settings_nonce'] ) && wp_verify_nonce( $_POST['dcd_settings_nonce'], 'dcd_save_settings' ) ) {
            $this->save_settings();
        }

        // Handle OAuth actions (with CSRF protection)
        if ( isset( $_GET['action'] ) ) {
            $action = sanitize_text_field( $_GET['action'] );

            // Verify nonce for connect/disconnect actions
            if ( in_array( $action, [ 'connect', 'disconnect' ], true ) ) {
                if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( $_GET['_wpnonce'] ), 'dcd_oauth_action' ) ) {
                    wp_die(
                        esc_html__( 'Security check failed. Please try again.', 'dragon-content-decay' ),
                        esc_html__( 'Error', 'dragon-content-decay' ),
                        [ 'response' => 403, 'back_link' => true ]
                    );
                }
            }

            $this->handle_oauth_action( $action );
        }

        // Get current settings
        $settings = $this->get_settings();
        $current_tab = 'settings';

        include DCD_PLUGIN_DIR . 'admin/views/settings.php';
    }

    /**
     * Get current settings
     */
    private function get_settings(): array {
        return [
            'client_id'         => get_option( 'dcd_google_client_id', '' ),
            'client_secret'     => get_option( 'dcd_google_client_secret', '' ),
            'ga4_property_id'   => get_option( 'dcd_ga4_property_id', '' ),
            'decay_threshold'   => get_option( 'dcd_decay_threshold', -20 ),
            'comparison_period' => get_option( 'dcd_comparison_period', 30 ),
            'email_frequency'   => get_option( 'dcd_email_frequency', 'off' ),
            'post_types'        => get_option( 'dcd_post_types', [ 'post' ] ),
            'is_connected'      => $this->oauth->is_connected(),
        ];
    }

    /**
     * Save settings
     */
    private function save_settings(): void {
        // Sanitize and save each setting
        if ( isset( $_POST['dcd_google_client_id'] ) ) {
            update_option( 'dcd_google_client_id', sanitize_text_field( $_POST['dcd_google_client_id'] ) );
        }

        if ( isset( $_POST['dcd_google_client_secret'] ) ) {
            update_option( 'dcd_google_client_secret', sanitize_text_field( $_POST['dcd_google_client_secret'] ) );
        }

        if ( isset( $_POST['dcd_ga4_property_id'] ) ) {
            update_option( 'dcd_ga4_property_id', sanitize_text_field( $_POST['dcd_ga4_property_id'] ) );
        }

        if ( isset( $_POST['dcd_decay_threshold'] ) ) {
            update_option( 'dcd_decay_threshold', intval( $_POST['dcd_decay_threshold'] ) );
        }

        if ( isset( $_POST['dcd_comparison_period'] ) ) {
            update_option( 'dcd_comparison_period', intval( $_POST['dcd_comparison_period'] ) );
        }

        if ( isset( $_POST['dcd_email_frequency'] ) ) {
            update_option( 'dcd_email_frequency', sanitize_text_field( $_POST['dcd_email_frequency'] ) );
        }

        if ( isset( $_POST['dcd_post_types'] ) ) {
            $post_types = array_map( 'sanitize_text_field', (array) $_POST['dcd_post_types'] );
            update_option( 'dcd_post_types', $post_types );
        }

        add_settings_error( 'dcd_settings', 'settings_saved', __( 'Settings saved.', 'dragon-content-decay' ), 'success' );
    }

    /**
     * Handle OAuth actions (connect/disconnect)
     */
    private function handle_oauth_action( string $action ): void {
        if ( 'connect' === $action ) {
            // Redirect to Google OAuth
            $auth_url = $this->oauth->get_auth_url();
            if ( $auth_url ) {
                wp_redirect( $auth_url );
                exit;
            }
        } elseif ( 'disconnect' === $action ) {
            $this->oauth->disconnect();
            wp_redirect( admin_url( 'tools.php?page=dragon-content-decay&tab=settings&disconnected=1' ) );
            exit;
        } elseif ( 'callback' === $action && isset( $_GET['code'] ) ) {
            // Handle OAuth callback
            $code = sanitize_text_field( $_GET['code'] );
            if ( $this->oauth->handle_callback( $code ) ) {
                wp_redirect( admin_url( 'tools.php?page=dragon-content-decay&tab=settings&connected=1' ) );
                exit;
            }
        }
    }

    /**
     * Get dashboard data
     */
    private function get_dashboard_data(): array {
        global $wpdb;

        $table_scores = $wpdb->prefix . 'dcd_scores';
        $results = $wpdb->get_results(
            "SELECT s.*, p.post_title, p.post_date, p.post_modified
             FROM {$table_scores} s
             JOIN {$wpdb->posts} p ON s.post_id = p.ID
             WHERE p.post_status = 'publish'
             ORDER BY s.decay_score ASC
             LIMIT 100",
            ARRAY_A
        );

        return $results ?: [];
    }

    /**
     * Add decay column to posts list
     */
    public function add_decay_column( array $columns ): array {
        $columns['dcd_decay'] = __( 'Decay', 'dragon-content-decay' );
        return $columns;
    }

    /**
     * Render decay column content
     */
    public function render_decay_column( string $column, int $post_id ): void {
        if ( 'dcd_decay' !== $column ) {
            return;
        }

        global $wpdb;
        $table_scores = $wpdb->prefix . 'dcd_scores';

        $score = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT decay_score, trend FROM {$table_scores} WHERE post_id = %d",
                $post_id
            )
        );

        if ( ! $score ) {
            echo '<span class="dcd-no-data">—</span>';
            return;
        }

        $class = 'dcd-stable';
        if ( $score->decay_score <= -20 ) {
            $class = 'dcd-decaying';
        } elseif ( $score->decay_score >= 20 ) {
            $class = 'dcd-growing';
        }

        printf(
            '<span class="dcd-score %s">%s%%</span>',
            esc_attr( $class ),
            esc_html( number_format( $score->decay_score, 1 ) )
        );
    }

    /**
     * Make decay column sortable
     */
    public function make_decay_column_sortable( array $columns ): array {
        $columns['dcd_decay'] = 'dcd_decay';
        return $columns;
    }

    /**
     * Sort posts by decay score
     */
    public function sort_by_decay_score( \WP_Query $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        if ( 'dcd_decay' !== $query->get( 'orderby' ) ) {
            return;
        }

        // Use posts_clauses filter for proper JOIN-based sorting
        add_filter( 'posts_clauses', [ $this, 'modify_query_for_decay_sort' ] );
    }

    /**
     * Modify query clauses to sort by decay score
     */
    public function modify_query_for_decay_sort( array $clauses ): array {
        global $wpdb;

        $table_scores = $wpdb->prefix . 'dcd_scores';
        $order = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_text_field( $_GET['order'] ) ) ? 'ASC' : 'DESC';

        // LEFT JOIN to include posts without scores (they'll sort to end)
        $clauses['join'] .= " LEFT JOIN {$table_scores} AS dcd_scores ON {$wpdb->posts}.ID = dcd_scores.post_id";

        // Sort by decay score, NULLs last
        $clauses['orderby'] = "COALESCE(dcd_scores.decay_score, 999999) {$order}";

        // Remove filter after use to prevent affecting other queries
        remove_filter( 'posts_clauses', [ $this, 'modify_query_for_decay_sort' ] );

        return $clauses;
    }

    /**
     * Add analytics link to post row actions
     */
    public function add_analytics_link( array $actions, \WP_Post $post ): array {
        if ( current_user_can( 'manage_options' ) ) {
            $url = add_query_arg(
                [ 'page' => 'dragon-content-decay', 'post_id' => $post->ID ],
                admin_url( 'admin.php' )
            );
            $actions['dcd_analytics'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url( $url ),
                esc_html__( 'View Analytics', 'dragon-content-decay' )
            );
        }
        return $actions;
    }
}
