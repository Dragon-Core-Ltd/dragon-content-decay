<?php
/**
 * Scheduler Class
 *
 * Handles cron jobs for data synchronization
 *
 * @package DragonContentDecay
 */

namespace DragonContentDecay;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Scheduler {

	/**
	 * Analyzer instance
	 */
	private Analyzer $analyzer;

	/**
	 * Cron hook name
	 */
	public const CRON_HOOK = 'dragoncontentdecay_daily_sync';

	/**
	 * Constructor
	 */
	public function __construct( Analyzer $analyzer ) {
		$this->analyzer = $analyzer;
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks(): void {
		add_action( self::CRON_HOOK, array( $this, 'run_daily_sync' ) );
		add_action( 'wp_ajax_dragoncontentdecay_manual_sync', array( $this, 'handle_manual_sync' ) );
	}

	/**
	 * Run daily sync job
	 */
	public function run_daily_sync(): void {
		$this->sync();
	}

	/**
	 * Handle manual sync request (AJAX)
	 */
	public function handle_manual_sync(): void {
		check_ajax_referer( 'dragoncontentdecay_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dragon-content-decay' ) ) );
		}

		$result = $this->sync();

		wp_send_json_success(
			array(
				'message'  => sprintf(
					/* translators: %d: Number of posts analyzed */
					__( 'Sync complete. Analyzed %d posts.', 'dragon-content-decay' ),
					$result['analyzed']
				),
				'analyzed' => $result['analyzed'],
				'synced'   => $result['synced'],
			)
		);
	}

	/**
	 * Perform sync operation
	 *
	 * @return array ['synced' => int, 'analyzed' => int]
	 */
	public function sync(): array {
		// Log start
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic logging of API/auth failures for troubleshooting; no sensitive data logged.
		error_log( 'DCD: Starting data sync at ' . current_time( 'mysql' ) );

		$start_time = microtime( true );

		// Analyze all posts and calculate decay scores
		$analyzed = $this->analyzer->analyze_all();

		$duration = round( microtime( true ) - $start_time, 2 );

		// Log completion
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic logging of API/auth failures for troubleshooting; no sensitive data logged.
		error_log( "DCD: Sync complete. Analyzed {$analyzed} posts in {$duration}s" );

		// Update last sync time
		update_option( 'dragoncontentdecay_last_sync', time() );
		update_option( 'dragoncontentdecay_last_sync_count', $analyzed );

		return array(
			'synced'   => $analyzed,
			'analyzed' => $analyzed,
		);
	}

	/**
	 * Get last sync info
	 *
	 * @return array
	 */
	public function get_last_sync_info(): array {
		$timestamp = get_option( 'dragoncontentdecay_last_sync', 0 );
		$count     = get_option( 'dragoncontentdecay_last_sync_count', 0 );

		return array(
			'timestamp' => $timestamp,
			'formatted' => $timestamp ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : __( 'Never', 'dragon-content-decay' ),
			'count'     => $count,
		);
	}

	/**
	 * Get next scheduled sync
	 *
	 * @return string
	 */
	public function get_next_sync(): string {
		$next = wp_next_scheduled( self::CRON_HOOK );

		if ( ! $next ) {
			return __( 'Not scheduled', 'dragon-content-decay' );
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next );
	}

	/**
	 * Check if sync is currently running
	 *
	 * @return bool
	 */
	public function is_syncing(): bool {
		return (bool) get_transient( 'dragoncontentdecay_sync_in_progress' );
	}

	/**
	 * Reschedule cron job
	 */
	public static function reschedule(): void {
		// Clear existing schedule
		wp_clear_scheduled_hook( self::CRON_HOOK );

		// Schedule new event
		wp_schedule_event( time(), 'daily', self::CRON_HOOK );
	}
}
