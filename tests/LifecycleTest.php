<?php
/**
 * Deactivation clears every scheduled event, the decay threshold stays in
 * -100..-1, and reconnecting or disconnecting clears a stored sync failure.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\Admin;
use DragonContentDecay\Analyzer;
use DragonContentDecay\Crypto;
use DragonContentDecay\Notifications;
use DragonContentDecay\OAuth;
use DragonContentDecay\Plugin;
use DragonContentDecay\Scheduler;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-plugin.php';

final class LifecycleTest extends TestCase {

	protected function setUp(): void {
		dragoncontentdecay_test_reset();
		$GLOBALS['dragoncontentdecay_test_settings_errors'] = array();
		add_filter( 'cron_schedules', array( Notifications::class, 'add_schedules' ) );
	}

	protected function tearDown(): void {
		$_POST = array();
	}

	public function test_deactivation_clears_the_digest_events_too(): void {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dragoncontentdecay_daily_sync' );
		wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', Notifications::WEEKLY_HOOK );
		wp_schedule_event( time() + DAY_IN_SECONDS, 'monthly', Notifications::MONTHLY_HOOK );

		Plugin::deactivate();

		$this->assertFalse( wp_next_scheduled( 'dragoncontentdecay_daily_sync' ) );
		$this->assertFalse( wp_next_scheduled( Notifications::WEEKLY_HOOK ) );
		$this->assertFalse( wp_next_scheduled( Notifications::MONTHLY_HOOK ) );
	}

	public function test_a_zero_threshold_does_not_make_unchanged_posts_decaying_and_growing(): void {
		update_option( 'dragoncontentdecay_decay_threshold', 0 );

		$this->assertSame( Analyzer::TREND_STABLE, AnalyzerTestSupport::analyzer()->determine_trend( 0.0 ) );
		$this->assertSame( -1, Analyzer::decay_threshold() );
	}

	public function test_threshold_is_read_within_range(): void {
		update_option( 'dragoncontentdecay_decay_threshold', 35 );
		$this->assertSame( -1, Analyzer::decay_threshold() );

		update_option( 'dragoncontentdecay_decay_threshold', -250 );
		$this->assertSame( -100, Analyzer::decay_threshold() );

		update_option( 'dragoncontentdecay_decay_threshold', 'junk' );
		$this->assertSame( -1, Analyzer::decay_threshold() );

		update_option( 'dragoncontentdecay_decay_threshold', -30 );
		$this->assertSame( -30, Analyzer::decay_threshold() );
	}

	/**
	 * @dataProvider saved_thresholds
	 */
	public function test_threshold_is_clamped_on_save( string $posted, int $stored ): void {
		$_POST = array(
			'dragoncontentdecay_settings_nonce'  => 'valid',
			'dragoncontentdecay_decay_threshold' => $posted,
		);

		$admin  = new Admin( AnalyzerTestSupport::oauth(), AnalyzerTestSupport::analyzer() );
		$method = new ReflectionMethod( Admin::class, 'save_settings' );
		$method->invoke( $admin );

		$this->assertSame( $stored, get_option( 'dragoncontentdecay_decay_threshold' ) );
	}

	public static function saved_thresholds(): array {
		return array(
			'zero'        => array( '0', -1 ),
			'positive'    => array( '40', -1 ),
			'below range' => array( '-150', -100 ),
			'in range'    => array( '-25', -25 ),
		);
	}

	private function record_fetch_failure(): void {
		update_option( 'dragoncontentdecay_last_sync_status', Scheduler::STATUS_FAILED );
		update_option( 'dragoncontentdecay_last_sync_error', 'Google no longer accepts this site\'s sign-in.' );
	}

	private function assert_failure_cleared(): void {
		$info = ( new Scheduler( AnalyzerTestSupport::analyzer() ) )->get_last_sync_info();
		$this->assertSame( '', $info['error'] );
		$this->assertSame( Scheduler::STATUS_COMPLETE, $info['status'] );
	}

	private function oauth_with_code_exchange( array $response ): OAuth {
		update_option( 'dragoncontentdecay_google_client_id', 'client-id' );
		OAuth::set_client_secret( 'client-secret' );

		return new class( $response ) extends OAuth {
			private array $response;

			public function __construct( array $response ) {
				$this->response = $response;
				parent::__construct();
			}

			protected function request_auth_code_exchange( string $code ): array {
				unset( $code );
				return $this->response;
			}
		};
	}

	public function test_reconnecting_clears_the_stored_sync_failure(): void {
		$this->record_fetch_failure();
		$oauth = $this->oauth_with_code_exchange(
			array(
				'access_token'  => 'new-access',
				'refresh_token' => 'new-refresh',
				'expires_in'    => 3600,
				'created'       => time(),
			)
		);

		$this->assertTrue( $oauth->handle_callback( 'auth-code' ) );
		$this->assert_failure_cleared();
	}

	public function test_a_failed_reconnect_keeps_the_stored_sync_failure(): void {
		$this->record_fetch_failure();
		$oauth = $this->oauth_with_code_exchange( array( 'error' => 'invalid_grant' ) );

		$this->assertFalse( $oauth->handle_callback( 'auth-code' ) );
		$this->assertSame( Scheduler::STATUS_FAILED, get_option( 'dragoncontentdecay_last_sync_status' ) );
	}

	public function test_disconnecting_clears_the_stored_sync_failure(): void {
		$this->record_fetch_failure();
		update_option( 'dragoncontentdecay_google_tokens', Crypto::encrypt( wp_json_encode( array( 'access_token' => 'a' ) ) ) );

		$this->oauth_with_code_exchange( array() )->disconnect();

		$this->assert_failure_cleared();
	}

	public function test_clearing_leaves_a_write_failure_alone(): void {
		// A run whose score writes all failed is not a connection problem.
		update_option( 'dragoncontentdecay_last_sync_status', Scheduler::STATUS_FAILED );

		Scheduler::clear_fetch_failure();

		$this->assertSame( Scheduler::STATUS_FAILED, get_option( 'dragoncontentdecay_last_sync_status' ) );
	}
}
