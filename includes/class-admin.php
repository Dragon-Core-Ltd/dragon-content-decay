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
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'manage_posts_columns', array( $this, 'add_decay_column' ) );
		add_action( 'manage_posts_custom_column', array( $this, 'render_decay_column' ), 10, 2 );
		add_filter( 'manage_edit-post_sortable_columns', array( $this, 'make_decay_column_sortable' ) );
		add_action( 'pre_get_posts', array( $this, 'sort_by_decay_score' ) );
		add_filter( 'post_row_actions', array( $this, 'add_analytics_link' ), 10, 2 );
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
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render admin page with tabs
	 */
	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'dragon-content-decay' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the current tab for display only; no state change.
		$tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'dashboard';

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
			DRAGONCONTENTDECAY_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			DRAGONCONTENTDECAY_VERSION
		);

		wp_enqueue_script(
			'dcd-admin',
			DRAGONCONTENTDECAY_PLUGIN_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			DRAGONCONTENTDECAY_VERSION,
			true
		);

		wp_localize_script(
			'dcd-admin',
			'dcdAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'dragoncontentdecay_admin_nonce' ),
				'i18n'    => array(
					'syncing' => __( 'Syncing...', 'dragon-content-decay' ),
					'synced'  => __( 'Sync complete!', 'dragon-content-decay' ),
					'error'   => __( 'An error occurred.', 'dragon-content-decay' ),
				),
			)
		);
	}

	/**
	 * Render dashboard page
	 */
	public function render_dashboard_page(): void {
		// Get data for dashboard
		$is_connected    = $this->oauth->is_connected();
		$decay_threshold = get_option( 'dragoncontentdecay_decay_threshold', -20 );
		$posts_data      = $is_connected ? $this->get_dashboard_data() : array();
		$current_tab     = 'dashboard';
		$summary         = $is_connected ? $this->analyzer->get_summary() : array();
		$last_sync       = $is_connected ? ( new Scheduler( $this->analyzer ) )->get_last_sync_info() : array();
		$trend_icons     = array(
			'decaying' => 'arrow-down-alt',
			'stable'   => 'minus',
			'growing'  => 'arrow-up-alt',
		);
		$trend_labels    = array(
			'decaying' => __( 'Decaying', 'dragon-content-decay' ),
			'stable'   => __( 'Stable', 'dragon-content-decay' ),
			'growing'  => __( 'Growing', 'dragon-content-decay' ),
		);

		include DRAGONCONTENTDECAY_PLUGIN_DIR . 'admin/views/dashboard.php';
	}

	/**
	 * Render settings page
	 */
	public function render_settings_page(): void {
		// Handle form submission
		if ( isset( $_POST['dragoncontentdecay_settings_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['dragoncontentdecay_settings_nonce'] ) ), 'dragoncontentdecay_save_settings' ) ) {
			$this->save_settings();
		}

		// Handle OAuth actions (with CSRF protection)
		if ( isset( $_GET['action'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below for state-changing actions; OAuth callback is validated separately.
			$action = sanitize_text_field( wp_unslash( $_GET['action'] ) );

			// Verify nonce for connect/disconnect actions
			if ( in_array( $action, array( 'connect', 'disconnect' ), true ) ) {
				if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'dragoncontentdecay_oauth_action' ) ) {
					wp_die(
						esc_html__( 'Security check failed. Please try again.', 'dragon-content-decay' ),
						esc_html__( 'Error', 'dragon-content-decay' ),
						array(
							'response'  => 403,
							'back_link' => true,
						)
					);
				}
			}

			$this->handle_oauth_action( $action );
		}

		// Get current settings
		$settings       = $this->get_settings();
		$current_tab    = 'settings';
		$post_types     = get_post_types( array( 'public' => true ), 'objects' );
		$selected_types = (array) $settings['post_types'];

		include DRAGONCONTENTDECAY_PLUGIN_DIR . 'admin/views/settings.php';
	}

	/**
	 * Get current settings
	 */
	private function get_settings(): array {
		return array(
			'client_id'         => get_option( 'dragoncontentdecay_google_client_id', '' ),
			'client_secret'     => get_option( 'dragoncontentdecay_google_client_secret', '' ),
			'ga4_property_id'   => get_option( 'dragoncontentdecay_ga4_property_id', '' ),
			'decay_threshold'   => get_option( 'dragoncontentdecay_decay_threshold', -20 ),
			'comparison_period' => get_option( 'dragoncontentdecay_comparison_period', 30 ),
			'email_frequency'   => get_option( 'dragoncontentdecay_email_frequency', 'off' ),
			'post_types'        => get_option( 'dragoncontentdecay_post_types', array( 'post' ) ),
			'is_connected'      => $this->oauth->is_connected(),
		);
	}

	/**
	 * Save settings
	 */
	private function save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! isset( $_POST['dragoncontentdecay_settings_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['dragoncontentdecay_settings_nonce'] ) ), 'dragoncontentdecay_save_settings' ) ) {
			return;
		}

		// Sanitize and save each setting
		if ( isset( $_POST['dragoncontentdecay_google_client_id'] ) ) {
			update_option( 'dragoncontentdecay_google_client_id', sanitize_text_field( wp_unslash( $_POST['dragoncontentdecay_google_client_id'] ) ) );
		}

		if ( isset( $_POST['dragoncontentdecay_google_client_secret'] ) ) {
			update_option( 'dragoncontentdecay_google_client_secret', sanitize_text_field( wp_unslash( $_POST['dragoncontentdecay_google_client_secret'] ) ) );
		}

		if ( isset( $_POST['dragoncontentdecay_ga4_property_id'] ) ) {
			update_option( 'dragoncontentdecay_ga4_property_id', sanitize_text_field( wp_unslash( $_POST['dragoncontentdecay_ga4_property_id'] ) ) );
		}

		if ( isset( $_POST['dragoncontentdecay_decay_threshold'] ) ) {
			update_option( 'dragoncontentdecay_decay_threshold', intval( $_POST['dragoncontentdecay_decay_threshold'] ) );
		}

		if ( isset( $_POST['dragoncontentdecay_comparison_period'] ) ) {
			update_option( 'dragoncontentdecay_comparison_period', intval( $_POST['dragoncontentdecay_comparison_period'] ) );
		}

		if ( isset( $_POST['dragoncontentdecay_email_frequency'] ) ) {
			update_option( 'dragoncontentdecay_email_frequency', sanitize_text_field( wp_unslash( $_POST['dragoncontentdecay_email_frequency'] ) ) );
		}

		if ( isset( $_POST['dragoncontentdecay_post_types'] ) ) {
			$post_types = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['dragoncontentdecay_post_types'] ) );
			update_option( 'dragoncontentdecay_post_types', $post_types );
		}

		add_settings_error( 'dragoncontentdecay_settings', 'settings_saved', __( 'Settings saved.', 'dragon-content-decay' ), 'success' );
	}

	/**
	 * Handle OAuth actions (connect/disconnect)
	 */
	private function handle_oauth_action( string $action ): void {
		if ( 'connect' === $action ) {
			// Redirect to Google OAuth
			$auth_url = $this->oauth->get_auth_url();
			if ( $auth_url ) {
				// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Redirect target is Google's external OAuth endpoint; wp_safe_redirect() would strip it.
				wp_redirect( $auth_url );
				exit;
			}
		} elseif ( 'disconnect' === $action ) {
			$this->oauth->disconnect();
			wp_safe_redirect( admin_url( 'tools.php?page=dragon-content-decay&tab=settings&disconnected=1' ) );
			exit;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback request originates from Google and cannot carry a WordPress nonce; page is capability-gated.
		} elseif ( 'callback' === $action && isset( $_GET['code'] ) ) {
			// Handle OAuth callback
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback request originates from Google and cannot carry a WordPress nonce; page is capability-gated.
			$code = sanitize_text_field( wp_unslash( $_GET['code'] ) );
			if ( $this->oauth->handle_callback( $code ) ) {
				wp_safe_redirect( admin_url( 'tools.php?page=dragon-content-decay&tab=settings&connected=1' ) );
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

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table name built from $wpdb->prefix, not user input; admin-only read of plugin-owned table.
		$results = $wpdb->get_results(
			"SELECT s.*, p.post_title, p.post_date, p.post_modified
             FROM {$table_scores} s
             JOIN {$wpdb->posts} p ON s.post_id = p.ID
             WHERE p.post_status = 'publish'
             ORDER BY s.decay_score ASC
             LIMIT 100",
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Add decay column to posts list
	 */
	public function add_decay_column( array $columns ): array {
		$columns['dragoncontentdecay_decay'] = __( 'Decay', 'dragon-content-decay' );
		return $columns;
	}

	/**
	 * Render decay column content
	 */
	public function render_decay_column( string $column, int $post_id ): void {
		if ( 'dragoncontentdecay_decay' !== $column ) {
			return;
		}

		global $wpdb;
		$table_scores = $wpdb->prefix . 'dcd_scores';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table name built from $wpdb->prefix, not user input; values passed through $wpdb->prepare().
		$score = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT decay_score, trend FROM {$table_scores} WHERE post_id = %d",
				$post_id
			)
		);
		// phpcs:enable

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
		$columns['dragoncontentdecay_decay'] = 'dragoncontentdecay_decay';
		return $columns;
	}

	/**
	 * Sort posts by decay score
	 */
	public function sort_by_decay_score( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( 'dragoncontentdecay_decay' !== $query->get( 'orderby' ) ) {
			return;
		}

		// Use posts_clauses filter for proper JOIN-based sorting
		add_filter( 'posts_clauses', array( $this, 'modify_query_for_decay_sort' ) );
	}

	/**
	 * Modify query clauses to sort by decay score
	 */
	public function modify_query_for_decay_sort( array $clauses ): array {
		global $wpdb;

		$table_scores = $wpdb->prefix . 'dcd_scores';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the list-table sort order for display only; no state change.
		$order = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC';

		// LEFT JOIN to include posts without scores (they'll sort to end)
		$clauses['join'] .= " LEFT JOIN {$table_scores} AS dcd_scores ON {$wpdb->posts}.ID = dcd_scores.post_id";

		// Sort by decay score, NULLs last
		$clauses['orderby'] = "COALESCE(dcd_scores.decay_score, 999999) {$order}";

		// Remove filter after use to prevent affecting other queries
		remove_filter( 'posts_clauses', array( $this, 'modify_query_for_decay_sort' ) );

		return $clauses;
	}

	/**
	 * Add analytics link to post row actions
	 */
	public function add_analytics_link( array $actions, \WP_Post $post ): array {
		if ( current_user_can( 'manage_options' ) ) {
			$url                      = add_query_arg(
				array(
					'page'    => 'dragon-content-decay',
					'post_id' => $post->ID,
				),
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
