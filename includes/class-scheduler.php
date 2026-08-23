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

		// A sync was already running (daily cron or another manual sync); don't
		// report a misleading "Sync complete. Analyzed 0 posts."
		if ( ! empty( $result['skipped'] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'A sync is already running. Please try again in a moment.', 'dragon-content-decay' ) )
			);
		}

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
		// Guard against overlapping syncs (the daily cron and a manual sync, or two
		// manual syncs). The lock carries its own expiry so a fatal or timeout
		// mid-sync cannot wedge every future run.
		if ( ! $this->acquire_lock() ) {
			return array(
				'synced'   => 0,
				'analyzed' => 0,
				'skipped'  => true,
			);
		}

		try {
			// Log start
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic logging of API/auth failures for troubleshooting; no sensitive data logged.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'DCD: Starting data sync at ' . current_time( 'mysql' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging, only when WP_DEBUG is enabled.
			}

			$start_time = microtime( true );

			// Analyze all posts and calculate decay scores
			$analyzed = $this->analyzer->analyze_all();

			$duration = round( microtime( true ) - $start_time, 2 );

			// Log completion
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic logging of API/auth failures for troubleshooting; no sensitive data logged.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( "DCD: Sync complete. Analyzed {$analyzed} posts in {$duration}s" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging, only when WP_DEBUG is enabled.
			}

			// Update last sync time
			update_option( 'dragoncontentdecay_last_sync', time() );
			update_option( 'dragoncontentdecay_last_sync_count', $analyzed );

			return array(
				'synced'   => $analyzed,
				'analyzed' => $analyzed,
			);
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Lock key for the in-progress sync guard.
	 */
	private const LOCK_KEY = 'dragoncontentdecay_sync_in_progress';

	/**
	 * Atomically acquire the sync lock. With a persistent object cache, wp_cache_add
	 * is a genuine atomic "set if absent" that closes the check-then-set race
	 * between concurrent requests; without one it falls back to a transient (the
	 * daily cron plus an occasional manual sync race only to duplicate idempotent
	 * work). The lock self-expires so a fatal mid-sync cannot wedge future runs.
	 *
	 * @return bool True if the lock was acquired.
	 */
	private function acquire_lock(): bool {
		if ( wp_using_ext_object_cache() ) {
			return (bool) wp_cache_add( self::LOCK_KEY, 1, 'dragoncontentdecay', 15 * MINUTE_IN_SECONDS );
		}

		if ( get_transient( self::LOCK_KEY ) ) {
			return false;
		}
		set_transient( self::LOCK_KEY, 1, 15 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Release the sync lock.
	 */
	private function release_lock(): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( self::LOCK_KEY, 'dragoncontentdecay' );
			return;
		}
		delete_transient( self::LOCK_KEY );
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
		if ( wp_using_ext_object_cache() ) {
			return false !== wp_cache_get( self::LOCK_KEY, 'dragoncontentdecay' );
		}
		return (bool) get_transient( self::LOCK_KEY );
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
