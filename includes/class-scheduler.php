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

		if ( self::STATUS_COMPLETE !== $result['status'] ) {
			wp_send_json_error(
				array(
					'message'  => sprintf(
						/* translators: 1: Number of posts analyzed, 2: Number of posts whose score could not be saved */
						__( 'Sync finished with errors. Analyzed %1$d posts; %2$d could not be saved. Check the database and try again.', 'dragon-content-decay' ),
						$result['analyzed'],
						$result['failed']
					),
					'analyzed' => $result['analyzed'],
					'failed'   => $result['failed'],
					'status'   => $result['status'],
				)
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
	 * Sync outcome: every score row written and the cursor saved.
	 */
	public const STATUS_COMPLETE = 'complete';

	/**
	 * Sync outcome: some score rows written, but at least one write (or the
	 * cursor save) failed.
	 */
	public const STATUS_PARTIAL = 'partial';

	/**
	 * Sync outcome: score rows were attempted and none could be written.
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * Perform sync operation
	 *
	 * @return array ['synced' => int, 'analyzed' => int, 'failed' => int, 'status' => string]
	 */
	public function sync(): array {
		// Guard against overlapping syncs (the daily cron and a manual sync, or two
		// manual syncs). The lock carries its own expiry so a fatal or timeout
		// mid-sync cannot wedge every future run.
		if ( ! $this->acquire_lock() ) {
			return array(
				'synced'   => 0,
				'analyzed' => 0,
				'failed'   => 0,
				'status'   => self::STATUS_COMPLETE,
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
			$outcome  = $this->analyzer->analyze_all();
			$analyzed = (int) ( $outcome['analyzed'] ?? 0 );
			$failed   = (int) ( $outcome['failed'] ?? 0 );
			$status   = self::status_for( $analyzed, $failed, ! empty( $outcome['cursor_saved'] ) );

			$duration = round( microtime( true ) - $start_time, 2 );

			// Log completion
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic logging of API/auth failures for troubleshooting; no sensitive data logged.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( "DCD: Sync {$status}. Analyzed {$analyzed} posts, {$failed} failed to save, in {$duration}s" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging, only when WP_DEBUG is enabled.
			}

			// Record the outcome for the dashboard
			update_option( 'dragoncontentdecay_last_sync', time() );
			update_option( 'dragoncontentdecay_last_sync_count', $analyzed );
			update_option( 'dragoncontentdecay_last_sync_failed', $failed );
			update_option( 'dragoncontentdecay_last_sync_status', $status );

			return array(
				'synced'   => $analyzed,
				'analyzed' => $analyzed,
				'failed'   => $failed,
				'status'   => $status,
			);
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Classify a run: failed when writes were attempted and none succeeded,
	 * partial when any write or the cursor save failed, complete otherwise.
	 *
	 * @param int  $analyzed     Score rows written.
	 * @param int  $failed       Score rows that could not be written.
	 * @param bool $cursor_saved Whether the rotating cursor persisted.
	 * @return string One of the STATUS_* constants.
	 */
	private static function status_for( int $analyzed, int $failed, bool $cursor_saved ): string {
		if ( $failed > 0 && 0 === $analyzed ) {
			return self::STATUS_FAILED;
		}

		if ( $failed > 0 || ! $cursor_saved ) {
			return self::STATUS_PARTIAL;
		}

		return self::STATUS_COMPLETE;
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
			'failed'    => (int) get_option( 'dragoncontentdecay_last_sync_failed', 0 ),
			'status'    => (string) get_option( 'dragoncontentdecay_last_sync_status', self::STATUS_COMPLETE ),
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
